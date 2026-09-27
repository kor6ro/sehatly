import 'dart:async';

import 'package:dio/dio.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

import 'support/test_support.dart';

/// Builds a client over a scripted transport and a fake secure store.
SehatlyApiClient clientWith(
  ScriptedAdapter adapter,
  FakeSecureBackend store, {
  void Function(ApiException error)? onSessionExpired,
}) {
  return SehatlyApiClient.secure(
    backend: store,
    environment: SehatlyEnvironment.test,
    onSessionExpired: onSessionExpired,
    httpClientAdapter: adapter,
  );
}

/// A successful `/auth/refresh` body carrying [accessToken] and [refreshToken].
Map<String, Object?> refreshBody(String accessToken, String refreshToken) {
  return <String, Object?>{
    'success': true,
    'data': <String, Object?>{
      'token': <String, Object?>{
        'token_type': 'Bearer',
        'access_token': accessToken,
        'expires_in': 1439,
        'access_token_expires_at': '2099-01-01T00:00:00.000000Z',
        'refresh_token': refreshToken,
        'refresh_token_expires_at': '2099-02-01T00:00:00.000000Z',
      },
    },
    'message': 'Token berhasil diperbarui.',
  };
}

void main() {
  group('single-flight refresh', () {
    test('5 concurrent 401s produce exactly ONE refresh call and 5 replays', () async {
      final ScriptedAdapter adapter = ScriptedAdapter();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'stale-access',
        kRefreshTokenKey: 'refresh-1',
      });

      // Counted at the transport, not inside the coordinator: the claim is about
      // requests that reached the network, so that is where the count belongs.
      var refreshCalls = 0;
      var meCalls = 0;

      adapter.handler = (RecordedRequest request) async {
        if (request.path == RefreshCoordinator.defaultRefreshPath) {
          refreshCalls += 1;
          // Held open long enough that all five 401s are handled while it is in
          // flight, so "concurrent" is a measured property of this run rather than
          // an assumption about how the event loop happens to interleave.
          await Future<void>.delayed(const Duration(milliseconds: 20));

          return ScriptedAdapter.jsonResponse(
            refreshBody('fresh-access', 'refresh-2'),
            200,
          );
        }

        meCalls += 1;

        if (meCalls <= 5) {
          return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
        }

        return ScriptedAdapter.successResponse(<String, Object?>{
          'user': userJson(),
        }, message: 'Profil berhasil dimuat.');
      };

      final SehatlyApiClient client = clientWith(adapter, store);

      final List<User> results = await Future.wait(<Future<User>>[
        for (int i = 0; i < 5; i++) client.me.show(),
      ]);

      // The invariant, asserted where it is actually about the wire.
      expect(refreshCalls, 1, reason: 'N 401s must coalesce into one rotation');
      expect(meCalls, 10, reason: '5 original + 5 replayed');
      expect(
        adapter.countOf(RefreshCoordinator.defaultRefreshPath),
        1,
        reason: 'the refresh path must be dispatched exactly once',
      );
      expect(
        adapter.callCount,
        11,
        reason: 'only /me and /auth/refresh may be dispatched at all',
      );
      expect(client.refreshCoordinator.refreshAttemptCount, 1);

      // All five replays succeeded and carried the rotated token.
      expect(results, hasLength(5));
      for (final User user in results) {
        expect(user.id, 7);
      }

      final Iterable<RecordedRequest> replays = adapter
          .to('/me')
          .where(
            (RecordedRequest r) => r.authorization == 'Bearer fresh-access',
          );

      expect(
        replays.length,
        5,
        reason: 'every replay must present the rotated access token',
      );

      expect(store.values[kAccessTokenKey], 'fresh-access');
      expect(store.values[kRefreshTokenKey], 'refresh-2');
    });

    test('the auth interceptor is a QueuedInterceptor', () {
      final ScriptedAdapter adapter = ScriptedAdapter();
      final SehatlyApiClient client = clientWith(adapter, FakeSecureBackend());

      final Interceptor auth = client.dio.interceptors.firstWhere(
        (Interceptor i) => i is AuthInterceptor,
      );

      expect(
        auth,
        isA<QueuedInterceptor>(),
        reason:
            'the plan mandates a QueuedInterceptor. See the evidence file for '
            'what the base class does and does not guarantee on its own.',
      );

      client.close();
    });

    test(
      'five genuinely concurrent coordinator calls dispatch one rotation',
      () async {
        final ScriptedAdapter adapter = ScriptedAdapter();
        final FakeSecureBackend store = FakeSecureBackend(<String, String>{
          kAccessTokenKey: 'stale-access',
          kRefreshTokenKey: 'refresh-1',
        });

        var refreshCalls = 0;
        adapter.handler = (RecordedRequest request) async {
          if (request.path == RefreshCoordinator.defaultRefreshPath) {
            refreshCalls += 1;
            await Future<void>.delayed(const Duration(milliseconds: 20));

            return ScriptedAdapter.jsonResponse(
              refreshBody('fresh-access', 'refresh-2'),
              200,
            );
          }

          return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
        };

        final SehatlyApiClient client = clientWith(adapter, store);
        final RefreshCoordinator coordinator = client.refreshCoordinator;

        final List<Future<TokenPair>> all = <Future<TokenPair>>[
          for (int i = 0; i < 5; i++)
            coordinator.refreshAfterUnauthorized(
              presentedAccessToken: 'stale-access',
            ),
        ];

        final List<TokenPair> pairs = await Future.wait(all);

        expect(
          refreshCalls,
          1,
          reason: 'the in-flight future covers simultaneity',
        );
        expect(pairs, hasLength(5));
        for (final TokenPair pair in pairs) {
          expect(pair.accessToken, 'fresh-access');
        }
        expect(
          identical(pairs.first, pairs.last),
          isTrue,
          reason:
              "every waiter must receive the same future's value, not five "
              'separately issued ones',
        );

        client.close();
      },
    );

    test('five SEQUENTIAL 401s still dispatch one rotation', () async {
      // This is the case a QueuedInterceptor creates and the in-flight future
      // cannot cover: the first refresh has already completed and cleared
      // `_refreshInFlight` by the time the second 401 is handled. Without the
      // rotated-token guard this dispatches five rotations, and under the server's
      // contract the second is a replay of a spent token -- which revokes every
      // live refresh token for the account.
      final ScriptedAdapter adapter = ScriptedAdapter();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'stale-access',
        kRefreshTokenKey: 'refresh-1',
      });

      var refreshCalls = 0;
      adapter.handler = (RecordedRequest request) async {
        if (request.path == RefreshCoordinator.defaultRefreshPath) {
          refreshCalls += 1;

          return ScriptedAdapter.jsonResponse(
            refreshBody('fresh-access', 'refresh-2'),
            200,
          );
        }

        return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
      };

      final SehatlyApiClient client = clientWith(adapter, store);
      final RefreshCoordinator coordinator = client.refreshCoordinator;

      // One at a time, each completing before the next starts.
      for (int i = 0; i < 5; i++) {
        await coordinator.refreshAfterUnauthorized(
          presentedAccessToken: 'stale-access',
        );
      }

      expect(
        refreshCalls,
        1,
        reason:
            'a 401 presenting the rotated-away token is answered from the '
            'rotation that already happened, with no network call',
      );

      client.close();
    });

    test(
      'five 401s presenting five DIFFERENT tokens each need their own rotation',
      () async {
        // The negative case, and the reason the guard is keyed on the token rather
        // than on "a rotation happened recently". A 401 whose bearer is not the one
        // the last rotation replaced is a genuinely different failure and must not be
        // papered over with a stale pair.
        final ScriptedAdapter adapter = ScriptedAdapter();
        final FakeSecureBackend store = FakeSecureBackend(<String, String>{
          kAccessTokenKey: 'stale-access',
          kRefreshTokenKey: 'refresh-1',
        });

        var refreshCalls = 0;
        adapter.handler = (RecordedRequest request) async {
          if (request.path == RefreshCoordinator.defaultRefreshPath) {
            refreshCalls += 1;

            return ScriptedAdapter.jsonResponse(
              refreshBody(
                'fresh-access-$refreshCalls',
                'refresh-$refreshCalls',
              ),
              200,
            );
          }

          return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
        };

        final SehatlyApiClient client = clientWith(adapter, store);
        final RefreshCoordinator coordinator = client.refreshCoordinator;

        for (int i = 0; i < 5; i++) {
          await coordinator.refreshAfterUnauthorized(
            presentedAccessToken: 'unknown-access-$i',
          );
        }

        expect(refreshCalls, 5);
        expect(store.values[kAccessTokenKey], 'fresh-access-5');

        client.close();
      },
    );
  });
}
