import 'json.dart';
import 'pagination.dart';

/// The success envelope produced by `App\Support\ApiResponse::success()`.
///
/// ```json
/// {"success": true, "data": <data>, "message": <message>}
/// {"success": true, "data": <data>, "message": <message>, "meta": <meta>}
/// ```
///
/// ## The key order is load-bearing and is preserved here for that reason
///
/// `data` sits between `success` and `message` because the generated OpenAPI
/// document and the mobile handoff both describe the envelope positionally, and
/// `meta` is appended **last** precisely so that adding it could not renumber
/// the three original keys. This class therefore never re-serialises an
/// envelope; it only reads one, and every field is a separate named property
/// rather than an index into a decoded map.
///
/// ## `meta == null` means the key was ABSENT, not present-and-null
///
/// `ApiResponse::success()` omits the key entirely when `$meta === null`, and
/// it does that on purpose: a `"meta": null` would force every non-list
/// endpoint to carry a fourth key a client has to null-check, and it would make
/// "this response is not paginated" ambiguous. Omission is the only shape in
/// which absence is unambiguous, so this class preserves it: [meta] is `null`
/// for every non-list response and non-null only when the server sent a block.
/// A server that *did* send `"meta": null` is normalised to the same `null`,
/// so a client never has to distinguish the two.
class ApiEnvelope<T> {
  /// Creates an envelope directly, without parsing.
  const ApiEnvelope({
    required this.success,
    this.data,
    this.message = '',
    this.meta,
  });

  /// Parses a decoded response body.
  ///
  /// [parseData] converts the `data` member into [T]. It is called even when
  /// `data` is absent, because a caller parsing a DTO has to be able to say what
  /// a missing `data` means for that DTO -- and for every endpoint in this API
  /// it means the contract was broken, so [parseData] is expected to throw
  /// rather than fabricate a default.
  factory ApiEnvelope.fromJson(
    Object? body, {
    required T Function(Object? data) parseData,
  }) {
    final Map<String, Object?> json = jsonMap(body);

    return ApiEnvelope<T>(
      success: jsonBool(json['success']) ?? false,
      data: parseData(json['data']),
      message: jsonString(json['message']) ?? '',
      meta: _readMeta(json['meta']),
    );
  }

  /// Parses a response body and hands back `data` untouched.
  ///
  /// Used by the delete-shaped endpoints, whose `data` is
  /// `{"deleted": true, "id": 7}` and is better read field by field than turned
  /// into a model class.
  static ApiEnvelope<Object?> raw(Object? body) {
    final Map<String, Object?> json = jsonMap(body);

    return ApiEnvelope<Object?>(
      success: jsonBool(json['success']) ?? false,
      data: json['data'],
      message: jsonString(json['message']) ?? '',
      meta: _readMeta(json['meta']),
    );
  }

  /// Whether the server reported success.
  ///
  /// Every 2xx in this API sets it, and the one documented exception is
  /// irrelevant here: `AuthController::devicesStore()` answers 201 for an insert
  /// and 200 for the upsert it collided with, and both set `success: true`.
  final bool success;

  /// The endpoint-specific `data` member, already parsed into [T].
  final T? data;

  /// The human-readable message. Indonesian, and not intended for display: the
  /// server documents it as a stable server-side string, and a client that wants
  /// user-facing copy should key off the status code and the `errors` map.
  final String message;

  /// The pagination block, or `null` when the response carried none.
  final Map<String, Object?>? meta;

  /// Whether this response carried a pagination block.
  bool get isPaginated => meta != null;

  /// The pagination block as a typed value, or `null` when there was none.
  ApiMeta? get pagination => meta == null ? null : ApiMeta.fromMap(meta!);

  /// [data], or a [StateError] when the response carried none.
  ///
  /// Named `requireData` rather than left as a nullable field so that a call
  /// site which forgets to check reads as a compile error at the call rather
  /// than as a null-dereference on a patient record.
  T get requireData {
    final T? value = data;

    if (value == null) {
      throw StateError(
        'Envelope reported success=$success but carried no "data" member.',
      );
    }

    return value;
  }

  /// The whole `data` member as a JSON object, for the endpoints whose `data` is
  /// a small fixed shape rather than a model.
  Map<String, Object?> get dataMap => jsonMap(data);

  @override
  String toString() {
    return 'ApiEnvelope<$T>(success: $success, message: $message, '
        'paginated: $isPaginated)';
  }

  /// Returns the `meta` object, or `null` when the key is absent or JSON `null`.
  ///
  /// A bare `null` for both cases is the whole point: see the class docblock on
  /// why absence must stay distinguishable from an empty block.
  static Map<String, Object?>? _readMeta(Object? value) {
    if (value == null) {
      return null;
    }

    if (value is Map<Object?, Object?>) {
      return jsonMap(value);
    }

    return null;
  }
}
