import 'dart:async';

import 'package:dio/dio.dart';

import 'api/auth_api.dart';
import 'api/dokter_api.dart';
import 'api/me_api.dart';
import 'api/pasien_api.dart';
import 'api/transport.dart';
import 'auth/auth_interceptor.dart';
import 'auth/refresh_coordinator.dart';
import 'config.dart';
import 'core/api_exception.dart';
import 'model/dto.dart';
import 'storage/key_value_backend.dart';
import 'storage/token_storage.dart';

/// The entry point the mobile app imports: one object, one construction, every
/// Module 1 endpoint reachable from a typed property.
///
/// ```dart
/// final client = SehatlyApiClient.secure(
///   SecureKeyValueBackend: myFlutterSecureStorageAdapter,
///   environment: SehatlyEnvironment.local,
/// );
///
/// final verified = await client.auth.verifyOtp(
///   kode: '014725',
///   tujuan: OtpTujuanDiterbitkan.verifikasiTelepon,
///   noTelepon: '08123456789',
/// );
/// await client.persistSession(verified.token);
/// final me = await client.me.show();
/// ```
///
/// ## What this object owns
///
/// | it owns | why it cannot be the caller's |
/// | --- | --- |
/// | the `Dio`, its timeouts and its `validateStatus` | three clients sharing one `Dio` share one connection pool and one interceptor chain, so one caller's `extra` can leak into another's request |
/// | the [AuthInterceptor] | it needs the same `Dio` it intercepts, so the replay re-enters the chain and picks up the new token |
/// | the [RefreshCoordinator] | the single-flight invariant is a property of *one* coordinator; two of them would be two single-flights, which is the bug |
/// | the [TokenStorage] | it is what "clear the session" clears |
///
/// ## Storage is chosen once, here, and it is never plain by accident
///
/// [SehatlyApiClient.secure] and [SehatlyApiClient.plain] are separate
/// constructors rather than one constructor with a flag, so the storage class is
/// visible in the call site and greppable. [SehatlyApiClient.plain] additionally
/// demands an acknowledgement. See [TokenStorage.plain].
class SehatlyApiClient {
  /// Builds a client over [storage], against [baseUrl].
  ///
  /// The low-level constructor, for a caller that has a `Dio` it needs to
  /// configure further (a proxy, a certificate, an extra logging interceptor).
  /// Prefer [secure] or [plain], which cannot be called with the wrong storage.
  ///
  /// [httpClientAdapter] replaces the transport outright, which is how a test
  /// counts the refresh calls that actually reached the network.
  SehatlyApiClient({
    required String baseUrl,
    required TokenStorage storage,
    Duration connectTimeout = const Duration(seconds: 10),
    Duration? receiveTimeout,
    Duration? sendTimeout,
    HttpClientAdapter? httpClientAdapter,
    List<Interceptor> extraInterceptors = const <Interceptor>[],
    void Function(ApiException error)? onSessionExpired,
  }) : storage = storage,
       baseUrl = baseUrl,
       onSessionExpired = onSessionExpired ?? _ignoreSessionExpiry {
    final Dio authenticated = Dio(
      _baseOptions(
        baseUrl: baseUrl,
        connectTimeout: connectTimeout,
        receiveTimeout: receiveTimeout ?? connectTimeout * 3,
        sendTimeout: sendTimeout ?? connectTimeout,
      ),
    );

    if (httpClientAdapter != null) {
      authenticated.httpClientAdapter = httpClientAdapter;
    }

    // The refresh call goes through a SECOND Dio with no interceptors at all.
    // That absence is the structural reason a refresh cannot recurse: there is
    // no `onError` to re-enter, and no stale `Authorization` to present.
    final Dio refresh = Dio(
      _baseOptions(
        baseUrl: baseUrl,
        connectTimeout: connectTimeout,
        receiveTimeout: receiveTimeout ?? connectTimeout * 3,
        sendTimeout: sendTimeout ?? connectTimeout,
      ),
    );

    if (httpClientAdapter != null) {
      refresh.httpClientAdapter = httpClientAdapter;
    }

    // A THIRD Dio, used only to replay a request after a refresh. It looks
    // redundant beside the two above and it is not.
    //
    // `AuthInterceptor` is a `QueuedInterceptor`, which runs one `onError` at a
    // time: while a task is active that instance's error queue is marked busy
    // and a newly arriving error is only appended, never started. Replaying
    // through `authenticated` therefore deadlocks the moment the replay itself
    // 401s -- its error queues behind the very task awaiting it, and the queue
    // only advances when that task's handler completes. Neither ever runs, and
    // the request hangs until the caller times out. A separate instance has its
    // own queue, so the replay's 401 is handled immediately, matches
    // [AuthInterceptor.retriedKey], and is passed straight through.
    //
    // It shares the [RefreshCoordinator] and the [storage] with the main
    // interceptor, so the single-flight invariant and the "read the rotated
    // token back out of the store" rule are untouched.
    final Dio replay = Dio(
      _baseOptions(
        baseUrl: baseUrl,
        connectTimeout: connectTimeout,
        receiveTimeout: receiveTimeout ?? connectTimeout * 3,
        sendTimeout: sendTimeout ?? connectTimeout,
      ),
    );

    if (httpClientAdapter != null) {
      replay.httpClientAdapter = httpClientAdapter;
    }

    refreshCoordinator = RefreshCoordinator(
      refreshDio: refresh,
      storage: storage,
      onSessionExpired: this.onSessionExpired,
    );

    // Only [AuthInterceptor] is registered here. A caller's own interceptor is
    // deliberately not carried onto the replay: the original request already
    // passed through it, and running it twice for one logical call is how a
    // retry counter or a request-id log ends up reporting phantom duplicates.
    replay.interceptors.add(
      AuthInterceptor(coordinator: refreshCoordinator, storage: storage),
    );

    _refreshDio = refresh;
    _replayDio = replay;

    authenticated.interceptors.add(
      AuthInterceptor(
        replayDio: replay,
        coordinator: refreshCoordinator,
        storage: storage,
      ),
    );

    for (final Interceptor interceptor in extraInterceptors) {
      authenticated.interceptors.add(interceptor);
    }

    dio = authenticated;
    transport = ApiTransport(authenticated);
    auth = AuthApi(transport);
    me = MeApi(transport);
    pasien = PasienApi(transport);
    dokter = DokterApi(transport);
  }

