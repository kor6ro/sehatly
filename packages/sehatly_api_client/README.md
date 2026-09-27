# sehatly_api_client

Pure-Dart client for the Sehatly telemedicine API. Dio under the hood, with
single-flight token refresh, a secure/plain token-storage split, typed errors and
pagination, and a realtime chat surface that is unit-testable without a broker.

## No Flutter, and that is load-bearing

There is no `flutter:` key in `pubspec.yaml` and no `package:flutter/` import
anywhere in `lib/`. The only runtime dependency is `dio`.

That is not asceticism. The mobile team's CI runners do not necessarily have
Flutter installed, and a `flutter:` SDK constraint would make `dart pub get` fail
on them before a single test ran. Keeping the package Flutter-free means it
resolves, analyzes and tests with a bare `dart` binary:

```console
dart pub get
dart analyze
dart test
```

The three pieces that genuinely need a platform -- secure storage, FCM, and a
real socket -- are declared as interfaces here and implemented in the
**consuming app**. All three are copy-pasteable below.

## The two storage factories, and why there are two

`SehatlyApiClient.secure` requires a `SecureKeyValueBackend`, and
`SehatlyApiClient.plain` requires a `PlainKeyValueBackend` *plus* an explicit
`allowInsecureStorage: true`. The split is not ceremony.

OWASP MASVS-STORAGE-1 requires that credentials at rest be stored in a
hardware-backed keystore, not in app-readable storage. A refresh token is a
long-lived credential: it is what a stolen device can replay to mint access
tokens indefinitely. `SehatlyApiClient.plain` is for a desktop build, a CLI
script and unit tests, and making the caller pass `allowInsecureStorage: true`
turns "I meant this" from an assumption into a line in a diff.

### Adapter 1 -- `flutter_secure_storage`

```dart
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';

class FlutterSecureStorageBackend implements SecureKeyValueBackend {
  FlutterSecureStorageBackend([FlutterSecureStorage? storage])
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock,
              ),
            );

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);

  @override
  Future<void> deleteAll() => _storage.deleteAll();
}

final client = SehatlyApiClient.secure(
  backend: FlutterSecureStorageBackend(),
  environment: SehatlyEnvironment.production,
);
```

`first_unlock` is deliberate: a background fetch that wakes the app while the
device is still locked must be able to read the refresh token, or notification
handling fails on every lock screen.

### Adapter 2 -- `firebase_messaging`

`firebase_messaging` is a Flutter plugin, which is exactly why it is an
interface here and a `class` in your app.

```dart
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';

class FirebasePushProvider implements PushTokenProvider {
  FirebasePushProvider() {
    _messaging.onTokenRefresh.listen(_refreshes.add);
    FirebaseMessaging.onMessageOpenedApp.listen(_openFromForeground);
    FirebaseMessaging.onMessageOpenedApp.listen(_openFromBackground);
  }

  final _messaging = FirebaseMessaging.instance;
  final _refreshes = StreamController<String>.broadcast();
  final _opened = StreamController<PushNotificationOpened>.broadcast();

  @override
  Stream<String> get tokenRefreshes => _refreshes.stream;

  @override
  Stream<PushNotificationOpened> get openedFromNotification => _opened.stream;

  @override
  Future<String?> requestPermissionAndGetToken() async {
    final settings = await _messaging.requestPermission();
    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      return null;
    }
    return _messaging.getToken();
  }

  void _openFromForeground(RemoteMessage message) => _open(message);

  void _openFromBackground(RemoteMessage message) => _open(message);

  void _open(RemoteMessage message) {
    _opened.add(
      PushNotificationOpened(
        messageId: message.messageId,
        data: <String, Object?>{...message.data},
      ),
    );
  }

  @override
  void dispose() {
    _refreshes.close();
    _opened.close();
  }
}
```

#### The two platform traps

Both of these are silent failures -- the app builds, the token is retrieved, and
push simply never arrives.

