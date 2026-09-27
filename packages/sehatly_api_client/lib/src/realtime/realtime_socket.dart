/// The transport seam: the Pusher protocol, expressed as an interface.
///
/// ## Why this is an interface and not a socket library
///
/// Reverb and Soketi both speak the **Pusher** protocol, so any Pusher-protocol
/// client can talk to this application's broadcaster -- and that is the whole
/// reason the wire format is the stable part and the socket library is not. This
/// file names the five things a transport has to be able to do (connect,
/// subscribe with headers, publish a bound event, report lifecycle, unsubscribe)
/// and nothing else. [RealtimeClient] is written against that, so:
///
/// - the client is unit-testable against a fake, with no port open and no
///   broker running, and
/// - the transport can be swapped, or upgraded, without touching a line of
///   [RealtimeClient].
///
/// `laravel_reverb` is the transport the plan nominates. It is **not** a
/// dependency of this package, for two reasons that are both about this
/// repository rather than about taste: the broadcaster does not exist yet --
/// `config/broadcasting.php` is absent, no `Broadcast::routes()` call is
/// registered under `app/`, and no class implements `ShouldBroadcast` -- and
/// adding all three is the plan's todo 31; and `laravel_reverb` is not in this
/// machine's pub cache, so `dart pub get` cannot resolve it here. Binding a real
/// socket is a few lines against this interface, and the README carries the
/// block verbatim; see that file for the adapter.
///
/// ## What the protocol actually guarantees, and the two things it does not
///
/// It guarantees ordered delivery **within one connection**, and nothing about
/// what happens across a disconnect. Two consequences shape [RealtimeClient]:
///
/// 1. **The broker does not replay.** A message broadcast while the socket was
///    down is not buffered for the client, so the gap has to be closed over REST
///    by the caller. [RealtimeClient.onResync] exists for exactly that.
/// 2. **A re-subscribe can re-deliver.** Re-sending the subscribe request makes
///    the broker replay whatever it considers in flight for that channel, so the
///    same `konsultasi_chat.id` can arrive twice. Dedupe is therefore keyed on
///    the message id and not on arrival order.
/// 3. **An authorisation failure is a normal outcome, not a crash.** A revoked
///    consultation, a signed-out session and a rate-limited auth endpoint all
///    surface as a refused subscribe, and one refused channel must not silence
///    the others on the same connection.
///
/// ## The channel name and the wire name are two different strings
///
/// On the wire a private channel carries a `private-` prefix that the protocol
/// and the client library both add, so the string this package passes around is
/// the **logical** one -- `konsultasi.5` -- and the broker sees
/// `private-konsultasi.5`. Code that writes `private-` itself produces
/// `private-private-konsultasi.5`, a channel that never exists.
/// [RealtimeChannelKind.wireName] is the only place the prefix is applied, and a
/// channel name in this package is always the logical one.
///
/// The logical name is a contract with the server's channel-authorisation
/// callback, which does not exist yet: there is no `routes/channels.php` in this
/// repository, and registering `konsultasi.{id}` there is the plan's todo 31.
/// Until it lands, the name is what the plan specifies and what a later todo
/// either confirms or changes in one line here.
library;

import 'dart:async';

/// The prefix the Pusher protocol puts in front of a private channel name.
const String privateChannelPrefix = 'private-';

/// The prefix the Pusher protocol puts in front of a presence channel name.
const String presenceChannelPrefix = 'presence-';

/// The channel a consultation's chat is broadcast on, without any wire prefix.
///
/// This is the string `routes/channels.php` authorises and the string
/// [RealtimeClient] passes to the transport, both unprefixed. The wire name is
/// [privateChannelPrefix] + this, so a subscription to consultation 5 is
/// `konsultasi.5` here and `private-konsultasi.5` on the wire.
String konsultasiChannel(int konsultasiId) => 'konsultasi.$konsultasiId';

/// The event name a chat message is broadcast under.
///
/// `chat.pesan` is the name the plan's todo 31 specifies; the event class that
/// will publish it does not exist yet (a search of `app/` finds no
/// `ShouldBroadcast` implementation).
///
/// The Pusher protocol carries the broadcast name **prefixed with a dot**, so
/// the leading `.` a raw frame's `event` field carries is not part of the name a
/// client binds. A client that binds `.chat.pesan` waits for a channel that
/// never fires, and the symptom is a subscription that confirms and a transcript
/// that never updates.
const String chatPesanEvent = 'chat.pesan';

