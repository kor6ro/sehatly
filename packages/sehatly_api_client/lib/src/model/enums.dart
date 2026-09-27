/// The ENUM vocabularies this API publishes, transcribed from
/// `telemedicine_test.sql`.
///
/// ## Why these are Dart enums and not `String`
///
/// Every value below is a DDL `ENUM` member, so the set is closed and the
/// server will 422 anything outside it. A `String` field would push that failure
/// to the call site, where it surfaces as "some booking is mysteriously invalid".
/// A Dart enum makes the constraint checkable at compile time and turns a
/// server-side addition into a compile error here, which is the correct time to
/// find out.
///
/// ## Every value is transcribed, and the source line is named
///
/// A.26 is the reason: a prior batch in this repository shipped `doker_umum` for
/// `doker_umum`'s intended `dokter_umum` inside an ENUM value list, and the
/// corruption was pure ASCII -- no encoding scan can see it. Each constant
/// below therefore carries the `telemedicine_test.sql` line it came from, so a
/// reader can check the transcription without running anything, and so a
/// re-audit after a schema change knows exactly which constants to recheck.
///
/// ## Two vocabularies that look alike and are not
///
/// `MasterSpesialisasiTipe` and [DokterTipe] **share exactly one member**,
/// `dokter_umum`:
///
/// | vocabulary | members |
/// | --- | --- |
/// | `master_spesialisasi.tipe` (:406) | `dokter_umum`, `spesialis`, `subspesialis` |
/// | `dokter.tipe` (:412) | `dokter_umum`, `dokter_spesialis`, `dokter_gigi`, `psikolog`, `bidan`, `perawat`, `apoteker` |
///
/// `spesialis` is not `dokter_spesialis`, and no other member of either list
/// matches anything in the other. `GET /api/v1/master-spesialisasi` publishes the
/// first and `GET /api/v1/dokter?tipe=` filters on the second, so a client
/// populating two dropdowns from one endpoint has to keep them apart. The types
/// being distinct Dart enums is what makes a mix-up a type error.
library;

/// `users.tipe` -- `telemedicine_test.sql:139`.
///
/// The account type. Note that `perawat` and `kurir` are real members that
/// carry no RBAC role, which is why several Module 1 routes deliberately have no
/// `tipe:` guard: gating on account type would lock those two out of their own
/// account.
enum UserTipe {
  /// `pasien`
  pasien('pasien'),

  /// `dokter`
  dokter('dokter'),

  /// `perawat`
  perawat('perawat'),

  /// `apoteker`
  apoteker('apoteker'),

  /// `kurir`
  kurir('kurir'),

  /// `admin`
  admin('admin'),

  /// `superadmin`
  superadmin('superadmin');

  const UserTipe(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static UserTipe? fromWire(String? value) =>
      _decode(UserTipe.values, value, (UserTipe v) => v.wire);
}

/// `users.status` -- `telemedicine_test.sql:140`.
///
/// An account moves `pending_verifikasi` -> `aktif` on its first OTP
/// verification, and can then be `nonaktif` or `ditangguhkan` by an
/// administrator. `AuthController::login()` refuses the last two with a 403
/// before it mints an OTP, and `verifyOtp()` never promotes out of them, so a
/// suspended account cannot be revived by replaying a code.
enum UserStatus {
  /// `pending_verifikasi`
  pendingVerifikasi('pending_verifikasi'),

  /// `aktif`
  aktif('aktif'),

  /// `nonaktif`
  nonaktif('nonaktif'),

  /// `ditangguhkan`
  ditangguhkan('ditangguhkan');

  const UserStatus(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static UserStatus? fromWire(String? value) =>
      _decode(UserStatus.values, value, (UserStatus v) => v.wire);
}

/// `users.bahasa` -- `telemedicine_test.sql:142`.
///
/// The account's display language. This client does not translate: the server's
/// `message` strings are Indonesian and a client that switches its own copy
/// without switching the server's produces a mixed-language screen.
enum Bahasa {
  /// `id`
  id('id'),