On iOS the app is a UIScene app so `AppDelegate.didFinishLaunchingWithOptions` must call `FLTFirebaseMessagingPlugin.configureNotificationCenterDelegate()` as the first statement before `super`

```swift
// ios/Runner/AppDelegate.swift
@main
@objc class AppDelegate: FlutterAppDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    // Must be the FIRST statement. Before `super` or before this call, iOS
    // does not hand the plugin the notification centre delegate and every
    // notification tap is dropped without an error.
    FLTFirebaseMessagingPlugin.configureNotificationCenterDelegate()
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }
}
```

Because the app is a UIScene app, this is not the whole iOS story: a tap that
cold-starts the app arrives in
`application(_:didFinishLaunchingWithOptions:)` as a `remoteNotification` user
activity rather than through the delegate, so it has to be forwarded out of the
scene connection as well. Firebase's own `flutterfire` docs cover the shape; the
point here is that the first statement above is non-negotiable *and* not
sufficient on its own.

On Android the manifest needs `POST_NOTIFICATIONS` plus a `com.google.firebase.messaging.default_notification_channel_id` meta-data entry

```xml
<!-- android/app/src/main/AndroidManifest.xml -->
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
  <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
  <uses-permission android:name="android.permission.INTERNET" />

  <application android:name="${applicationName}">
    <meta-data
        android:name="com.google.firebase.messaging.default_notification_channel_id"
        android:value="@string/default_notification_channel_id" />

    <meta-data
        android:name="com.google.firebase.messaging.default_notification_icon"
        android:resource="@mipmap/ic_launcher" />
  </application>
</manifest>
```

Android 13+ will not show a notification at all without `POST_NOTIFICATIONS`,
and without the channel meta-data FCM silently falls back to a default channel
that is not created on the device, so the notification is dropped by the system
with no callback to your app.

#### Driving the registration

```dart
final registration = PushRegistration(
  auth: client.auth,
  provider: FirebasePushProvider(),
  platform: DevicePlatform.android,
  appVersi: '1.4.2',
  onRegistered: (row) => debugPrint('device ${row.deviceId} active=${row.aktif}'),
  onFailed: (error) => debugPrint('server refused: ${error.message}'),
  onWarning: (reason) => debugPrint('device-side problem: $reason'),
);

await registration.register(deviceId: installationId);
registration.start();
```

Two things about this call that are not obvious:

`register` returns `null` and posts **nothing** when the user refuses
permission. `user_devices.fcm_token` is nullable, so posting a null would
overwrite a working token on that row and silently stop push for a device that
was fine. A refusal is a `null` return, not an error.

Call `start()` once. It is idempotent, so calling it from `initState` is safe,
and it is what keeps the device registered when FCM rotates the token -- which it
does on a restore-from-backup, on a data wipe, and on rotations the app never
asks about. Without it, the app keeps a token the platform has already discarded
and every notification is delivered to nobody while the API answers 200.

`dispose()` when the owning screen goes away. It is idempotent, and it cancels
the refresh listener rather than abandoning it -- a listener left behind keeps
posting tokens for a device the app no longer considers signed in.

### Adapter 3 -- the socket

`RealtimeSocket` is the transport seam: the Pusher protocol expressed as an
interface. Reverb and Soketi both speak it, so any Pusher-protocol client works
and the socket library stays swappable without touching `RealtimeClient`.

`laravel_reverb` is **not** a dependency of this package -- the broadcaster does
not exist yet in this repository (see "Not built yet" below), and it is not in
this machine's pub cache. Bind it in your app:

