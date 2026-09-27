import 'dart:async';
import 'dart:collection';

import '../storage/token_storage.dart';
import 'chat_message.dart';
import 'realtime_socket.dart';

/// Resolves the headers a subscribe is authorised with, **at the moment it is
/// called**.
///
/// The return type is a `Future` because the correct implementation reads a
/// platform keychain, and a keychain read is not synchronous. It is a callback
/// and not a `Map` because the value has a short life: the access token
/// rotates, so a map captured once is a dead credential by the second subscribe.
typedef RealtimeAuthHeaders = Future<Map<String, String>> Function();

/// Supplies the history a resync needs, and may return `null` to supply none.
///
/// Returning the page rather than having the client fetch it is deliberate. The
/// REST call is `GET /konsultasi/{id}/chat` on the plan's todo 32, it does not
/// exist yet, and it needs the application's own pagination and error handling --
/// all of which [SehatlyApiClient] already has. So the client asks, and the app
/// answers with the rows, and the client keeps the part that only it can do:
/// merging them into the dedupe set so the overlap is not rendered twice.
typedef RealtimeResyncCallback = FutureOr<Iterable<ChatMessage>?> Function(
  RealtimeResync resync,
);

/// What the client tells the caller after a reconnect.
class RealtimeResync {
  /// Describes the [attempt]-th resync at [occurredAt].
  RealtimeResync({
    required this.attempt,
    required List<String> channels,
    required this.occurredAt,
  }) : channels = List<String>.unmodifiable(channels);

  /// Which reconnect this is, counting from 1.
  ///
  /// It is in the object rather than only in a counter so a caller that shows a
  /// "reconnected" toast twice can tell the two apart, and so a resync that
  /// repeats itself -- a flapping network produces one every few seconds -- is
  /// visible as a rising number rather than as one indistinguishable event.
  final int attempt;

  /// The wire names that were re-subscribed, e.g. `private-konsultasi.5`.
  final List<String> channels;

  /// When the reconnect was observed.
  final DateTime occurredAt;

  @override
  String toString() =>
      'RealtimeResync(attempt: $attempt, channels: ${channels.join(', ')})';
}

/// One live channel subscription, as the client tracks it.
class RealtimeSubscription {
  /// Tracks a subscription to [channelName] published as [wireName].
  RealtimeSubscription({
    required this.channelName,
    required this.wireName,
    required this.eventName,
  });

  /// The logical channel name, e.g. `konsultasi.5`.
  final String channelName;

  /// The name the broker sees, e.g. `private-konsultasi.5`.
  final String wireName;

  /// The bound event name, e.g. `chat.pesan`.
  final String eventName;

  /// How many subscribe attempts this subscription has made, successful or not.
  int attempts = 0;

  /// Why the most recent attempt failed, or `null` if it has not failed.
  String? lastFailure;

  @override
  String toString() =>
      'RealtimeSubscription($wireName, event: $eventName, '
      'attempts: $attempts)';
}

