import '../core/json.dart';
import '../model/enums.dart';

/// One `konsultasi_chat` row, as it arrives on the `chat.pesan` event.
///
/// ```json
/// {
///   "id": 91,
///   "konsultasi_id": 5,
///   "pengirim_user_id": 7,
///   "pengirim_tipe": "pasien",
///   "tipe_pesan": "teks",
///   "isi": "Dokter, gejalanya sudah sejak kemarin.",
///   "file_url": null,
///   "file_nama": null,
///   "file_ukuran_kb": null,
///   "dibaca_at": null,
///   "terkirim_at": "2026-01-02T03:04:05.000000Z"
/// }
/// ```
///
/// ## This is transcribed from the DDL, and the DDL is the only authority
/// available
///
/// Every key above is a `konsultasi_chat` column at
/// `telemedicine_test.sql:563-579`, and the two ENUM vocabularies are
/// [ChatPengirimTipe] and [ChatTipePesan]. **The publisher of this payload does
/// not exist yet**: a search of `app/` finds no `ShouldBroadcast` implementation,
/// no `broadcastWith()` call and no `KonsultasiMessageSent` class, and the event
/// itself is the plan's todo 31.
///
/// That is the same position [ApiException.slotErrorKey] documents for the
/// booking surface: the client declares the contract the DDL and the plan
/// specify, and a later todo either confirms it or changes one line here. The
/// alternative -- leaving realtime unmodelled until the server exists -- makes
/// the mobile team's task wait on the backend's, which is the dependency this
/// client is supposed to absorb.
///
/// The one thing that is **not** negotiable is the key name. A resource
/// serialises a model's attributes, so a Laravel model whose column is
/// `konsultasi_id` publishes `konsultasi_id`, and a client reading
/// `konsultasi` here would silently score every message as consultation `0`.
/// That is why [konsultasiId] and the JSON key are spelled identically.
///
/// ## `id` is the dedupe key, and that is why it is modelled first
///
/// A message can reach a client by two routes: over the socket, and out of the
/// REST history the app fetches to fill the gap the broker does not replay. The
/// same row arrives by both, and a frame the broker re-delivers after a
/// re-subscribe arrives twice on its own. [id] is what makes those one message
/// rather than three, so it is a first-class field and not a display detail. See
/// `RealtimeClient`.
///
/// ## `dibaca_at` and `terkirim_at` are different kinds of timestamp
///
/// `terkirim_at` is a `TIMESTAMP` (`:575`), which MySQL converts using the
/// server's time zone, and `config/app.php:68` sets that zone to `UTC` -- so a
/// resource serialising it with `toISOString()` ends in `Z` and the parsed
/// [DateTime] is unambiguous. `dibaca_at` is a `DATETIME` (`:574`), which
/// carries no time zone at all. It is read through the same total reader for
/// uniformity, and [isRead] is the only question a client actually asks of it:
/// whether the other party has read the message. Formatting a `DATETIME` as a
/// local time is off by the device's offset, and no caller of this class needs
/// the instant.
class ChatMessage {
  /// Creates a message directly, without parsing.
  const ChatMessage({
    required this.id,
    required this.konsultasiId,
    required this.pengirimUserId,
    required this.pengirimTipe,
    required this.tipePesan,
    required this.terkirimAt,
    this.isi,
    this.fileUrl,
    this.fileNama,
    this.fileUkuranKb,
    this.dibacaAt,
  });

  /// Parses a `chat.pesan` frame body.
  ///
  /// Total, like every `fromJson` in this package: an absent or wrongly-typed
  /// key reads as `null` or `0` rather than throwing, because a schema surprise
  /// on one chat frame must not tear down a listener that is mid-transcript.
  /// The two vocabularies default to `sistem` and `teks` -- the DDL's
  /// `NOT NULL` members -- so an unrecognised member degrades to something
  /// renderable instead of to `null` that a bubble has to special-case.
  factory ChatMessage.fromJson(Map<String, Object?> json) {
    return ChatMessage(
      id: jsonInt(json['id']) ?? 0,
      konsultasiId: jsonInt(json['konsultasi_id']) ?? 0,
      pengirimUserId: jsonInt(json['pengirim_user_id']) ?? 0,
      pengirimTipe:
          ChatPengirimTipe.fromWire(jsonString(json['pengirim_tipe'])) ??
          ChatPengirimTipe.sistem,
      tipePesan:
          ChatTipePesan.fromWire(jsonString(json['tipe_pesan'])) ??
          ChatTipePesan.teks,
      terkirimAt:
          jsonDateTime(json['terkirim_at']) ??
          DateTime.fromMillisecondsSinceEpoch(0, isUtc: true),
      isi: jsonString(json['isi']),
      fileUrl: jsonString(json['file_url']),
      fileNama: jsonString(json['file_nama']),
      fileUkuranKb: jsonInt(json['file_ukuran_kb']),
      dibacaAt: jsonDateTime(json['dibaca_at']),
    );
  }

