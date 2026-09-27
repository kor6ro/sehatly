import 'dart:async';

import '../api/auth_api.dart';
import '../core/api_exception.dart';
import '../model/dto.dart';
import '../model/enums.dart';

/// A notification the user opened from the tray, after the app was already
/// running.
///
/// [data] is the FCM data payload verbatim. It is exposed as a raw map rather
/// than parsed, because the payload is defined by whatever the server chose to
/// put in it and this package has not been told what that is; a deep-link parser
/// here would be a guess a caller would have to unpick.
class PushNotificationOpened {
  /// Records an opened notification carrying [data] and [messageId].
  const PushNotificationOpened({required this.data, this.messageId});

  /// The FCM data payload, verbatim.
  final Map<String, Object?> data;

  /// The FCM message id, when the platform supplied one.
  final String? messageId;

  /// The data value at [key], or `null`.
  ///
  /// Stringified for a non-`String`, because an FCM data value is defined as a
  /// string and a number that arrived anyway is more usefully read as text than
  /// discarded.
  String? value(String key) {
    final Object? raw = data[key];

    if (raw == null) {
      return null;
    }

    return raw is String ? raw : '$raw';
  }

  @override
  String toString() =>
      'PushNotificationOpened(messageId: $messageId, keys: '
      '${data.keys.join(', ')})';
}

/// The FCM surface this package needs, and nothing else.
///
/// ## Why this is an interface and not `firebase_messaging`
///
/// `firebase_messaging` is a **Flutter** plugin. Importing it would put a
/// `flutter:` SDK constraint in `pubspec.yaml` and a `package:flutter/`
/// import in `lib/`, and this package is verified with a bare `dart` binary on a
/// runner that has no Flutter installed. That property is what keeps this
/// package's whole suite runnable in CI, and it is not worth spending on a
/// token getter.
///
/// So the three calls are declared here, the wiring lives in the **consuming
/// app** behind this interface, and the README carries the adapter as a
/// copy-pasteable block rather than as compiled code.
///
/// The third member is the one that is easy to forget: a token that is never
/// refreshed stops delivering within days, silently, and a registration that
/// happens once at start-up is the usual reason why. See
/// [PushRegistration.start].
abstract interface class PushTokenProvider {
  /// Asks for permission and returns the current token, or `null` when there is
  /// none.
  ///
  /// `null` covers both a refusal and a platform with no push service, and the
  /// two are not distinguishable here -- a provider that needs to tell them
  /// apart throws instead, which [PushRegistration.register] also handles.
  Future<String?> requestPermissionAndGetToken();

  /// Emits a new token whenever the platform rotates one.
  ///
  /// A rotation is not rare: it happens on a restore-from-backup, on a data
  /// wipe, and on a platform-side rotation the app never asks for. The server
  /// has to learn the new value or push goes to a token no device holds.
  Stream<String> get tokenRefreshes;

  /// Emits a notification the user opened from the tray.
  Stream<PushNotificationOpened> get openedFromNotification;

  /// Releases the provider's own resources.
  void dispose();
}

/// Registers a push token as a `user_devices` row, and keeps it registered.
///
/// ## What it adds, and what it deliberately does not
///
/// The registration is a `POST /api/v1/auth/devices`, which the Module 1
/// surface already implements as [AuthApi.registerDevice]. So this class adds
/// **no HTTP of its own**: it owns the *lifecycle* -- permission, the initial
/// registration, re-registration on every token rotation, and the deep links --
/// and delegates the request. A second implementation of that endpoint here
/// would be a second place for the wire contract to drift.
///
/// ## Why re-registration on rotation is the load-bearing part
///
/// `user_devices` carries `UNIQUE KEY uq_device (user_id, device_id)`
/// (`telemedicine_test.sql:201`) and a nullable `fcm_token` (`:195`), so a
/// rotated token is a plain upsert onto the same row and the **old** value is
/// overwritten rather than accumulated. An app that registers once at start-up
/// therefore keeps a token the platform has already discarded, and every
/// notification is delivered to nobody while the API answers 200.
///
/// ## `POST /auth/logout` deactivates every device row, and this undoes it
///
/// `logout` sets `aktif = 0` on all of the account's rows, because
/// `user_refresh_tokens` has no `device_id` and the server cannot tell which
/// session is ending. Calling [register] again re-activates this device, which
/// is the documented recovery. See [AuthApi.logout].
///
/// ## The two callbacks are not interchangeable
///
/// [onFailed] receives an [ApiException] and means *the server refused*, which
/// on a 401 means the session is over. [onWarning] receives a [String] and
/// means *something on this device went wrong* -- a token rotated before
/// anything was registered, or a re-registration that failed while offline. They
/// are separate because a caller must not sign a user out over the second, and
/// must not offer a retry over the first: the interceptor has already handled
/// it.
class PushRegistration {
  /// Creates a registration that posts through [auth] and reads [provider].
  PushRegistration({
    required AuthApi auth,
    required PushTokenProvider provider,
    required this.platform,
    this.appVersi,
    this.onRegistered,
    this.onFailed,
    this.onWarning,
  }) : _auth = auth,
       _provider = provider;

  /// The `user_devices.platform` value this registration writes.
  ///
  /// [DevicePlatform.web] is a valid member of the column and the type permits
  /// it, but a [PushRegistration] driven by a [PushTokenProvider] is describing
  /// a push token, and a browser's is issued by a different vendor under a
  /// different delivery contract.
  final DevicePlatform platform;