/// The endpoint the socket authorises a private or presence channel against.
///
/// ## Why the path is a constant here, and why it carries `api`
///
/// A phone authenticates with a Sanctum bearer token and has no session cookie,
/// so it cannot use a route that is behind the web middleware group's session
/// check. The `/api` prefix is what puts the request in the API group, and
/// [RealtimeSocket] implementations are expected to `POST` the auth payload
/// there.
///
/// No such route is registered today: `app/` contains no `Broadcast::routes()`
/// call, and adding one under the API group is the plan's todo 31. This constant
/// is therefore the path that registration must produce, named in one place so a
/// transport cannot quietly substitute the session-authenticated one -- whose
/// failure mode is a redirect or a 419 on a request that carries only
/// `Authorization: Bearer`, and a subscription that never confirms with no error
/// anywhere. It travels on every [RealtimeSubscribeRequest] for that reason.
const String broadcastAuthEndpoint = '/api/broadcasting/auth';

/// Which wire prefix a channel name is published under.
enum RealtimeChannelKind {
  /// `private-` -- authorised for one account, invisible to everyone else.
  ///
  /// What a consultation's chat uses, and the only kind this application needs
  /// today: a consultation is a grant to its patient and its doctor rather than
  /// to a roster, which is the shape `private-` exists for. The callback that
  /// will decide that does not exist yet (plan todo 31).
  privateChannel(privateChannelPrefix),

  /// `presence-` -- carries a member list alongside the events.
  ///
  /// Included because it is the other half of the Pusher protocol's private
  /// surface and a future "who is in this consultation" feature needs it. It is
  /// **not** what a chat uses, and switching a chat to it would change the
  /// channel's semantics and its authorisation: presence channels authenticate
  /// the same way but are designed to hold and publish a member list.
  presenceChannel(presenceChannelPrefix);

  const RealtimeChannelKind(this.wirePrefix);

  /// The prefix, without the trailing dash being a separator.
  final String wirePrefix;

  /// Applies [wirePrefix] to a **logical** [channelName].
  ///
  /// The one place the prefix is added. A [channelName] that already carries a
  /// prefix is not de-duplicated here: passing one is a programming error and
  /// the resulting `private-private-` name is a channel the server never
  /// authorised, which surfaces as a subscription that never confirms.
  String wireName(String channelName) => '$wirePrefix$channelName';
}

/// One subscribe request, as handed to the transport.
///
/// ## `authHeaders` is a value, and the caller of this class decides how fresh
/// it is
///
/// A subscribe is authorised by a `POST` the transport makes to
/// [RealtimeSubscribeRequest.authEndpoint], and the `Authorization` header on
/// that request is the whole authorisation. The access token rotates, so a
/// header map captured once at construction is a **stale** credential by the time
/// the second subscribe happens, and a stale token on a private channel is
/// refused. [RealtimeClient] therefore re-reads it on every attempt; see that
/// class for the mechanism and the test that pins it.
class RealtimeSubscribeRequest {
  /// Describes a subscribe to [channelName].
  RealtimeSubscribeRequest({
    required this.channelName,
    required this.wireName,
    required this.authHeaders,
    this.authEndpoint = broadcastAuthEndpoint,
    List<String> events = const <String>[],
  }) : events = List<String>.unmodifiable(events);

  /// The **logical** channel name, with no wire prefix. `konsultasi.5`.
  final String channelName;

  /// The name the broker sees, prefix included. `private-konsultasi.5`.
  final String wireName;

  /// The headers for the auth `POST`, resolved **at the moment of this attempt**.
  final Map<String, String> authHeaders;

  /// Where to `POST` [authHeaders] to get the channel signature.
  final String authEndpoint;

  /// The event names to bind on this channel, without a leading dot.
  final List<String> events;

  @override
  String toString() =>
      'RealtimeSubscribeRequest($wireName, authEndpoint: $authEndpoint)';
}

/// One inbound event frame, as the transport reports it.
class RealtimeEvent {
  /// Records a frame of [eventName] on [channelName].
  RealtimeEvent({
    required this.channelName,
    required this.eventName,
    required Map<String, Object?> data,
  }) : data = Map<String, Object?>.unmodifiable(data);