  /// Builds a client that keeps both tokens in a platform secret store.
  ///
  /// **This is the constructor a shipping mobile app uses.** [backend] must be a
  /// [SecureKeyValueBackend] -- the interface a `flutter_secure_storage` adapter
  /// implements -- so the secure store cannot be backed by a plain map without a
  /// compile error.
  factory SehatlyApiClient.secure({
    required SecureKeyValueBackend backend,
    SehatlyEnvironment environment = SehatlyEnvironment.local,
    void Function(ApiException error)? onSessionExpired,
    HttpClientAdapter? httpClientAdapter,
    List<Interceptor> extraInterceptors = const <Interceptor>[],
  }) {
    return SehatlyApiClient(
      baseUrl: environment.baseUrl,
      storage: TokenStorage.secure(backend),
      connectTimeout: environment.connectTimeout,
      receiveTimeout: environment.receiveTimeout,
      sendTimeout: environment.sendTimeout,
      onSessionExpired: onSessionExpired,
      httpClientAdapter: httpClientAdapter,
      extraInterceptors: extraInterceptors,
    );
  }

  /// Builds a client that keeps both tokens in non-secret storage.
  ///
  /// For desktop development, a CLI script, and unit tests. **Not** for anything
  /// that ships to a phone: see [TokenStorage.plain] for the OWASP MASVS-STORAGE-1
  /// reasoning, and note that [allowInsecureStorage] must be passed `true`
  /// explicitly for the call to be accepted.
  factory SehatlyApiClient.plain({
    required PlainKeyValueBackend backend,
    required bool allowInsecureStorage,
    SehatlyEnvironment environment = SehatlyEnvironment.local,
    void Function(ApiException error)? onSessionExpired,
    HttpClientAdapter? httpClientAdapter,
    List<Interceptor> extraInterceptors = const <Interceptor>[],
  }) {
    return SehatlyApiClient(
      baseUrl: environment.baseUrl,
      storage: TokenStorage.plain(
        backend,
        allowInsecureStorage: allowInsecureStorage,
      ),
      connectTimeout: environment.connectTimeout,
      receiveTimeout: environment.receiveTimeout,
      sendTimeout: environment.sendTimeout,
      onSessionExpired: onSessionExpired,
      httpClientAdapter: httpClientAdapter,
      extraInterceptors: extraInterceptors,
    );
  }

