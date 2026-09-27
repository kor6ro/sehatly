import 'dart:async';

import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

import 'support/test_support.dart';

/// The installation id every test registers under.
///
/// `user_devices.device_id` is a client-supplied `VARCHAR(255)` (`:194`), so it
/// is an opaque string, not a surrogate key.
const String deviceId = 'installation-abc';

/// The app version written to `user_devices.app_versi`.
const String appVersi = '1.4.2';

/// A [PushTokenProvider] a test drives by hand.
///
/// ## Why this is not `firebase_messaging`
///
/// `firebase_messaging` is a Flutter plugin, and importing it would put a
/// `flutter:` SDK constraint on this package and a `package:flutter/` import in
/// `lib/`. The package is verified with a bare `dart` binary on a runner with no
/// Flutter installed, and that property is what keeps the whole suite runnable
/// in CI. See [PushTokenProvider].
class FakePushProvider implements PushTokenProvider {
  /// Creates a provider that hands out [token] and then rotates.
  FakePushProvider({this.token = 'fcm-token-1', this.permissionError});

  /// What [requestPermissionAndGetToken] returns, or `null` for a refusal.
  String? token;

  /// Thrown by [requestPermissionAndGetToken] instead of answering, to model a
  /// platform that needs to tell a refusal from "no push service".
  Object? permissionError;

  /// How many times permission was asked for.
  int permissionRequests = 0;

  /// How many times [dispose] was called.
  int disposeCount = 0;

  final StreamController<String> _refreshes =
      StreamController<String>.broadcast();
  final StreamController<PushNotificationOpened> _opened =
      StreamController<PushNotificationOpened>.broadcast();

  @override
  Stream<String> get tokenRefreshes => _refreshes.stream;

  @override
  Stream<PushNotificationOpened> get openedFromNotification => _opened.stream;

  @override
  Future<String?> requestPermissionAndGetToken() async {
    permissionRequests += 1;

    final Object? error = permissionError;

    if (error != null) {
      throw error;
    }

    return token;
  }

  /// Pushes a platform-side token rotation.
  void rotateToken(String next) {
    token = next;
    _refreshes.add(next);
  }

  /// Pushes a notification the user opened from the tray.
  void open(PushNotificationOpened notification) => _opened.add(notification);

  /// Makes the refresh stream fail, which is not a rotation.
  void failRefreshStream(Object error) => _refreshes.addError(error);

  @override
  void dispose() {
    disposeCount += 1;
  }

  /// Closes both controllers.
  Future<void> close() async {
    await _refreshes.close();
    await _opened.close();
  }
}

/// A client over a scripted transport, with a token already in the store.
///
/// The registration posts to an authenticated endpoint, so a test that does not
/// seed a token would assert on a request with no `Authorization` header and
/// pass for the wrong reason.
SehatlyApiClient _client(ScriptedAdapter adapter) {
  final FakeSecureBackend backend = FakeSecureBackend()
    ..values[kAccessTokenKey] = 'access-1';

  return SehatlyApiClient.secure(
    backend: backend,
    environment: SehatlyEnvironment.test,
    httpClientAdapter: adapter,
  );
}

/// A `UserDeviceResource` body, as `POST /auth/devices` returns it.
Map<String, Object?> _deviceJson({
  String fcm = 'fcm-token-1',
  String platform = 'android',
  bool aktif = true,
}) {
  return <String, Object?>{
    'device': <String, Object?>{
      'device_id': deviceId,
      'platform': platform,
      'fcm_token': fcm,
      'app_versi': appVersi,
      'aktif': aktif,
      'last_active_at': '2026-01-02T03:04:05.000000Z',
      'dibuat_at': '2026-01-01T00:00:00.000000Z',
    },
  };
}