```dart
import 'package:pusher_channels_flutter/pusher_channels_flutter.dart';
import 'package:sehatly_api_client/sehatly_api_client.dart';

class PusherRealtimeSocket implements RealtimeSocket {
  PusherRealtimeSocket({
    required this.host,
    required this.port,
    required this.appKey,
    required this.secret,
    this.useTls = true,
    this.authEndpoint = broadcastAuthEndpoint,
  });

  final String host;
  final int port;
  final String appKey;
  final String secret;
  final bool useTls;
  final String authEndpoint;

  late final PusherChannelsFlutter _pusher = PusherChannelsFlutter(
    PusherChannelsConfig(
      host: host,
      port: port,
      appKey: appKey,
      useTLS: useTls,
      authEndpoint: authEndpoint,
    ),
    channelAuthorizer: PusherChannelAuthorizer(
      host,
      port,
      useTls,
      authEndpoint,
      PusherAuthCredentials(appKey, secret),
    ),
  );

  final _signals = StreamController<RealtimeSocketSignal>.broadcast();
  final _events = StreamController<RealtimeEvent>.broadcast();

  @override
  Stream<RealtimeSocketSignal> get signals => _signals.stream;

  @override
  Stream<RealtimeEvent> get events => _events.stream;

  @override
  void connect() {
    // The authorizer must be configured for private channels, or the
    // `POST /api/broadcasting/auth` call is never made and the subscribe
    // hangs until it times out.
    _pusher.pusher.channelAuthorization(
      PusherChannelAuthorization(
        PusherAuthCredentials(appKey, secret),
        PusherChannelAuthorizationOptions(
          host: host,
          port: port,
          sslEnabled: useTls,
          authEndpoint: authEndpoint,
          auth: {'headers': {'Accept': 'application/json'}},
        ),
      ),
    );
    _pusher.connect(
      onConnectionStateChange: (PusherConnectionState state) {
        switch (state) {
          case PusherConnectionState.connected:
            _signals.add(
              _everConnected ? RealtimeReconnected() : RealtimeConnected(),
            );
            _everConnected = true;
          case PusherConnectionState.disconnected:
          case PusherConnectionState.connectionUnavailable:
          case PusherConnectionState.failed:
            _signals.add(RealtimeDisconnected());
        }
      },
    );
  }

  bool _everConnected = false;

  @override
  void disconnect() {
    _pusher.unsubscribeAll();
    _pusher.disconnect();
  }

  @override
  Future<void> subscribe(RealtimeSubscribeRequest request) async {
    final channel = _pusher.subscribe(request.wireName);

    for (final name in request.events) {
      // Pusher delivers the broadcast name prefixed with a dot, so bind
      // `.chat.pesan`, not `chat.pesan`.
      channel.bind('.$name', (PusherEvent event) {
        if (!event.channelName.startsWith(privateChannelPrefix)) return;
        _events.add(
          RealtimeEvent(
            // Obligation 1: hand back the LOGICAL name. The client matches on
            // `konsultasi.5`; forwarding `private-konsultasi.5` makes every
            // channel look unsubscribed and the transcript never updates.
            channelName: event.channelName.substring(privateChannelPrefix.length),
            eventName: event.eventName,
            data: Map<String, Object?>.from(event.data as Map),
          ),
        );
      });
    }
  }

  @override
  void unsubscribe(String channelName) {
    _pusher.unsubscribe(_channelKind.wireName(channelName));
  }
}
```

Two obligations the interface places on any implementation:

1. **Strip the wire prefix** from the channel name on the way in. `RealtimeClient`
   matches on the logical name (`konsultasi.5` -> `private-konsultasi.5` on the
   wire), so forwarding the prefixed name makes every channel look unsubscribed.
   A code sample that writes `private-` itself produces
   `private-private-konsultasi.5`, a channel that was never authorised.
2. **Report a re-subscribe's re-delivery honestly.** A broker that replays frames
   in flight for a channel is producing duplicates, and the interface permits
   that. Dedupe belongs to the consumer, which is the only thing that knows what
   it has already rendered.

`PusherChannelAuthorizer` needs the `secret` because the server-side signature is
normally produced by `POST /api/broadcasting/auth`; when the authorizer is
configured on the client with the secret, the app is holding a key that can
authorise *any* channel. Prefer the auth-endpoint flow (which is what
`RealtimeSubscribeRequest.authHeaders` is for) and only use the client-side
authorizer for local development.

