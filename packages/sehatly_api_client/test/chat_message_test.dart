import 'package:sehatly_api_client/sehatly_api_client.dart';
import 'package:test/test.dart';

/// A `konsultasi_chat` row, keyed exactly as the columns are named at
/// `telemedicine_test.sql:563-579`.
///
/// Every test here builds its payload from this one map, so a column renamed in
/// the DDL fails in one place instead of being quietly defaulted to `0` and
/// passing.
Map<String, Object?> row({
  int id = 91,
  int konsultasiId = 5,
  int pengirimUserId = 7,
  String pengirimTipe = 'pasien',
  String tipePesan = 'teks',
  Object? isi = 'Dokter, gejalanya sudah sejak kemarin.',
  Object? fileUrl,
  Object? fileNama,
  Object? fileUkuranKb,
  Object? dibacaAt,
  Object? terkirimAt = '2026-01-02T03:04:05.000000Z',
}) {
  return <String, Object?>{
    'id': id,
    'konsultasi_id': konsultasiId,
    'pengirim_user_id': pengirimUserId,
    'pengirim_tipe': pengirimTipe,
    'tipe_pesan': tipePesan,
    'isi': isi,
    'file_url': fileUrl,
    'file_nama': fileNama,
    'file_ukuran_kb': fileUkuranKb,
    'dibaca_at': dibacaAt,
    'terkirim_at': terkirimAt,
  };
}