/// The realtime layer: a private-channel chat listener that survives a
/// reconnect and never renders the same message twice.
///
/// ## What this class is responsible for
///
/// Three things, and they are the three things a naive socket wrapper gets
/// wrong:
///
/// 1. **The access token is re-read on every subscribe attempt.** Not cached at
///    construction. See [_dispatchSubscribe] and [bearerAuthHeaders].
/// 2. **A message is delivered once.** Keyed on `konsultasi_chat.id`, seeded from
///    the REST history so the overlap between a resync and a live frame is
///    suppressed. See [_remember].
/// 3. **A reconnect re-subscribes and then asks for the gap to be backfilled.**
///    See [_onSignal] and [RealtimeResyncCallback].
///
/// The transport is [RealtimeSocket], not a socket library. That is what makes
/// the three testable without a broker, and it is why this package declares no
/// Pusher dependency; see `realtime_socket.dart` and the README.
///
/// ## The refresh contract, and why this class never rotates a token
///
/// `POST /auth/refresh` **rotates both tokens**, the presented refresh token is
/// **revoked on every use**, and a *replayed* one revokes **every live refresh
/// token for the account**. A subscribe has no safe way to rotate:
///
/// - it may be one of several concurrent subscribes, and two of them rotating
///   would make the second look like a theft, signing the user out of every
///   device;
/// - a subscribe that failed and retried would spend a one-shot token to learn
///   the same thing twice.
///
/// The default `authHeaders` only **reads** the store. A subscribe against an
/// expired token is refused by the broker, and the right recovery is the
/// application's own guarded rotation -- `SehatlyApiClient.rotateIfExpired()`,
/// which does nothing when the access token has not expired. **This class never
/// calls `POST /auth/refresh`, and a subscribe failure is terminal here**: it is
/// reported through [onFailure] and [RealtimeSubscription.lastFailure], and the
/// only two things that will try again are an explicit
/// [RealtimeClient.resubscribe] and a reconnect. There is no retry loop to
/// bound, because there is no loop.
///
/// ## A message with no id is delivered, not suppressed
///
/// [ChatMessage.id] is `0` when a frame carried none. Suppressing those would
/// silently drop every message from a server that stopped publishing the key,
/// which is the worst possible failure mode for a chat: it looks like a working
/// client that is quiet. So an unidentified message is emitted and counted in
/// [unidentifiedEventCount] instead.
class RealtimeClient {
  /// Attaches to [socket], reading the access token from [storage].
  ///
  /// [onResync] is invoked after every reconnect, once the channels are live
  /// again, and its return value seeds the dedupe set. [onFailure] receives every
  /// subscribe failure and every transport failure.
  ///
  /// [dedupeCapacity] bounds the dedupe set; see [_remember] for why it is
  /// bounded and why the bound is generous. [now] is injectable so a test can
  /// assert on [RealtimeResync.occurredAt] without a clock.
  RealtimeClient({
    required RealtimeSocket socket,
    required TokenStorage storage,
    RealtimeChannelKind channelKind = RealtimeChannelKind.privateChannel,
    String authEndpoint = broadcastAuthEndpoint,
    RealtimeAuthHeaders? authHeaders,
    RealtimeResyncCallback? onResync,
    void Function(String reason)? onFailure,
    int dedupeCapacity = defaultDedupeCapacity,
    DateTime Function()? now,
  }) : _socket = socket,
       _storage = storage,
       _channelKind = channelKind,
       _authEndpoint = authEndpoint,
       _customAuthHeaders = authHeaders,
       onResync = onResync,
       onFailure = onFailure ?? _ignore,
       _dedupeCapacity = dedupeCapacity < 1 ? 1 : dedupeCapacity,
       _now = now ?? DateTime.now {
    _signalSubscription = _socket.signals.listen(_onSignal);
    _eventSubscription = _socket.events.listen(_onEvent);
  }

  /// How many ids the dedupe set holds before it evicts the oldest.
  ///
  /// A chat transcript for a whole consultation is hundreds of rows, so 500
  /// covers a busy session. The set is **not** unbounded because a client left
  /// open for weeks would otherwise hold every message id it ever saw; and it is
  /// not small because an id evicted too eagerly stops suppressing a duplicate
  /// that arrives inside the resync window, which is the one duplicate that
  /// matters.
  static const int defaultDedupeCapacity = 500;

  final RealtimeSocket _socket;
  final TokenStorage _storage;
  final RealtimeChannelKind _channelKind;
  final String _authEndpoint;

  /// The caller's own header provider, or `null` to read [storage].
  ///
  /// Held as a **nullable callback** rather than a resolved function, so the
  /// resolution happens in [_resolveAuthHeaders] at the moment of a subscribe.
  /// A resolved function built once in the constructor would be equally stale
  /// if it closed over a token; holding the call keeps the store as the single
  /// source of truth.
  final RealtimeAuthHeaders? _customAuthHeaders;
  final int _dedupeCapacity;
  final DateTime Function() _now;

  /// Invoked after a reconnect, once every channel is live again.
  ///
  /// A mutable field rather than a constructor argument because the natural
  /// caller is a controller that does not exist yet when the client is built --
  /// the client is created at start-up, the controller when the consultation
  /// screen is entered. Install it there; `null` means a reconnect re-subscribes
  /// and nothing else, which leaves a gap.
  RealtimeResyncCallback? onResync;