  /// `en`
  en('en');

  const Bahasa(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static Bahasa? fromWire(String? value) =>
      _decode(Bahasa.values, value, (Bahasa v) => v.wire);
}

/// `pasien.jenis_kelamin` (:225) and `pasien_anggota_keluarga.jenis_kelamin`
/// (:265) -- `ENUM('L','P')`.
///
/// One enum for both columns because the DDL declares the identical list for
/// both, and two enums for the same two values would let a client pass a
/// family-member value where a patient's was meant.
///
/// The members are single letters and that is not a typo: the DDL is
/// `ENUM('L','P')`, not `'Laki-laki','Perempuan'`.
enum JenisKelamin {
  /// `L`
  lakiLaki('L'),

  /// `P`
  perempuan('P');

  const JenisKelamin(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static JenisKelamin? fromWire(String? value) =>
      _decode(JenisKelamin.values, value, (JenisKelamin v) => v.wire);
}

/// `pasien.rhesus` -- `telemedicine_test.sql:229` -- `ENUM('positif','negatif',
/// 'tidak_diketahui')`.
///
/// Three members, and the third is a real state rather than a NULL stand-in:
/// the column is `NOT NULL DEFAULT 'tidak_diketahui'`.
enum Rhesus {
  /// `positif`
  positif('positif'),

  /// `negatif`
  negatif('negatif'),

  /// `tidak_diketahui`
  tidakDiketahui('tidak_diketahui');

  const Rhesus(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static Rhesus? fromWire(String? value) =>
      _decode(Rhesus.values, value, (Rhesus v) => v.wire);
}

/// `pasien_alergi.tipe_alergen` -- `telemedicine_test.sql:277` -- `ENUM('obat',
/// 'makanan','lingkungan','lainnya')`.
///
/// `lainnya` is a real category and not an escape hatch the client should
/// pre-empt: an unknown allergen type is better recorded coarsely than dropped.
enum TipeAlergen {
  /// `obat`
  obat('obat'),

  /// `makanan`
  makanan('makanan'),

  /// `lingkungan`
  lingkungan('lingkungan'),

  /// `lainnya`
  lainnya('lainnya');

  const TipeAlergen(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static TipeAlergen? fromWire(String? value) =>
      _decode(TipeAlergen.values, value, (TipeAlergen v) => v.wire);
}

/// `pasien_alergi.keparahan` -- `telemedicine_test.sql:280` -- `ENUM('ringan',
/// 'sedang','berat','anafilaksis')`.
///
/// The column is `NOT NULL DEFAULT 'ringan'`, so an omitted `keparahan` on a
/// create comes back as `ringan` rather than `null`; the server re-reads the row
/// after insert precisely so the response reflects the database default rather
/// than an unset model attribute.
enum Keparahan {
  /// `ringan`
  ringan('ringan'),

  /// `sedang`
  sedang('sedang'),

  /// `berat`
  berat('berat'),

  /// `anafilaksis`
  anafilaksis('anafilaksis');

  const Keparahan(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static Keparahan? fromWire(String? value) =>
      _decode(Keparahan.values, value, (Keparahan v) => v.wire);
}

/// `user_otp.tujuan` -- `telemedicine_test.sql:183` -- `ENUM('verifikasi_telepon',
/// 'verifikasi_email','reset_kata_sandi','login')`.
///
/// The column holds four values, but `POST /auth/otp/verify` accepts only two of
/// them; see [OtpTujuanDiterbitkan].
enum OtpTujuan {
  /// `verifikasi_telepon`
  verifikasiTelepon('verifikasi_telepon'),

  /// `verifikasi_email`
  verifikasiEmail('verifikasi_email'),

  /// `reset_kata_sandi`
  resetKataSandi('reset_kata_sandi'),

  /// `login`
  login('login');

