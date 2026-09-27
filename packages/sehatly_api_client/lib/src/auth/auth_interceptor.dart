import 'dart:async';

import 'package:dio/dio.dart';

import '../core/api_exception.dart';
import '../storage/token_storage.dart';
import 'refresh_coordinator.dart';

/// Attaches the bearer token, and repairs a 401 with a single-flight refresh.
///
/// ## Why `QueuedInterceptor` and not `Interceptor`
///
/// The base class is load-bearing for the **refresh itself**. A `QueuedInterceptor`
/// runs `onError` one callback at a time per queue, so a burst of N 401s is
/// handled in sequence rather than N-at-once. That bounds the damage a
/// thundering herd can do twice over: only one error handler is ever inside the
/// refresh at a time, and the replays go out in order instead of stampeding.
///
/// It is **not** sufficient on its own, and this class does not pretend it is.
/// The exactly-one-refresh invariant is held by
/// [RefreshCoordinator]'s two guards, and the second of them exists *because* of
/// this class: serialisation means a late 401 arrives after the in-flight future
/// has already been cleared, so the in-flight guard alone would fire a second
/// rotation. See that class's docblock.
///
/// ## Why the replay is dispatched on a *different* `Dio`
///
/// The obvious implementation -- hold a reference to the `Dio` this interceptor
/// is registered on, and replay through it -- deadlocks, and the queue that
/// causes it is the reason the class extends `QueuedInterceptor` in the first
/// place.
///
/// `QueuedInterceptor` runs one `onError` at a time per instance. While this
/// `onError` is running, its instance's error queue is busy, so a second error
/// arriving on that same instance is appended to the queue and **never started**;
/// the queue only advances when the active task's handler completes. A replay
/// dispatched on this same `Dio` that then receives a 401 therefore produces
/// this: the replay's error is queued behind the task that is awaiting the
/// replay, and that task cannot complete until the replay settles. Neither runs.
/// The request hangs until the caller's own timeout fires, with no exception and
/// no log line to say why.
///
/// So the replay goes out on [replayDio] -- a second `Dio` carrying an equivalent
/// instance of this class, sharing the same [RefreshCoordinator] and the same
/// [storage]. Its error queue is not the occupied one, so the replay's 401 is
/// handled immediately, matches [retriedKey], and is passed through.
///
/// That second instance is terminal by construction: every request dispatched to
/// it is a replay, and every replay carries [retriedKey], so it can only ever
/// pass a 401 through. It is given no `replayDio` of its own, which makes a
/// third hop impossible rather than merely unlikely.
///
/// The `QueuedInterceptor` base class stays. The plan mandates it, and the
/// reasoning above still holds: the deadlock is a property of awaiting a
/// re-entrant dispatch *from inside a queue slot*, not an argument against the
/// queue.
///
/// ## The `extra` keys this reads and writes
///
/// dio's `RequestOptions.extra` is the only per-request state that survives a
/// dispatch, and both keys live there rather than in a field, so two requests
/// cannot share one another's decision:
///
/// | key | meaning |
/// | --- | --- |
/// | [retriedKey] | this request has already been replayed once after a refresh |
/// | [noAuthKey] | do not attach `Authorization` to this request, and do not refresh on its 401 |
/// | [allowUnsafeRetryKey] | the caller accepts a replay of a non-idempotent request |
///
/// ## Why an anonymous 401 is never refreshed
///
/// `noAuthKey` is read in [onRequest] *and* in [onError], and the second read is
/// load-bearing rather than defensive. A request marked anonymous left this
/// class without an `Authorization` header, so its 401 is the server refusing an
/// unidentified caller -- a guard regression on a public route, or a policy
/// change -- and no amount of rotation changes the answer. Refreshing anyway
/// would spend a one-shot refresh token to learn that, and because a spent
/// refresh token reads as theft to the server, a client that raced itself here
/// would lose every live refresh token for the account over a 401 from
/// `GET /dokter`. The pass-through 401 is the recoverable outcome; the rotation
/// is not.
///
/// ## Why a non-idempotent request is not replayed by default
///
/// The plan's rule is that a `POST`, `PUT`, `PATCH` or `DELETE` is not retried
/// after a refresh without an explicit opt-out flag, and the flag is
/// [allowUnsafeRetryKey].
///
/// The conservative default is defensible on its own terms -- HTTP gives no
/// guarantee that a request which reached the server did not partially apply --
/// but this API is a special case worth stating plainly: a 401 here is produced
/// by the `auth:sanctum` guard, which runs **before** the controller, so a 401
/// means no controller code executed and a replay is in fact safe. That is why
/// the flag exists at all: it lets a call site that knows this for a specific
/// endpoint say so, and it keeps the default honest for a client that has been
/// pointed at some other server.
class AuthInterceptor extends QueuedInterceptor {
  /// Creates the interceptor.
  ///
  /// [replayDio] is the client a post-refresh replay is dispatched through. It
  /// must be a `Dio` that carries an equivalent instance of this class, sharing
  /// [coordinator] and [storage], so the replay picks up the rotated token and
  /// still terminates on [retriedKey].
  ///
  /// Pass `null` for the interceptor registered on that replay client. Nothing
  /// without [retriedKey] can reach it, so it is terminal and must not be able
  /// to dispatch a replay of its own -- see the class docblock.
  AuthInterceptor({
    required RefreshCoordinator coordinator,
    required TokenStorage storage,
    Dio? replayDio,
  }) : _replayDio = replayDio,
       _coordinator = coordinator,
       _storage = storage;