  /// Invoked on every subscribe failure and every transport failure.
  void Function(String reason) onFailure;

  final Map<String, RealtimeSubscription> _subscriptions =
      <String, RealtimeSubscription>{};

  /// Insertion-ordered so eviction is "oldest first" and not "arbitrary".
  final LinkedHashSet<int> _seen = LinkedHashSet<int>();

  final StreamController<ChatMessage> _messages =
      StreamController<ChatMessage>.broadcast();

  /// Both are cancelled in [dispose]. The `cancel_subscriptions` hits below are
  /// that lint's known limitation rather than a real leak: it recognises a
  /// cancel only in the same function that created the subscription, and a
  /// lifecycle-owned listener never is one. The cancel goes through a local in
  /// [dispose] so the null-check and the cancel cannot disagree.
  // ignore: cancel_subscriptions
  StreamSubscription<RealtimeSocketSignal>? _signalSubscription;
  // ignore: cancel_subscriptions
  StreamSubscription<RealtimeEvent>? _eventSubscription;

  bool _isConnected = false;
  bool _isDisposed = false;
  int _disconnectCount = 0;
  int _resyncCount = 0;
  int _subscribeAttemptCount = 0;
  int _duplicateSuppressedCount = 0;
  int _unidentifiedEventCount = 0;

  /// Chat messages, deduplicated, in arrival order.
  ///
  /// Broadcast, so a GetX controller, a badge counter and a transcript view can
  /// all listen without any of them stealing events from the others.
  Stream<ChatMessage> get messages => _messages.stream;

  /// The wire name a consultation is published under, e.g.
  /// `private-konsultasi.5`.
  ///
  /// Exposed so a caller building a log line or a debug overlay names the same
  /// string the broker does, rather than guessing at the prefix rule.
  String wireNameFor(int konsultasiId) =>
      _channelKind.wireName(konsultasiChannel(konsultasiId));

  /// Opens the transport.
  ///
  /// A thin, named pass-through to [RealtimeSocket.connect] rather than an
  /// implicit side effect of subscribing, for one reason: a subscribe is
  /// authorised over HTTP, and a transport that opened its socket inside that
  /// `await` would hold a connection open across a keychain read. A caller
  /// decides when the socket opens; this method just says so in one place.
  ///
  /// Idempotent, exactly as [RealtimeSocket.connect] is.
  void connect() {
    _assertUsable();
    _socket.connect();
  }

  /// Closes the transport without forgetting the subscriptions.
  ///
  /// The subscriptions stay registered, so a later [connect] or [resubscribe]
  /// brings the same channels back. Use [dispose] when the client itself is
  /// finished with.
  void disconnect() {
    _socket.disconnect();
  }

  /// Whether the socket is up.
  bool get isConnected => _isConnected;

  /// How many times the socket has dropped.
  int get disconnectCount => _disconnectCount;

  /// How many resyncs have been performed.
  int get resyncCount => _resyncCount;

  /// How many subscribe attempts have been dispatched, in total.
  ///
  /// The honest denominator for "how many times did we authorise a channel",
  /// which is the number a rate-limited auth endpoint is actually counting.
  int get subscribeAttemptCount => _subscribeAttemptCount;

  /// How many messages were withheld as duplicates.
  int get duplicateSuppressedCount => _duplicateSuppressedCount;

  /// How many messages were delivered without an id, and so could not be
  /// deduplicated.
  int get unidentifiedEventCount => _unidentifiedEventCount;

  /// The live subscriptions, keyed by logical channel name.
  Map<String, RealtimeSubscription> get subscriptions =>
      Map<String, RealtimeSubscription>.unmodifiable(_subscriptions);

  /// Subscribes to a consultation's chat channel.
  ///
  /// Registers the subscription **synchronously**, then resolves the auth
  /// headers and dispatches. Registering first is what makes a reconnect
  /// arriving before the keychain read finishes still find the channel and
  /// re-subscribe it.
  ///
  /// Throws [RealtimeSubscribeException] if the transport refused. The
  /// subscription stays registered, so a later reconnect retries it; see the
  /// class docblock for why nothing retries it sooner.
  Future<RealtimeSubscription> subscribeKonsultasi({
    required int konsultasiId,
    String eventName = chatPesanEvent,
  }) async {
    _assertUsable();

    final String channel = konsultasiChannel(konsultasiId);
    final RealtimeSubscription subscription = _subscriptions.putIfAbsent(
      channel,
      () => RealtimeSubscription(
        channelName: channel,
        wireName: _channelKind.wireName(channel),
        eventName: eventName,
      ),
    );

    await _dispatchSubscribe(subscription);

    return subscription;
  }

