import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

import 'support/fake_realtime_socket.dart';
import 'support/test_support.dart';

/// The consultation every test subscribes to.
const int consultation = 5;

/// A `chat.pesan` frame body, exactly as `KonsultasiMessageSent` will publish
/// the `konsultasi` row.
///
/// The keys are the DDL columns at `telemedicine_test.sql:563-579`; see
/// `ChatMessage` for why the DDL and not a resource is the authority today.
Map<String, Object?> chatFrame(
  int id, {
  String isi = 'Halo dokter.',
  ChatTipePesan tipePesan = ChatTipePesan.teks,
  ChatPengirimTipe pengirimTipe = ChatPengirimTipe.pasien,
  int? pengirimUserId = 7,
  String? fileUrl,
}) {
  return <String, Object?>{
    'id': id,
    'konsultasi_id': consultation,
    'pengirim_user_id': pengirimUserId,
    'pengirim_tipe': pengirimTipe.wire,
    'tipe_pesan': tipePesan.wire,
    'isi': isi,
    'file_url': fileUrl,
    'file_nama': fileUrl == null ? null : 'foto.png',
    'file_ukuran_kb': fileUrl == null ? null : 128,
    'dibaca_at': null,
    'terkirim_at': '2026-01-02T03:04:05.000000Z',
  };
}

/// A [ChatMessage] built without parsing, for seeding a dedupe set.
ChatMessage seededMessage(int id) {
  return ChatMessage(
    id: id,
    konsultasiId: consultation,
    pengirimUserId: 7,
    pengirimTipe: ChatPengirimTipe.pasien,
    tipePesan: ChatTipePesan.teks,
    terkirimAt: DateTime.utc(2026, 1, 2, 3, 4, 5),
    isi: 'Halo dokter.',
  );
}

/// A plain in-memory [TokenStorage], acknowledged as insecure on purpose.
///
/// A realtime test is about the socket and the dedupe set, not about where the
/// token lives, so the acknowledged-plain store keeps the fixtures short. The
/// two tests that *are* about the token -- the re-read, and the proof that a
/// refused subscribe spends no refresh token -- use [FakeSecureBackend] and the
/// real [SehatlyApiClient] instead.
TokenStorage _storage() {
  return TokenStorage.plain(
    InMemoryKeyValueBackend(),
    allowInsecureStorage: true,
  );
}

/// A [RealtimeClient] over a throwaway store.
RealtimeClient _client(FakeRealtimeSocket socket, {int dedupeCapacity = 500}) {
  return RealtimeClient(
    socket: socket,
    storage: _storage(),
    dedupeCapacity: dedupeCapacity,
  );
}

