import 'dart:io';

import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

import 'support/test_support.dart';

void main() {
  group('the storage split is selected by an explicit flag', () {
    test('TokenStorage.plain without the flag is refused', () {
      // The plan's rule is "must NOT put tokens in a plain store by default". A
      // default of `secure` does not satisfy that on its own, because the failure
      // mode is a developer reaching for the convenient constructor on a build
      // that ships to a phone. The flag turns the choice into a greppable line at
      // the call site.
      expect(
        () => TokenStorage.plain(
          InMemoryKeyValueBackend(),
          allowInsecureStorage: false,
        ),
        throwsStateError,
      );
    });

    test('the refusal message names the reason and the fix', () {
      try {
        TokenStorage.plain(
          InMemoryKeyValueBackend(),
          allowInsecureStorage: false,
        );
        fail('expected a StateError');
      } on StateError catch (error) {
        final String message = error.message;
        expect(message, contains('allowInsecureStorage'));
        expect(message, contains('TokenStorage.secure()'));
        expect(message, contains('readable by any other process'));
      }
    });

    test(
      'TokenStorage.plain with the flag succeeds and reports itself plain',
      () {
        final TokenStorage storage = TokenStorage.plain(
          InMemoryKeyValueBackend(),
          allowInsecureStorage: true,
        );

        expect(storage.isSecure, isFalse);
        expect(storage.description, startsWith('access=plain:'));
        expect(storage.description, contains('refresh=plain:'));
      },
    );

    test('TokenStorage.secure reports itself secure', () {
      final TokenStorage storage = TokenStorage.secure(FakeSecureBackend());

      expect(storage.isSecure, isTrue);
      expect(storage.description, contains('secure: FakeSecureBackend'));
    });

    test('SehatlyApiClient.secure defaults to a secure store', () {
      final SehatlyApiClient client = SehatlyApiClient.secure(
        backend: FakeSecureBackend(),
        environment: SehatlyEnvironment.test,
        httpClientAdapter: ScriptedAdapter(),
      );

      expect(client.isSecureStorage, isTrue);
      expect(client.storageDescription, contains('secure:'));

      client.close();
    });

    test('SehatlyApiClient.plain propagates the flag refusal', () {
      expect(
        () => SehatlyApiClient.plain(
          backend: InMemoryKeyValueBackend(),
          allowInsecureStorage: false,
          environment: SehatlyEnvironment.test,
        ),
        throwsStateError,
      );
    });
  });

  group('the stores round-trip and clear', () {
    test('SecureTokenStore and SecureRefreshTokenStore use distinct namespaced keys', () async {
      final FakeSecureBackend backend = FakeSecureBackend();
      final TokenStorage storage = TokenStorage.secure(backend);

      await storage.accessTokens.writeAccessToken('access-1');
      await storage.refreshTokens.writeRefreshToken('refresh-1');

      expect(await storage.accessTokens.readAccessToken(), 'access-1');
      expect(await storage.refreshTokens.readRefreshToken(), 'refresh-1');

      expect(
        backend.values.keys,
        containsAll(<String>[kAccessTokenKey, kRefreshTokenKey]),
      );
      expect(kAccessTokenKey, 'sehatly.access_token');
      expect(kRefreshTokenKey, 'sehatly.refresh_token');

      // Clearing one half must not touch the other; that is the whole reason they
      // are two interfaces.
      await storage.accessTokens.clear();
      expect(await storage.accessTokens.readAccessToken(), isNull);
      expect(await storage.refreshTokens.readRefreshToken(), 'refresh-1');

      await storage.clearAll();
      expect(backend.values, isEmpty);
    });

    test('clearAll attempts both halves even when the first throws', () async {
      // Leaving the refresh token behind is the worse of the two to leave: it is
      // the longer-lived secret.
      final TokenStorage storage = TokenStorage.secure(_FailingAccessBackend());

      await expectLater(storage.clearAll(), throwsStateError);
      expect(
        _FailingAccessBackend.refreshCleared,
        isTrue,
        reason: 'the refresh half must still be cleared',
      );
    });

    test('InMemoryKeyValueBackend is plain, never secure', () {
      final InMemoryKeyValueBackend backend = InMemoryKeyValueBackend();

      expect(backend, isA<PlainKeyValueBackend>());
      expect(backend, isNot(isA<SecureKeyValueBackend>()));
      expect(backend.description, contains('not a secret store'));
    });

    test(
      'FileKeyValueBackend is plain, and says the value is not encrypted',
      () {
        final FileKeyValueBackend backend = FileKeyValueBackend(
          '/tmp/does-not-matter',
        );

        expect(backend, isA<PlainKeyValueBackend>());
        expect(backend, isNot(isA<SecureKeyValueBackend>()));
        expect(backend.description, contains('base64, not encrypted'));
      },
    );
  });

  group('FileKeyValueBackend', () {
    late Directory temp;

    setUp(() async {
      temp = await Directory.systemTemp.createTemp('sehatly_storage_test');
    });

    tearDown(() async {
      if (temp.existsSync()) {
        await temp.delete(recursive: true);
      }
    });

    String pathTo(String name) => '${temp.path}${Platform.pathSeparator}$name';

    test('round-trips a value across instances', () async {
      final FileKeyValueBackend writer = FileKeyValueBackend(pathTo('t.json'));
      await writer.write(kAccessTokenKey, 'access-1');

      // A second instance reads the same file, which is what makes this a
      // persistence and not a cache.
      final FileKeyValueBackend reader = FileKeyValueBackend(pathTo('t.json'));
      expect(await reader.read(kAccessTokenKey), 'access-1');
    });

    test('a value survives a read/write cycle without corruption', () async {
      // A Sanctum bearer token is an opaque string with no newlines; the encoding
      // exists so nothing mangles it in the middle.
      final String token =
          '2|abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
          'abcdefghijklmnop|klmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQ';

      final FileKeyValueBackend backend = FileKeyValueBackend(pathTo('t.json'));
      await backend.write(kRefreshTokenKey, token);

      expect(await backend.read(kRefreshTokenKey), token);
    });

    test('a missing file reads as absent, not as an error', () async {
      final FileKeyValueBackend backend = FileKeyValueBackend(
        pathTo('absent.json'),
      );

      expect(await backend.read(kAccessTokenKey), isNull);
    });

    test('a corrupt file reads as absent, not as a crash', () async {
      // A truncated write from a crash mid-save is the same situation from the
      // caller's side: no token is stored, so sign in again. Throwing would turn
      // a recoverable "your session was not persisted" into a startup crash.
      final File file = File(pathTo('bad.json'));
      await file.writeAsString('{not json', flush: true);

      final FileKeyValueBackend backend = FileKeyValueBackend(
        pathTo('bad.json'),
      );
      expect(await backend.read(kAccessTokenKey), isNull);
    });

    test('a plain-text value written by hand is still readable', () async {
      final File file = File(pathTo('hand.json'));
      await file.writeAsString(
        '{"sehatly.access_token": "hand-written"}',
        flush: true,
      );

      final FileKeyValueBackend backend = FileKeyValueBackend(
        pathTo('hand.json'),
      );
      expect(await backend.read(kAccessTokenKey), 'hand-written');
    });

    test('deleteAll empties the file and every read is then absent', () async {
      final FileKeyValueBackend backend = FileKeyValueBackend(pathTo('t.json'));
      await backend.write(kAccessTokenKey, 'a');
      await backend.write(kRefreshTokenKey, 'b');

      await backend.deleteAll();

      expect(await backend.read(kAccessTokenKey), isNull);
      expect(await backend.read(kRefreshTokenKey), isNull);
    });
  });
}

/// A secure backend whose access-token half always throws, for the partial-clear
/// assertion. The refresh half is a real map so the assertion can observe it.
class _FailingAccessBackend implements SecureKeyValueBackend {
  final Map<String, String> values = <String, String>{};

  static bool refreshCleared = false;

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async {
    values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    if (key == kAccessTokenKey) {
      throw StateError('the platform keychain refused to delete this entry');
    }

    refreshCleared = true;
    values.remove(key);
  }

  @override
  Future<void> deleteAll() async => values.clear();

  @override
  String get description => '_FailingAccessBackend';
}