  /// Drops a subscription and tells the transport to unsubscribe.
  void unsubscribe(int konsultasiId) {
    final String channel = konsultasiChannel(konsultasiId);

    if (_subscriptions.remove(channel) == null) {
      return;
    }

    _socket.unsubscribe(channel);
  }

  /// Re-dispatches every live subscription, re-reading the auth headers.
  ///
  /// The caller-driven half of the recovery path. A reconnect does the same
  /// thing on its own; this exists for the case the reconnect cannot cover --
  /// the token rotated while the socket stayed up, and every channel is now
  /// subscribed under a token that no longer exists.
  Future<void> resubscribe() async {
    _assertUsable();

    for (final RealtimeSubscription subscription
        in _subscriptions.values.toList(growable: false)) {
      await _dispatchSubscribe(subscription);
    }
  }

  /// Teaches the dedupe set about messages the app already has.
  ///
  /// Call it with the rows the app already rendered from its chat history
  /// fetch. Every
  /// id is remembered, so a frame that arrives over the socket for a message
  /// already on screen is suppressed. This is the "deduplicate against the REST
  /// history" rule, and it is a separate call from [onResync] because a client
  /// that loads history on a cold start has to do it too -- there was no
  /// reconnect to hang it off.
  void seedHistory(Iterable<ChatMessage> messages) {
    for (final ChatMessage message in messages) {
      if (message.id > 0) {
        _seen.add(message.id);
      }
    }

    _evictIfNeeded();
  }

  /// Emits the backfilled rows this client has never delivered, and remembers
  /// the rest.
  ///
  /// ## Why this is not [seedHistory]
  ///
  /// The two look alike and are opposites. [seedHistory] is for rows the app has
  /// **already rendered**, so remembering them and staying silent is correct.
  /// A resync's backfill is the opposite: it is the window the broker never
  /// replayed, so at least part of it has never been rendered. Seeding instead of
  /// delivering marks a message known and then throws it away -- the client
  /// reports a healthy transcript that is silently missing everything sent while
  /// the socket was down, which is the one failure this whole path exists to
  /// prevent.
  ///
  /// So each row is put through the same [_remember] gate a live frame goes
  /// through: an id already seen is withheld, an id that is new is delivered, and
  /// an id of `0` is delivered and counted in [unidentifiedEventCount] rather
  /// than suppressed. A resync that re-fetches a page the app has partly on
  /// screen therefore neither loses the gap nor renders the overlap twice.
  void _deliverBackfill(Iterable<ChatMessage> messages) {
    for (final ChatMessage message in messages) {
      if (message.id == 0) {
        _unidentifiedEventCount += 1;
        _messages.add(message);
        continue;
      }

      if (_remember(message.id)) {
        _messages.add(message);
      }
    }
  }

  /// Whether [message]'s id has already been delivered or suppressed.
  ///
  /// For a renderer that wants to skip a row it fetched itself.
  bool hasSeen(int messageId) => _seen.contains(messageId);

  /// Stops listening and closes [messages].
  ///
  /// Both stream subscriptions are cancelled, not just the output controller: a
  /// client left attached to a transport keeps a reference to every frame the
  /// transport produces for as long as the transport lives, which outlives the
  /// screen that created it.
  ///
  /// The transport is **not** closed here. The socket belongs to whoever built
  /// it, and one socket may outlive several clients; call [disconnect] first to
  /// close it. Disposing a client that shares a transport must not take the other
  /// clients' channels down with it.
  void dispose() {
    if (_isDisposed) {
      return;
    }

    _isDisposed = true;

    final StreamSubscription<RealtimeSocketSignal>? signals =
        _signalSubscription;
    final StreamSubscription<RealtimeEvent>? frames = _eventSubscription;

    _signalSubscription = null;
    _eventSubscription = null;

    if (signals != null) {
      unawaited(signals.cancel());
    }

    if (frames != null) {
      unawaited(frames.cancel());
    }

    _subscriptions.clear();
    unawaited(_messages.close());
  }