  const OtpTujuan(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static OtpTujuan? fromWire(String? value) =>
      _decode(OtpTujuan.values, value, (OtpTujuan v) => v.wire);
}

/// The two `tujuan` values `POST /auth/otp/verify` accepts.
///
/// `OtpService::TUJUAN_DI_TERBITKAN`, narrowed from the four members of
/// [OtpTujuan]. The other two are refused on purpose: this endpoint's *effect*
/// is "issue an access and refresh pair", which is not a correct outcome for a
/// password-reset code or an email-verification code, so accepting them here
/// would let a caller turn a reset code into a session.
///
/// The client's [OtpTujuan] stays the full four-member column, because the
/// *server* mints codes for all four; it is only the verify endpoint that
/// narrows. This enum exists so a verify call site cannot express the widened
/// set at all.
enum OtpTujuanDiterbitkan {
  /// `verifikasi_telepon` -- the registration flow.
  verifikasiTelepon('verifikasi_telepon'),

  /// `login` -- the login flow.
  login('login');

  const OtpTujuanDiterbitkan(this.wire);

  /// The exact value the request field `tujuan` must carry.
  final String wire;

  /// The column-level value, for a response's `otp.tujuan`.
  OtpTujuan get asTujuan => OtpTujuan.fromWire(wire) ?? OtpTujuan.login;
}

/// `user_devices.platform` -- `telemedicine_test.sql:194` -- `ENUM('android',
/// 'ios','web')`.
///
/// Three members, and `web` is a real one: a browser SPA calls the same device
/// endpoint so a signed-in browser can be revoked from a phone.
enum DevicePlatform {
  /// `android`
  android('android'),

  /// `ios`
  ios('ios'),

  /// `web`
  web('web');

  const DevicePlatform(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static DevicePlatform? fromWire(String? value) =>
      _decode(DevicePlatform.values, value, (DevicePlatform v) => v.wire);
}

/// `master_spesialisasi.tipe` -- `telemedicine_test.sql:406` -- `ENUM(
/// 'dokter_umum','spesialis','subspesialis')`.
///
/// **Not** [DokterTipe], and the two share exactly one member. See the library
/// docblock.
enum MasterSpesialisasiTipe {
  /// `dokter_umum`
  dokterUmum('dokter_umum'),

  /// `spesialis`
  spesialis('spesialis'),

  /// `subspesialis`
  subspesialis('subspesialis');

  const MasterSpesialisasiTipe(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static MasterSpesialisasiTipe? fromWire(String? value) => _decode(
    MasterSpesialisasiTipe.values,
    value,
    (MasterSpesialisasiTipe v) => v.wire,
  );
}

/// `dokter.tipe` -- `telemedicine_test.sql:412` -- `ENUM('dokter_umum',
/// 'dokter_spesialis','dokter_gigi','psikolog','bidan','perawat','apoteker')`.
///
/// **Not** [MasterSpesialisasiTipe]. See the library docblock.
enum DokterTipe {
  /// `dokter_umum`
  dokterUmum('dokter_umum'),

  /// `dokter_spesialis`
  dokterSpesialis('dokter_spesialis'),

  /// `dokter_gigi`
  dokterGigi('dokter_gigi'),

  /// `psikolog`
  psikolog('psikolog'),

  /// `bidan`
  bidan('bidan'),

  /// `perawat`
  perawat('perawat'),

  /// `apoteker`
  apoteker('apoteker');

  const DokterTipe(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static DokterTipe? fromWire(String? value) =>
      _decode(DokterTipe.values, value, (DokterTipe v) => v.wire);
}

/// `dokter.status_verifikasi` -- `telemedicine_test.sql:427` -- `ENUM('pending',
/// 'terverifikasi','ditolak')`.
///
/// Every row `GET /api/v1/dokter` returns is `terverifikasi` by construction --
/// the directory service filters on it -- so [DokterListing.statusVerifikasi] is
/// a constant in practice. It is still published, because a constant that is
/// visible tells a client the eligibility rule was applied.
enum DokterStatusVerifikasi {
  /// `pending`
  pending('pending'),

