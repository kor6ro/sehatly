import 'dart:async';

import 'package:dio/dio.dart';

import '../core/api_exception.dart';
import '../core/json.dart';
import '../model/dto.dart';
import '../storage/token_storage.dart';

/// Called when the session is unrecoverably over.
///
/// Invoked at most once per [RefreshCoordinator] failure, and only when a refresh
/// actually failed -- not on every 401. A 401 that a refresh then repairs is a
/// normal event; a refresh that fails is the end of the session.
typedef SessionExpiredCallback = void Function(ApiException error);

/// Performs at most one token rotation for any number of simultaneous 401s.
///
/// ## The invariant
///
/// When N in-flight requests each receive a 401, **exactly one**
/// `POST /auth/refresh` reaches the network and all N await that one call.
/// Without this, a screen that fires six parallel reads on resume would send six
/// refreshes -- and because the server's contract is that a refresh token is
/// **revoked on every use** and a *replayed* one revokes every live refresh token
/// for the account, the second through sixth would each look like a theft and
/// collectively sign the user out of every device. The concurrency is not a
/// performance problem here; it is a correctness one.
///
/// ## How the invariant is held: two guards, and both are load-bearing
///
/// **Guard 1, the in-flight future.** The first caller installs a
/// [Completer]'s future in [refreshInFlight] *synchronously*, before its first
/// `await`. Every later caller in the same event-loop turn sees a non-null value
/// and awaits the same future. This is what makes the invariant hold when N 401s
/// are genuinely concurrent, i.e. under a plain `Interceptor` where every
/// `onError` runs at once.
///
/// **Guard 2, the rotated-away token.** [AuthInterceptor] is a
/// `QueuedInterceptor`, so its `onError` callbacks run **one at a time**. By the
/// time the second 401 is handled, guard 1's refresh has already completed and
/// cleared [refreshInFlight], so guard 1 alone would issue a *second* refresh.
/// Guard 2 remembers which access token was current immediately before the last
/// rotation, so a 401 that presents that exact token is answered from the
/// rotation that already happened -- with no network call at all.
///
/// Neither guard subsumes the other. Guard 1 covers simultaneity; guard 2 covers
/// serialisation. Removing guard 2 is safe under a plain `Interceptor` and fatal
/// under a `QueuedInterceptor`, which is why both are here.
///
/// ## A refresh failure is terminal, and that is the server's rule
///
/// `TokenService::rotate()` revokes the presented token on every use. So a 401
/// from `POST /auth/refresh` means the token was already spent -- either a client
/// bug or a stolen token -- and in the second case the server has revoked every
/// live refresh token for the account as well. There is no retry that can
/// succeed, so a failure here clears both stores, fires [onSessionExpired] once,
/// and propagates. Retrying forever against a rotation endpoint is the one thing
/// that would turn a recoverable situation into a permanent one.
///
/// ## Why the refresh call bypasses the authenticated client
///
/// [refreshDio] is a **separate, interceptor-free** `Dio`. If the refresh went
/// through the client's own `Dio`, the interceptor would attach the stale
/// `Authorization` header, and -- worse -- a 401 from the refresh endpoint would
/// re-enter this class and recurse. The absence of interceptors is the structural
/// reason the loop cannot be built.
class RefreshCoordinator {
  /// Creates a coordinator.
  ///
  /// [refreshDio] must be a `Dio` with **no** interceptors attached.
  /// [refreshPath] is the endpoint path, `/auth/refresh`, and is resolved against
  /// [refreshDio]'s own `baseUrl`.
  RefreshCoordinator({
    required Dio refreshDio,
    required TokenStorage storage,
    required this.onSessionExpired,
    String refreshPath = defaultRefreshPath,
  }) : _refreshDio = refreshDio,
       _storage = storage,
       _refreshPath = refreshPath;

  /// `POST /api/v1/auth/refresh`, relative to the versioned base URL.
  static const String defaultRefreshPath = '/auth/refresh';

  /// The `refresh_token` request field. `LogoutRequest` and
  /// `RefreshTokenRequest` both require it, and the rule is `size:`-exact
  /// against the generated secret, so a client must send back verbatim what it
  /// was issued and must never trim or re-encode it.
  static const String refreshTokenField = 'refresh_token';

  final Dio _refreshDio;
  final TokenStorage _storage;
  final SessionExpiredCallback onSessionExpired;

  /// The path the refresh is posted to, without the `/api/v1` prefix.
  final String _refreshPath;

  Future<TokenPair>? _refreshInFlight;

  /// The access token that was current immediately before the last rotation.
  String? _rotatedAwayAccessToken;

  /// The pair produced by the last rotation, kept so a late 401 can be answered
  /// from it without a second network call.
  TokenPair? _lastRotation;

  /// How many refreshes this coordinator has actually attempted.
  ///
  /// Exposed for a diagnostic and for the single-flight test. It is incremented
  /// once per *started* refresh, which is the number the invariant is about; a
  /// caller that wants an independent count should instrument the transport.
  int get refreshAttemptCount => _refreshAttemptCount;
  int _refreshAttemptCount = 0;

  /// Whether a refresh is running right now.
  bool get isRefreshing => _refreshInFlight != null;

  /// Whether a session-ended callback has already fired.
  ///
  /// Latches at the first one so a second failure cannot re-navigate the user to
  /// a login screen they have already left.
  bool get didExpireSession => _didExpireSession;
  bool _didExpireSession = false;

