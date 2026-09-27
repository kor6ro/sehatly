import 'package:dio/dio.dart';

import 'json.dart';

/// The typed representation of a failed API call.
///
/// ## The two envelopes this parses
///
/// `App\Support\ApiResponse` produces exactly two failure bodies, and every
/// failure under `/api/*` is rendered by the single `render()` callback in
/// `bootstrap/app.php` rather than by a controller, so there is no third shape
/// to handle:
///
/// ```
/// {"success": false, "message": <string>, "errors": {<field>: [<string>, ...]}}
/// ```
///
/// `message` is a **stable, translatable, field-independent** string. That is
/// deliberate on the server: Laravel's default validation message is built by
/// `ValidationException::summarize()`, which promotes the first field error and
/// appends "(and N more errors)", so forwarding it would make `message`
/// data-dependent and duplicate detail that `errors` already carries. Field
/// detail belongs in [errors]; key UI off [message] and render [errors].
///
/// [errors] is empty for every 401, 403, 404 and sanitized 500, because
/// `ApiResponse::error()` casts it to a JSON object specifically so an empty map
/// encodes as `{}` and not `[]`. An empty [errors] is therefore a normal,
/// expected state and never an indication of a parse failure.
///
/// ## Network failures get status 0, and that is the only sentinel
///
/// A connection refusal, a DNS failure or a timeout has no HTTP status, so this
/// class gives it `statusCode == 0` and [isNetworkError] true. One sentinel is
/// enough and it is checked explicitly, which is why there is no separate
/// exception subclass for "the device is offline": a `catch (e)` that only
/// inspects [message] would silently swallow a timeout, and one that only
/// inspects [statusCode] would treat an offline device as a 500 from the
/// server.
class ApiException implements Exception {
  /// Creates a failure with an already-known status, message and error map.
  const ApiException({
    required this.statusCode,
    required this.message,
    this.errors = const <String, List<String>>{},
    this.method,
    this.path,
    this.cause,
  });

  /// The HTTP status code, or `0` when the request never reached the server.
  final int statusCode;

  /// The envelope's `message`, or a transport-level description for a network
  /// failure.
  final String message;

  /// The envelope's per-field `errors` map, empty for a non-validation failure.
  final Map<String, List<String>> errors;

  /// The HTTP method of the failed request, when it is known.
  final String? method;

  /// The request path of the failed request, when it is known.
  final String? path;

  /// The underlying [DioException], when this wraps one.
  ///
  /// Kept so a caller can inspect `DioExceptionType` (timeout vs cancelled vs
  /// bad certificate) without this package having to re-classify every case.
  final DioException? cause;

  /// The `errors` key that marks a booking slot as already taken.
  ///
  /// The booking surface is the plan's todo 27, which has not been built: a
  /// repository-wide search for the literal `'slot'` in `app/` returns nothing,
  /// so **no endpoint implemented today can produce this failure**. The helper
  /// exists now so a mobile call site written against todo 27 compiles and
  /// behaves correctly the day that endpoint lands, rather than string-matching
  /// a status code when it does.
  static const String slotErrorKey = 'slot';

  /// The `errors` keys that a PDP-consent refusal is expected to publish.
  ///
  /// Same caveat as [slotErrorKey]: the consent gate is the plan's todo 47 and
  /// does not exist yet (`persetujuan_pdp` has a model and a migration but no
  /// controller, no route and no middleware). These are the key names the
  /// client will look for, declared in one place so that pinning the real
  /// contract is a one-line change here rather than a search across the
  /// codebase.
  static const List<String> consentErrorKeys = <String>[
    'consent',
    'persetujuan_pdp',
    'setuju',
  ];

  /// Case-insensitive substrings that mark a 403 as a consent refusal.
  ///
  /// The Indonesian vocabulary the server uses is
  /// `persetujuan`/`setuju` (permission/agree), which is why `setuju` is
  /// listed separately: it is the substring of both `persetujuan` and any
  /// future `setuju_...` key. Treat this list as provisional until todo 47
  /// exists to be measured against.
  static const List<String> consentMessageMarkers = <String>[
    'consent',
    'persetujuan',
    'setuju',
    'pdp',
  ];

  /// Builds an [ApiException] from a [DioException], parsing the failure
  /// envelope when the server answered and synthesising a message when it did
  /// not.
  ///
  /// A non-JSON body (an HTML error page from a proxy, a truncated response)
  /// is not a parse failure: [message] falls back to the status code's
  /// standard reason phrase, and [rawBody] keeps whatever arrived so the cause
  /// is diagnosable.
  factory ApiException.fromDio(DioException error) {
    final Response<dynamic>? response = error.response;
    final RequestOptions request = error.requestOptions;

    if (response == null) {
      return ApiException(
        statusCode: 0,
        message: _transportMessage(error),
        method: request.method,
        path: request.path,
        cause: error,
      );
    }

    final Object? body = response.data;
    final Map<String, Object?> json = jsonMap(body);

    return ApiException(
      statusCode: response.statusCode ?? 0,
      message:
          jsonString(json['message']) ??
          _reasonPhrase(response.statusCode ?? 0),
      errors: jsonErrorMap(json['errors']),
      method: request.method,
      path: request.path,
      cause: error,
    );
  }

  /// Whether the call failed validation.
  ///
  /// 422 is the only status `bootstrap/app.php` maps
  /// `ValidationException` to, and the only one that carries field detail in
  /// [errors]. `AuthController` also emits 422 for a rejected OTP code with the
  /// reason under `errors.kode`, so [errors] is meaningful on a 422 even when
  /// the failure was not a rule violation.
  bool get isValidationError => statusCode == 422;

