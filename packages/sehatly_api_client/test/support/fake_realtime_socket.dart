import 'dart:async';

import 'package:sehatly_api_client/sehatly_api_client.dart';

/// A [RealtimeSocket] that opens no socket, listens on no port, and records
/// every subscribe verbatim.
///
/// ## Why the recorded request, and not just a counter
///
/// The three claims the realtime tests make are all about *what was sent*: that
/// the wire name is `private-`-prefixed, that the second subscribe carried a
/// rotated token, and that the auth endpoint is the API-group one. A counter
/// answers none of them -- `subscribeCallCount == 2` is equally true of a
/// client that sent the same stale token twice. So the fake keeps the whole
/// [RealtimeSubscribeRequest] and the assertions read the headers.
///
/// The event and signal streams are broadcast controllers, so the test drives
/// the broker side by pushing into them, which is the closest a pure-Dart test
/// can get to a broker replaying a frame.
class FakeRealtimeSocket implements RealtimeSocket {
  /// Creates a fake, optionally already "connected".
  FakeRealtimeSocket({bool isConnected = false}) {
    if (isConnected) {
      connect();
    }
  }

  final StreamController<RealtimeSocketSignal> _signals =
      StreamController<RealtimeSocketSignal>.broadcast();

  final StreamController<RealtimeEvent> _events =
      StreamController<RealtimeEvent>.broadcast();

  /// Every subscribe, in order, with the headers as they were at the attempt.
  final List<RealtimeSubscribeRequest> subscribeRequests =
      <RealtimeSubscribeRequest>[];

  /// Every `unsubscribe` call, in order.
  final List<String> unsubscribed = <String>[];

  /// How many times [connect] was called.
  int connectCount = 0;

  /// Whether the fake believes it is connected.
  bool get isConnected => _connected;
  bool _connected = false;

  /// Thrown by [subscribe] for **every** channel when set, to model a refused
  /// authorisation.
  Object? subscribeFailure;

  /// Thrown by [subscribe] only for the wire names this predicate accepts.
  ///
  /// ## Why this exists and [subscribeFailure] is not enough
  ///
  /// The claim under test is that one dead channel does not stop the others
  /// being re-subscribed. A single global failure cannot express it: it fails
  /// every channel, so both subscribes on a two-channel client throw and the
  /// test proves only that the loop does not abort -- not that a healthy channel
  /// is still re-subscribed while a sick one is refused. A predicate names the
  /// sick one, which is what makes the assertion mean anything.
  Object? Function(RealtimeSubscribeRequest request)? subscribeFailureFor;

  /// Gates [subscribe] until its future completes, so a test can hold a
  /// subscribe open and drive a reconnect underneath it.
  Future<void>? subscribeGate;

  @override
  Stream<RealtimeSocketSignal> get signals => _signals.stream;

  @override
  Stream<RealtimeEvent> get events => _events.stream;

  @override
  void connect() {
    connectCount += 1;
    _connected = true;
  }

  @override
  void disconnect() {
    _connected = false;
  }

  @override
  Future<void> subscribe(RealtimeSubscribeRequest request) async {
    final Future<void>? gate = subscribeGate;

    if (gate != null) {
      await gate;
    }

    subscribeRequests.add(request);

    final Object? perChannel = subscribeFailureFor?.call(request);

    if (perChannel != null) {
      throw perChannel;
    }

    final Object? failure = subscribeFailure;

    if (failure != null) {
      throw failure;
    }
  }

  @override
  void unsubscribe(String channelName) {
    unsubscribed.add(channelName);
  }

  /// The most recent subscribe's `Authorization` header, or `null`.
  String? get lastAuthorization {
    for (final MapEntry<String, String> entry
        in subscribeRequests.last.authHeaders.entries) {
      if (entry.key.toLowerCase() == 'authorization') {
        return entry.value;
      }
    }

    return null;
  }

  /// The `Authorization` header of the subscribe at [index], or `null`.
  ///
  /// Index 0 is the first subscribe, so a test can assert the *first* attempt
  /// carried one token and the *second* carried another. Reading `last` twice
  /// would prove nothing.
  String? authorizationAt(int index) {
    final RealtimeSubscribeRequest request = subscribeRequests[index];

    for (final MapEntry<String, String> entry in request.authHeaders.entries) {
      if (entry.key.toLowerCase() == 'authorization') {
        return entry.value;
      }
    }

    return null;
  }

  /// Reports a first connection.
  void emitConnected() => _signals.add(const RealtimeConnected());

  /// Reports a disconnect.
  void emitDisconnected() {
    _connected = false;
    _signals.add(const RealtimeDisconnected());
  }

  /// Reports a successful reconnect.
  void emitReconnected() {
    _connected = true;
    _signals.add(const RealtimeReconnected());
  }

  /// Reports a transport failure.
  void emitFailure(String message) =>
      _signals.add(RealtimeSocketFailure(message));

  /// Delivers a frame on [channelName].
  ///
  /// Deliberately not dedupe-aware: a real broker re-delivers, and the test
  /// needs a genuine duplicate, not a simulated one.
  void emit(
    Map<String, Object?> data, {
    String channelName = 'konsultasi.5',
    String eventName = chatPesanEvent,
  }) {
    _events.add(
      RealtimeEvent(channelName: channelName, eventName: eventName, data: data),
    );
  }

  /// Closes both controllers, so a test leaks no listener.
  Future<void> close() async {
    await _signals.close();
    await _events.close();
  }
}

/// A [PushTokenProvider] driven by hand, with no `firebase_messaging`.
///
/// `disposeCount` is public because the registration test asserts that
/// [PushRegistration.dispose] really reaches the provider: a `dispose` that
/// only cancels its own subscription leaves a live FCM listener behind, and that
/// is the leak a lifecycle test exists to catch.
class FakePushTokenProvider implements PushTokenProvider {
  /// Creates a provider that will hand out [token].
  FakePushTokenProvider({this.token = 'fcm-token-1'});

  /// What [requestPermissionAndGetToken] returns, or `null` to model a refusal.
  String? token;

  /// How many times a token was asked for.
  int requestCount = 0;

  /// How many times [dispose] was called.
  int disposeCount = 0;

  /// When set, [requestPermissionAndGetToken] throws it instead.
  Object? requestFailure;

  final StreamController<String> _refreshes =
      StreamController<String>.broadcast();

  final StreamController<PushNotificationOpened> _opened =
      StreamController<PushNotificationOpened>.broadcast();

  @override
  Future<String?> requestPermissionAndGetToken() async {
    requestCount += 1;

    final Object? failure = requestFailure;

    if (failure != null) {
      throw failure;
    }

    return token;
  }

  @override
  Stream<String> get tokenRefreshes => _refreshes.stream;

  @override
  Stream<PushNotificationOpened> get openedFromNotification => _opened.stream;

  @override
  void dispose() {
    disposeCount += 1;
  }

  /// Emits a platform-side token rotation.
  void emitTokenRefresh(String fresh) {
    token = fresh;
    _refreshes.add(fresh);
  }

  /// Emits a notification the user opened from the tray.
  void emitOpened(Map<String, Object?> data, {String? messageId}) {
    _opened.add(PushNotificationOpened(data: data, messageId: messageId));
  }

  /// Closes both controllers.
  Future<void> close() async {
    await _refreshes.close();
    await _opened.close();
  }
}