  /// Marks a request as already replayed after a refresh.
  ///
  /// Set before the replay is dispatched, and preserved across it, so a replay
  /// that also 401s is passed straight through instead of starting a second
  /// refresh. This flag -- not the queue -- is what makes a refresh loop
  /// impossible.
  static const String retriedKey = 'sehatly_retried';

  /// Suppresses the `Authorization` header for one request, **and** suppresses
  /// the refresh on its 401.
  ///
  /// Needed for `POST /api/v1/auth/logout`'s sibling call during sign-out and for
  /// any future public endpoint reached through the authenticated client. The
  /// token is also never attached to a request that already carries an
  /// `Authorization` header, so a caller that set one by hand is not overwritten.
  ///
  /// It is read in [onError] as well as [onRequest], and that second read is the
  /// one that keeps a public route from costing a refresh token. See the class
  /// docblock.
  static const String noAuthKey = 'sehatly_no_auth';

  /// Opts one request in to being replayed after a refresh despite being
  /// non-idempotent. See the class docblock.
  static const String allowUnsafeRetryKey = 'sehatly_allow_unsafe_retry';

  /// Records on a failed request why the refresh could not repair it.
  ///
  /// Read it in a crash report or a `Log` line; there is no accessor for it,
  /// because the value a caller should act on is the 401 itself.
  static const String refreshFailedKey = 'sehatly_refresh_failed';

  /// Methods dio considers safe to replay without an explicit opt-in.
  static const Set<String> safeMethods = <String>{'GET', 'HEAD', 'OPTIONS'};

  final Dio? _replayDio;
  final RefreshCoordinator _coordinator;
  final TokenStorage _storage;

  /// Attaches `Authorization: Bearer <access token>` when there is one.
  ///
  /// The token is read from the store on **every** request rather than cached at
  /// construction, because a rotation writes a new one mid-session and a cached
  /// copy is exactly the stale token that produces the 401 this class exists to
  /// handle. That costs one storage read per request, which is a keychain hit on
  /// mobile -- acceptable, and the alternative is a token cache with its own
  /// invalidation bug.
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    if (options.extra[noAuthKey] == true) {
      handler.next(options);
      return;
    }

    if (_hasAuthorizationHeader(options)) {
      handler.next(options);
      return;
    }