  /// Resolves the headers for one subscribe attempt.
  ///
  /// The caller's callback when one was supplied, otherwise a read of the
  /// store. Either way it is *called* here, per attempt, and the resolved map
  /// goes straight into the request and is not kept.
  Future<Map<String, String>> _resolveAuthHeaders() async {
    final RealtimeAuthHeaders? custom = _customAuthHeaders;

    if (custom != null) {
      return custom();
    }

    return _storeBackedHeaders(_storage);
  }

  /// Dispatches one subscribe, resolving the headers **here** and not earlier.
  ///
  /// ## The one line that makes a rotated token work
  ///
  /// [_resolveAuthHeaders] is a call, not a value. Nothing in this class holds a
  /// resolved header map between attempts, so the second subscribe after a
  /// rotation necessarily reads the new token -- there is no field a stale copy
  /// could be hiding in. The alternative, `final headers = await authHeaders()`
  /// in the constructor, produces a client that authenticates its first channel
  /// correctly and then fails every channel after the first rotation, which
  /// looks exactly like a server-side authorisation bug.
  Future<void> _dispatchSubscribe(RealtimeSubscription subscription) async {
    subscription.attempts += 1;
    _subscribeAttemptCount += 1;

    try {
      final Map<String, String> headers = await _resolveAuthHeaders();

      await _socket.subscribe(
        RealtimeSubscribeRequest(
          channelName: subscription.channelName,
          wireName: subscription.wireName,
          authHeaders: headers,
          authEndpoint: _authEndpoint,
          events: <String>[subscription.eventName],
        ),
      );

      subscription.lastFailure = null;
    } catch (error) {
      // Terminal here by construction: nothing below re-dispatches, and this
      // method has no loop. Recorded rather than swallowed so a caller can show
      // a "chat unavailable" state instead of a transcript that never updates.
      final String reason =
          'subscribe to ${subscription.wireName} failed: $error';
      subscription.lastFailure = reason;
      onFailure(reason);

      throw RealtimeSubscribeException(
        wireName: subscription.wireName,
        cause: error,
      );
    }
  }

  void _onSignal(RealtimeSocketSignal signal) {
    if (_isDisposed) {
      return;
    }

    switch (signal) {
      case RealtimeConnected():
        _isConnected = true;
      case RealtimeDisconnected():
        _isConnected = false;
        _disconnectCount += 1;
      case RealtimeReconnected():
        _isConnected = true;
        unawaited(_resume());
      case RealtimeSocketFailure(:final String message):
        onFailure(message);
    }
  }

  /// Re-subscribes every channel, then asks the caller to backfill the gap.
  ///
  /// ## The order is load-bearing: re-subscribe first, resync second
  ///
  /// The broker begins delivering on a channel as soon as the subscribe is
  /// confirmed, and [onResync] is where the app fetches history over REST. If
  /// the resync ran first, a message sent during the fetch would arrive live
  /// *before* the history page it belongs to, and the transcript would render
  /// it out of order. Re-subscribing first means the live path is already up
  /// when the history lands, and the dedupe set -- which the resync seeds --
  /// then suppresses the overlap in whichever order it arrives.
  ///
  /// The gap itself is real and unavoidable: the broker does not replay, so
  /// anything broadcast while the socket was down exists only in the database.
  /// The resync is therefore not a fallback, it is the only way those messages
  /// reach the client at all.
  Future<void> _resume() async {
    _resyncCount += 1;

    final List<String> channels = <String>[
      for (final RealtimeSubscription subscription in _subscriptions.values)
        subscription.wireName,
    ];

    for (final RealtimeSubscription subscription
        in _subscriptions.values.toList(growable: false)) {
      try {
        await _dispatchSubscribe(subscription);
      } on Object {
        // Already reported by [_dispatchSubscribe], with the wire name and the
        // transport's own error, so reporting it again here would double every
        // failure in the caller's log. What this catch buys is the *loop
        // survives*: one dead channel must not stop the others being
        // re-subscribed, or a single revoked consultation would silence every
        // channel on the connection.
      }
    }

    final RealtimeResyncCallback? callback = onResync;

    if (callback == null) {
      return;
    }

    try {
      final Iterable<ChatMessage>? backfill = await callback(
        RealtimeResync(
          attempt: _resyncCount,
          channels: channels,
          occurredAt: _now(),
        ),
      );

      if (backfill != null) {
        _deliverBackfill(backfill);
      }
    } on Object catch (error) {
      // A failed backfill costs the gap and nothing else: the live path is up,
      // and the next reconnect tries again. Surfaced rather than swallowed so
      // the app can show that history is stale.
      onFailure('resync: history backfill failed ($error)');
    }
  }

