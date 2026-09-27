import 'package:dio/dio.dart';

import '../auth/auth_interceptor.dart';
import '../core/api_envelope.dart';
import '../core/api_exception.dart';
import '../core/json.dart';

/// Sends requests and unwraps the envelope, converting every failure into an
/// [ApiException].
///
/// ## Why the envelope is unwrapped here and not by the caller
///
/// Every endpoint in this API answers `{success, data, message}` or
/// `{success, data, message, meta}`, and every failure answers
/// `{success, message, errors}`. A call site that wanted `PasienProfile` should
/// not have to know that it arrived as `data.profile`, and should not have to
/// re-implement the "throw on 4xx" rule sixteen times. So each endpoint class
/// hands this a `parseData` callback and gets a typed envelope back, and the
/// only exception type that escapes is [ApiException].
///
/// ## Why a 404 is not retried and not swallowed
///
/// `DELETE /pasien/alergi/{id}` for somebody else's row is a 404, by design: a
/// 403 would confirm the row exists. So [isNotFound] here means "not yours, or
/// gone", and a client that retries it is leaking the existence of another
/// patient's row to itself. The transport does not retry anything, and
/// [ApiException] makes the distinction visible to the caller.
class ApiTransport {
  /// Wraps [dio].
  ///
  /// [dio] is expected to carry an [AuthInterceptor] for the authenticated
  /// endpoints; the two anonymous ones pass [AuthInterceptor.noAuthKey] so the
  /// header is not attached even when a token happens to be stored.
  const ApiTransport(this._dio);

  final Dio _dio;

  /// The underlying client, for a caller that needs a request this class does
  /// not model.
  Dio get dio => _dio;

  /// `GET path`, returning the unwrapped envelope.
  Future<ApiEnvelope<T>> get<T>(
    String path, {
    required T Function(Object? data) parseData,
    Map<String, Object?>? queryParameters,
    bool anonymous = false,
  }) {
    return _send<T>(
      () => _dio.get<Object?>(
        path,
        queryParameters: _clean(queryParameters),
        options: _options(anonymous: anonymous),
      ),
      parseData,
    );
  }

  /// `POST path`, returning the unwrapped envelope.
  Future<ApiEnvelope<T>> post<T>(
    String path, {
    required T Function(Object? data) parseData,
    Object? body,
    Map<String, Object?>? queryParameters,
    bool anonymous = false,
    bool allowUnsafeRetry = false,
  }) {
    return _send<T>(
      () => _dio.post<Object?>(
        path,
        data: body,
        queryParameters: _clean(queryParameters),
        options: _options(
          anonymous: anonymous,
          allowUnsafeRetry: allowUnsafeRetry,
        ),
      ),
      parseData,
    );
  }

  /// `PUT path`, returning the unwrapped envelope.
  Future<ApiEnvelope<T>> put<T>(
    String path, {
    required T Function(Object? data) parseData,
    Object? body,
    Map<String, Object?>? queryParameters,
    bool anonymous = false,
    bool allowUnsafeRetry = false,
  }) {
    return _send<T>(
      () => _dio.put<Object?>(
        path,
        data: body,
        queryParameters: _clean(queryParameters),
        options: _options(
          anonymous: anonymous,
          allowUnsafeRetry: allowUnsafeRetry,
        ),
      ),
      parseData,
    );
  }

  /// `DELETE path`, returning the unwrapped envelope.
  Future<ApiEnvelope<T>> delete<T>(
    String path, {
    required T Function(Object? data) parseData,
    Map<String, Object?>? queryParameters,
    bool anonymous = false,
    bool allowUnsafeRetry = false,
  }) {
    return _send<T>(
      () => _dio.delete<Object?>(
        path,
        queryParameters: _clean(queryParameters),
        options: _options(
          anonymous: anonymous,
          allowUnsafeRetry: allowUnsafeRetry,
        ),
      ),
      parseData,
    );
  }

  Future<ApiEnvelope<T>> _send<T>(
    Future<Response<Object?>> Function() run,
    T Function(Object? data) parseData,
  ) async {
    try {
      final Response<Object?> response = await run();

      return ApiEnvelope<T>.fromJson(response.data, parseData: parseData);
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }

  /// Builds the per-request [Options].
  ///
  /// [allowUnsafeRetry] is forwarded to the interceptor's
  /// [AuthInterceptor.allowUnsafeRetryKey], which is what lets a `POST` be
  /// replayed once after a refresh. Off by default; see
  /// [AuthInterceptor]'s class docblock for why.
  static Options _options({
    required bool anonymous,
    bool allowUnsafeRetry = false,
  }) {
    return Options(
      extra: <String, Object?>{
        if (anonymous) AuthInterceptor.noAuthKey: true,
        if (allowUnsafeRetry) AuthInterceptor.allowUnsafeRetryKey: true,
      },
    );
  }

  /// Drops `null` entries and renders booleans as `1`/`0`.
  ///
  /// dio serialises a Dart `bool` query value as `true`/`false`, and Laravel's
  /// `boolean` validation rule accepts both, so no conversion is strictly needed
  /// -- but a `null` filter left in the query string is how
  /// `?spesialisasi=` ends up answering "the value is empty" instead of "no
  /// filter", which is a 422 rather than the unfiltered list a caller meant.
  static Map<String, Object?> _clean(Map<String, Object?>? queryParameters) {
    if (queryParameters == null) {
      return const <String, Object?>{};
    }

    final Map<String, Object?> cleaned = <String, Object?>{};

    for (final MapEntry<String, Object?> entry in queryParameters.entries) {
      final Object? value = entry.value;

      if (value == null) {
        continue;
      }

      cleaned[entry.key] = value is bool ? (value ? 1 : 0) : value;
    }

    return cleaned;
  }
}

/// The `?page=` and `?per_page=` pair every Module 1 list endpoint accepts.
///
/// ## `perPage` is capped at 100 on the server, and this does not pretend
/// otherwise
///
/// The cap is `PasienRecordAccess::PER_PAGE_MAX` and
/// `DokterDirectoryService::PER_PAGE_MAX`, both 100. A request for more is
/// **clamped, not rejected**: the response's `meta.per_page` reports what was
/// applied. So [perPage] is passed through as given and the truth is read back
/// from the envelope -- see [PageQuery.maxPerPage] for the constant, which exists
/// so a picker offers a range the server will actually honour.
class PageQuery {
  /// Creates a page request, or a bare `const PageQuery()` for "no paging".
  const PageQuery({this.page, this.perPage});

  /// The 1-based page. `null` omits the parameter entirely.
  final int? page;

  /// The requested page size. `null` omits the parameter, and the server applies
  /// its own default.
  final int? perPage;

  /// The server's per-page cap, from `PasienRecordAccess::PER_PAGE_MAX` and
  /// `DokterDirectoryService::PER_PAGE_MAX`.
  static const int maxPerPage = 100;

  /// The parameters to put in a query string.
  Map<String, Object?> toQueryParameters() {
    return <String, Object?>{
      if (page != null) 'page': page,
      if (perPage != null) 'per_page': perPage,
    };
  }
}

/// Reads the `data` member as a JSON object, or returns an empty map.
///
/// The shared `parseData` for the endpoints whose `data` is a small fixed shape
/// -- a delete acknowledgement, a nested single object -- rather than a model.
/// A missing or wrongly-typed `data` yields an empty map, and the model built
/// from it reports zero-valued fields, which is a visible wrong answer on screen
/// rather than an unhandled cast in a transport.
Map<String, Object?> parseDataObject(Object? data) => jsonMap(data);
