import 'package:dio/dio.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

import 'support/test_support.dart';

/// A client over a scripted transport, with the store pre-seeded.
///
/// [store] lets a test keep a handle on the *same* backend the client reads
/// through. Without it a test that wants to change a token mid-session has to
/// build its own backend, and writing to that one is invisible to the client --
/// so the assertion cannot distinguish "re-read every request" from "cached the
/// first value" and would fail for reasons that have nothing to do with either.
SehatlyApiClient seeded(
  ScriptedAdapter adapter, {
  String? accessToken = 'access-1',
  String? refreshToken = 'refresh-1',
  void Function(ApiException error)? onSessionExpired,
  FakeSecureBackend? store,
}) {
  final FakeSecureBackend backend = store ?? FakeSecureBackend();

  if (accessToken != null) {
    backend.values[kAccessTokenKey] = accessToken;
  }

  if (refreshToken != null) {
    backend.values[kRefreshTokenKey] = refreshToken;
  }

  return SehatlyApiClient.secure(
    backend: backend,
    environment: SehatlyEnvironment.test,
    onSessionExpired: onSessionExpired,
    httpClientAdapter: adapter,
  );
}

void main() {
  group('the Authorization header', () {
    test('is attached to an authenticated request', () async {
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async => ScriptedAdapter.successResponse(
          <String, Object?>{'user': userJson()},
        ),
      );

      final SehatlyApiClient client = seeded(adapter);
      await client.me.show();

      expect(adapter.requests.single.authorization, 'Bearer access-1');
      client.close();
    });

    test('is re-read from the store on every request, never cached', () async {
      // A cached token is exactly the stale credential that produces the 401 this
      // package exists to handle.
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async => ScriptedAdapter.successResponse(
          <String, Object?>{'user': userJson()},
        ),
      );

      final FakeSecureBackend store = FakeSecureBackend();
      final SehatlyApiClient client = seeded(adapter, store: store);

      await client.me.show();
      expect(adapter.requests[0].authorization, 'Bearer access-1');

      await store.write(kAccessTokenKey, 'rotated');
      await client.me.show();
      expect(adapter.requests[1].authorization, 'Bearer rotated');

      client.close();
    });

    test('is omitted entirely when nothing is stored', () async {
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async => ScriptedAdapter.successResponse(
          <String, Object?>{'user': userJson()},
        ),
      );

      final SehatlyApiClient client = seeded(adapter, accessToken: null);
      await client.me.show();

      expect(adapter.requests.single.authorization, isNull);
      client.close();
    });

    test('is not attached to an anonymous endpoint', () async {
      // The public directory is reachable with no session, and a token sent to it
      // is a token sent to a host the caller did not need to identify itself to.
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async => ScriptedAdapter.paginatedResponse(
          <String, Object?>{
            'dokter': <Object?>[dokterListingJson()],
          },
          currentPage: 1,
          lastPage: 1,
          perPage: 1,
          total: 1,
          from: 1,
          to: 1,
        ),
      );

      final SehatlyApiClient client = seeded(adapter);
      await client.dokter.index();

      expect(adapter.requests.single.path, pathDokter);
      expect(adapter.requests.single.authorization, isNull);
      client.close();
    });

    test('is not attached to a caller-supplied Authorization header', () async {
      // A caller that set the header by hand meant it, and dio preserves the case
      // it was written in, so the check is case-insensitive.
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async => ScriptedAdapter.successResponse(
          <String, Object?>{'user': userJson()},
        ),
      );

      final SehatlyApiClient client = seeded(adapter);
      await client.dio.get<Object?>(
        '/me',
        options: Options(
          headers: <String, dynamic>{'authorization': 'Bearer manual'},
        ),
      );

      expect(adapter.requests.single.authorization, 'Bearer manual');
      client.close();
    });
  });

  group('the unsafe-method replay gate', () {
    test('a POST is NOT replayed after a refresh by default', () async {
      // Issued through [ApiTransport] rather than a typed endpoint, on purpose.
      //
      // Every Module 1 write endpoint hardcodes `allowUnsafeRetry: true` -- see
      // `PasienApi` -- so the *unopted-in* path this test is about cannot be
      // reached by calling one. Calling `createAlergi` here would assert
      // `countOf(refresh) == 0` about a request that unconditionally opts in,
      // and that expectation would be false rather than uncovered.
      //
      // Omitting the flag is the entire point: this is the same call `PasienApi`
      // makes with one argument removed, so the default is observed on the real
      // dispatch path. Going through the raw `client.dio` instead would lose the
      // typed error -- the conversion to [ApiException] is [ApiTransport]'s job,
      // not dio's -- and the test would assert a `DioException` that no caller
      // of this package ever sees.
      final ScriptedAdapter adapter = ScriptedAdapter();
      adapter.handler = (RecordedRequest r) async {
        if (r.path == RefreshCoordinator.defaultRefreshPath) {
          return ScriptedAdapter.jsonResponse(<String, Object?>{
            'success': true,
            'data': <String, Object?>{
              'token': <String, Object?>{
                'token_type': 'Bearer',
                'access_token': 'fresh',
                'expires_in': 1439,
                'access_token_expires_at': '2099-01-01T00:00:00.000000Z',
                'refresh_token': 'refresh-2',
                'refresh_token_expires_at': '2099-02-01T00:00:00.000000Z',
              },
            },
            'message': 'ok',
          }, 200);
        }

        if (r.path == pathPasienAlergi) {
          return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
        }

        return ScriptedAdapter.errorResponse('Resource not found.', 404);
      };

      final SehatlyApiClient client = seeded(adapter);

      await expectLater(
        client.transport.post<Object?>(
          pathPasienAlergi,
          body: <String, Object?>{'nama_alergen': 'Penicillin'},
          parseData: (Object? data) => data,
        ),
        throwsA(
          isA<ApiException>().having(
            (ApiException e) => e.statusCode,
            'statusCode',
            401,
          ),
        ),
      );

      expect(
        adapter.countOf(RefreshCoordinator.defaultRefreshPath),
        0,
        reason: 'a POST that 401s must not spend a refresh token on the default path',
      );
      expect(adapter.countOf(pathPasienAlergi), 1);
      client.close();
    });

    test('a POST IS replayed when the endpoint opts in', () async {
      // Every Module 1 write goes through `allowUnsafeRetry: true`, because a 401
      // here is produced by the `auth:sanctum` guard, which runs before the
      // controller: a replay cannot double-apply.
      final ScriptedAdapter adapter = ScriptedAdapter();
      var allergyCalls = 0;

      adapter.handler = (RecordedRequest r) async {
        if (r.path == RefreshCoordinator.defaultRefreshPath) {
          return ScriptedAdapter.jsonResponse(<String, Object?>{
            'success': true,
            'data': <String, Object?>{
              'token': <String, Object?>{
                'token_type': 'Bearer',
                'access_token': 'fresh',
                'expires_in': 1439,
                'access_token_expires_at': '2099-01-01T00:00:00.000000Z',
                'refresh_token': 'refresh-2',
                'refresh_token_expires_at': '2099-02-01T00:00:00.000000Z',
              },
            },
            'message': 'ok',
          }, 200);
        }

        if (r.path == pathPasienAlergi) {
          allergyCalls += 1;

          if (allergyCalls == 1) {
            return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
          }

          return ScriptedAdapter.successResponse(<String, Object?>{
            'alergi': <String, Object?>{
              'id': 5,
              'tipe_alergen': 'obat',
              'nama_alergen': 'Penicillin',
              'reaksi': 'Rontok',
              'keparahan': 'berat',
              'dicatat_oleh_user_id': 7,
              'dibuat_at': '2026-01-01T00:00:00.000000Z',
            },
          }, message: 'Alergi berhasil ditambahkan.');
        }

        return ScriptedAdapter.errorResponse('Resource not found.', 404);
      };

      final SehatlyApiClient client = seeded(adapter);

      final PasienAlergi created = await client.pasien.createAlergi(
        <String, Object?>{'nama_alergen': 'Penicillin'},
      );

      expect(created.id, 5);
      expect(created.keparahan, Keparahan.berat);
      expect(adapter.countOf(RefreshCoordinator.defaultRefreshPath), 1);
      expect(
        adapter.countOf(pathPasienAlergi),
        2,
        reason: 'one original, one replay',
      );
      expect(
        adapter.to(pathPasienAlergi).last.authorization,
        'Bearer fresh',
        reason: 'the replay must present the rotated token, not the dead one',
      );
      expect(
        adapter.to(pathPasienAlergi).last.body['nama_alergen'],
        'Penicillin',
        reason: 'the replay must carry the original body',
      );

      client.close();
    });
  });

  group('the anonymous-401 refresh gate', () {
    /// A 200 the refresh endpoint would answer with, so a refresh that *did*
    /// happen is observable both as a call count and as a rotated store.
    ResponseBody refreshOk() => ScriptedAdapter.jsonResponse(<String, Object?>{
      'success': true,
      'data': <String, Object?>{
        'token': <String, Object?>{
          'token_type': 'Bearer',
          'access_token': 'rotated',
          'expires_in': 1439,
          'access_token_expires_at': '2099-01-01T00:00:00.000000Z',
          'refresh_token': 'rotated-refresh',
          'refresh_token_expires_at': '2099-02-01T00:00:00.000000Z',
        },
      },
      'message': 'ok',
    }, 200);

    test('a 401 from an anonymous GET spends no refresh token', () async {
      // The regression this pins. `GET /dokter` is a **safe method**, so it used to
      // clear both remaining gates in `onError` and drive a real rotation: a 401
      // off a route the caller never authenticated to cost a one-shot refresh
      // token, and a spent token reads as theft to the server.
      final List<ApiException> expired = <ApiException>[];
      final ScriptedAdapter adapter = ScriptedAdapter();

      adapter.handler = (RecordedRequest r) async {
        if (r.path == RefreshCoordinator.defaultRefreshPath) {
          return refreshOk();
        }

        return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
      };

      final FakeSecureBackend store = FakeSecureBackend();
      final SehatlyApiClient client = seeded(
        adapter,
        onSessionExpired: expired.add,
        store: store,
      );

      await expectLater(
        client.dokter.index(),
        throwsA(
          isA<ApiException>().having(
            (ApiException e) => e.statusCode,
            'statusCode',
            401,
          ),
        ),
      );

      expect(
        adapter.countOf(RefreshCoordinator.defaultRefreshPath),
        0,
        reason: 'an anonymous request carries no credential, so a rotation '
            'cannot repair its 401 and must not be attempted',
      );
      expect(
        adapter.countOf(pathDokter),
        1,
        reason: 'no replay either: there is nothing new to present',
      );
      expect(
        store.values[kRefreshTokenKey],
        'refresh-1',
        reason: 'the stored refresh token must be untouched, not merely unused',
      );
      expect(
        store.values[kAccessTokenKey],
        'access-1',
        reason: 'a failed anonymous read must not sign the session out',
      );
      expect(
        expired,
        isEmpty,
        reason: 'a public-route 401 is not the end of the session',
      );
      client.close();
    });

    test('a 401 from an anonymous POST spends no refresh token', () async {
      // The severe instance of the same bug, and the one a user hits by accident.
      // `POST /auth/login` answers 401 for a wrong password, is marked anonymous,
      // and opts into `allowUnsafeRetry` -- so before the gate it refreshed, and
      // a mistyped password cost a refresh token. Two wrong attempts against an
      // account with a live session elsewhere could revoke the whole chain.
      final List<ApiException> expired = <ApiException>[];
      final ScriptedAdapter adapter = ScriptedAdapter();

      adapter.handler = (RecordedRequest r) async {
        if (r.path == RefreshCoordinator.defaultRefreshPath) {
          return refreshOk();
        }

        return ScriptedAdapter.errorResponse('Email atau kata sandi salah.', 401);
      };

      final FakeSecureBackend store = FakeSecureBackend();
      final SehatlyApiClient client = seeded(
        adapter,
        onSessionExpired: expired.add,
        store: store,
      );

      await expectLater(
        client.auth.login(
          password: 'wrong-password',
          email: 'siti@example.test',
        ),
        throwsA(
          isA<ApiException>().having(
            (ApiException e) => e.statusCode,
            'statusCode',
            401,
          ),
        ),
      );

      expect(adapter.countOf(RefreshCoordinator.defaultRefreshPath), 0);
      expect(adapter.countOf(pathAuthLogin), 1);
      expect(store.values[kRefreshTokenKey], 'refresh-1');
      expect(expired, isEmpty);
      client.close();
    });

    test('an authenticated 401 on the same path still refreshes', () async {
      // The control. Without this the two tests above would also pass against a
      // build where the gate suppressed *every* refresh, which is not a fix but
      // the opposite of one: it would turn an expired access token into a hard
      // sign-out on every screen.
      final ScriptedAdapter adapter = ScriptedAdapter();
      var meCalls = 0;

      adapter.handler = (RecordedRequest r) async {
        if (r.path == RefreshCoordinator.defaultRefreshPath) {
          return refreshOk();
        }

        if (r.path == '/me') {
          meCalls += 1;

          if (meCalls == 1) {
            return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
          }

          return ScriptedAdapter.successResponse(<String, Object?>{
            'user': userJson(),
          });
        }

        return ScriptedAdapter.errorResponse('Resource not found.', 404);
      };

      final SehatlyApiClient client = seeded(adapter);
      final User profile = await client.me.show();

      expect(profile.namaLengkap, 'Siti Aminah');
      expect(
        adapter.countOf(RefreshCoordinator.defaultRefreshPath),
        1,
        reason: 'the gate keys on the anonymous marker, not on the path',
      );
      expect(meCalls, 2, reason: 'one original, one replay');
      client.close();
    });
  });

  group('the loop terminator', () {
    test(
      'a replay that 401s again is passed through, not re-refreshed',
      () async {
        final ScriptedAdapter adapter = ScriptedAdapter();
        adapter.handler = (RecordedRequest r) async {
          if (r.path == RefreshCoordinator.defaultRefreshPath) {
            return ScriptedAdapter.jsonResponse(<String, Object?>{
              'success': true,
              'data': <String, Object?>{
                'token': <String, Object?>{
                  'token_type': 'Bearer',
                  'access_token': 'fresh',
                  'expires_in': 1439,
                  'access_token_expires_at': '2099-01-01T00:00:00.000000Z',
                  'refresh_token': 'refresh-2',
                  'refresh_token_expires_at': '2099-02-01T00:00:00.000000Z',
                },
              },
              'message': 'ok',
            }, 200);
          }

          return ScriptedAdapter.errorResponse('Unauthenticated.', 401);
        };

        final SehatlyApiClient client = seeded(adapter);

        await expectLater(client.me.show(), throwsA(isA<ApiException>()));

        expect(
          adapter.countOf(RefreshCoordinator.defaultRefreshPath),
          1,
          reason: 'the retried flag is what stops a second rotation',
        );
        expect(
          adapter.countOf('/me'),
          2,
          reason: 'one original, one replay, no more',
        );
        client.close();
      },
    );

    test('a non-401 error never triggers a refresh', () async {
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async =>
            ScriptedAdapter.errorResponse('This action is unauthorized.', 403),
      );

      final SehatlyApiClient client = seeded(adapter);
      await expectLater(client.me.show(), throwsA(isA<ApiException>()));

      expect(adapter.countOf(RefreshCoordinator.defaultRefreshPath), 0);
      expect(adapter.countOf('/me'), 1);
      client.close();
    });

    test('a 404 is never retried and never refreshed', () async {
      // A row owned by another account is a 404 by design, so a 404 must not be
      // read as an expired session.
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async =>
            ScriptedAdapter.errorResponse('Resource not found.', 404),
      );

      final SehatlyApiClient client = seeded(adapter);
      await expectLater(
        client.pasien.deleteAlergi('9'),
        throwsA(
          isA<ApiException>().having(
            (ApiException e) => e.isNotFound,
            'isNotFound',
            isTrue,
          ),
        ),
      );

      expect(adapter.countOf(RefreshCoordinator.defaultRefreshPath), 0);
      client.close();
    });
  });
}