  void _onEvent(RealtimeEvent event) {
    if (_isDisposed) {
      return;
    }

    final RealtimeSubscription? subscription =
        _subscriptions[event.channelName];

    if (subscription == null || subscription.eventName != event.eventName) {
      return;
    }

    final ChatMessage message = ChatMessage.fromJson(event.data);

    if (message.id == 0) {
      _unidentifiedEventCount += 1;
      _messages.add(message);
      return;
    }

    if (!_remember(message.id)) {
      return;
    }

    _messages.add(message);
  }

  /// Records [id] as delivered, or reports that it already was.
  ///
  /// This is the whole of the dedupe rule, and the key is `konsultasi_chat.id`:
  /// the primary key of the row, so the same row is the same id whether it
  /// arrived over the socket, out of a history page, or twice from the broker
  /// after a re-subscribe. Keying on anything else -- the payload, the arrival
  /// time, the sender -- treats "the same text sent twice" as one message and
  /// drops a real one.
  bool _remember(int id) {
    if (_seen.contains(id)) {
      _duplicateSuppressedCount += 1;
      return false;
    }

    _seen.add(id);
    _evictIfNeeded();

    return true;
  }

  void _evictIfNeeded() {
    // `_seen` is insertion-ordered, so its first element is the oldest entry.
    // Not a strict LRU -- a re-delivered old id is not promoted back to the
    // end -- which is the right trade: an exact LRU needs a list plus a map
    // keyed by id, and the only duplicate that matters is one arriving shortly
    // after the first delivery, which insertion order already covers.
    while (_seen.length > _dedupeCapacity) {
      _seen.remove(_seen.first);
    }
  }

  /// The default [RealtimeAuthHeaders]: a read of the store, and nothing else.
  ///
  /// ## Why no `Authorization` key when there is no token
  ///
  /// An empty `Bearer` is a credential-shaped string that is guaranteed to be
  /// refused, and it turns "you are signed out" into an opaque 403 that looks
  /// like a channel-authorisation bug. Omitting the header sends an
  /// unauthenticated request, which the auth endpoint answers as a plain
  /// authentication failure -- a state the app already knows how to detect,
  /// because it is the same shape as a REST request with no session.
  static Future<Map<String, String>> _storeBackedHeaders(
    TokenStorage storage,
  ) async {
    final Map<String, String> headers = <String, String>{
      'Accept': 'application/json',
    };

    final String? token = await storage.accessTokens.readAccessToken();

    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }

    return headers;
  }

  void _assertUsable() {
    if (_isDisposed) {
      throw StateError(
        'RealtimeClient was disposed. Build a new one; the socket and the '
        'dedupe set do not survive dispose().',
      );
    }
  }

  static void _ignore(String _) {
    // The default for [onFailure]. A failure nobody asked about must not
    // become an unhandled async error, and this class has no business deciding
    // what a mobile app does with a log line.
  }
}

/// Thrown when a subscribe is refused by the transport.
class RealtimeSubscribeException implements Exception {
  /// Wraps [cause] from subscribing to [wireName].
  const RealtimeSubscribeException({
    required this.wireName,
    required this.cause,
  });

  /// The wire name that was being subscribed.
  final String wireName;

  /// The transport's failure.
  final Object cause;

  @override
  String toString() => 'RealtimeSubscribeException($wireName: $cause)';
}