  /// Obtains a fresh pair, or joins the one already being obtained.
  ///
  /// [presentedAccessToken] is the bearer token the caller's 401 arrived with,
  /// read out of the `Authorization` header. It is what lets guard 2 recognise a
  /// 401 that a previous rotation has already answered; passing `null` disables
  /// guard 2 for that call and forces a real refresh.
  Future<TokenPair> refreshAfterUnauthorized({String? presentedAccessToken}) {
    final Future<TokenPair>? inFlight = _refreshInFlight;

    if (inFlight != null) {
      return inFlight;
    }

    final TokenPair? rotation = _lastRotation;

    if (rotation != null &&
        presentedAccessToken != null &&
        presentedAccessToken == _rotatedAwayAccessToken) {
      return Future<TokenPair>.value(rotation);
    }

    return _startRefresh();
  }

  /// The refresh token currently stored, or `null`.
  ///
  /// Exposed so a client can decide whether a 401 is worth a refresh at all: with
  /// no refresh token there is nothing to rotate and the caller should go
  /// straight to [expireSession].
  Future<String?> currentRefreshToken() =>
      _storage.refreshTokens.readRefreshToken();

  /// Clears both stores and fires [onSessionExpired] exactly once.
  ///
  /// The explicit "give up" path, for a 401 on a request when no refresh token is
  /// stored. Idempotent: the second call is a no-op apart from clearing, which
  /// matters because two parallel 401s can both reach it.
  Future<void> expireSession({ApiException? reason}) async {
    await _storage.clearAll();

    if (_didExpireSession) {
      return;
    }

    _didExpireSession = true;
    onSessionExpired(
      reason ??
          const ApiException(
            statusCode: 401,
            message: 'Session expired and there is no refresh token to rotate.',
          ),
    );
  }

  /// Forgets the rotation memo.
  ///
  /// Call this on an explicit sign-in. Without it, a token that happened to equal
  /// the last rotated-away one -- which is possible for a freshly issued pair, if
  /// the server is ever configured to reuse an identifier -- would be answered
  /// from a stale rotation instead of triggering a real one.
  void resetRotationMemo() {
    _rotatedAwayAccessToken = null;
    _lastRotation = null;
  }

  Future<TokenPair> _startRefresh() {
    final Completer<TokenPair> completer = Completer<TokenPair>();
    final Future<TokenPair> future = completer.future;

    _refreshInFlight = future;
    _refreshAttemptCount += 1;

    // Mark the error as handled for this internal branch. A caller that attaches
    // its own listener a microtask later would otherwise get an unhandled-error
    // report from the zone before it ever sees the failure, and the report names
    // this class rather than the call site that cared. A second listener on the
    // same future still receives the error normally.
    unawaited(
      future.then<void>((TokenPair _) {}, onError: (Object _, StackTrace _) {}),
    );

    unawaited(_performRefresh(completer, future));

    return future;
  }

  Future<void> _performRefresh(
    Completer<TokenPair> completer,
    Future<TokenPair> future,
  ) async {
    try {
      final String? stored = await _storage.refreshTokens.readRefreshToken();

      if (stored == null || stored.isEmpty) {
        throw const ApiException(
          statusCode: 401,
          message: 'No refresh token is stored, so there is nothing to rotate.',
        );
      }

      final Response<Object?> response = await _refreshDio.post<Object?>(
        _refreshPath,
        data: <String, Object?>{refreshTokenField: stored},
      );

      final Object? body = response.data;
      final Map<String, Object?> data = jsonMap(
        body is Map<Object?, Object?> ? body['data'] : null,
      );

      final TokenPair pair = TokenPair.fromJson(jsonMap(data['token']));

      // Read the outgoing access token BEFORE overwriting it, so guard 2 records
      // the token these 401s presented rather than the new one.
      final String? outgoing = await _storage.accessTokens.readAccessToken();

      await _storage.accessTokens.writeAccessToken(pair.accessToken);
      await _storage.refreshTokens.writeRefreshToken(pair.refreshToken);

      _rotatedAwayAccessToken = outgoing;
      _lastRotation = pair;

      completer.complete(pair);
    } catch (error, stackTrace) {
      final ApiException failure = _asApiException(error);

      // Terminal. See the class docblock: the rotation contract makes a 401 here
      // unrecoverable, and the server has already revoked the whole chain.
      _rotatedAwayAccessToken = null;
      _lastRotation = null;

      await _storage.clearAll();

      if (!_didExpireSession) {
        _didExpireSession = true;
        onSessionExpired(failure);
      }

      completer.completeError(failure, stackTrace);
    } finally {
      if (identical(_refreshInFlight, future)) {
        _refreshInFlight = null;
      }
    }
  }

  /// Normalises [error] to an [ApiException] without discarding its cause.
  ///
  /// The three cases are kept distinct on purpose. Re-wrapping a [DioException]
  /// inside a fresh one -- which is the tempting one-liner -- throws away
  /// `DioException.response`, so a 401 from the refresh endpoint would surface as
  /// `statusCode == 0` and `isNetworkError`, and a client's `sessionExpired`
  /// handler would be told the device was offline at the moment the server
  /// deliberately ended the session. Passing the original through keeps the
  /// status, the parsed `errors` map and the method and path.
  ///
  /// The third case is reached only for something that was neither -- a storage
  /// backend that threw, or a [FormatException] from a `token` object the server
  /// did not send. Wrapping it keeps the error type uniform out of a method
  /// documented to throw [ApiException] rather than leaking a third shape.
  static ApiException _asApiException(Object error) {
    return switch (error) {
      final ApiException already => already,
      final DioException transport => ApiException.fromDio(transport),
      _ => ApiException.fromDio(
        DioException(
          requestOptions: RequestOptions(path: defaultRefreshPath),
          error: error,
          type: DioExceptionType.unknown,
        ),
      ),
    };
  }
}
