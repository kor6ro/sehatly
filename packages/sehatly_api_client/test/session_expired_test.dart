import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

import 'support/test_support.dart';

void main() {
  group('a 401 on POST /auth/refresh ends the session', () {
    test('clears BOTH stores and fires sessionExpired exactly once', () async {
      final ScriptedAdapter adapter = ScriptedAdapter();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'stale-access',
        kRefreshTokenKey: 'already-spent-refresh',
      });

      final List<ApiException> expiries = <ApiException>[];

      adapter.handler = (RecordedRequest request) async {
        if (request.path == RefreshCoordinator.defaultRefreshPath) {
          // The server's rotation contract: the presented token was revoked on a
          // previous use, so this 401 is a replay and every live refresh token for
          // the account has just been revoked too. Unrecoverable.
          return ScriptedAdapter.errorResponse(
            'Refresh token tidak valid atau sudah dipakai.',
            401,
          );
        }

        return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
      };

      final SehatlyApiClient client = SehatlyApiClient.secure(
        backend: store,
        environment: SehatlyEnvironment.test,
        onSessionExpired: expiries.add,
        httpClientAdapter: adapter,
      );

      ApiException? caught;

      try {
        await client.me.show();
      } on ApiException catch (error) {
        caught = error;
      }

      expect(caught, isA<ApiException>());
      expect(caught?.statusCode, 401);
      expect(
        caught?.message,
        'Unauthenticated.',
        reason: 'the caller sees the 401 it made, not the refresh failure',
      );

      // Both halves cleared. This is the assertion the plan names.
      expect(store.values.containsKey(kAccessTokenKey), isFalse);
      expect(store.values.containsKey(kRefreshTokenKey), isFalse);
      expect(store.values, isEmpty);

      // The callback fired, once, and it is latched.
      expect(expiries, hasLength(1));
      expect(expiries.single.isUnauthorized, isTrue);
      expect(expiries.single.message, contains('sudah dipakai'));

      // A second failure must not re-navigate a user who already left the screen.
      await client.refreshCoordinator.expireSession();
      expect(expiries, hasLength(1), reason: 'the callback is latched');

      client.close();
    });

    test('does NOT loop: exactly one refresh dispatch, one replay attempt', () async {
      final ScriptedAdapter adapter = ScriptedAdapter();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'stale-access',
        kRefreshTokenKey: 'already-spent-refresh',
      });

      adapter.handler = (RecordedRequest request) async {
        if (request.path == RefreshCoordinator.defaultRefreshPath) {
          return ScriptedAdapter.errorResponse('Refresh token ditolak.', 401);
        }

        return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
      };

      final SehatlyApiClient client = SehatlyApiClient.secure(
        backend: store,
        environment: SehatlyEnvironment.test,
        httpClientAdapter: adapter,
      );

      await expectLater(client.me.show(), throwsA(isA<ApiException>()));

      // The loop terminator, measured. A refresh that 401s must produce exactly
      // one dispatch, and a failed replay must not produce a second one.
      expect(
        adapter.countOf(RefreshCoordinator.defaultRefreshPath),
        1,
        reason: 'a failed rotation must not be retried',
      );
      expect(
        adapter.countOf('/me'),
        1,
        reason: 'a request whose repair failed must not be replayed',
      );
      expect(
        adapter.callCount,
        2,
        reason: 'one /me, one /auth/refresh, nothing else',
      );
      expect(client.refreshCoordinator.didExpireSession, isTrue);

      client.close();
    });

    test(
      'five parallel 401s with a failing refresh still dispatch ONE refresh',
      () async {
        final ScriptedAdapter adapter = ScriptedAdapter();
        final FakeSecureBackend store = FakeSecureBackend(<String, String>{
          kAccessTokenKey: 'stale-access',
          kRefreshTokenKey: 'already-spent-refresh',
        });

        adapter.handler = (RecordedRequest request) async {
          if (request.path == RefreshCoordinator.defaultRefreshPath) {
            await Future<void>.delayed(const Duration(milliseconds: 20));

            return ScriptedAdapter.errorResponse('Refresh token ditolak.', 401);
          }

          return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
        };

        final List<ApiException> expiries = <ApiException>[];
        final SehatlyApiClient client = SehatlyApiClient.secure(
          backend: store,
          environment: SehatlyEnvironment.test,
          onSessionExpired: expiries.add,
          httpClientAdapter: adapter,
        );

        final List<Object?> outcomes = await Future.wait<Object?>(
          <Future<Object?>>[
            for (int i = 0; i < 5; i++)
              client.me
                  .show()
                  .then<Object?>((User u) => u)
                  .catchError((Object e) => e),
          ],
        );

        expect(
          adapter.countOf(RefreshCoordinator.defaultRefreshPath),
          1,
          reason: 'the failure path is single-flight too',
        );
        expect(
          adapter.countOf('/me'),
          5,
          reason: 'no replay after a failed repair',
        );
        expect(outcomes, hasLength(5));
        for (final Object? outcome in outcomes) {
          expect(outcome, isA<ApiException>());
        }
        expect(
          expiries,
          hasLength(1),
          reason: 'latched, even across five failures',
        );
        expect(store.values, isEmpty);

        client.close();
      },
    );
  });

  group('a 401 with no refresh token stored', () {
    test('expires the session without dispatching a refresh', () async {
      final ScriptedAdapter adapter = ScriptedAdapter();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'stale-access',
      });

      final List<ApiException> expiries = <ApiException>[];

      adapter.handler = (RecordedRequest request) async =>
          ScriptedAdapter.errorResponse('Unauthenticated.', 401);

      final SehatlyApiClient client = SehatlyApiClient.secure(
        backend: store,
        environment: SehatlyEnvironment.test,
        onSessionExpired: expiries.add,
        httpClientAdapter: adapter,
      );

      await expectLater(client.me.show(), throwsA(isA<ApiException>()));

      expect(
        adapter.countOf(RefreshCoordinator.defaultRefreshPath),
        0,
        reason: 'there is nothing to rotate, so nothing may be sent',
      );
      expect(store.values, isEmpty);
      expect(expiries, hasLength(1));
      expect(expiries.single.isUnauthorized, isTrue);

      client.close();
    });
  });

  group('signOut', () {
    test('clears both stores even when the server call fails', () async {
      // A client that left a live credential in storage because the network was
      // down would keep a token on a device the user believes they have signed out
      // of, which is the worse outcome.
      final ScriptedAdapter adapter = ScriptedAdapter();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'access-1',
        kRefreshTokenKey: 'refresh-1',
      });

      adapter.handler = (RecordedRequest request) async =>
          ScriptedAdapter.errorResponse('Internal server error.', 500);

      final SehatlyApiClient client = SehatlyApiClient.secure(
        backend: store,
        environment: SehatlyEnvironment.test,
        httpClientAdapter: adapter,
      );

      await expectLater(client.signOut(), throwsA(isA<ApiException>()));
      expect(store.values, isEmpty);

      client.close();
    });

    test('sends refresh_token in the body, as LogoutRequest requires', () async {
      final ScriptedAdapter adapter = ScriptedAdapter();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'access-1',
        kRefreshTokenKey: 'refresh-1',
      });

      adapter.handler = (RecordedRequest request) async {
        if (request.path == pathAuthLogout) {
          return ScriptedAdapter.successResponse(<String, Object?>{
            'refresh_token': <String, Object?>{'dicabut': true},
            'access_token': <String, Object?>{'dihapus': true},
            'perangkat': <String, Object?>{'dimatikan': 2},
          }, message: 'Logout berhasil.');
        }

        return ScriptedAdapter.errorResponse('Resource not found.', 404);
      };

      final SehatlyApiClient client = SehatlyApiClient.secure(
        backend: store,
        environment: SehatlyEnvironment.test,
        httpClientAdapter: adapter,
      );

      final LogoutResult result = await client.auth.logout('refresh-1');

      expect(result.refreshDicabut, isTrue);
      expect(result.accessDihapus, isTrue);
      expect(result.perangkatDimatikan, 2);

      final RecordedRequest logout = adapter.to(pathAuthLogout).single;
      expect(logout.method, 'POST');
      expect(
        logout.body['refresh_token'],
        'refresh-1',
        reason:
            'LogoutRequest extends RefreshTokenRequest; the field is required',
      );

      client.close();
    });
  });
}