void main() {
  group('the channel name and the wire name', () {
    test('the channel is konsultasi.5 and the wire is private-konsultasi.5', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      final RealtimeSubscription sub = await client.subscribeKonsultasi(
        konsultasiId: consultation,
      );

      // The string a call site writes, and the one routes/channels.php
      // authorises. No prefix: adding one here is what produces
      // `private-private-konsultasi.5`, a channel that does not exist.
      expect(sub.channelName, 'konsultasi.5');
      expect(sub.channelName, isNot(startsWith(privateChannelPrefix)));

      // The string the broker sees. The prefix is added by the protocol and by
      // RealtimeChannelKind.wireName, never by a call site.
      expect(sub.wireName, 'private-konsultasi.5');
      expect(sub.wireName, '${privateChannelPrefix}konsultasi.5');

      // Both travel on the same request, which is the claim under test.
      expect(socket.subscribeRequests, hasLength(1));
      expect(socket.subscribeRequests.first.channelName, 'konsultasi.5');
      expect(socket.subscribeRequests.first.wireName, 'private-konsultasi.5');

      client.dispose();
      await socket.close();
    });

    test('a presence channel is prefixed presence-', () {
      expect(
        RealtimeChannelKind.presenceChannel.wireName('konsultasi.5'),
        'presence-konsultasi.5',
      );
      expect(
        RealtimeChannelKind.privateChannel.wireName('konsultasi.5'),
        'private-konsultasi.5',
      );
      expect(presenceChannelPrefix, 'presence-');
      expect(privateChannelPrefix, 'private-');
    });

    test('the bound event name is chat.pesan with no leading dot', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      final RealtimeSubscription sub = await client.subscribeKonsultasi(
        konsultasiId: consultation,
      );

      expect(sub.eventName, 'chat.pesan');
      expect(sub.eventName, isNot(startsWith('.')));
      expect(chatPesanEvent, 'chat.pesan');
      expect(socket.subscribeRequests.first.events, <String>['chat.pesan']);

      // A frame under a name nothing bound is dropped rather than handed to a
      // parser that expects a chat row.
      final List<ChatMessage> received = <ChatMessage>[];
      client.messages.listen(received.add);

      socket.emit(chatFrame(1), eventName: '.chat.pesan');
      socket.emit(chatFrame(2), eventName: 'chat.pesan.typo');
      socket.emit(chatFrame(3), channelName: 'konsultasi.999');
      await pumpEventQueue();

      expect(received, isEmpty);

      client.dispose();
      await socket.close();
    });

    test('the auth endpoint is the API-group one', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      await client.subscribeKonsultasi(konsultasiId: consultation);

      expect(
        socket.subscribeRequests.first.authEndpoint,
        '/api/broadcasting/auth',
      );
      expect(
        socket.subscribeRequests.first.authEndpoint,
        broadcastAuthEndpoint,
      );
      expect(
        socket.subscribeRequests.first.authEndpoint,
        isNot('/broadcasting/auth'),
        reason:
            'the web-group path is session-authenticated and answers a '
            'bearer-only request with a redirect. It is the single most common '
            'Reverb integration failure.',
      );

      client.dispose();
      await socket.close();
    });
  });

  group('authHeaders is re-read on every subscribe', () {
    test('a rotated token reaches the second subscribe', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'access-1',
        kRefreshTokenKey: 'refresh-1',
      });
      final TokenStorage storage = TokenStorage.secure(store);
      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: storage,
      );

      await client.subscribeKonsultasi(konsultasiId: consultation);

      expect(
        socket.authorizationAt(0),
        'Bearer access-1',
        reason: 'the first subscribe presents the token that was stored',
      );

      // The rotation the app performs elsewhere in the session: the
      // single-flight RefreshCoordinator has written a new pair into the very
      // same store.
      await storage.accessTokens.writeAccessToken('access-2');

      await client.subscribeKonsultasi(konsultasiId: consultation);

      expect(
        socket.authorizationAt(1),
        'Bearer access-2',
        reason:
            'the second subscribe must present the ROTATED token. A client that '
            'captured the header map at construction presents access-1 here, and '
            'the broker refuses the channel as unauthorised, which looks '
            'exactly like a server-side authorisation bug.',
      );
      expect(
        client.subscribeAttemptCount,
        2,
        reason: 'the re-read is a consequence of a second attempt, not of a retry loop',
      );

      client.dispose();
      await socket.close();
    });

    test('a caller-supplied provider is called once per attempt', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final TokenStorage storage = TokenStorage.secure(FakeSecureBackend());

      // The value a test can rotate between two subscribes, which is exactly
      // what a real rotation does to the token in the store.
      String current = 'access-1';
      var calls = 0;

      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: storage,
        authHeaders: () async {
          calls += 1;
          return <String, String>{
            'Authorization': 'Bearer $current',
            'Accept': 'application/json',
          };
        },
      );

      await client.subscribeKonsultasi(konsultasiId: consultation);
      current = 'access-2';
      await client.subscribeKonsultasi(konsultasiId: consultation);
      current = 'access-3';
      await client.subscribeKonsultasi(konsultasiId: consultation);

      expect(calls, 3, reason: 'one invocation per subscribe attempt');
      expect(
        socket.subscribeRequests.map(
          (RealtimeSubscribeRequest r) => r.authHeaders['Authorization'],
        ),
        <String>['Bearer access-1', 'Bearer access-2', 'Bearer access-3'],
      );

      client.dispose();
      await socket.close();
    });

    test('with no stored token no Authorization header is sent', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: TokenStorage.secure(FakeSecureBackend()),
      );

      await client.subscribeKonsultasi(konsultasiId: consultation);

      expect(
        socket.lastAuthorization,
        isNull,
        reason:
            'an empty Bearer is a credential-shaped string guaranteed to be '
            'refused; omitting the header is the honest signed-out signal',
      );
      expect(
        socket.subscribeRequests.first.authHeaders['Accept'],
        'application/json',
      );

      client.dispose();
      await socket.close();
    });

    test('a reconnect re-reads the token too', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final TokenStorage storage = TokenStorage.secure(
        FakeSecureBackend(<String, String>{kAccessTokenKey: 'access-1'}),
      );
      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: storage,
      );

      await client.subscribeKonsultasi(konsultasiId: consultation);

      await storage.accessTokens.writeAccessToken('access-2');

      socket.emitReconnected();
      await pumpEventQueue();

      expect(
        socket.authorizationAt(1),
        'Bearer access-2',
        reason:
            'the token can rotate while the socket is down, so the resume path '
            're-reads it for exactly the reason the first path does',
      );

      client.dispose();
      await socket.close();
    });
  });

  group('dedupe', () {
    test('a duplicate frame is delivered once', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      final List<ChatMessage> received = <ChatMessage>[];
      client.messages.listen(received.add);

      await client.subscribeKonsultasi(konsultasiId: consultation);

      // A real duplicate: the same row, byte-identical payload, delivered
      // twice. Not a similar message and not the same text with a new id.
      socket.emit(chatFrame(100));
      await pumpEventQueue();
      socket.emit(chatFrame(100));
      await pumpEventQueue();

      expect(received, hasLength(1));
      expect(received.single.id, 100);
      expect(
        client.duplicateSuppressedCount,
        1,
        reason: 'the second copy was recognised and withheld',
      );

      client.dispose();
      await socket.close();
    });

    test('the same text with two different ids is two messages', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      final List<ChatMessage> received = <ChatMessage>[];
      client.messages.listen(received.add);

      await client.subscribeKonsultasi(konsultasiId: consultation);

      // Identical payloads except the id. A dedupe keyed on the content would
      // drop the second, and the user's second message would vanish.
      socket.emit(chatFrame(100, isi: 'Halo dokter.'));
      await pumpEventQueue();
      socket.emit(chatFrame(101, isi: 'Halo dokter.'));
      await pumpEventQueue();

      expect(received.map((ChatMessage m) => m.id), <int>[100, 101]);
      expect(client.duplicateSuppressedCount, 0);

      client.dispose();
      await socket.close();
    });

    test(
      'a message already in the REST history is not emitted again',
      () async {
        final FakeRealtimeSocket socket = FakeRealtimeSocket();
        final RealtimeClient client = _client(socket);

        final List<ChatMessage> received = <ChatMessage>[];
        client.messages.listen(received.add);

        await client.subscribeKonsultasi(konsultasiId: consultation);

        // The app loaded the last page of history over REST.
        client.seedHistory(<ChatMessage>[seededMessage(90), seededMessage(91)]);
        expect(client.hasSeen(91), isTrue);

        // The socket then delivers one of them live, which happens whenever a
        // message lands between the history fetch and the subscribe confirming.
        socket.emit(chatFrame(91));
        await pumpEventQueue();

        expect(received, isEmpty);
        expect(client.duplicateSuppressedCount, 1);

        // A genuinely new one still gets through.
        socket.emit(chatFrame(92));
        await pumpEventQueue();

        expect(received.map((ChatMessage m) => m.id), <int>[92]);

        client.dispose();
        await socket.close();
      },
    );

    test('a frame with no id is delivered, not silently swallowed', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      final List<ChatMessage> received = <ChatMessage>[];
      client.messages.listen(received.add);

      await client.subscribeKonsultasi(konsultasiId: consultation);

      // A server that stopped publishing `id`. Suppressing these would look
      // like a working client that is simply quiet, which is the worst failure
      // mode a chat can have.
      socket.emit(chatFrame(0));
      await pumpEventQueue();
      socket.emit(chatFrame(0));
      await pumpEventQueue();

      expect(received, hasLength(2));
      expect(client.unidentifiedEventCount, 2);
      expect(
        client.duplicateSuppressedCount,
        0,
        reason:
            'an unidentified message cannot be deduplicated, and the counter is '
            'the honest report of that',
      );

      client.dispose();
      await socket.close();
    });

    test('the dedupe set is bounded and evicts the oldest id', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket, dedupeCapacity: 3);

      await client.subscribeKonsultasi(konsultasiId: consultation);

      client.seedHistory(<ChatMessage>[
        seededMessage(1),
        seededMessage(2),
        seededMessage(3),
        seededMessage(4),
      ]);

      expect(client.hasSeen(1), isFalse, reason: 'the oldest id was evicted');
      expect(client.hasSeen(2), isTrue);
      expect(client.hasSeen(4), isTrue);

      // ...and an evicted id is delivered again rather than crashing.
      final List<ChatMessage> received = <ChatMessage>[];
      client.messages.listen(received.add);
      socket.emit(chatFrame(1));
      await pumpEventQueue();

      expect(received, hasLength(1));

      client.dispose();
      await socket.close();
    });
  });

  group('resume across a drop', () {
    test('subscribe, events, drop, recover, events', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final TokenStorage storage = TokenStorage.secure(
        FakeSecureBackend(<String, String>{kAccessTokenKey: 'access-1'}),
      );

      // The backfill the resync asks for: what GET /konsultasi/5/chat returned
      // at the moment of the reconnect. 102 is the message broadcast while the
      // socket was down, so it reached the broker's other subscribers and never
      // this client.
      final List<RealtimeResync> resyncs = <RealtimeResync>[];
      final List<ChatMessage> received = <ChatMessage>[];

      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: storage,
        onResync: (RealtimeResync resync) {
          resyncs.add(resync);
          return <ChatMessage>[
            seededMessage(100),
            seededMessage(101),
            seededMessage(102),
          ];
        },
      );
      client.messages.listen(received.add);

      // --- before the drop ----------------------------------------------------
      socket.emitConnected();
      await client.subscribeKonsultasi(konsultasiId: consultation);

      socket.emit(chatFrame(100));
      socket.emit(chatFrame(101));
      await pumpEventQueue();

      expect(received.map((ChatMessage m) => m.id), <int>[100, 101]);
      expect(resyncs, isEmpty, reason: 'a first connection is not a resync');

      // --- the drop -----------------------------------------------------------
      socket.emitDisconnected();
      await pumpEventQueue();

      expect(client.isConnected, isFalse);
      expect(client.disconnectCount, 1);
      expect(
        received,
        hasLength(2),
        reason: 'nothing arrives during the gap: the broker does not replay',
      );

      // --- recovery -----------------------------------------------------------
      // The token rotated while the socket was down, so the resume has to
      // re-read it or the channel comes back unauthorised.
      await storage.accessTokens.writeAccessToken('access-2');
      socket.emitReconnected();
      await pumpEventQueue();

      expect(client.isConnected, isTrue);
      expect(socket.subscribeRequests, hasLength(2));
      expect(
        socket.authorizationAt(1),
        'Bearer access-2',
        reason: 'the resume re-reads the rotated token',
      );
      expect(
        socket.subscribeRequests.first.wireName,
        socket.subscribeRequests.last.wireName,
        reason: 'the same channel is re-subscribed, not a different one',
      );

      // The resync ran, and the message from the gap is now known.
      expect(resyncs, hasLength(1));
      expect(resyncs.single.attempt, 1);
      expect(resyncs.single.channels, <String>['private-konsultasi.5']);
      expect(client.resyncCount, 1);
      expect(client.hasSeen(102), isTrue);

      // 102 is now *delivered*, not merely remembered. Before the reconnect it
      // had never been rendered, so a client that only seeded the dedupe set here
      // would leave a gap in the transcript while reporting a healthy count.
      expect(
        received.map((ChatMessage m) => m.id),
        <int>[100, 101, 102],
        reason:
            'the backfill delivers 102, which was broadcast while the socket was '
            'down and which the broker therefore never replayed',
      );

      // The backfill page also re-covered 100 and 101, which had already been
      // delivered live before the drop. Those are real duplicates and the counter
      // reports them.
      expect(
        client.duplicateSuppressedCount,
        2,
        reason: 'the backfill overlapped the two messages already on screen',
      );

      // --- after the drop -----------------------------------------------------
      // The broker re-delivers 100, 101 and 102 on the new subscription, which
      // is exactly the duplicate the dedupe exists for.
      socket.emit(chatFrame(100));
      socket.emit(chatFrame(101));
      socket.emit(chatFrame(102));
      socket.emit(chatFrame(103));
      await pumpEventQueue();

      // Nothing lost: 100 and 101 live, 102 from the gap, 103 after.
      // Nothing counted twice.
      expect(received.map((ChatMessage m) => m.id), <int>[100, 101, 102, 103]);
      expect(
        client.duplicateSuppressedCount,
        5,
        reason:
            'two from the backfill overlap, then three more as the broker '
            're-delivered 100, 101 and 102 live. All five were withheld.',
      );

      client.dispose();
      await socket.close();
    });

    test('the resync runs after the channels are live', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final List<int> subscribesWhenResyncRan = <int>[];

      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: _storage(),
        onResync: (RealtimeResync resync) {
          subscribesWhenResyncRan.add(socket.subscribeRequests.length);
          return null;
        },
      );

      await client.subscribeKonsultasi(konsultasiId: consultation);

      socket.emitReconnected();
      await pumpEventQueue();

      expect(
        subscribesWhenResyncRan,
        <int>[2],
        reason:
            'the re-subscribe must complete first. If the resync ran first, a '
            'message sent during the REST fetch would arrive live before the '
            'history page it belongs to, and the transcript renders it out of '
            'order.',
      );

      client.dispose();
      await socket.close();
    });

    test('a resync with no callback still re-subscribes', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      await client.subscribeKonsultasi(konsultasiId: consultation);
      socket.emitDisconnected();
      socket.emitReconnected();
      await pumpEventQueue();

      expect(socket.subscribeRequests, hasLength(2));
      expect(client.resyncCount, 1);
      expect(
        client.hasSeen(1),
        isFalse,
        reason: 'with no onResync there is no backfill, so nothing was seeded',
      );

      client.dispose();
      await socket.close();
    });

    test('a failing backfill does not stop the live path', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final List<String> failures = <String>[];

      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: _storage(),
        onFailure: failures.add,
        onResync: (RealtimeResync resync) =>
            throw StateError('the chat history request is offline'),
      );

      final List<ChatMessage> received = <ChatMessage>[];
      client.messages.listen(received.add);

      await client.subscribeKonsultasi(konsultasiId: consultation);
      socket.emitReconnected();
      await pumpEventQueue();

      expect(failures, hasLength(1));
      expect(failures.single, contains('backfill'));

      // The channel is live again even though the history fetch failed.
      socket.emit(chatFrame(200));
      await pumpEventQueue();

      expect(received.map((ChatMessage m) => m.id), <int>[200]);

      client.dispose();
      await socket.close();
    });

    test('a dead channel does not stop the others being re-subscribed', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final List<String> failures = <String>[];

      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: _storage(),
        onFailure: failures.add,
        onResync: (RealtimeResync resync) => null,
      );

      await client.subscribeKonsultasi(konsultasiId: consultation);
      await client.subscribeKonsultasi(konsultasiId: 6);

      // One consultation is revoked and refuses authorisation; the other is
      // still fine. Naming the sick channel is the point: a fake that failed
      // every subscribe would prove only that the loop does not abort, not that
      // a healthy channel is re-subscribed alongside a dead one.
      socket.subscribeFailureFor = (RealtimeSubscribeRequest request) {
        if (request.wireName == 'private-konsultasi.5') {
          return StateError('403 from the auth endpoint');
        }
        return null;
      };
      socket.emitReconnected();
      await pumpEventQueue();

      expect(
        socket.subscribeRequests,
        hasLength(4),
        reason:
            'both channels were attempted again, not just the one that failed',
      );
      expect(
        failures,
        hasLength(1),
        reason:
            'exactly one failure is reported. A resume that reported it again in '
            'its own catch would log every dead channel twice, which is how a '
            'single revoked consultation looks like a storm of errors.',
      );
      expect(failures.single, contains('private-konsultasi.5'));
      expect(
        client.subscriptions['konsultasi.6']!.lastFailure,
        isNull,
        reason: 'the healthy channel came back up',
      );
      expect(
        client.subscriptions['konsultasi.5']!.lastFailure,
        contains('403'),
      );

      client.dispose();
      await socket.close();
    });
  });

  group('a failed subscribe is terminal here, not retried', () {
    test('one attempt, and nothing retries it without a reconnect', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final List<String> failures = <String>[];
      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: _storage(),
        onFailure: failures.add,
      );

      socket.subscribeFailure = StateError('403 from the auth endpoint');

      await expectLater(
        client.subscribeKonsultasi(konsultasiId: consultation),
        throwsA(isA<RealtimeSubscribeException>()),
      );

      expect(client.subscribeAttemptCount, 1);

      // Pump generously. A retry loop would show up here as a second attempt;
      // this is the assertion that the class has no loop at all.
      await pumpEventQueue(times: 20);
      expect(client.subscribeAttemptCount, 1);
      expect(failures, hasLength(1));
      expect(
        client.subscriptions['konsultasi.5']!.lastFailure,
        contains('403'),
      );

      client.dispose();
      await socket.close();
    });

    test('a terminal subscribe failure never spends a refresh token', () async {
      // The server's contract: POST /auth/refresh rotates both tokens, revokes
      // the presented one on every use, and a replayed one revokes every live
      // refresh token for the account. So a realtime path that "helped itself"
      // with a rotation on a refused subscribe would sign the user out of every
      // device. The assertion is that the store is untouched and that no HTTP
      // at all was dispatched.
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final ScriptedAdapter adapter = ScriptedAdapter();
      final FakeSecureBackend store = FakeSecureBackend(<String, String>{
        kAccessTokenKey: 'access-1',
        kRefreshTokenKey: 'refresh-1',
      });

      final SehatlyApiClient api = SehatlyApiClient.secure(
        backend: store,
        environment: SehatlyEnvironment.test,
        httpClientAdapter: adapter,
      );

      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: api.storage,
      );

      socket.subscribeFailure = StateError('the broker refused');
      await expectLater(
        client.subscribeKonsultasi(konsultasiId: consultation),
        throwsA(isA<RealtimeSubscribeException>()),
      );
      await pumpEventQueue(times: 20);

      expect(adapter.callCount, 0, reason: 'no HTTP at all was dispatched');
      expect(store.values[kRefreshTokenKey], 'refresh-1');
      expect(store.values[kAccessTokenKey], 'access-1');
      expect(api.refreshCoordinator.refreshAttemptCount, 0);

      client.dispose();
      await socket.close();
      api.close();
    });

    test('an explicit resubscribe retries a failed subscription', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      socket.subscribeFailure = StateError('offline');
      await expectLater(
        client.subscribeKonsultasi(konsultasiId: consultation),
        throwsA(isA<RealtimeSubscribeException>()),
      );

      socket.subscribeFailure = null;
      await client.resubscribe();

      expect(client.subscribeAttemptCount, 2);
      expect(
        client.subscriptions['konsultasi.5']!.lastFailure,
        isNull,
        reason: 'the recorded failure is cleared once an attempt succeeds',
      );

      client.dispose();
      await socket.close();
    });

    test('a transport failure signal reaches onFailure', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final List<String> failures = <String>[];
      final RealtimeClient client = RealtimeClient(
        socket: socket,
        storage: _storage(),
        onFailure: failures.add,
      );

      socket.emitFailure('pusher error 4001: app key rejected');
      await pumpEventQueue();

      expect(failures, <String>['pusher error 4001: app key rejected']);

      client.dispose();
      await socket.close();
    });
  });

  group('lifecycle', () {
    test('dispose stops delivering and is idempotent', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      final List<ChatMessage> received = <ChatMessage>[];
      client.messages.listen(received.add);

      await client.subscribeKonsultasi(konsultasiId: consultation);
      client.dispose();
      client.dispose();

      socket.emit(chatFrame(300));
      await pumpEventQueue();

      expect(received, isEmpty);
      expect(client.subscriptions, isEmpty);

      await socket.close();
    });

    test('subscribe after dispose throws rather than half-working', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      client.dispose();

      await expectLater(
        client.subscribeKonsultasi(konsultasiId: consultation),
        throwsA(isA<StateError>()),
      );

      await socket.close();
    });

    test('unsubscribe drops the channel from the resume set', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      await client.subscribeKonsultasi(konsultasiId: consultation);
      client.unsubscribe(consultation);

      expect(client.subscriptions, isEmpty);
      expect(socket.unsubscribed, <String>['konsultasi.5']);

      socket.emitReconnected();
      await pumpEventQueue();

      expect(
        socket.subscribeRequests,
        hasLength(1),
        reason: 'a channel the app left is not re-subscribed behind its back',
      );

      client.dispose();
      await socket.close();
    });

    test('connect opens the transport on demand, not on subscribe', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      // The claim: a subscribe is authorised over HTTP, so it must not open a
      // socket as a side effect and hold a connection across a keychain read.
      expect(socket.connectCount, 0);

      await client.subscribeKonsultasi(konsultasiId: consultation);

      expect(
        socket.connectCount,
        0,
        reason: 'subscribing authorises a channel; it does not open a socket',
      );

      client.connect();
      expect(socket.connectCount, 1);
      expect(socket.isConnected, isTrue);

      client.dispose();
      await socket.close();
    });

    test('disconnect closes the transport but keeps the subscriptions', () async {
      final FakeRealtimeSocket socket = FakeRealtimeSocket();
      final RealtimeClient client = _client(socket);

      client.connect();
      await client.subscribeKonsultasi(konsultasiId: consultation);
      socket.emit(chatFrame(100));
      await pumpEventQueue();

      client.disconnect();

      expect(socket.isConnected, isFalse);
      expect(client.subscriptions.keys, <String>[
        'konsultasi.5',
      ], reason: 'a backgrounded app reconnects to the same channels');
      expect(
        client.hasSeen(100),
        isTrue,
        reason:
            'the dedupe set survives a backgrounding, so a frame the broker '
            'replays on reconnect is not shown twice',
      );

      // A reconnect brings the same channel back without the app re-issuing the
      // subscribe, which is the whole reason disconnect() is not dispose().
      client.connect();
      socket.emitReconnected();
      await pumpEventQueue();

      expect(socket.subscribeRequests, hasLength(2));
      expect(socket.subscribeRequests.last.wireName, 'private-konsultasi.5');

      socket.emit(chatFrame(100));
      await pumpEventQueue();

      expect(
        client.duplicateSuppressedCount,
        1,
        reason: 'the replayed frame was withheld after the reconnect',
      );

      client.dispose();
      await socket.close();
    });

    test(
      'connect after dispose throws rather than reopening a dead socket',
      () async {
        final FakeRealtimeSocket socket = FakeRealtimeSocket();
        final RealtimeClient client = _client(socket);

        client.dispose();

        expect(() => client.connect(), throwsA(isA<StateError>()));
        expect(
          socket.connectCount,
          0,
          reason: 'the transport was not reopened behind the guard',
        );

        await socket.close();
      },
    );
  });
}
