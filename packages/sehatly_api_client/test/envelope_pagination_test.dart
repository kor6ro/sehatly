import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

import 'support/test_support.dart';

/// Reads a `data` member out of a raw body, the way `ApiEnvelope.raw` does.
Map<String, Object?> dataOf(Map<String, Object?> body) => jsonMap(body['data']);

void main() {
  group('the success envelope', () {
    test('parses success, data and message', () {
      final ApiEnvelope<Object?> envelope = ApiEnvelope.raw(<String, Object?>{
        'success': true,
        'data': <String, Object?>{'id': 1},
        'message': 'Profil berhasil dimuat.',
      });

      expect(envelope.success, isTrue);
      expect(envelope.message, 'Profil berhasil dimuat.');
      expect(envelope.isPaginated, isFalse);
      expect(envelope.meta, isNull);
      expect(envelope.pagination, isNull);
      expect(envelope.dataMap['id'], 1);
    });

    test('a body with NO meta key leaves meta null -- not an empty block', () {
      // `ApiResponse::success()` omits the key entirely when `$meta === null`, and
      // that omission is the only shape in which "not paginated" is unambiguous.
      final ApiEnvelope<Object?> envelope = ApiEnvelope.raw(<String, Object?>{
        'success': true,
        'data': null,
        'message': 'OK',
      });

      expect(envelope.meta, isNull);
      expect(envelope.isPaginated, isFalse);
      expect(envelope.pagination, isNull);
    });

    test('an explicit "meta": null is normalised to the same absent state', () {
      final ApiEnvelope<Object?> envelope = ApiEnvelope.raw(<String, Object?>{
        'success': true,
        'data': null,
        'message': 'OK',
        'meta': null,
      });

      expect(envelope.meta, isNull);
      expect(envelope.isPaginated, isFalse);
    });

    test('a body with a meta block exposes it', () {
      final ApiEnvelope<Object?> envelope = ApiEnvelope.raw(<String, Object?>{
        'success': true,
        'data': <String, Object?>{'devices': <Object?>[]},
        'message': 'Daftar perangkat berhasil dimuat.',
        'meta': <String, Object?>{
          'current_page': 1,
          'last_page': 1,
          'per_page': 0,
          'total': 0,
          'from': null,
          'to': null,
        },
      });

      expect(envelope.isPaginated, isTrue);
      expect(envelope.pagination, isNotNull);
      expect(envelope.pagination!.total, 0);
      expect(envelope.pagination!.from, isNull);
    });

    test('requireData throws a StateError rather than returning null', () {
      final ApiEnvelope<Object?> envelope = ApiEnvelope.raw(<String, Object?>{
        'success': true,
        'data': null,
        'message': 'OK',
      });

      expect(() => envelope.requireData, throwsStateError);
    });
  });

  group('ApiMeta', () {
    test('parses the six project-wide keys', () {
      final ApiMeta meta = ApiMeta.fromMap(<String, Object?>{
        'current_page': 2,
        'last_page': 5,
        'per_page': 15,
        'total': 68,
        'from': 16,
        'to': 30,
      });

      expect(meta.currentPage, 2);
      expect(meta.lastPage, 5);
      expect(meta.perPage, 15);
      expect(meta.total, 68);
      expect(meta.from, 16);
      expect(meta.to, 30);
      expect(meta.hasNextPage, isTrue);
      expect(meta.hasPreviousPage, isTrue);
      expect(meta.nextPage, 3);
      expect(meta.previousPage, 1);
      expect(meta.pageRowCount, 15);
    });

    test('from/to stay null on an empty page, never 0', () {
      // "No rows" has no first and last row. `from: 0` renders as "0-0 of 0" and
      // reads as a bug to a user.
      final ApiMeta meta = ApiMeta.fromMap(<String, Object?>{
        'current_page': 1,
        'last_page': 1,
        'per_page': 15,
        'total': 0,
        'from': null,
        'to': null,
      });

      expect(meta.from, isNull);
      expect(meta.to, isNull);
      expect(meta.total, 0);
      expect(meta.hasNextPage, isFalse);
      expect(meta.nextPage, isNull);
    });

    test('per_page is the APPLIED size, so it is what a pager renders', () {
      // A request for 500 is clamped to 100 server-side; the response says so.
      final ApiMeta meta = ApiMeta.fromMap(<String, Object?>{
        'current_page': 1,
        'last_page': 1,
        'per_page': 100,
        'total': 12,
        'from': 1,
        'to': 12,
      });

      expect(meta.perPage, 100);
      expect(meta.total, 12);
    });

    test('missing keys degrade to page 1 of 1 and total 0', () {
      final ApiMeta meta = ApiMeta.fromMap(<String, Object?>{'total': 5});

      expect(meta.currentPage, 1);
      expect(meta.lastPage, 1);
      expect(meta.perPage, 0);
      expect(meta.total, 5);
      expect(meta.from, isNull);
    });

    test('tryParse returns null only for an absent block', () {
      expect(ApiMeta.tryParse(null), isNull);
      expect(ApiMeta.tryParse(<String, Object?>{'total': 3}), isNotNull);
    });
  });

  group('Paginated', () {
    test('reads a page from the top-level meta (the real /dokter shape)', () {
      // Verified against the current server: `DokterController::index()` passes
      // `ApiResponse::pageMeta($paginator)` to `ApiResponse::success()`, and
      // `DokterDirectoryTest` asserts `meta.total` and five siblings on that route.
      final Paginated<DokterListing> page =
          Paginated<DokterListing>.fromEnvelope(
            data: dataOf(<String, Object?>{
              'success': true,
              'data': <String, Object?>{
                'dokter': <Object?>[
                  dokterListingJson(),
                  dokterListingJson(id: 4),
                ],
              },
              'message': 'Daftar dokter berhasil dimuat.',
              'meta': <String, Object?>{
                'current_page': 1,
                'last_page': 3,
                'per_page': 15,
                'total': 47,
                'from': 1,
                'to': 2,
              },
            }),
            meta: <String, Object?>{
              'current_page': 1,
              'last_page': 3,
              'per_page': 15,
              'total': 47,
              'from': 1,
              'to': 2,
            },
            key: 'dokter',
            itemParser: DokterListing.fromJson,
          );

      expect(page.isPaginated, isTrue);
      expect(page.length, 2);
      expect(page.first.id, 3);
      expect(page.meta!.total, 47);
      expect(page.meta!.hasNextPage, isTrue);
    });

    test('a response with no meta is not paginated, and says so', () {
      final Paginated<UserDevice> page = Paginated<UserDevice>.fromEnvelope(
        data: dataOf(<String, Object?>{
          'success': true,
          'data': <String, Object?>{
            'devices': <Object?>[
              <String, Object?>{
                'device_id': 'android-abc',
                'platform': 'android',
                'fcm_token': 'fcm-1',
                'app_versi': '1.0.0',
                'aktif': true,
                'last_active_at': '2026-01-02T03:04:05.000000Z',
                'dibuat_at': '2026-01-01T00:00:00.000000Z',
              },
            ],
          },
          'message': 'OK',
        }),
        meta: null,
        key: 'devices',
        itemParser: UserDevice.fromJson,
      );

      expect(page.isPaginated, isFalse);
      expect(page.length, 1);
      expect(page.first.deviceId, 'android-abc');
      // The synthetic block is truthful for a client rendering a uniform footer.
      expect(page.effectiveMeta.total, 1);
      expect(page.effectiveMeta.hasNextPage, isFalse);
    });

    test('the degenerate single-page block is paginated but has no next page', () {
      // `GET /auth/devices` and `GET /master-spesialisasi` are lists that are
      // deliberately not paged, and the server still sends the project-wide shape
      // with current_page = last_page = 1 so a client parses one list envelope.
      final Paginated<UserDevice> page = Paginated<UserDevice>.fromEnvelope(
        data: <String, Object?>{
          'devices': <Object?>[
            <String, Object?>{
              'device_id': 'ios-1',
              'platform': 'ios',
              'fcm_token': null,
              'app_versi': null,
              'aktif': false,
              'last_active_at': null,
              'dibuat_at': '2026-01-01T00:00:00.000000Z',
            },
          ],
        },
        meta: <String, Object?>{
          'current_page': 1,
          'last_page': 1,
          'per_page': 1,
          'total': 1,
          'from': 1,
          'to': 1,
        },
        key: 'devices',
        itemParser: UserDevice.fromJson,
      );

      expect(page.isPaginated, isTrue);
      expect(page.meta!.hasNextPage, isFalse);
      expect(page.meta!.nextPage, isNull);
      expect(page.length, 1);
    });

    test('falls back to a pagination block embedded in data', () {
      // The pre-`meta` shape the plan's todo 24 prose describes for /dokter. Not
      // what the current server sends, but reading it costs nothing and reading
      // only the current shape would break against a rolled-back server.
      final Paginated<DokterListing> page =
          Paginated<DokterListing>.fromEnvelope(
            data: <String, Object?>{
              'dokter': <Object?>[dokterListingJson()],
              'current_page': 1,
              'last_page': 1,
              'per_page': 15,
              'total': 1,
              'from': 1,
              'to': 1,
            },
            meta: null,
            key: 'dokter',
            itemParser: DokterListing.fromJson,
          );

      expect(page.isPaginated, isTrue);
      expect(page.meta!.total, 1);
      expect(page.length, 1);
    });

    test(
      'the data fallback does NOT fabricate a block for a single resource',
      () {
        // This is what keeps `isPaginated` false for `GET /api/v1/me`, whose `data`
        // is `{user: {...}}` and carries no pagination keys at all.
        final Paginated<User> page = Paginated<User>.fromEnvelope(
          data: <String, Object?>{'user': userJson()},
          meta: null,
          key: 'user',
          itemParser: User.fromJson,
        );

        expect(page.isPaginated, isFalse);
        expect(
          page.length,
          0,
          reason: 'a single object is not a one-element page',
        );
      },
    );

    test('a real meta block always wins over the data fallback', () {
      final Paginated<DokterListing> page =
          Paginated<DokterListing>.fromEnvelope(
            data: <String, Object?>{
              'dokter': <Object?>[dokterListingJson()],
              // A stale embedded block that must be ignored.
              'current_page': 9,
              'last_page': 9,
              'per_page': 1,
              'total': 999,
            },
            meta: <String, Object?>{
              'current_page': 1,
              'last_page': 2,
              'per_page': 15,
              'total': 16,
              'from': 1,
              'to': 15,
            },
            key: 'dokter',
            itemParser: DokterListing.fromJson,
          );

      expect(page.meta!.currentPage, 1);
      expect(page.meta!.total, 16);
    });

    test('a missing list key yields an empty page, not a throw', () {
      final Paginated<PasienAlergi> page = Paginated<PasienAlergi>.fromEnvelope(
        data: <String, Object?>{'something_else': <Object?>[]},
        meta: <String, Object?>{
          'current_page': 1,
          'last_page': 1,
          'per_page': 15,
          'total': 0,
        },
        key: 'alergi',
        itemParser: PasienAlergi.fromJson,
      );

      expect(page.isEmpty, isTrue);
      expect(page.isNotEmpty, isFalse);
      expect(page.length, 0);
      expect(() => page.first, throwsStateError);
    });
  });
}