### Using the client

```dart
final realtime = RealtimeClient(
  socket: PusherRealtimeSocket(
    host: 'reverb.example.test',
    port: 8080,
    appKey: const String.fromEnvironment('REVERB_APP_KEY'),
    secret: const String.fromEnvironment('REVERB_SECRET'),
  ),
  storage: client.storage,
  onResync: (RealtimeResync gap) async {
    // The broker replayed nothing, so the gap is closed over REST. Return the
    // rows for gap.channels; anything already seen is withheld automatically.
    return api.chatHistory(konsultasiId: currentKonsultasiId);
  },
  onFailure: (reason) => debugPrint('realtime: $reason'),
);

realtime.messages.listen(render);
await realtime.subscribeKonsultasi(konsultasiId: 5);
realtime.connect();
```

`connect()` is separate from `subscribe` on purpose. A subscribe is authorised
over HTTP, so a transport that opened its socket inside that `await` would hold
a connection open across a keychain read. The app decides when the socket opens.

## What the client guarantees, and why

**It re-reads the access token on every subscribe.** The token rotates, so a
header map captured at construction is a stale credential by the second
subscribe, and a stale token on a private channel is refused. There is a test
that rotates the token between two subscribes and asserts the second one carries
the new value.

**It deduplicates on `konsultasi_chat.id`**, the row's own primary key, not on
arrival order and not on `konsultasi_id` (which every message in one transcript
shares). A
message can reach a client twice: once live, once in the REST history the app
fetches to fill the gap the broker does not replay, and a re-subscribe can make
the broker replay frames in flight. The dedupe set is bounded (500 ids, oldest
evicted) and `duplicateSuppressedCount` is the honest count of what was withheld.
A frame with no id is delivered rather than swallowed -- it cannot be
deduplicated, and swallowing it loses a message.

**The backfill is delivered, not just remembered.** On reconnect the client
re-subscribes, then hands the resync result through the same dedupe gate. A
message broadcast while the socket was down therefore reaches the transcript,
while one that arrived live first does not appear twice.

**`disconnect()` is not `dispose()`.** `disconnect()` closes the transport and
keeps the subscriptions and the dedupe set, so a backgrounded app comes back to
the same channels without re-issuing the subscribe. `dispose()` releases the
stream subscriptions and clears the state; the client is finished.

## Not built yet

These are the plan's later todos, and this package is written so that landing
them changes one line rather than the design:

- **Todo 31 -- the broadcaster.** There is no `config/broadcasting.php`, no
  `Broadcast::routes()` call and no class implementing `ShouldBroadcast` in this
  repository. The logical channel name (`konsultasi.{id}`), the event name
  (`chat.pesan`) and the auth endpoint (`/api/broadcasting/auth`) are
  transcribed from the plan and named as constants so a transport cannot quietly
  substitute the session-authenticated route, whose failure mode is a redirect
  or a 419 on a request carrying only `Authorization: Bearer` and a subscription
  that never confirms with no error anywhere.
- **Todo 32 -- the chat endpoints.** The REST history and mark-read endpoints
  do not exist yet. `ChatMessage` is transcribed from the `konsultasi_chat`
  table at `telemedicine_test.sql:563-579`, and every DDL `ENUM` member in this
  package carries the line it came from.

The `konsultasi_id` wire key is the one thing that is not negotiable. A resource
serialises a model's attributes, so a model whose column is `konsultasi_id`
publishes `konsultasi_id`. A client reading `konsultasi` would score every
message in the transcript as consultation `0` -- a silent, plausible-looking
failure. There is a test that pins the key for exactly that reason.

## Tests

```console
dart test
```

The realtime layer is tested against a `FakeRealtimeSocket` with no port open
and no broker running. The HTTP layer is tested against a `ScriptedAdapter` that
records post-interceptor state, so a test asserting on an `Authorization` header
is asserting on what went on the wire rather than on what an endpoint class
intended.