void main() {
  group('register posts the device the provider handed out', () {
    test(
      'the body carries the DDL keys and the Authorization header',
      () async {
        final ScriptedAdapter adapter = ScriptedAdapter(
          handler: (RecordedRequest r) async =>
              ScriptedAdapter.successResponse(_deviceJson()),
        );

        final SehatlyApiClient client = _client(adapter);
        final FakePushProvider provider = FakePushProvider();

        final PushRegistration registration = PushRegistration(
          auth: client.auth,
          provider: provider,
          platform: DevicePlatform.android,
          appVersi: appVersi,
        );

        final UserDevice? device = await registration.register(
          deviceId: deviceId,
        );

        expect(device, isNotNull);
        expect(device!.deviceId, deviceId);
        expect(device.platform, DevicePlatform.android);
        expect(device.fcmToken, 'fcm-token-1');
        expect(device.aktif, isTrue);

        final RecordedRequest request = adapter.requests.single;
        expect(request.method, 'POST');
        expect(request.path, pathAuthDevices);
        expect(request.authorization, 'Bearer access-1');
        expect(
          request.body['device_id'],
          deviceId,
          reason: 'the string installation id, never the surrogate',
        );
        expect(request.body['platform'], 'android');
        expect(request.body['fcm_token'], 'fcm-token-1');
        expect(request.body['app_versi'], appVersi);
        expect(registration.registrationCount, 1);
        expect(registration.reRegistrationCount, 0);
        expect(registration.registeredToken, 'fcm-token-1');

        registration.dispose();
        await provider.close();
        client.close();
      },
    );

    test('onRegistered receives the row the server returned', () async {
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async =>
            ScriptedAdapter.successResponse(_deviceJson()),
      );

      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider();
      final List<UserDevice> rows = <UserDevice>[];

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
        appVersi: appVersi,
        onRegistered: rows.add,
      );

      await registration.register(deviceId: deviceId);
      await pumpEventQueue();

      expect(rows, hasLength(1));
      expect(rows.single.deviceId, deviceId);

      registration.dispose();
      await provider.close();
      client.close();
    });

    test(
      'app_versi is omitted from the body when it was not supplied',
      () async {
        final ScriptedAdapter adapter = ScriptedAdapter(
          handler: (RecordedRequest r) async =>
              ScriptedAdapter.successResponse(_deviceJson()),
        );

        final SehatlyApiClient client = _client(adapter);
        final FakePushProvider provider = FakePushProvider();

        final PushRegistration registration = PushRegistration(
          auth: client.auth,
          provider: provider,
          platform: DevicePlatform.android,
        );

        await registration.register(deviceId: deviceId);

        expect(
          adapter.requests.single.body.containsKey('app_versi'),
          isFalse,
          reason: 'an absent optional key is omitted, not sent as null',
        );

        registration.dispose();
        await provider.close();
        client.close();
      },
    );
  });

  group('a refused permission changes nothing', () {
    test('nothing is posted and no existing registration is cleared', () async {
      // The claim is the load-bearing one: a null token must not post, because
      // `fcm_token` is nullable (:195) and posting a null would overwrite a good
      // token on the row and silently stop push for a working device.
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async =>
            ScriptedAdapter.successResponse(_deviceJson()),
      );

      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider(token: null);
      final List<String> warnings = <String>[];

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
        onWarning: warnings.add,
      );

      final UserDevice? device = await registration.register(
        deviceId: deviceId,
      );

      expect(device, isNull);
      expect(adapter.callCount, 0, reason: 'no request was dispatched at all');
      expect(registration.registrationCount, 0);
      expect(warnings, hasLength(1));
      expect(warnings.single, contains('no push token'));

      registration.dispose();
      await provider.close();
      client.close();
    });

    test('an empty token is treated the same as a refusal', () async {
      final ScriptedAdapter adapter = ScriptedAdapter();
      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider(token: '');

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
      );

      expect(await registration.register(deviceId: deviceId), isNull);
      expect(adapter.callCount, 0);

      registration.dispose();
      await provider.close();
      client.close();
    });

    test('a platform error is a warning, not a signed-out session', () async {
      // The two callbacks are separate on purpose. A provider that throws is a
      // device problem; calling onFailed with it would push an ApiException-typed
      // channel to something that is not one.
      final ScriptedAdapter adapter = ScriptedAdapter();
      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider(
        permissionError: StateError('push service unavailable'),
      );

      final List<ApiException> failures = <ApiException>[];
      final List<String> warnings = <String>[];

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
        onFailed: failures.add,
        onWarning: warnings.add,
      );

      await expectLater(
        registration.register(deviceId: deviceId),
        throwsA(isA<StateError>()),
      );

      expect(failures, isEmpty, reason: 'no server refusal happened');
      expect(adapter.callCount, 0);

      registration.dispose();
      await provider.close();
      client.close();
    });
  });

  group('a token rotation re-registers on the same row', () {
    test('the new token is posted and the old one is not kept', () async {
      // `uq_device (user_id, device_id)` (:201) makes this an upsert onto the
      // same row, so the rotated token replaces the stale one. An app that
      // registers once at start-up keeps a token the platform has discarded and
      // every notification goes nowhere while the API answers 200.
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async {
          final String token = r.body['fcm_token']! as String;

          return ScriptedAdapter.successResponse(_deviceJson(fcm: token));
        },
      );

      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider();

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
        appVersi: appVersi,
      );

      await registration.register(deviceId: deviceId);
      registration.start();

      provider.rotateToken('fcm-token-2');
      await pumpEventQueue();

      expect(registration.registrationCount, 2);
      expect(registration.reRegistrationCount, 1);
      expect(registration.registeredToken, 'fcm-token-2');

      final List<RecordedRequest> posts = adapter.to(pathAuthDevices).toList();
      expect(posts, hasLength(2));
      expect(posts.last.body['fcm_token'], 'fcm-token-2');
      expect(
        posts.map((RecordedRequest r) => r.body['device_id']),
        everyElement(deviceId),
        reason: 'the same installation, so the same row is overwritten',
      );
      expect(posts.last.authorization, 'Bearer access-1');

      registration.dispose();
      await provider.close();
      client.close();
    });

    test('start() is idempotent, so initState cannot double-post', () async {
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async =>
            ScriptedAdapter.successResponse(_deviceJson()),
      );

      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider();

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
        appVersi: appVersi,
      );

      await registration.register(deviceId: deviceId);

      registration.start();
      registration.start();
      registration.start();

      provider.rotateToken('fcm-token-2');
      await pumpEventQueue();

      expect(
        adapter.countOf(pathAuthDevices),
        2,
        reason: 'one initial registration plus exactly one rotation',
      );

      registration.dispose();
      await provider.close();
      client.close();
    });

    test('a rotation before any registration posts nothing', () async {
      // There is no device id to post against, and inventing one would create a
      // `user_devices` row for a device nobody identified.
      final ScriptedAdapter adapter = ScriptedAdapter();
      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider();
      final List<String> warnings = <String>[];

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
        onWarning: warnings.add,
      );

      registration.start();
      provider.rotateToken('fcm-token-2');
      await pumpEventQueue();

      expect(adapter.callCount, 0);
      expect(registration.registrationCount, 0);
      expect(warnings, hasLength(1));
      expect(warnings.single, contains('rotated before this device'));

      // The next register() reads the current token from the provider, so the
      // rotation is not lost -- it just cannot be posted before an identity.
      await registration.register(deviceId: deviceId);
      expect(adapter.requests.single.body['fcm_token'], 'fcm-token-2');

      registration.dispose();
      await provider.close();
      client.close();
    });

    test('a failed re-registration warns and does not rethrow', () async {
      // A rotation that fails while offline must not surface as a server
      // refusal: the session is fine, and a caller must not be signed out over a
      // network blip on a background listener.
      bool fail = false;

      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async {
          if (fail) {
            return ScriptedAdapter.jsonResponse(<String, Object?>{
              'success': false,
              'message': 'the device could not be stored',
              'errors': <String, Object?>{},
            }, 500);
          }

          return ScriptedAdapter.successResponse(_deviceJson());
        },
      );

      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider();
      final List<ApiException> failures = <ApiException>[];
      final List<String> warnings = <String>[];

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
        onFailed: failures.add,
        onWarning: warnings.add,
      );

      await registration.register(deviceId: deviceId);
      registration.start();

      fail = true;
      provider.rotateToken('fcm-token-2');
      await pumpEventQueue();

      expect(registration.reRegistrationCount, 1);
      expect(registration.registeredToken, 'fcm-token-1', reason: 'unchanged');
      expect(failures, hasLength(1));
      expect(failures.single.statusCode, 500);
      expect(warnings, hasLength(1));
      expect(warnings.single, contains('re-registration failed'));

      registration.dispose();
      await provider.close();
      client.close();
    });

    test(
      'a failing refresh stream warns and leaves the registration alive',
      () async {
        final ScriptedAdapter adapter = ScriptedAdapter(
          handler: (RecordedRequest r) async =>
              ScriptedAdapter.successResponse(_deviceJson()),
        );

        final SehatlyApiClient client = _client(adapter);
        final FakePushProvider provider = FakePushProvider();
        final List<String> warnings = <String>[];

        final PushRegistration registration = PushRegistration(
          auth: client.auth,
          provider: provider,
          platform: DevicePlatform.android,
          onWarning: warnings.add,
        );

        await registration.register(deviceId: deviceId);
        registration.start();

        provider.failRefreshStream(StateError('the stream died'));
        await pumpEventQueue();

        expect(warnings, hasLength(1));
        expect(warnings.single, contains('token-refresh stream failed'));

        registration.dispose();
        await provider.close();
        client.close();
      },
    );

    test(
      'a server refusal on the first register reaches onFailed and rethrows',
      () async {
        final ScriptedAdapter adapter = ScriptedAdapter(
          handler: (RecordedRequest r) async =>
              ScriptedAdapter.jsonResponse(<String, Object?>{
                'success': false,
                'message': 'Unauthenticated.',
                'errors': <String, Object?>{},
              }, 401),
        );

        final SehatlyApiClient client = _client(adapter);
        final FakePushProvider provider = FakePushProvider();
        final List<ApiException> failures = <ApiException>[];

        final PushRegistration registration = PushRegistration(
          auth: client.auth,
          provider: provider,
          platform: DevicePlatform.android,
          onFailed: failures.add,
        );

        await expectLater(
          registration.register(deviceId: deviceId),
          throwsA(isA<ApiException>()),
        );

        expect(failures, hasLength(1));
        expect(failures.single.statusCode, 401);
        expect(
          registration.registeredToken,
          isNull,
          reason: 'a refused registration recorded no token',
        );

        registration.dispose();
        await provider.close();
        client.close();
      },
    );
  });

  group('dispose stops the listener and releases the provider', () {
    test('a rotation after dispose posts nothing', () async {
      final ScriptedAdapter adapter = ScriptedAdapter(
        handler: (RecordedRequest r) async =>
            ScriptedAdapter.successResponse(_deviceJson()),
      );

      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider();

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
        appVersi: appVersi,
      );

      await registration.register(deviceId: deviceId);
      registration.start();
      registration.dispose();

      provider.rotateToken('fcm-token-2');
      await pumpEventQueue();

      expect(
        adapter.countOf(pathAuthDevices),
        1,
        reason:
            'a listener left on the provider keeps posting a token for a '
            'device the app no longer considers signed in',
      );
      expect(provider.disposeCount, 1);

      await provider.close();
      client.close();
    });

    test('dispose is idempotent and releases the provider once', () async {
      final ScriptedAdapter adapter = ScriptedAdapter();
      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider();

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
      );

      registration.dispose();
      registration.dispose();

      expect(provider.disposeCount, 1);

      await provider.close();
      client.close();
    });
  });

  group('an opened notification is passed through unparsed', () {
    test('the raw data payload reaches a listener verbatim', () async {
      // The payload is defined by whatever the server put in it, and this package
      // has not been told what that is, so a deep-link parser here would be a
      // guess every caller had to unpick.
      final ScriptedAdapter adapter = ScriptedAdapter();
      final SehatlyApiClient client = _client(adapter);
      final FakePushProvider provider = FakePushProvider();

      final PushRegistration registration = PushRegistration(
        auth: client.auth,
        provider: provider,
        platform: DevicePlatform.android,
      );

      final List<PushNotificationOpened> opened = <PushNotificationOpened>[];
      registration.openedNotifications.listen(opened.add);

      provider.open(
        PushNotificationOpened(
          messageId: 'fcm-message-1',
          data: <String, Object?>{
            'konsultasi_id': 5,
            'tipe': 'chat.pesan',
            'ruang': 'konsultasi.5',
          },
        ),
      );
      await pumpEventQueue();

      expect(opened, hasLength(1));
      expect(opened.single.messageId, 'fcm-message-1');
      expect(opened.single.data, hasLength(3));
      expect(opened.single.value('ruang'), 'konsultasi.5');
      expect(
        opened.single.value('konsultasi_id'),
        '5',
        reason: 'a non-String data value is read as text, not discarded',
      );
      expect(opened.single.value('tidak_ada'), isNull);

      registration.dispose();
      await provider.close();
      client.close();
    });

    test(
      'toString names the message id and the keys, not the payload',
      () async {
        const PushNotificationOpened notification = PushNotificationOpened(
          messageId: 'fcm-message-2',
          data: <String, Object?>{
            'ruang': 'konsultasi.5',
            'tipe': 'chat.pesan',
          },
        );

        final String text = notification.toString();

        expect(text, contains('fcm-message-2'));
        expect(text, contains('ruang, tipe'));
        expect(
          text,
          isNot(contains('konsultasi.5')),
          reason: 'payload values are not for a log line',
        );
      },
    );
  });
}