    unawaited(_attachAndContinue(options, handler));
  }

  Future<void> _attachAndContinue(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    try {
      final String? token = await _storage.accessTokens.readAccessToken();

      if (token != null && token.isNotEmpty) {
        options.headers['Authorization'] = 'Bearer $token';
      }
    } catch (error, stackTrace) {
      // A storage read that throws is a device problem, not a request problem.
      // Letting the request go out unauthenticated produces an honest 401 the
      // caller can act on; failing here would report a storage fault as a
      // request fault and skip the refresh path entirely.
      options.extra['sehatly_token_read_error'] = error;
      if (stackTrace != StackTrace.empty) {
        options.extra['sehatly_token_read_stack'] = stackTrace.toString();
      }
    }

    handler.next(options);
  }

  /// Handles a 401 by rotating once and replaying once.
  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final RequestOptions options = err.requestOptions;

    if (err.response?.statusCode != 401) {
      handler.next(err);
      return;
    }

    // Explicitly anonymous. This is a 401 for a request that carried no
    // credential, so no rotation can repair it, and attempting one is not a
    // no-op: `POST /auth/refresh` revokes the presented refresh token on every
    // use, so spending one here burns a one-shot token to fix nothing. If the
    // token was already spent, or a concurrent request spent it first, the
    // server reads the replay as theft and revokes *every* live refresh token for
    // the account -- turning a public-route 401 into a sign-out the caller never
    // asked for. A 401 on `/dokter` or `/master-spesialisasi` is a server-side
    // guard regression, and the honest response to a regression is the 401.
    //
    // Checked before the method gate and before [retriedKey] because it is the
    // strongest of the three statements: there is nothing here to refresh *with*.
    // The flag rides along on a replay, because `_replayOptionsFor` copies
    // `extra` across, so an anonymous request can never re-enter this path.
    if (options.extra[noAuthKey] == true) {
      handler.next(err);
      return;
    }

    // Already replayed. The loop terminator: without this a replay that 401s
    // again would start a second refresh, and the second refresh would be a
    // replayed token, which the server treats as theft.
    if (options.extra[retriedKey] == true) {
      handler.next(err);
      return;
    }

    final String method = options.method.toUpperCase();

    if (!safeMethods.contains(method) &&
        options.extra[allowUnsafeRetryKey] != true) {
      handler.next(err);
      return;
    }

    // Set BEFORE the replay is dispatched, and carried onto the replay by
    // `copyWith(extra:)`. Without this the flag is never written, the replay's own
    // 401 is handled as a fresh failure, and the pair refreshes forever -- and
    // because each rotation spends the refresh token, the second one is a replay
    // as far as the server is concerned and revokes every live refresh token for
    // the account. One line, and the difference between a bounded retry and an
    // account-wide sign-out.
    options.extra[retriedKey] = true;

    unawaited(_refreshAndReplay(err, options, handler));
  }

  /// Rotates once, then replays [options] once.
  ///
  /// Every exit hands [ErrorInterceptorHandler] a [DioException], because that is
  /// the only type dio's error chain carries. Converting to [ApiException] is
  /// [ApiTransport]'s job, one layer up, so a caller never sees two error types
  /// depending on which layer failed.
  Future<void> _refreshAndReplay(
    DioException original,
    RequestOptions options,
    ErrorInterceptorHandler handler,
  ) async {
    final String? presented = _bearerFrom(options.headers['Authorization']);

    try {
      final String? refreshToken = await _storage.refreshTokens
          .readRefreshToken();

      if (refreshToken == null || refreshToken.isEmpty) {
        // Nothing to rotate, so there is nothing to retry with. Sign out rather
        // than replaying into the same 401.
        await _coordinator.expireSession();
        handler.next(_withNote(original, 'no refresh token was stored'));
        return;
      }

      await _coordinator.refreshAfterUnauthorized(
        presentedAccessToken: presented,
      );
    } catch (error) {
      // The coordinator has already cleared both stores and fired
      // `sessionExpired`. The *original* 401 is what the caller should see,
      // because it is the failure they made and the one their UI keys off; the
      // refresh failure is recorded in `extra` for a log line.
      handler.next(_withNote(original, 'refresh failed: $error'));
      return;
    }

    final Dio? replayDio = _replayDio;

    if (replayDio == null) {
      // Terminal by construction: only a replay reaches this instance, and every
      // replay carries `retriedKey`, so the guard above already handled it. This
      // branch exists so that an unexpected arrival is passed through as a plain
      // 401 rather than silently repairing itself into a third hop.
      handler.next(_withNote(original, 'no replay client is configured here'));
      return;
    }

    try {
      final Response<Object?> replayed = await replayDio.fetch<Object?>(
        _replayOptionsFor(options),
      );
      handler.resolve(replayed);
    } on DioException catch (error) {
      handler.next(error);
    }
  }

  /// Returns [original] with the refresh outcome recorded in `extra`.
  ///
  /// `extra` is the only per-request channel dio keeps, and a note there is
  /// visible in a crash report's request context without being a second error
  /// type a caller has to learn. The key is namespaced with the package name so
  /// it cannot collide with another interceptor's bookkeeping.
  static DioException _withNote(DioException original, String note) {
    original.requestOptions.extra[refreshFailedKey] = note;

    return original;
  }

  /// Builds the options for the replay.
  ///
  /// A **copy**, not the original. dio's `fetch` runs a full dispatch on whatever
  /// it is handed, and reusing an already-dispatched `RequestOptions` leaves its
  /// `extra` map and headers in a state the next dispatch inherits. `copyWith`
  /// produces a fresh object carrying the same `data`, `queryParameters` and
  /// `extra`, and copies rather than shares the two mutable maps.
  ///
  /// ## The stale `Authorization` header is stripped, and it has to be
  ///
  /// The original carried `Bearer <stale>`, which is precisely the credential the
  /// server just rejected. Copying it forward would leave the replay presenting
  /// the same dead token -- and because [onRequest] deliberately leaves an
  /// existing `Authorization` header alone, nothing downstream would repair it.
  /// The replay would 401 a second time, [retriedKey] would stop the loop, and
  /// the caller would see a failure while the refresh had in fact succeeded.
  ///
  /// Stripping rather than overwriting is what keeps the store the single source
  /// of truth: the replay's `onRequest` reads the rotated token back out of it,
  /// exactly as any other request does, instead of this method carrying a second
  /// copy of the credential.
  ///
  /// [retriedKey] is already `true` on the copy, because [onError] set it on the
  /// original before this runs and [copyWith] carries `extra` across. That is
  /// what terminates the loop.
  static RequestOptions _replayOptionsFor(RequestOptions original) {
    final Map<String, dynamic> headers = <String, dynamic>{};

    for (final MapEntry<String, dynamic> entry in original.headers.entries) {
      if (entry.key.toLowerCase() == 'authorization') {
        continue;
      }

      headers[entry.key] = entry.value;
    }

    return original.copyWith(
      headers: headers,
      extra: Map<String, dynamic>.from(original.extra),
    );
  }

  /// Whether [headers] already carries an `Authorization` entry.
  ///
  /// Case-insensitively, because HTTP header names are case-insensitive and dio
  /// preserves the case a caller wrote. A caller that set the header by hand
  /// meant it.
  static bool _hasAuthorizationHeader(RequestOptions options) {
    return options.headers.keys.any(
      (String name) => name.toLowerCase() == 'authorization',
    );
  }

  /// Extracts the bearer value from an `Authorization` header.
  ///
  /// Tolerates a header that is already the bare token, and returns `null` for a
  /// non-bearer scheme, which the coordinator then treats as "no usable token"
  /// and performs a real rotation for.
  static String? _bearerFrom(Object? headerValue) {
    if (headerValue is! String) {
      return null;
    }

    const String prefix = 'Bearer ';

    if (headerValue.length > prefix.length &&
        headerValue.substring(0, prefix.length).toLowerCase() ==
            prefix.toLowerCase()) {
      return headerValue.substring(prefix.length);
    }

    return headerValue.isEmpty ? null : headerValue;
  }
}