  /// Whether the call was refused for want of a valid access token.
  ///
  /// 401 also covers `POST /auth/refresh` presenting an already-spent refresh
  /// token, and the server's answer to that is to revoke **every** live refresh
  /// token for the account. That is why a 401 on the refresh path is terminal
  /// rather than retryable; see `RefreshCoordinator`.
  bool get isUnauthorized => statusCode == 401;

  /// Whether the authenticated caller lacked a grant, or a row was not theirs.
  bool get isForbidden => statusCode == 403;

  /// Whether the addressed row does not exist *for this caller*.
  ///
  /// A row owned by another account is a 404 rather than a 403 on the Module 1
  /// patient surface, so a 404 is not evidence of a server fault and must not
  /// be retried.
  bool get isNotFound => statusCode == 404;

  /// Whether the call was throttled.
  ///
  /// Module 1 applies `throttle:auth-otp-send` (10/min), `throttle:auth-login`
  /// (5/min) and `throttle:auth-verify` (5/min), so this is reachable from a
  /// client that retries a login too eagerly.
  bool get isTooManyRequests => statusCode == 429;

  /// Whether the server faulted.
  ///
  /// The 500 body is always the fixed string `Internal server error.`; the
  /// server never publishes a stack trace, so there is nothing more specific
  /// to read out of it.
  bool get isServerError => statusCode >= 500 && statusCode <= 599;

  /// Whether the request never reached the server.
  bool get isNetworkError => statusCode == 0;

  /// Whether this is a 422 reporting that the requested booking `slot` is gone.
  ///
  /// Matches on the [slotErrorKey] entry in [errors] rather than on the status
  /// code, because a slot conflict is a validation failure like any other and
  /// nothing in the status distinguishes it. See [slotErrorKey] for why no
  /// implemented endpoint produces this yet.
  bool get isSlotTaken => isValidationError && errors.containsKey(slotErrorKey);

  /// Whether this is a 403 refusing the call for want of a PDP consent.
  ///
  /// Matched on [isForbidden] plus either a consent key in [errors] or one of
  /// [consentMessageMarkers] in [message], because a consent gate is a
  /// permission failure and would otherwise be indistinguishable from a missing
  /// RBAC grant. See [consentErrorKeys] for why the marker list is provisional.
  bool get isConsentRequired {
    if (!isForbidden) {
      return false;
    }

    for (final String key in consentErrorKeys) {
      if (errors.containsKey(key)) {
        return true;
      }
    }

    final String haystack = message.toLowerCase();

    for (final String marker in consentMessageMarkers) {
      if (haystack.contains(marker)) {
        return true;
      }
    }

    return false;
  }

  /// Every field name the server complained about, in no particular order.
  Iterable<String> get errorFields => errors.keys;

  /// The first message for [field], or `null` when the server did not name it.
  ///
  /// Laravel attaches one message per rule violation, so a field with three
  /// rules failed has three entries; this returns the first, which is the one
  /// a single-line form hint wants.
  String? firstErrorFor(String field) {
    final List<String>? messages = errors[field];

    if (messages == null || messages.isEmpty) {
      return null;
    }

    return messages.first;
  }

  @override
  String toString() {
    final StringBuffer buffer = StringBuffer('ApiException($statusCode');

    if (method != null && path != null) {
      buffer.write(', $method $path');
    }

    buffer.write('): $message');

    if (errors.isNotEmpty) {
      buffer.write(' errors=');
      buffer.write(errors.keys.join(','));
    }

    return buffer.toString();
  }

  static String _transportMessage(DioException error) {
    switch (error.type) {
      case DioExceptionType.connectionTimeout:
        return 'Connection timed out before the server responded.';
      case DioExceptionType.sendTimeout:
        return 'Timed out while sending the request.';
      case DioExceptionType.receiveTimeout:
        return 'Timed out while waiting for the response.';
      case DioExceptionType.badCertificate:
        return 'The server certificate could not be verified.';
      case DioExceptionType.cancel:
        return 'The request was cancelled.';
      case DioExceptionType.connectionError:
        return 'Could not reach the server.';
      case DioExceptionType.transformTimeout:
        return 'Timed out while decoding the response body.';
      case DioExceptionType.badResponse:
      case DioExceptionType.unknown:
        return 'The request failed before a response was received.';
    }
  }

  /// The reason phrase for a status the body did not name.
  ///
  /// The server sends an English `message` for every failure it renders itself,
  /// so this is only reached for a response whose body was not the failure
  /// envelope -- an HTML error page from a proxy in front of the app, a
  /// truncated body, a 502 from the web server rather than from Laravel. Matching
  /// what the server *would* have said is a better answer than a generic
  /// "request failed", and it is why the list below is the same set the
  /// `bootstrap/app.php` handler maps.
  static String _reasonPhrase(int status) {
    return switch (status) {
      400 => 'The request was malformed.',
      401 => 'Unauthenticated.',
      403 => 'This action is unauthorized.',
      404 => 'Resource not found.',
      405 => 'Method not allowed for this endpoint.',
      419 => 'Request rejected.',
      422 => 'The given data was invalid.',
      429 => 'Too many requests.',
      _ when status >= 500 && status <= 599 => 'Internal server error.',
      _ => 'Request rejected.',
    };
  }
}

/// The name this package's failure type is known by in handoff documents.
///
/// The plan's todo 24 specifies `ApiException`; the mobile team's brief names
/// the same type `ApiError`. Both names refer to this one class, so a call site
/// written against either spelling compiles unchanged.
typedef ApiError = ApiException;