  /// The `user_devices.app_versi` value, or `null`.
  final String? appVersi;

  /// Called with the row after every successful registration.
  void Function(UserDevice device)? onRegistered;

  /// Called with the server's refusal after every failed registration.
  void Function(ApiException error)? onFailed;

  /// Called for a client-side problem that involved no request at all.
  void Function(String reason)? onWarning;

  final AuthApi _auth;
  final PushTokenProvider _provider;

  /// Cancelled in [dispose]. The `cancel_subscriptions` hit is that lint's known
  /// limitation rather than a real leak: it recognises a cancel only in the same
  /// function that created the subscription, and a lifecycle-owned listener never
  /// is one. The cancel goes through a local in [dispose] so the null-check and
  /// the cancel cannot disagree.
  // ignore: cancel_subscriptions
  StreamSubscription<String>? _refreshSubscription;
  String? _deviceId;
  int _registrationCount = 0;
  int _reRegistrationCount = 0;
  String? _registeredToken;

  /// Whether [dispose] has already released the provider. See that method for
  /// why the second call has to be a no-op rather than a second release.
  bool _isDisposed = false;

  /// How many registrations have been dispatched, in total.
  ///
  /// The honest denominator for "how many times did we tell the server about
  /// this device", which is what a rate-limited endpoint counts.
  int get registrationCount => _registrationCount;

  /// How many of those a token rotation caused.
  int get reRegistrationCount => _reRegistrationCount;

  /// The token the last successful registration sent, or `null`.
  String? get registeredToken => _registeredToken;

  /// Notifications the user opened from the tray, for a deep link.
  ///
  /// Passed through rather than re-published, so a GetX controller can bind
  /// straight to it and the app decides what a payload opens.
  Stream<PushNotificationOpened> get openedNotifications =>
      _provider.openedFromNotification;

  /// Requests permission and registers the device.
  ///
  /// Returns the registered row, or `null` when the platform gave no token --
  /// the user refused, or there is no push service. A `null` is **not** an error
  /// and **nothing is dispatched**: posting an absent `fcm_token` would
  /// overwrite a good token on the row with a null and stop push for a device
  /// that was working.
  ///
  /// Remember [deviceId]. `POST /auth/logout` deactivates every row for the
  /// account, and calling this again is how this device comes back.
  Future<UserDevice?> register({required String deviceId}) async {
    final String? token = await _provider.requestPermissionAndGetToken();

    if (token == null || token.isEmpty) {
      onWarning?.call(
        'no push token: the user refused, or this platform has no push '
        'service. Nothing was posted and no existing registration was changed.',
      );
      return null;
    }

    _deviceId = deviceId;
    _registrationCount += 1;

    try {
      final UserDevice device = await _auth.registerDevice(
        deviceId: deviceId,
        platform: platform,
        fcmToken: token,
        appVersi: appVersi,
      );

      _registeredToken = token;
      onRegistered?.call(device);

      return device;
    } on ApiException catch (error) {
      onFailed?.call(error);
      rethrow;
    }
  }

  /// Starts re-registering on every token rotation.
  ///
  /// Idempotent, and safe to call from a screen's `initState`: a second call
  /// while one is already running is a no-op rather than a second listener that
  /// posts the same token twice.
  void start() {
    if (_refreshSubscription != null) {
      return;
    }

    _refreshSubscription = _provider.tokenRefreshes.listen(
      (String token) => unawaited(_onTokenRefreshed(token)),
      onError: (Object error) =>
          onWarning?.call('the token-refresh stream failed: $error'),
    );
  }

  /// Stops re-registering and releases the provider.
  ///
  /// Idempotent, for the same reason [RealtimeClient.dispose] is: a controller
  /// can be torn down twice on a real route change (a pop and a logout landing
  /// together), and [PushTokenProvider.dispose] is a plain `void` whose contract
  /// says it releases the provider's own resources -- it is not required to
  /// survive being called twice. A real adapter that cancels a subscription and
  /// deletes an FCM token should not have to be defensive about its caller, and
  /// this class is the one holding the reference.
  ///
  /// The subscription is cancelled rather than abandoned: a listener left on
  /// the provider keeps registering after the screen that wanted it is gone,
  /// and every later rotation posts a token for a device the app no longer
  /// considers signed in.
  void dispose() {
    if (_isDisposed) {
      return;
    }

    _isDisposed = true;

    final StreamSubscription<String>? subscription = _refreshSubscription;

    _refreshSubscription = null;

    if (subscription != null) {
      unawaited(subscription.cancel());
    }

    _provider.dispose();
  }

  Future<void> _onTokenRefreshed(String token) async {
    final String? deviceId = _deviceId;

    if (deviceId == null) {
      // A rotation before any registration recorded a device id. There is
      // nothing to post against, and inventing one would create a
      // `user_devices` row for a device nobody identified. The next
      // [register] picks the new token up anyway, because [register] asks the
      // provider rather than reading a cached token.
      onWarning?.call(
        'the push token rotated before this device was registered; nothing to '
        'update. The next register() reads the current token from the provider.',
      );
      return;
    }

    _reRegistrationCount += 1;
    _registrationCount += 1;

    try {
      final UserDevice device = await _auth.registerDevice(
        deviceId: deviceId,
        platform: platform,
        fcmToken: token,
        appVersi: appVersi,
      );

      _registeredToken = token;
      onRegistered?.call(device);
    } on ApiException catch (error) {
      onFailed?.call(error);
      onWarning?.call('re-registration failed: ${error.message}');
    }
  }
}