  /// `terverifikasi`
  terverifikasi('terverifikasi'),

  /// `ditolak`
  ditolak('ditolak');

  const DokterStatusVerifikasi(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static DokterStatusVerifikasi? fromWire(String? value) => _decode(
    DokterStatusVerifikasi.values,
    value,
    (DokterStatusVerifikasi v) => v.wire,
  );
}

/// `dokter_pendidikan.jenjang` -- `telemedicine_test.sql:460` -- `ENUM(
/// 's1_kedokteran','profesi','sp1','sp2','s2','s3','lainnya')`.
///
/// Note `s1_kedokteran` and not `s1`: the first member is the qualified string.
enum DokterJenjang {
  /// `s1_kedokteran`
  s1Kedokteran('s1_kedokteran'),

  /// `profesi`
  profesi('profesi'),

  /// `sp1`
  sp1('sp1'),

  /// `sp2`
  sp2('sp2'),

  /// `s2`
  s2('s2'),

  /// `s3`
  s3('s3'),

  /// `lainnya`
  lainnya('lainnya');

  const DokterJenjang(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static DokterJenjang? fromWire(String? value) =>
      _decode(DokterJenjang.values, value, (DokterJenjang v) => v.wire);
}

/// `konsultasi_chat.pengirim_tipe` -- `telemedicine_test.sql:567` --
/// `ENUM('pasien','dokter','sistem')`.
///
/// Three members and the third is not a sender at all: a `sistem` message is
/// written by the server, not by either party. It carries a `pengirim_user_id`
/// like every other row (the column is `NOT NULL`, :566), so a client that keys
/// "is this mine" off the type must exclude `sistem` explicitly rather than
/// assuming the two-party set.
///
/// `pasien` and `dokter` are `users.tipe` values, but they are **not** the whole
/// of [UserTipe]: a `perawat` or `bidan` who is neither cannot be a chat sender,
/// which is why this is a separate three-member enum and not a narrowed alias.
enum ChatPengirimTipe {
  /// `pasien`
  pasien('pasien'),

  /// `dokter`
  dokter('dokter'),

  /// `sistem`
  sistem('sistem');

  const ChatPengirimTipe(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static ChatPengirimTipe? fromWire(String? value) =>
      _decode(ChatPengirimTipe.values, value, (ChatPengirimTipe v) => v.wire);
}

/// `konsultasi_chat.tipe_pesan` -- `telemedicine_test.sql:568-569` --
/// `ENUM('teks','gambar','dokumen','audio','video_note','resep',
/// 'surat_keterangan','sistem')` -- `NOT NULL DEFAULT 'teks'`.
///
/// Eight members in three groups, and the grouping is what a renderer keys on:
///
/// | group | members | what it carries |
/// | --- | --- | --- |
/// | free text | `teks` | `isi` |
/// | upload | `gambar`, `dokumen`, `audio`, `video_note` | `file_url`, `file_nama`, `file_ukuran_kb` |
/// | authored on another screen | `resep`, `surat_keterangan` | `isi`, plus a row in `resep` (:742) / `surat_keterangan` (:581) |
/// | no human author | `sistem` | `isi` |
///
/// The three groups are inferred from the member names and from the columns
/// beside them, not from anything the DDL states: nothing in
/// `konsultasi_chat` says which members populate `file_url`, and the endpoint
/// that publishes these rows does not exist yet (plan todo 32). The inference is
/// recorded rather than presented as fact, so todo 32 can contradict it in one
/// place.
///
/// `resep` and `surat_keterangan` are not free text the sender typed: both
/// tables carry `dokter_id NOT NULL` (`:748`, `:587`), so a doctor authors them
/// on a prescription or medical-letter screen and they reach the transcript as
/// their own row. A bubble that renders all eight as a string is wrong for five
/// of them.
///
/// The column is `NOT NULL DEFAULT 'teks'`, so a create that omits it is stored
/// as `teks` rather than rejected -- the default is a real state a client can
/// receive, and [ChatTipePesan] models `teks` first for that reason.
enum ChatTipePesan {
  /// `teks`
  teks('teks'),