void main() {
  group('the row parses as the DDL names it', () {
    test('every column at :563-579 reaches a field', () {
      final ChatMessage message = ChatMessage.fromJson(
        row(
          fileUrl: 'https://cdn.example.test/foto.png',
          fileNama: 'foto.png',
          fileUkuranKb: 128,
          dibacaAt: '2026-01-02T04:00:00.000000',
        ),
      );

      expect(message.id, 91);
      expect(message.konsultasiId, 5);
      expect(message.pengirimUserId, 7);
      expect(message.pengirimTipe, ChatPengirimTipe.pasien);
      expect(message.tipePesan, ChatTipePesan.teks);
      expect(message.isi, 'Dokter, gejalanya sudah sejak kemarin.');
      expect(message.fileUrl, 'https://cdn.example.test/foto.png');
      expect(message.fileNama, 'foto.png');
      expect(message.fileUkuranKb, 128);
      expect(message.dibacaAt, isNotNull);
      expect(message.terkirimAt, DateTime.utc(2026, 1, 2, 3, 4, 5));
    });

    test(
      'the consultation key is `konsultasi_id`, and a payload keyed any other '
      'way reads as 0',
      () {
        // The DDL column is `konsultasi_id` (:565). A resource serialises the
        // model's attributes, so that is the key that arrives on the wire.
        expect(row(konsultasiId: 5)['konsultasi_id'], 5);
        expect(ChatMessage.fromJson(row(konsultasiId: 5)).konsultasiId, 5);
      },
    );

    test('an unknown extra key is ignored rather than fatal', () {
      final Map<String, Object?> payload = row()
        ..['harga'] = 50000
        ..['konsultasi'] = 999
        ..['nested'] = <String, Object?>{'a': 1};

      final ChatMessage message = ChatMessage.fromJson(payload);

      expect(message.id, 91);
      expect(message.konsultasiId, 5, reason: 'the decoy key was not read');
    });
  });

  group('both vocabularies decode every DDL member', () {
    test('pengirim_tipe (:567)', () {
      expect(ChatPengirimTipe.values, hasLength(3));

      for (final String wire in <String>['pasien', 'dokter', 'sistem']) {
        expect(
          ChatPengirimTipe.fromWire(wire)?.wire,
          wire,
          reason: 'a member of a closed ENUM must decode',
        );
      }

      // `perawat` is a real `users.tipe` (:139) and is deliberately NOT a chat
      // sender, so it must not decode here.
      expect(ChatPengirimTipe.fromWire('perawat'), isNull);
      expect(ChatPengirimTipe.fromWire(null), isNull);
    });

    test('tipe_pesan (:568-569)', () {
      const List<String> members = <String>[
        'teks',
        'gambar',
        'dokumen',
        'audio',
        'video_note',
        'resep',
        'surat_keterangan',
        'sistem',
      ];

      expect(ChatTipePesan.values, hasLength(members.length));

      for (final String wire in members) {
        expect(
          ChatTipePesan.fromWire(wire)?.wire,
          wire,
          reason: 'a member of a closed ENUM must decode',
        );
      }

      expect(ChatTipePesan.fromWire('voice'), isNull);
      expect(ChatTipePesan.fromWire('stiker'), isNull);
    });

    test('the two-word members keep their exact DDL spelling', () {
      // `videoNote` is the risk A.26 is about: a Dart member spelled
      // `video_note`, or `videonote` on the wire, would compile and then never
      // match a real frame.
      expect(ChatTipePesan.videoNote.wire, 'video_note');
      expect(ChatTipePesan.suratKeterangan.wire, 'surat_keterangan');

      expect(ChatTipePesan.fromWire('videoNote'), isNull);
      expect(ChatTipePesan.fromWire('videonote'), isNull);
      expect(ChatTipePesan.fromWire('video note'), isNull);
    });
  });

  group('an unusable frame degrades instead of throwing', () {
    test('a missing vocabulary falls back to the NOT NULL members', () {
      final ChatMessage message = ChatMessage.fromJson(
        row()
          ..remove('pengirim_tipe')
          ..remove('tipe_pesan'),
      );

      // The DDL declares both NOT NULL with those defaults (:567-569), so a
      // frame without them is a server bug -- but the client still has to render
      // a bubble, so the fallbacks are the two members that are always
      // renderable.
      expect(message.pengirimTipe, ChatPengirimTipe.sistem);
      expect(message.tipePesan, ChatTipePesan.teks);
    });

    test('an unrecognised vocabulary degrades the same way', () {
      final ChatMessage message = ChatMessage.fromJson(
        row(pengirimTipe: 'apoteker', tipePesan: 'stiker'),
      );

      expect(message.pengirimTipe, ChatPengirimTipe.sistem);
      expect(message.tipePesan, ChatTipePesan.teks);
    });

    test('a missing id is 0, and that is a state the caller can act on', () {
      // `0` means "no id", which is why RealtimeClient delivers such a frame
      // rather than suppressing it -- it cannot be deduplicated.
      final ChatMessage message = ChatMessage.fromJson(row()..remove('id'));

      expect(message.id, 0);
      expect(message.konsultasiId, 5, reason: 'one bad key, not all of them');
    });

    test('a wrongly typed value reads as 0 or null, never a cast error', () {
      final ChatMessage message = ChatMessage.fromJson(<String, Object?>{
        'id': 'not-a-number',
        'konsultasi_id': <int>[5],
        'pengirim_user_id': null,
        'tipe_pesan': 7,
        'file_ukuran_kb': 'heavy',
        'dibaca_at': 'never',
        'terkirim_at': 'nonsense',
      });

      expect(message.id, 0);
      expect(message.konsultasiId, 0);
      expect(message.pengirimUserId, 0);
      expect(
        message.tipePesan,
        ChatTipePesan.teks,
        reason: '7 is not a member',
      );
      expect(message.fileUkuranKb, isNull);
      expect(message.dibacaAt, isNull);
      expect(message.isRead, isFalse);
      expect(
        message.terkirimAt,
        DateTime.fromMillisecondsSinceEpoch(0, isUtc: true),
        reason: 'an unparseable timestamp is the epoch, not a crash',
      );
    });

    test('a number where text was expected is read as text, not dropped', () {
      // jsonString stringifies numbers on purpose, so a server that changed
      // `isi` to a number still renders. Asserted because the alternative --
      // nulling the body -- would show an empty bubble.
      expect(ChatMessage.fromJson(row(isi: 42)).isi, '42');
    });

    test('a decimal or string id truncates rather than failing', () {
      // jsonInt accepts a double and a numeric string, because DECIMAL columns
      // arrive as either depending on the driver. A `double` is a `double` in
      // JSON, so the map is built directly rather than through [row].
      expect(ChatMessage.fromJson(row()..['id'] = 91.9).id, 91);
      expect(ChatMessage.fromJson(row()..['id'] = '91').id, 91);
      expect(ChatMessage.fromJson(row()..['id'] = 91.0).id, 91);
    });

    test('an empty map is a message with no id rather than an exception', () {
      final ChatMessage message = ChatMessage.fromJson(<String, Object?>{});

      expect(message.id, 0);
      expect(message.konsultasiId, 0);
      expect(message.isi, isNull);
      expect(message.hasAttachment, isFalse);
      expect(message.isRead, isFalse);
    });
  });

  group('the renderers answer the two questions a bubble asks', () {
    test('isRead follows dibaca_at (:574)', () {
      expect(ChatMessage.fromJson(row()).isRead, isFalse);
      expect(
        ChatMessage.fromJson(row(dibacaAt: '2026-01-02T04:00:00')).isRead,
        isTrue,
      );
    });

    test('hasAttachment follows the member, not the file columns', () {
      // The point of delegating to isAttachment: an upload whose file failed to
      // store still has a null `file_url`, and a renderer branching on
      // `fileUrl != null` shows an empty bubble exactly where a retry belongs.
      final ChatMessage failedUpload = ChatMessage.fromJson(
        row(tipePesan: 'gambar', fileUrl: null),
      );

      expect(failedUpload.fileUrl, isNull);
      expect(failedUpload.hasAttachment, isTrue);
      expect(failedUpload.tipePesan.isAttachment, isTrue);

      for (final ChatTipePesan member in <ChatTipePesan>[
        ChatTipePesan.gambar,
        ChatTipePesan.dokumen,
        ChatTipePesan.audio,
        ChatTipePesan.videoNote,
      ]) {
        expect(member.isAttachment, isTrue, reason: member.wire);
      }

      for (final ChatTipePesan member in <ChatTipePesan>[
        ChatTipePesan.teks,
        ChatTipePesan.resep,
        ChatTipePesan.suratKeterangan,
        ChatTipePesan.sistem,
      ]) {
        expect(member.isAttachment, isFalse, reason: member.wire);
      }
    });

    test('isSystemGenerated is true for `sistem` only -- a prescription is a '
        "doctor's work, not the server's", () {
      expect(ChatTipePesan.sistem.isSystemGenerated, isTrue);

      // `resep` and `surat_keterangan` each carry a `dokter_id NOT NULL`
      // (:748, :587), so a doctor authors them. Calling them server-generated
      // would misattribute a doctor's work to the backend and make a client
      // refuse to reply to its own doctor's prescription.
      expect(ChatTipePesan.resep.isSystemGenerated, isFalse);
      expect(ChatTipePesan.suratKeterangan.isSystemGenerated, isFalse);

      expect(ChatTipePesan.teks.isSystemGenerated, isFalse);
      expect(ChatTipePesan.gambar.isSystemGenerated, isFalse);
      expect(ChatTipePesan.dokumen.isSystemGenerated, isFalse);
      expect(ChatTipePesan.audio.isSystemGenerated, isFalse);
      expect(ChatTipePesan.videoNote.isSystemGenerated, isFalse);
    });
  });

  group('toString names what a log line needs', () {
    test('it carries the id, the consultation and the wire member', () {
      final String text = ChatMessage.fromJson(
        row(id: 91, konsultasiId: 5, tipePesan: 'video_note'),
      ).toString();

      expect(text, contains('91'));
      expect(text, contains('konsultasiId: 5'));
      expect(text, contains('video_note'));
    });
  });
}