  /// Where the tokens live.
  final TokenStorage storage;

  /// The base URL every request is resolved against.
  final String baseUrl;

  /// Called once when the session becomes unrecoverable.
  ///
  /// Fires when a `POST /auth/refresh` fails -- which, under the server's
  /// rotation contract, means the token was already spent -- and when a 401
  /// arrives with no refresh token to rotate. It does **not** fire for a 401 that
  /// a refresh repaired, which is a normal event.
  ///
  /// The default is a no-op, so a caller that has not wired a navigator does not
  /// crash on a backgrounded app whose token expired.
  final void Function(ApiException error) onSessionExpired;

  /// The authenticated `Dio`.
  ///
  /// Exposed for a request this package does not model. Going through it still
  /// gets the bearer header, the single-flight refresh and the typed
  /// [ApiException] conversion, because the interceptor and the transport are on
  /// it.
  late final Dio dio;

  /// The envelope-unwrapping transport, for a raw call.
  late final ApiTransport transport;

  /// The single-flight token rotation.
  ///
  /// Exposed for a test that wants to assert the invariant directly, and for a
  /// proactive rotation. Read [RefreshCoordinator] before calling
  /// [RefreshCoordinator.refreshAfterUnauthorized] yourself: the coordinator's
  /// two guards only compose correctly when every 401 goes through the
  /// interceptor, which is the path that supplies the presented token.
  late final RefreshCoordinator refreshCoordinator;

  /// `POST|GET|DELETE` under `/auth`, plus OTP verification and token rotation.
  late final AuthApi auth;

  /// `GET /me`.
  late final MeApi me;

  /// The caller's own patient records: profile, family members, allergies.
  late final PasienApi pasien;

  /// The public doctor directory and the specialisation reference list.
  ///
  /// Unauthenticated, so it works with no session at all.
  late final DokterApi dokter;

  /// The interceptor-free client the rotation is sent through.
  late final Dio _refreshDio;

  /// The second interceptor client a post-refresh replay is dispatched through.
  ///
  /// Separate from [dio] for a structural reason, not a stylistic one: see the
  /// constructor's third `Dio` for why a replay dispatched on [dio] deadlocks
  /// when the replay itself 401s.
  late final Dio _replayDio;