  /// `gambar`
  gambar('gambar'),

  /// `dokumen`
  dokumen('dokumen'),

  /// `audio`
  audio('audio'),

  /// `video_note`
  videoNote('video_note'),

  /// `resep`
  resep('resep'),

  /// `surat_keterangan`
  suratKeterangan('surat_keterangan'),

  /// `sistem`
  sistem('sistem');

  const ChatTipePesan(this.wire);

  /// The exact DDL member, as it appears on the wire.
  final String wire;

  /// Decodes a DDL member, or returns `null` when the value is not a member.
  static ChatTipePesan? fromWire(String? value) =>
      _decode(ChatTipePesan.values, value, (ChatTipePesan v) => v.wire);

  /// Whether the member is an upload rather than typed text.
  ///
  /// **Inferred, not stated by the DDL.** True for the four members whose names
  /// denote a file -- `gambar`, `dokumen`, `audio`, `video_note` -- and false for
  /// the other four. The inference rests on those names and on the three
  /// `file_*` columns sitting beside this one (`:571-573`, all `NULL`-able);
  /// nothing in `konsultasi_chat` says which members populate them, and the
  /// publishing endpoint is the plan's todo 32.
  ///
  /// It is exposed because a bubble has to choose between a text body and a
  /// preview before it has read the columns, and branching on `fileUrl != null`
  /// instead gets that choice wrong in the one case that matters: an upload whose
  /// file failed to store has a null `file_url` and is still a [gambar], so a
  /// `fileUrl` test renders an empty bubble where a retryable error belongs.
  bool get isAttachment => switch (this) {
    ChatTipePesan.gambar ||
    ChatTipePesan.dokumen ||
    ChatTipePesan.audio ||
    ChatTipePesan.videoNote => true,
    ChatTipePesan.teks ||
    ChatTipePesan.resep ||
    ChatTipePesan.suratKeterangan ||
    ChatTipePesan.sistem => false,
  };

  /// Whether the member is authored by the server rather than by a party.
  ///
  /// True for [sistem] only. [resep] and [suratKeterangan] are **not** included:
  /// `resep` (:742) and `surat_keterangan` (:581) are their own tables with a
  /// `dokter_id` each, so a doctor authors them from a prescription or a
  /// medical-letter screen and they arrive in the transcript as a row referencing
  /// that record. Calling them server-written would misattribute a doctor's work
  /// to the backend.
  ///
  /// A [sistem] message is the one member with no human author, which is why it
  /// is the one a bubble should render as a notice and refuse to attach a reply
  /// to.
  bool get isSystemGenerated => switch (this) {
    ChatTipePesan.sistem => true,
    ChatTipePesan.teks ||
    ChatTipePesan.gambar ||
    ChatTipePesan.dokumen ||
    ChatTipePesan.audio ||
    ChatTipePesan.videoNote ||
    ChatTipePesan.resep ||
    ChatTipePesan.suratKeterangan => false,
  };
}

/// Decodes [wire] against [values] using [wireOf], or returns `null`.
///
/// Returns `null` rather than throwing for an unknown member. The server is the
/// authority on a closed ENUM, and a client that has not been rebuilt after a
/// schema migration should render the unknown value as-is and log it, not crash
/// on a screen the user is looking at.
///
/// [wireOf] is passed in rather than read off [Enum] reflectively because Dart's
/// `Enum` has no `wire` member, so a generic `value.wire` here would be a dynamic
/// call that the analyzer cannot check -- and an unchecked dynamic call is
/// exactly how a mistyped DDL member survives to runtime.
T? _decode<T extends Enum>(
  List<T> values,
  String? wire,
  String Function(T value) wireOf,
) {
  if (wire == null) {
    return null;
  }

  for (final T value in values) {
    if (wireOf(value) == wire) {
      return value;
    }
  }

  return null;
}