  /// The **logical** channel the frame arrived on, prefix already stripped by
  /// the transport. A transport that forwards the prefixed name would never
  /// match a subscription and would look like a broker that never publishes.
  final String channelName;

  /// The event name, without a leading dot. `chat.pesan`.
  final String eventName;

  /// The decoded frame body.
  ///
  /// Copied and unmodifiable so a transport that reuses one decode buffer for
  /// every frame -- which is the obvious optimisation, and the one every fast
  /// Pusher client makes -- cannot hand the same map to two listeners and have
  /// the second see the first one's message.
  final Map<String, Object?> data;

  @override
  String toString() => 'RealtimeEvent($channelName/$eventName)';
}

/// What a transport reports about the connection, as a closed set.
///
/// [RealtimeClient] reacts to exactly one of these, [RealtimeReconnected], and
/// the distinction between connected and reconnected is load-bearing: the first
/// subscribe happens after a [RealtimeConnected] and needs no resync, while a
/// [RealtimeReconnected] means a gap opened and closed, so every channel has to
/// be re-subscribed and the history has to be re-fetched.
sealed class RealtimeSocketSignal {
  const RealtimeSocketSignal();
}

/// The socket is up and no gap preceded it.
final class RealtimeConnected extends RealtimeSocketSignal {
  /// Records a first connection.
  const RealtimeConnected();

  @override
  String toString() => 'RealtimeConnected()';
}

/// The socket went down. Subscriptions are not valid any more.
final class RealtimeDisconnected extends RealtimeSocketSignal {
  /// Records a disconnect.
  const RealtimeDisconnected();

  @override
  String toString() => 'RealtimeDisconnected()';
}

/// The socket came back up after a [RealtimeDisconnected].
///
/// The trigger for the whole resume path: re-subscribe every channel, then ask
/// the caller to re-fetch history, because the broker replayed nothing.
final class RealtimeReconnected extends RealtimeSocketSignal {
  /// Records a successful reconnect.
  const RealtimeReconnected();

  @override
  String toString() => 'RealtimeReconnected()';
}

/// The transport failed, or the broker refused something.
final class RealtimeSocketFailure extends RealtimeSocketSignal {
  /// Records a failure described by [message].
  const RealtimeSocketFailure(this.message);

  /// A human-readable description, for a log line.
  ///
  /// A `String` and not a typed cause on purpose: this is the one place in the
  /// realtime layer where the failure is the transport's to describe, and a
  /// transport cannot be required to speak this package's error vocabulary.
  final String message;

  @override
  String toString() => 'RealtimeSocketFailure($message)';
}

/// A Pusher-protocol transport.
///
/// Implement this over `laravel_reverb`, over `pusher` itself, or over
/// something in between, and [RealtimeClient] works unchanged. The interface is
/// four verbs and two streams on purpose: a wider surface would be a wider
/// contract to keep true across two libraries.
///
/// ## The two obligations an implementation takes on
///
/// 1. **Strip the wire prefix** from [RealtimeEvent.channelName] and from
///    [RealtimeSubscribeRequest.channelName]. [RealtimeClient] matches on the
///    logical name; a transport that forwards the prefixed name would make every
///    channel look unsubscribed.
/// 2. **Report a re-subscribe's re-delivery honestly.** A broker that replays
///    frames in flight for a channel is producing duplicates, and the interface
///    permits that -- dedupe belongs to the consumer, which is the one that knows
///    what it has already rendered. See [RealtimeEvent].
abstract interface class RealtimeSocket {
  /// Lifecycle signals, as they happen.
  Stream<RealtimeSocketSignal> get signals;

  /// Inbound event frames, as they happen.
  Stream<RealtimeEvent> get events;

  /// Opens the connection. Idempotent.
  void connect();

  /// Closes the connection and drops every subscription.
  void disconnect();

  /// Authorises and subscribes to [RealtimeSubscribeRequest.channelName].
  ///
  /// Completes when the transport has asked the broker to subscribe, which for a
  /// private channel means after the auth `POST` has succeeded. Throws on a
  /// refused authorisation.
  Future<void> subscribe(RealtimeSubscribeRequest request);

  /// Drops the subscription on [channelName], if there is one. Idempotent.
  void unsubscribe(String channelName);
}