  /// The one [BaseOptions] every `Dio` in this client is built from.
  ///
  /// Factored out because there are three of them and they must not drift. A
  /// replay that quietly used different timeouts than the original request would
  /// make a 401's recovery slower or faster than the failure it is repairing,
  /// which is exactly the kind of difference nobody would find by reading.
  static BaseOptions _baseOptions({
    required String baseUrl,
    required Duration connectTimeout,
    required Duration receiveTimeout,
    required Duration sendTimeout,
  }) {
    return BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: connectTimeout,
      receiveTimeout: receiveTimeout,
      sendTimeout: sendTimeout,
      // A non-2xx must throw so the transport can convert it. dio's default
      // already does, but stating it means changing it later is a visible edit
      // rather than a silent behaviour change.
      validateStatus: (int? status) =>
          status != null && status >= 200 && status < 300,
      // Laravel's error bodies are JSON and must survive a 4xx/5xx, otherwise
      // `ApiException.fromDio` sees a string and reports "Resource not found."
      // for every validation failure.
      receiveDataWhenStatusError: true,
      headers: <String, dynamic>{'Accept': 'application/json'},
    );
  }

  /// Whether both tokens are held in a platform secret store.
  bool get isSecureStorage => storage.isSecure;

  /// A one-line description of where the tokens live.
  String get storageDescription => storage.description;

  /// Writes a freshly issued pair into storage.
  ///
  /// Call this after [AuthApi.verifyOtp] and after [AuthApi.refresh]. It is not
  /// done inside those methods on purpose: a caller that wants to inspect a pair,
  /// or to store it somewhere else first, should be able to, and a method that
  /// silently persisted the secret would have no way to opt out.
  ///
  /// Also resets the coordinator's rotation memo, so a subsequent 401 cannot be
  /// answered from the memo of a previous session.
  Future<void> persistSession(TokenPair pair) async {
    await storage.accessTokens.writeAccessToken(pair.accessToken);
    await storage.refreshTokens.writeRefreshToken(pair.refreshToken);
    refreshCoordinator.resetRotationMemo();
  }

  /// The access token currently stored, or `null`.
  Future<String?> currentAccessToken() =>
      storage.accessTokens.readAccessToken();

  /// The refresh token currently stored, or `null`.
  Future<String?> currentRefreshToken() =>
      storage.refreshTokens.readRefreshToken();

  /// Signs out: calls the server if a refresh token is stored, then clears both
  /// stores **either way**.
  ///
  /// The local clear is not conditional on the network call succeeding. A client
  /// that left a token in storage because the server was unreachable would keep
  /// a live credential on a device the user believes they have signed out of,
  /// which is the one outcome worse than an orphaned session.
  ///
  /// The server call needs the refresh token, which is therefore read **before**
  /// the clear. It is broad by necessity -- `POST /auth/logout` deactivates every
  /// device for the account -- and `POST /auth/devices` re-activates one.
  Future<void> signOut() async {
    final String? refreshToken = await storage.refreshTokens.readRefreshToken();

    try {
      if (refreshToken != null && refreshToken.isNotEmpty) {
        await auth.logout(refreshToken);
      }
    } finally {
      await storage.clearAll();
      refreshCoordinator.resetRotationMemo();
    }
  }

  /// Rotates proactively, if the stored access token is already expired.
  ///
  /// The guard is why this is safe to call from a screen's `initState` without
  /// risking the server's reuse detection: if the access token has not expired,
  /// nothing is sent. A client that called `AuthApi.refresh` unconditionally here
  /// would spend a refresh token on every screen entry, and the second such call
  /// would look like a replay to the server and revoke the whole chain.
  ///
  /// Returns the pair it obtained, or `null` when no rotation was needed or none
  /// was possible.
  Future<TokenPair?> rotateIfExpired({DateTime? now}) async {
    final String? refreshToken = await storage.refreshTokens.readRefreshToken();

    if (refreshToken == null || refreshToken.isEmpty) {
      return null;
    }

    final String? accessToken = await storage.accessTokens.readAccessToken();

    if (accessToken != null && accessToken.isNotEmpty) {
      final TokenPair current = TokenPair(
        tokenType: 'Bearer',
        accessToken: accessToken,
        expiresIn: 0,
        refreshToken: refreshToken,
      );

      if (!current.isAccessTokenExpired(now: now)) {
        return null;
      }
    }

    try {
      final TokenPair pair = await auth.refresh(refreshToken);
      await persistSession(pair);

      return pair;
    } on ApiException {
      return null;
    }
  }

  /// Releases the `Dio` instances.
  ///
  /// Closes the connection pool. A long-lived app never needs this; a test or a
  /// short-lived CLI does, and without it the VM keeps a socket alive past the
  /// end of the test.
  ///
  /// All three are closed. Leaving the rotation or the replay client open would
  /// hold a socket that nothing on the instance can ever reach again, so the
  /// leak would be invisible in a short test and permanent in a long one.
  void close({bool force = false}) {
    dio.close(force: force);
    _refreshDio.close(force: force);
    _replayDio.close(force: force);
  }

  static void _ignoreSessionExpiry(ApiException _) {
    // The default. See [onSessionExpired] for why silence is the right default
    // rather than a throw: this fires from inside a `catch` in an interceptor
    // on whatever request happened to be in flight, and there is nobody there to
    // receive an exception.
  }

  @override
  String toString() =>
      'SehatlyApiClient(baseUrl: $baseUrl, storage: '
      '$storageDescription, secure: $isSecureStorage)';
}