  /// `konsultasi_chat.id` (`:564`) -- the row's identity, and the dedupe key.
  ///
  /// A `BIGINT UNSIGNED` surrogate, so it is not stable across environments and
  /// must never be persisted as a cross-install identifier. `0` means the frame
  /// carried no id, and that is a state the caller can act on: a message with no
  /// id cannot be deduplicated, so `RealtimeClient` delivers it rather than
  /// suppressing it.
  final int id;

  /// `konsultasi_chat.konsultasi_id` (`:565`) -- the consultation this belongs
  /// to.
  ///
  /// Named after the column rather than shortened to `konsultasi`, because the
  /// column name is the wire key and the two are one contract. It is also a
  /// `BIGINT UNSIGNED` foreign key with `ON DELETE CASCADE` (`:576`), so a
  /// consultation's whole transcript goes with the consultation.
  final int konsultasiId;

  /// `konsultasi_chat.pengirim_user_id` (`:566`) -- the `users` row that sent
  /// it.
  ///
  /// `NOT NULL`, and a foreign key to `users(id)` (`:577`), so a `sistem` row
  /// carries one too: a client that keys "did I write this" off the id alone
  /// will claim a system notice as its own. See [ChatPengirimTipe].
  final int pengirimUserId;

  /// `konsultasi_chat.pengirim_tipe` (`:567`).
  final ChatPengirimTipe pengirimTipe;

  /// `konsultasi_chat.tipe_pesan` (`:568-569`).
  final ChatTipePesan tipePesan;

  /// `konsultasi_chat.isi` (`:570`), or `null`.
  ///
  /// `TEXT NULL`, and nullable rather than an empty-string default, so `null`
  /// is a state a renderer must handle. [isRead] and [hasAttachment] are the
  /// two questions this class answers for it.
  final String? isi;

  /// `konsultasi_chat.file_url` (`:571`), or `null`.
  final String? fileUrl;

  /// `konsultasi_chat.file_nama` (`:572`), or `null`.
  final String? fileNama;

  /// `konsultasi_chat.file_ukuran_kb` (`:573`), or `null`.
  final int? fileUkuranKb;

  /// `konsultasi_chat.dibaca_at` (`:574`), or `null`.
  ///
  /// The column is `DATETIME`, not a timestamp, so it is stored in the server's
  /// zone with no offset attached. Nothing in the schema writes it: the
  /// mark-read endpoint that would set it is the plan's todo 32 and does not
  /// exist yet, which is why this class deliberately exposes only [isRead] and
  /// not the instant.
  final DateTime? dibacaAt;

  /// `konsultasi_chat.terkirim_at` (`:575`).
  ///
  /// `NOT NULL DEFAULT CURRENT_TIMESTAMP`, so a row that never set it still has
  /// one.
  ///
  /// ## The index is an ordering key, not a promised sort order
  ///
  /// `INDEX idx_chat (konsultasi_id, terkirim_at)` (`:578`) is what the schema
  /// is built to read by, so `(konsultasi_id, terkirim_at)` is the tuple to
  /// sort and page on. It is **not** evidence that any endpoint returns rows in
  /// that order, and the endpoint that will return them is the plan's todo 32.
  /// A transcript should therefore sort on [terkirimAt] explicitly rather than
  /// trust the order it was handed; two messages can share a `terkirim_at` to
  /// the second, and [id] is the tiebreak.
  final DateTime terkirimAt;

  /// Whether the other party has read this message.
  ///
  /// `dibaca_at != null`. The only question a chat bubble asks of the column.
  bool get isRead => dibacaAt != null;

  /// Whether the row is an upload rather than typed text.
  ///
  /// Delegates to [ChatTipePesan.isAttachment] rather than testing [fileUrl].
  /// The three `file_*` columns are nullable (`:571-573`), so a renderer that
  /// branches on `fileUrl != null` shows an empty bubble exactly when the
  /// upload is the part that failed.
  bool get hasAttachment => tipePesan.isAttachment;

  @override
  String toString() =>
      'ChatMessage(id: $id, konsultasiId: $konsultasiId, '
      'tipePesan: ${tipePesan.wire})';
}
