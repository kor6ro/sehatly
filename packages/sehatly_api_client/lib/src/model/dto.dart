import '../core/json.dart';
import 'enums.dart';

/// The token pair, exactly as `App\Http\Resources\AuthTokenResource` publishes it.
///
/// ```json
/// {
///   "token_type": "Bearer",
///   "access_token": "<secret>",
///   "expires_in": 1439,
///   "access_token_expires_at": "2026-01-02T03:04:05.000000Z",
///   "refresh_token": "<secret>",
///   "refresh_token_expires_at": "2026-02-02T03:04:05.000000Z"
/// }
/// ```
///
/// ## `expires_in` is a duration in seconds, not a timestamp
///
/// It is the number a client can act on without a clock: a cache stores "expires
/// in 1439 seconds" and re-authenticates when it reaches zero. The two absolute
/// `*_expires_at` values are published alongside it for a client that does have a
/// trustworthy clock and wants to schedule a refresh rather than react to one.
/// Comparing an absolute stamp without trusting the device clock is how a
/// handset set two hours fast logs every user out.
///
/// ## `token_type` is always `Bearer`
///
/// `AuthTokenPair::TOKEN_TYPE` is a constant, so this is a field the server
/// publishes for a standard-compliant client rather than a value that varies. It
/// is modelled because a standard client should read it, and it is used verbatim
/// in the `Authorization` header rather than hard-coded there.
///
/// ## `toString` and `==` deliberately do not leak the secrets
///
/// [toString] redacts both tokens. A `print()` or a `log()` of a pair is a
/// routine debugging move, and a bearer token in a log file is a bearer token in
/// a backup. This mirrors the server, where `AuthTokenPair::__debugInfo()`
/// redacts the same values.
class TokenPair {
  /// Creates a pair directly, without parsing.
  const TokenPair({
    required this.tokenType,
    required this.accessToken,
    required this.expiresIn,
    this.accessTokenExpiresAt,
    required this.refreshToken,
    this.refreshTokenExpiresAt,
  });

  /// Parses the `data.token` object.
  ///
  /// [accessToken] and [refreshToken] are required and an absent one throws a
  /// [FormatException]: a token pair with a missing secret is not a degraded
  /// pair, it is a contract violation, and storing a half-pair would leave the
  /// client unable to refresh and unable to say why. Everything else is optional,
  /// because everything else is informational.
  factory TokenPair.fromJson(Map<String, Object?> json) {
    final String? access = jsonString(json['access_token']);
    final String? refresh = jsonString(json['refresh_token']);

    if (access == null || access.isEmpty) {
      throw const FormatException('Token pair is missing "access_token".');
    }

    if (refresh == null || refresh.isEmpty) {
      throw const FormatException('Token pair is missing "refresh_token".');
    }

    return TokenPair(
      tokenType: jsonString(json['token_type']) ?? 'Bearer',
      accessToken: access,
      expiresIn: jsonInt(json['expires_in']) ?? 0,
      accessTokenExpiresAt: jsonDateTime(json['access_token_expires_at']),
      refreshToken: refresh,
      refreshTokenExpiresAt: jsonDateTime(json['refresh_token_expires_at']),
    );
  }

  /// The authorization scheme, always `Bearer`.
  final String tokenType;

  /// The bearer token. Never log this.
  final String accessToken;

  /// Seconds until [accessToken] expires, measured from the response.
  final int expiresIn;

  /// The absolute UTC expiry of [accessToken], or `null` when not published.
  final DateTime? accessTokenExpiresAt;

  /// The long-lived secret. Never log this.
  final String refreshToken;

  /// The absolute UTC expiry of [refreshToken], or `null` when not published.
  final DateTime? refreshTokenExpiresAt;

  /// The value for the `Authorization` header, e.g. `Bearer eyJ...`.
  String get authorizationHeader => '$tokenType $accessToken';

  /// Whether [accessToken] is known to have expired at [now].
  ///
  /// Uses [accessTokenExpiresAt] and deliberately ignores [expiresIn]: a
  /// duration is only meaningful relative to the moment the response arrived,
  /// and by the time this is called that moment may be several minutes ago. A
  /// client that wants the duration should record its own receipt time.
  bool isAccessTokenExpired({DateTime? now}) {
    final DateTime? expiry = accessTokenExpiresAt;

    if (expiry == null) {
      return false;
    }

    return !(now ?? DateTime.now().toUtc()).isBefore(expiry);
  }

  @override
  String toString() {
    return 'TokenPair(tokenType: $tokenType, accessToken: <redacted>, '
        'expiresIn: $expiresIn, refreshToken: <redacted>)';
  }
}

/// The OTP the server minted, as published inside `data.otp`.
///
/// ```json
/// {"tujuan": "login", "kedaluwarsa_at": "...", "ttl_detik": 300, "kode": "014725"}
/// ```
///
/// ## `kode` is a STRING of six digits, and it must stay one
///
/// `OtpService` zero-pads the code, so a code may begin with `0`. The server
/// validates it with a regex rather than `digits:6` and never casts it to a
/// number, and its own docblock says a client that parsed this as an integer
/// would send a five-digit code that can never match. It is typed `String` here
/// for that reason and there is deliberately no `int` accessor.
///
/// ## `kode` is populated at all only outside production
///
/// `OtpService::plainTextForClient()` returns the code only when the environment
/// is local; in production this member is `null` and the code arrives over SMS.
/// It is nullable, never defaulted to a placeholder, so a client that formats it
/// unconditionally crashes in development rather than sending a literal "000000"
/// to the verify endpoint in production.
class OtpChallenge {
  /// Creates a challenge directly, without parsing.
  const OtpChallenge({
    required this.tujuan,
    required this.kedaluwarsaAt,
    required this.ttlDetik,
    this.kode,
  });

  /// Parses the `data.otp` object.
  factory OtpChallenge.fromJson(Map<String, Object?> json) {
    return OtpChallenge(
      tujuan:
          OtpTujuan.fromWire(jsonString(json['tujuan'])) ??
          OtpTujuan.verifikasiTelepon,
      kedaluwarsaAt: jsonDateTime(json['kedaluwarsa_at']),
      ttlDetik: jsonInt(json['ttl_detik']) ?? 0,
      kode: jsonString(json['kode']),
    );
  }

  /// What this code was minted for.
  ///
  /// A code is only valid for the purpose it was issued for, so the client has
  /// to carry this back on verify rather than assuming "the last code I asked
  /// for". Defaults to [OtpTujuan.verifikasiTelepon] when the server sends a
  /// value outside the column, which keeps a `fromJson` total.
  final OtpTujuan tujuan;

  /// The absolute UTC instant the code stops working.
  final DateTime? kedaluwarsaAt;

  /// Seconds from issue until [kedaluwarsaAt]. `OtpService::TTL_MENIT` is 5,
  /// so this is normally 300.
  final int ttlDetik;

  /// The code itself, as a zero-padded six-digit string, or `null`.
  ///
  /// `null` outside the local environment. See the class docblock.
  final String? kode;

  @override
  String toString() {
    return 'OtpChallenge(tujuan: ${tujuan.wire}, ttlDetik: $ttlDetik, '
        'kode: ${kode == null ? '<not published>' : '<redacted>'})';
  }
}

/// The `users` projection, as published by `App\Http\Resources\UserResource`.
///
/// The scalar members are `users` columns and nothing else -- the resource is an
/// **allow-list**, so a column added to `users` later is invisible to the API
/// until somebody adds it there on purpose. `kata_sandi_hash`
/// (`telemedicine_test.sql:138`) is absent by that mechanism and not by a model
/// attribute, which is what makes "the password hash can never be published" a
/// property of the resource rather than of an `#[Hidden]` a future edit could
/// remove.
///
/// `nik` is **not** on this projection. It lives on [PasienProfile] and is
/// always masked; there is no code path that publishes a raw NIK.
class User {
  /// Creates a user directly, without parsing.
  const User({
    required this.id,
    required this.uuid,
    required this.namaLengkap,
    this.noTelepon,
    this.email,
    required this.tipe,
    required this.status,
    this.bahasa,
    this.fotoProfil,
    this.teleponTerverifikasi = false,
    this.emailTerverifikasi = false,
    this.lastLoginAt,
    this.dibuatAt,
    this.pasien,
    this.dokter,
  });

  /// Parses a `UserResource` body.
  ///
  /// [pasien] and [dokter] are read with `whenLoaded()` semantics on the server:
  /// `GET /api/v1/me` eager-loads both and the register / verify endpoints load
  /// neither, so the two keys are **absent** on those responses rather than
  /// `null`. Reading them as nullable keeps a single type for all three
  /// endpoints, and the docblock on each member says which endpoints populate it.
  factory User.fromJson(Map<String, Object?> json) {
    return User(
      id: jsonInt(json['id']) ?? 0,
      uuid: jsonString(json['uuid']) ?? '',
      namaLengkap: jsonString(json['nama_lengkap']) ?? '',
      noTelepon: jsonString(json['no_telepon']),
      email: jsonString(json['email']),
      tipe: UserTipe.fromWire(jsonString(json['tipe'])) ?? UserTipe.pasien,
      status:
          UserStatus.fromWire(jsonString(json['status'])) ??
          UserStatus.pendingVerifikasi,
      bahasa: Bahasa.fromWire(jsonString(json['bahasa'])),
      fotoProfil: jsonString(json['foto_profil']),
      teleponTerverifikasi: jsonBool(json['telepon_terverifikasi']) ?? false,
      emailTerverifikasi: jsonBool(json['email_terverifikasi']) ?? false,
      lastLoginAt: jsonDateTime(json['last_login_at']),
      dibuatAt: jsonDateTime(json['dibuat_at']),
      pasien: _readNested(json['pasien'], PasienProfile.fromJson),
      dokter: _readNested(json['dokter'], DokterAccount.fromJson),
    );
  }

  /// `users.id`
  final int id;

  /// `users.uuid`
  final String uuid;

  /// `users.nama_lengkap`
  final String namaLengkap;

  /// `users.no_telepon`. `NOT NULL` in the DDL, nullable here because a
  /// projection that stops publishing it should not break parsing.
  final String? noTelepon;

  /// `users.email`, or `null`.
  ///
  /// `VARCHAR(255) NULL UNIQUE` (:136), so two accounts may both have `null`
  /// here. A phone-only registration has no email at all.
  final String? email;

  /// `users.tipe`
  final UserTipe tipe;

  /// `users.status`
  final UserStatus status;

  /// `users.bahasa`
  final Bahasa? bahasa;

  /// `users.foto_profil`, or `null`.
  final String? fotoProfil;

  /// `users.telepon_terverifikasi`
  final bool teleponTerverifikasi;

  /// `users.email_terverifikasi`
  final bool emailTerverifikasi;

  /// `users.last_login_at`, or `null` before the first successful verify.
  final DateTime? lastLoginAt;

  /// `users.dibuat_at`
  final DateTime? dibuatAt;

  /// The caller's own `pasien` row.
  ///
  /// Present **only** on `GET /api/v1/me`, which eager-loads it. `null` on
  /// `POST /auth/register` and `POST /auth/otp/verify`, which publish the caller's
  /// own `users` row without the relation -- the server omits the key rather
  /// than publishing a `null` for a row that exists.
  final PasienProfile? pasien;

  /// The caller's own `dokter` row, when the account is a doctor.
  ///
  /// Present **only** on `GET /api/v1/me`. `null` for a patient account, where
  /// the relation genuinely is `null`, and absent on the two auth endpoints.
  final DokterAccount? dokter;

  @override
  String toString() =>
      'User(id: $id, namaLengkap: $namaLengkap, tipe: ${tipe.wire})';
}

/// The `pasien` projection, as published by `App\Http\Resources\PasienResource`.
///
/// ## `nik` and `nomorKk` are ALWAYS masked
///
/// Both go through `App\Support\NikMasker`, which keeps the first four and the
/// last four characters and replaces everything between with U+2022 BULLET. The
/// output is always exactly as long as the input.
///
/// **There is no unmasked accessor and no raw NIK anywhere in this package.** An
/// edit form therefore has to keep the value it was given rather than read it
/// back, which is the cost the server accepts in exchange for never disclosing
/// it. `pasien.nik` is `CHAR(16) NULL UNIQUE` and is marked in the DDL as
/// requiring application-level or TDE encryption under UU PDP; publishing it raw
/// would undo that.
///
/// ## Three columns are deliberately absent
///
/// `user_id` (the list is always the caller's own), `nomor_ihs_satusehat` (a
/// Kemenkes SATUSEHAT identity with no self-service use), and `catatan_alergi`
/// -- the last because it is a **second, unsynchronised source of truth** for
/// allergies alongside the `pasien_alergi` table that
/// `GET /api/v1/pasien/alergi` owns. Publishing both from one response is how a
/// client ends up showing two disagreeing allergy lists.
///
/// ## `tanggalLahir` and `tanggalMeninggal` are calendar dates, held as strings
///
/// `PasienResource` emits them with `toDateString()`, i.e. `Y-m-d`, and never
/// converts them. They are typed `String` here for the same reason: a `DATE` is a
/// calendar date, and parsing it into a `DateTime` means choosing an offset, and
/// a zone shift moves somebody's birthday. A client that needs a `DateTime` for a
/// date picker should construct one at local noon rather than parse midnight UTC.
class PasienProfile {
  /// Creates a patient profile directly, without parsing.
  const PasienProfile({
    required this.id,
    this.nomorRm,
    this.nik,
    this.nomorKk,
    this.namaLengkap,
    this.jenisKelamin,
    this.tanggalLahir,
    this.tempatLahir,
    this.golonganDarahId,
    this.rhesus,
    this.agamaId,
    this.pendidikanId,
    this.pekerjaan,
    this.statusPernikahanId,
    this.alamatLengkap,
    this.provinsiId,
    this.kabupatenKotaId,
    this.kecamatanId,
    this.kelurahanId,
    this.rt,
    this.rw,
    this.kodePos,
    this.tinggiBadanCm,
    this.beratBadanKg,
    this.isMeninggal = false,
    this.tanggalMeninggal,
    this.dibuatAt,
    this.diubahAt,
  });

  /// Parses a `PasienResource` body.
  factory PasienProfile.fromJson(Map<String, Object?> json) {
    return PasienProfile(
      id: jsonInt(json['id']) ?? 0,
      nomorRm: jsonString(json['nomor_rm']),
      nik: jsonString(json['nik']),
      nomorKk: jsonString(json['nomor_kk']),
      namaLengkap: jsonString(json['nama_lengkap']),
      jenisKelamin: JenisKelamin.fromWire(jsonString(json['jenis_kelamin'])),
      tanggalLahir: jsonString(json['tanggal_lahir']),
      tempatLahir: jsonString(json['tempat_lahir']),
      golonganDarahId: jsonInt(json['golongan_darah_id']),
      rhesus: Rhesus.fromWire(jsonString(json['rhesus'])),
      agamaId: jsonInt(json['agama_id']),
      pendidikanId: jsonInt(json['pendidikan_id']),
      pekerjaan: jsonString(json['pekerjaan']),
      statusPernikahanId: jsonInt(json['status_pernikahan_id']),
      alamatLengkap: jsonString(json['alamat_lengkap']),
      provinsiId: jsonInt(json['provinsi_id']),
      kabupatenKotaId: jsonInt(json['kabupaten_kota_id']),
      kecamatanId: jsonInt(json['kecamatan_id']),
      kelurahanId: jsonInt(json['kelurahan_id']),
      rt: jsonString(json['rt']),
      rw: jsonString(json['rw']),
      kodePos: jsonString(json['kode_pos']),
      tinggiBadanCm: jsonDouble(json['tinggi_badan_cm']),
      beratBadanKg: jsonDouble(json['berat_badan_kg']),
      isMeninggal: jsonBool(json['is_meninggal']) ?? false,
      tanggalMeninggal: jsonString(json['tanggal_meninggal']),
      dibuatAt: jsonDateTime(json['dibuat_at']),
      diubahAt: jsonDateTime(json['diubah_at']),
    );
  }

  /// `pasien.id`
  final int id;

  /// `pasien.nomor_rm`, or `null`. Format `RM-YYYYMM-XXXXXX` per the DDL.
  final String? nomorRm;

  /// `pasien.nik`, **masked**. Never raw. See the class docblock.
  final String? nik;

  /// `pasien.nomor_kk`, **masked**. Never raw.
  final String? nomorKk;

  /// `users.nama_lengkap`, published from the eager-loaded `user` relation.
  ///
  /// `pasien` has no name column. It is duplicated from [User.namaLengkap] on
  /// purpose: two projections of one account may each carry the field they need,
  /// and forcing a profile screen to fetch `/me` first is a second round trip for
  /// a string.
  final String? namaLengkap;

  /// `pasien.jenis_kelamin`
  final JenisKelamin? jenisKelamin;

  /// `pasien.tanggal_lahir` as `Y-m-d`. A calendar date, not an instant.
  final String? tanggalLahir;

  /// `pasien.tempat_lahir`
  final String? tempatLahir;

  /// `pasien.golongan_darah_id`, a `master_golongan_darah` id, or `null`.
  final int? golonganDarahId;

  /// `pasien.rhesus`
  final Rhesus? rhesus;

  /// `pasien.agama_id`, a `master_agama` id, or `null`.
  final int? agamaId;

  /// `pasien.pendidikan_id`, a `master_pendidikan` id, or `null`.
  final int? pendidikanId;

  /// `pasien.pekerjaan`
  final String? pekerjaan;

  /// `pasien.status_pernikahan_id`, a `master_status_pernikahan` id, or `null`.
  final int? statusPernikahanId;

  /// `pasien.alamat_lengkap`
  final String? alamatLengkap;

  /// `pasien.provinsi_id`, a `master_provinsi` id, or `null`.
  final int? provinsiId;

  /// `pasien.kabupaten_kota_id`, a `master_kabupaten_kota` id, or `null`.
  final int? kabupatenKotaId;

  /// `pasien.kecamatan_id`, a `master_kecamatan` id, or `null`.
  final int? kecamatanId;

  /// `pasien.kelurahan_id`, a `master_kelurahan` id, or `null`.
  final int? kelurahanId;

  /// `pasien.rt`, or `null`. A `VARCHAR(5)` in the DDL, not a number.
  final String? rt;

  /// `pasien.rw`, or `null`. A `VARCHAR(5)` in the DDL, not a number.
  final String? rw;

  /// `pasien.kode_pos`, or `null`. A `CHAR(5)`, so a string with leading zeros
  /// is meaningful and parsing it as a number would lose them.
  final String? kodePos;

  /// `pasien.tinggi_badan_cm`, a `DECIMAL(5,1)`, or `null`.
  final double? tinggiBadanCm;

  /// `pasien.berat_badan_kg`, a `DECIMAL(5,2)`, or `null`.
  final double? beratBadanKg;

  /// `pasien.is_meninggal`. Read-only: it is not a writable profile key.
  final bool isMeninggal;

  /// `pasien.tanggal_meninggal` as `Y-m-d`, or `null`.
  final String? tanggalMeninggal;

  /// `pasien.dibuat_at`
  final DateTime? dibuatAt;

  /// `pasien.diubah_at`
  final DateTime? diubahAt;

  @override
  String toString() => 'PasienProfile(id: $id, namaLengkap: $namaLengkap)';
}

/// One `user_devices` row, as published by `App\Http\Resources\UserDeviceResource`.
///
/// ## The surrogate `id` and `user_id` are NOT published
///
/// `user_devices` has a `BIGINT UNSIGNED AUTO_INCREMENT` surrogate *and* a
/// `UNIQUE KEY uq_device (user_id, device_id)` (:201). The API addresses a device
/// by the **string** `device_id`, so the surrogate is omitted rather than
/// published as a number that is not stable across environments. `user_id` is
/// omitted because the list is always the caller's own.
class UserDevice {
  /// Creates a device directly, without parsing.
  const UserDevice({
    required this.deviceId,
    required this.platform,
    this.fcmToken,
    this.appVersi,
    this.aktif = true,
    this.lastActiveAt,
    this.dibuatAt,
  });

  /// Parses a `UserDeviceResource` body.
  factory UserDevice.fromJson(Map<String, Object?> json) {
    return UserDevice(
      deviceId: jsonString(json['device_id']) ?? '',
      platform:
          DevicePlatform.fromWire(jsonString(json['platform'])) ??
          DevicePlatform.web,
      fcmToken: jsonString(json['fcm_token']),
      appVersi: jsonString(json['app_versi']),
      aktif: jsonBool(json['aktif']) ?? false,
      lastActiveAt: jsonDateTime(json['last_active_at']),
      dibuatAt: jsonDateTime(json['dibuat_at']),
    );
  }

  /// `user_devices.device_id`: the client-supplied installation identifier, a
  /// `VARCHAR(255)` string, and the value `DELETE /api/v1/auth/devices/{deviceId}`
  /// takes. **Not** the surrogate key.
  final String deviceId;

  /// `user_devices.platform`
  final DevicePlatform platform;

  /// `user_devices.fcm_token`, or `null`.
  ///
  /// Published because the client just supplied it and a device-management screen
  /// needs to show which push registration is live. It is not a credential for
  /// this API: possession of it grants no access.
  final String? fcmToken;

  /// `user_devices.app_versi`, or `null`.
  final String? appVersi;

  /// `user_devices.aktif`.
  ///
  /// Set back to `true` by `POST /api/v1/auth/devices` on the upsert, which is
  /// what makes a device deactivated by a logout usable again.
  final bool aktif;

  /// `user_devices.last_active_at`
  final DateTime? lastActiveAt;

  /// `user_devices.dibuat_at`
  final DateTime? dibuatAt;

  @override
  String toString() =>
      'UserDevice(deviceId: $deviceId, platform: ${platform.wire})';
}

/// One `pasien_anggota_keluarga` row, as published by
/// `App\Http\Resources\PasienAnggotaKeluargaResource`.
///
/// A family member is registered **by** a patient and has **no account of its
/// own** (the DDL's own comment at :258), so this row is never addressable by
/// anybody but the account holder and `pasien_id` is never published.
///
/// ## `nik` is masked, exactly like [PasienProfile.nik]
///
/// The plan's masked-`nik` rule is written about `pasien.nik`; applying it here
/// too is the consistent extension, and the server records it as a deliberate
/// widening rather than assuming it. The cost is that an edit form cannot read
/// the NIK back and has to keep what it was given.
class AnggotaKeluarga {
  /// Creates a family member directly, without parsing.
  const AnggotaKeluarga({
    required this.id,
    this.hubunganId,
    this.hubungan,
    this.nik,
    required this.namaLengkap,
    this.jenisKelamin,
    this.tanggalLahir,
    this.noTelepon,
    this.catatanAlergi,
    this.dibuatAt,
  });

  /// Parses a `PasienAnggotaKeluargaResource` body.
  factory AnggotaKeluarga.fromJson(Map<String, Object?> json) {
    return AnggotaKeluarga(
      id: jsonInt(json['id']) ?? 0,
      hubunganId: jsonInt(json['hubungan_id']),
      hubungan: jsonString(json['hubungan']),
      nik: jsonString(json['nik']),
      namaLengkap: jsonString(json['nama_lengkap']) ?? '',
      jenisKelamin: JenisKelamin.fromWire(jsonString(json['jenis_kelamin'])),
      tanggalLahir: jsonString(json['tanggal_lahir']),
      noTelepon: jsonString(json['no_telepon']),
      catatanAlergi: jsonString(json['catatan_alergi']),
      dibuatAt: jsonDateTime(json['dibuat_at']),
    );
  }

  /// `pasien_anggota_keluarga.id`
  final int id;

  /// `pasien_anggota_keluarga.hubungan_id`, a `master_hubungan_keluarga` id.
  final int? hubunganId;

  /// `master_hubungan_keluarga.nama`, e.g. `Ibu`.
  ///
  /// Published only when the relation was eager-loaded. The Module 1 endpoints
  /// both load it, so this is populated for every response they produce; it is
  /// nullable because the server uses `whenLoaded()`.
  final String? hubungan;

  /// `pasien_anggota_keluarga.nik`, **masked**. Never raw.
  final String? nik;

  /// `pasien_anggota_keluarga.nama_lengkap`
  final String namaLengkap;

  /// `pasien_anggota_keluarga.jenis_kelamin`
  final JenisKelamin? jenisKelamin;

  /// `pasien_anggota_keluarga.tanggal_lahir` as `Y-m-d`. A calendar date.
  final String? tanggalLahir;

  /// `pasien_anggota_keluarga.no_telepon`
  final String? noTelepon;

  /// `pasien_anggota_keluarga.catatan_alergi`
  ///
  /// Free text about this *person*, and distinct from the account holder's own
  /// `pasien.catatan_alergi` and from the `pasien_alergi` table. See
  /// [PasienAlergi] for why the table is the API's allergy surface.
  final String? catatanAlergi;

  /// `pasien_anggota_keluarga.dibuat_at`
  final DateTime? dibuatAt;

  @override
  String toString() => 'AnggotaKeluarga(id: $id, namaLengkap: $namaLengkap)';
}

/// One `pasien_alergi` row, as published by
/// `App\Http\Resources\PasienAlergiResource`.
///
/// ## `namaAlergen` is free text and is NOT joined to `master_obat`
///
/// The column is a `VARCHAR(150)` with no foreign key, so there is no join even
/// if one were wanted. The server publishes the stored string exactly as written
/// and invents no normalisation, and this client does the same: a normalised
/// spelling that disagreed with the database would be a second thing to keep
/// true.
///
/// The schema's recorded limitation, which this client cannot fix: allergy
/// matching is best-effort name matching against `resep_item.nama_obat` (a
/// snapshot), and `pasien.catatan_alergi` is a second unsynchronised source.
class PasienAlergi {
  /// Creates an allergy entry directly, without parsing.
  const PasienAlergi({
    required this.id,
    this.tipeAlergen,
    this.namaAlergen,
    this.reaksi,
    this.keparahan,
    this.dicatatOlehUserId,
    this.dibuatAt,
  });

  /// Parses a `PasienAlergiResource` body.
  factory PasienAlergi.fromJson(Map<String, Object?> json) {
    return PasienAlergi(
      id: jsonInt(json['id']) ?? 0,
      tipeAlergen: TipeAlergen.fromWire(jsonString(json['tipe_alergen'])),
      namaAlergen: jsonString(json['nama_alergen']),
      reaksi: jsonString(json['reaksi']),
      keparahan: Keparahan.fromWire(jsonString(json['keparahan'])),
      dicatatOlehUserId: jsonInt(json['dicatat_oleh_user_id']),
      dibuatAt: jsonDateTime(json['dibuat_at']),
    );
  }

  /// `pasien_alergi.id`
  final int id;

  /// `pasien_alergi.tipe_alergen`
  final TipeAlergen? tipeAlergen;

  /// `pasien_alergi.nama_alergen`, free text. See the class docblock.
  final String? namaAlergen;

  /// `pasien_alergi.reaksi`, free text, or `null`.
  final String? reaksi;

  /// `pasien_alergi.keparahan`, defaulting to `ringan` in the DDL.
  final Keparahan? keparahan;

  /// `pasien_alergi.dicatat_oleh_user_id`, or `null`.
  ///
  /// A bare id because the column has **no foreign key** (:281), so there is no
  /// relation to join and nothing to nest. The write path sets it to the calling
  /// account, so an entry whose value equals the caller's own id is
  /// self-reported; an update does **not** rewrite it, because it records who
  /// first recorded the row.
  ///
  /// Never used as an authorisation input: a row is scoped by `pasien_id` alone.
  final int? dicatatOlehUserId;

  /// `pasien_alergi.dibuat_at`
  final DateTime? dibuatAt;

  @override
  String toString() => 'PasienAlergi(id: $id, namaAlergen: $namaAlergen)';
}

/// One `master_spesialisasi` row, as published by
/// `App\Http\Resources\MasterSpesialisasiResource`.
///
/// The whole table, all three columns: there is no timestamp, no soft delete and
/// no relation to hide behind, so this resource is the complete row.
///
/// [tipe] is [MasterSpesialisasiTipe], **not** [DokterTipe]. They share exactly
/// one member.
class MasterSpesialisasi {
  /// Creates a specialisation directly, without parsing.
  const MasterSpesialisasi({
    required this.id,
    required this.kode,
    required this.nama,
    this.tipe,
  });

  /// Parses a `MasterSpesialisasiResource` body.
  factory MasterSpesialisasi.fromJson(Map<String, Object?> json) {
    return MasterSpesialisasi(
      id: jsonInt(json['id']) ?? 0,
      kode: jsonString(json['kode']) ?? '',
      nama: jsonString(json['nama']) ?? '',
      tipe: MasterSpesialisasiTipe.fromWire(jsonString(json['tipe'])),
    );
  }

  /// `master_spesialisasi.id`
  final int id;

  /// `master_spesialisasi.kode`, e.g. `SP.PD`, `UMUM`, `GIGI`.
  ///
  /// `VARCHAR(10) NOT NULL UNIQUE`. This is the value the `?spesialisasi=` filter
  /// accepts as a non-numeric lookup, which is why it is modelled rather than
  /// left as decoration.
  final String kode;

  /// `master_spesialisasi.nama`
  final String nama;

  /// `master_spesialisasi.tipe`. See the class docblock on the two vocabularies.
  final MasterSpesialisasiTipe? tipe;

  @override
  String toString() => 'MasterSpesialisasi(kode: $kode, nama: $nama)';
}

/// One row of `v_dokter_katalog`, as published by
/// `App\Http\Resources\DokterResource`.
///
/// ## [spesialisasi] is a STRING here and an array on the detail endpoint
///
/// The list projection reads the view, whose `spesialisasi` is
/// `GROUP_CONCAT(s.nama SEPARATOR ', ')` -- already joined, already display-ready,
/// and `null` for a doctor with no `dokter_spesialisasi` row. The detail
/// projection reads the `dokter` table and publishes a structured array instead.
/// The two are deliberately different types, [String?] here and
/// `List<DokterSpesialisasi>` on [DokterDetail.spesialisasi], so a client cannot
/// write one call site's use of it against the other.
///
/// The value is also **truncated silently** at MySQL's `group_concat_max_len`
/// (server default 1024) for a doctor with many specialisations. A client needing
/// the complete set must read [DokterDetail].
///
/// ## Two columns are absent, not null
///
/// `durasi_default_menit` and `jumlah_ulasan` are real `dokter` columns and both
/// are in the detail projection, but the view does not select them. A `null` here
/// would tell a client the doctor has no reviews rather than that the list
/// endpoint does not report them.
class DokterListing {
  /// Creates a directory row directly, without parsing.
  const DokterListing({
    required this.id,
    required this.namaLengkap,
    this.tipe,
    this.spesialisasi,
    this.biayaKonsultasiOnline,
    this.ratingRataRata,
    this.jumlahKonsultasi,
    this.statusVerifikasi = DokterStatusVerifikasi.terverifikasi,
  });

  /// Parses a `DokterResource` body.
  factory DokterListing.fromJson(Map<String, Object?> json) {
    return DokterListing(
      id: jsonInt(json['id']) ?? 0,
      namaLengkap: jsonString(json['nama_lengkap']) ?? '',
      tipe: DokterTipe.fromWire(jsonString(json['tipe'])),
      spesialisasi: jsonDelimitedList(json['spesialisasi']),
      biayaKonsultasiOnline: jsonDouble(json['biaya_konsultasi_online']),
      ratingRataRata: jsonDouble(json['rating_rata_rata']),
      jumlahKonsultasi: jsonInt(json['jumlah_konsultasi']),
      statusVerifikasi:
          DokterStatusVerifikasi.fromWire(
            jsonString(json['status_verifikasi']),
          ) ??
          DokterStatusVerifikasi.terverifikasi,
    );
  }

  /// `v_dokter_katalog.dokter_id`, i.e. the `dokter` primary key.
  final int id;

  /// `users.nama_lengkap`, joined by the view.
  final String namaLengkap;

  /// `dokter.tipe`. Note [DokterTipe], not [MasterSpesialisasiTipe].
  final DokterTipe? tipe;

  /// The view's `GROUP_CONCAT` string, or `null` when the doctor has no
  /// specialisation row. See the class docblock.
  final List<String>? spesialisasi;

  /// `dokter.biaya_konsultasi_online`, a `DECIMAL(12,2)`, or `null`.
  final double? biayaKonsultasiOnline;

  /// `dokter.rating_rata_rata`, a `DECIMAL(3,2)`, or `null`.
  final double? ratingRataRata;

  /// `dokter.jumlah_konsultasi`
  final int? jumlahKonsultasi;

  /// `dokter.status_verifikasi`, a constant `terverifikasi` for every row this
  /// endpoint returns.
  final DokterStatusVerifikasi statusVerifikasi;

  @override
  String toString() => 'DokterListing(id: $id, namaLengkap: $namaLengkap)';
}

/// One `dokter_spesialisasi` row, joined out to the master record it points at.
///
/// The **detail** endpoint's shape. The list endpoint publishes a
/// `GROUP_CONCAT` string instead; see [DokterListing.spesialisasi].
class DokterSpesialisasi {
  /// Creates an entry directly, without parsing.
  const DokterSpesialisasi({
    this.id,
    this.kode,
    this.nama,
    this.tipe,
    this.isUtama = false,
  });

  /// Parses one element of the `spesialisasi` array.
  factory DokterSpesialisasi.fromJson(Map<String, Object?> json) {
    return DokterSpesialisasi(
      id: jsonInt(json['id']),
      kode: jsonString(json['kode']),
      nama: jsonString(json['nama']),
      tipe: MasterSpesialisasiTipe.fromWire(jsonString(json['tipe'])),
      isUtama: jsonBool(json['is_utama']) ?? false,
    );
  }

  /// `master_spesialisasi.id`, or `null` when the master row could not be joined.
  final int? id;

  /// `master_spesialisasi.kode`, or `null`.
  final String? kode;

  /// `master_spesialisasi.nama`, or `null`.
  final String? nama;

  /// `master_spesialisasi.tipe`, or `null`.
  final MasterSpesialisasiTipe? tipe;

  /// `dokter_spesialisasi.is_utama`.
  ///
  /// Published because the list ordering is built on it, so a client rendering
  /// the profile has to see which specialisation the doctor considers primary.
  final bool isUtama;

  @override
  String toString() => 'DokterSpesialisasi(kode: $kode, nama: $nama)';
}

/// One `dokter_pendidikan` row, as published by [DokterDetail].
class DokterPendidikan {
  /// Creates an entry directly, without parsing.
  const DokterPendidikan({
    this.id,
    this.jenjang,
    this.institusi,
    this.tahunLulus,
  });

  /// Parses one element of the `pendidikan` array.
  ///
  /// The year key is `tahun_lulus`, which is the DDL's own column name (:462). The
  /// plan's todo 22 prose writes `tahun_lullah` in one clause and `tahun_lulus`
  /// in the next; the DDL decides, and `tahun_lulus` is what is used. The
  /// misspelling is called out here so a future reader does not "fix" it back.
  factory DokterPendidikan.fromJson(Map<String, Object?> json) {
    return DokterPendidikan(
      id: jsonInt(json['id']),
      jenjang: DokterJenjang.fromWire(jsonString(json['jenjang'])),
      institusi: jsonString(json['institusi']),
      tahunLulus: jsonInt(json['tahun_lulus']),
    );
  }

  /// `dokter_pendidikan.id`
  final int? id;

  /// `dokter_pendidikan.jenjang`
  final DokterJenjang? jenjang;

  /// `dokter_pendidikan.institusi`
  final String? institusi;

  /// `dokter_pendidikan.tahun_lulus`, or `null`.
  ///
  /// A nullable `SMALLINT UNSIGNED`, and the ordering puts a null year **last**
  /// because MySQL sorts `NULL` last under `DESC`. A doctor who has not graduated
  /// has no year; that is a real state, not missing data.
  final int? tahunLulus;

  @override
  String toString() =>
      'DokterPendidikan(jenjang: ${jenjang?.wire}, '
      'institusi: $institusi)';
}

/// One `dokter_faskes` row, as published by [DokterDetail].
///
/// A composite-primary-key pivot (:447-:455) with **no** `id` column and no
/// timestamps, so [faskesId] is the handle.
///
/// [statusAktif] is published and **not** filtered on: `dokter_faskes` has its
/// own `status_aktif`, distinct from `faskes.status_aktif`, and hiding an
/// inactive affiliation would leave a client unable to tell "not affiliated" from
/// "affiliation withdrawn".
class DokterFaskes {
  /// Creates an entry directly, without parsing.
  const DokterFaskes({
    required this.faskesId,
    this.isUtama = false,
    this.statusAktif = true,
    this.kodeFaskes,
    this.nama,
    this.tipe,
    this.kelasRs,
    this.alamat,
  });

  /// Parses one element of the `faskes` array.
  factory DokterFaskes.fromJson(Map<String, Object?> json) {
    return DokterFaskes(
      faskesId: jsonInt(json['faskes_id']) ?? 0,
      isUtama: jsonBool(json['is_utama']) ?? false,
      statusAktif: jsonBool(json['status_aktif']) ?? false,
      kodeFaskes: jsonString(json['kode_faskes']),
      nama: jsonString(json['nama']),
      tipe: jsonString(json['tipe']),
      kelasRs: jsonString(json['kelas_rs']),
      alamat: jsonString(json['alamat']),
    );
  }

  /// `dokter_faskes.faskes_id`
  final int faskesId;

  /// `dokter_faskes.is_utama`
  final bool isUtama;

  /// `dokter_faskes.status_aktif`, published unfiltered. See the class docblock.
  final bool statusAktif;

  /// `faskes.kode_faskes`, or `null`.
  final String? kodeFaskes;

  /// `faskes.nama`, or `null`.
  final String? nama;

  /// `faskes.tipe`, or `null`.
  ///
  /// Kept a `String` rather than an enum: the `faskes` table is not published
  /// anywhere else in Module 1, no endpoint filters on it, and the DDL member list
  /// belongs to a module that does not exist yet. Transcribing it here would be
  /// an enum with one known consumer and no test behind it.
  final String? tipe;

  /// `faskes.kelas_rs`, or `null`.
  final String? kelasRs;

  /// `faskes.alamat`, or `null`.
  final String? alamat;

  @override
  String toString() => 'DokterFaskes(faskesId: $faskesId, nama: $nama)';
}

/// `GET /api/v1/dokter/{dokter}`: the full public profile of one eligible doctor.
///
/// ## Four things are never published, and one test pins it on the wire
///
/// `nomor_str`, `nomor_sip`, `nomor_ihs_satusehat` and the two licence-document
/// URLs are absent. A licence number is an administrative identifier and a
/// `VARCHAR(500)` document URL would hand an anonymous caller a signed-URL
/// surface if storage is ever configured for it. `no_telepon` and `email` are
/// absent too: a doctor's contact is reached through a booking.
///
/// ## Nothing at all is published about the STR
///
/// `str_berlaku_sampai` is a rule *input*: the service already decided the
/// doctor is eligible because of it. There is no STR field on this model and no
/// accessor for one, so a client cannot accidentally render an expiry reminder
/// that is an administrative concern.
class DokterDetail {
  /// Creates a detail profile directly, without parsing.
  const DokterDetail({
    required this.id,
    required this.namaLengkap,
    this.fotoProfil,
    this.tipe,
    this.pengalamanTahun,
    this.bio,
    this.biayaKonsultasiOnline,
    this.biayaLuarJam,
    this.durasiDefaultMenit,
    this.ratingRataRata,
    this.jumlahUlasan,
    this.jumlahKonsultasi,
    this.tersediaTelemedisin = false,
    this.statusVerifikasi,
    this.spesialisasi = const <DokterSpesialisasi>[],
    this.pendidikan = const <DokterPendidikan>[],
    this.faskes = const <DokterFaskes>[],
    this.dibuatAt,
  });

  /// Parses a `DokterDetailResource` body.
  factory DokterDetail.fromJson(Map<String, Object?> json) {
    return DokterDetail(
      id: jsonInt(json['id']) ?? 0,
      namaLengkap: jsonString(json['nama_lengkap']) ?? '',
      fotoProfil: jsonString(json['foto_profil']),
      tipe: DokterTipe.fromWire(jsonString(json['tipe'])),
      pengalamanTahun: jsonInt(json['pengalaman_tahun']),
      bio: jsonString(json['bio']),
      biayaKonsultasiOnline: jsonDouble(json['biaya_konsultasi_online']),
      biayaLuarJam: jsonDouble(json['biaya_luar_jam']),
      durasiDefaultMenit: jsonInt(json['durasi_default_menit']),
      ratingRataRata: jsonDouble(json['rating_rata_rata']),
      jumlahUlasan: jsonInt(json['jumlah_ulasan']),
      jumlahKonsultasi: jsonInt(json['jumlah_konsultasi']),
      tersediaTelemedisin: jsonBool(json['tersedia_telemedisin']) ?? false,
      statusVerifikasi: DokterStatusVerifikasi.fromWire(
        jsonString(json['status_verifikasi']),
      ),
      spesialisasi: jsonList(json['spesialisasi'])
          .map((Object? row) => DokterSpesialisasi.fromJson(jsonMap(row)))
          .toList(growable: false),
      pendidikan: jsonList(json['pendidikan'])
          .map((Object? row) => DokterPendidikan.fromJson(jsonMap(row)))
          .toList(growable: false),
      faskes: jsonList(json['faskes'])
          .map((Object? row) => DokterFaskes.fromJson(jsonMap(row)))
          .toList(growable: false),
      dibuatAt: jsonDateTime(json['dibuat_at']),
    );
  }

  /// `dokter.id`
  final int id;

  /// `users.nama_lengkap`, read from the eager-loaded `user` relation.
  final String namaLengkap;

  /// `users.foto_profil`, or `null`.
  ///
  /// Read from the `dokter` table's parent relation rather than the view, which
  /// does not expose it at all.
  final String? fotoProfil;

  /// `dokter.tipe`
  final DokterTipe? tipe;

  /// `dokter.pengalaman_tahun`
  final int? pengalamanTahun;

  /// `dokter.bio`, or `null`.
  final String? bio;

  /// `dokter.biaya_konsultasi_online`, a `DECIMAL(12,2)`.
  final double? biayaKonsultasiOnline;

  /// `dokter.biaya_luar_jam`, a nullable `DECIMAL(12,2)`, or `null`.
  final double? biayaLuarJam;

  /// `dokter.durasi_default_menit`
  final int? durasiDefaultMenit;

  /// `dokter.rating_rata_rata`, a `DECIMAL(3,2)`.
  final double? ratingRataRata;

  /// `dokter.jumlah_ulasan`
  final int? jumlahUlasan;

  /// `dokter.jumlah_konsultasi`
  final int? jumlahKonsultasi;

  /// `dokter.tersedia_telemedisin`
  final bool tersediaTelemedisin;

  /// `dokter.status_verifikasi`
  final DokterStatusVerifikasi? statusVerifikasi;

  /// `dokter_spesialisasi` joined to `master_spesialisasi`, primary first.
  final List<DokterSpesialisasi> spesialisasi;

  /// `dokter_pendidikan`, most recent qualification first.
  final List<DokterPendidikan> pendidikan;

  /// `dokter_faskes` joined to `faskes`.
  final List<DokterFaskes> faskes;

  /// `dokter.dibuat_at`.
  ///
  /// `dokter.diubah_at` is **not** published. It is
  /// `ON UPDATE CURRENT_TIMESTAMP`, so it moves whenever an administrator
  /// touches the row for any reason and would tell a patient when the doctor's
  /// profile was last edited, which is neither useful nor the plan's field list.
  final DateTime? dibuatAt;

  @override
  String toString() => 'DokterDetail(id: $id, namaLengkap: $namaLengkap)';
}

/// The `dokter` row `GET /api/v1/me` publishes for the caller's **own** doctor
/// account, as `App\Http\Resources\DokterAkunResource` does.
///
/// ## Not to be confused with [DokterDetail]
///
/// Two projections of two different tables for two different audiences. The
/// public directory reads the `v_dokter_katalog` view; this one reads the
/// `dokter` table for the account holder. They are not reconcilable by widening
/// either, and the server keeps them in separate classes for the same reason.
///
/// ## The same four things are still absent
///
/// `nomor_str`, `nomor_sip`, `file_str_url` and `file_sip_url` are refused here
/// too, for a structural reason rather than a philosophical one: a resource is a
/// reusable class, the public directory is the second thing anybody will build on
/// a "doctor profile" projection, and a credential inside a shared projection is
/// one refactor away from a public endpoint. The server asserts it with a test
/// that no `/me` body contains `nomor_str`.
///
/// ## [spesialisasi] and [pendidikan] are `null` when the relation is unloaded
///
/// The server returns `null` rather than `[]` for a relation it did not
/// eager-load, so a caller that forgot to load it cannot mistake "not loaded" for
/// "has none". `GET /api/v1/me` does load both, so both are populated for that
/// endpoint.
class DokterAccount {
  /// Creates a doctor account projection directly, without parsing.
  const DokterAccount({
    required this.id,
    this.tipe,
    this.nomorIhsSatusehat,
    this.pengalamanTahun,
    this.bio,
    this.biayaKonsultasiOnline,
    this.biayaLuarJam,
    this.durasiDefaultMenit,
    this.ratingRataRata,
    this.jumlahUlasan,
    this.jumlahKonsultasi,
    this.tersediaTelemedisin = false,
    this.statusVerifikasi,
    this.statusAktif = true,
    this.spesialisasi,
    this.pendidikan,
    this.dibuatAt,
    this.diubahAt,
  });

  /// Parses a `DokterAkunResource` body.
  factory DokterAccount.fromJson(Map<String, Object?> json) {
    return DokterAccount(
      id: jsonInt(json['id']) ?? 0,
      tipe: DokterTipe.fromWire(jsonString(json['tipe'])),
      nomorIhsSatusehat: jsonString(json['nomor_ihs_satusehat']),
      pengalamanTahun: jsonInt(json['pengalaman_tahun']),
      bio: jsonString(json['bio']),
      biayaKonsultasiOnline: jsonDouble(json['biaya_konsultasi_online']),
      biayaLuarJam: jsonDouble(json['biaya_luar_jam']),
      durasiDefaultMenit: jsonInt(json['durasi_default_menit']),
      ratingRataRata: jsonDouble(json['rating_rata_rata']),
      jumlahUlasan: jsonInt(json['jumlah_ulasan']),
      jumlahKonsultasi: jsonInt(json['jumlah_konsultasi']),
      tersediaTelemedisin: jsonBool(json['tersedia_telemedisin']) ?? false,
      statusVerifikasi: DokterStatusVerifikasi.fromWire(
        jsonString(json['status_verifikasi']),
      ),
      statusAktif: jsonBool(json['status_aktif']) ?? true,
      spesialisasi: _readNestedList(
        json['spesialisasi'],
        (Map<String, Object?> row) => _akunSpesialisasi(row),
      ),
      pendidikan: _readNestedList(
        json['pendidikan'],
        (Map<String, Object?> row) => DokterPendidikan.fromJson(row),
      ),
      dibuatAt: jsonDateTime(json['dibuat_at']),
      diubahAt: jsonDateTime(json['diubah_at']),
    );
  }

  /// `dokter.id`
  final int id;

  /// `dokter.tipe`
  final DokterTipe? tipe;

  /// `dokter.nomor_ihs_satusehat`, or `null`.
  ///
  /// The account holder's own SATUSEHAT practitioner identifier, published here
  /// because a doctor has no other way to see it. The equivalent patient column
  /// is *not* published, for the same class of reason.
  final String? nomorIhsSatusehat;

  /// `dokter.pengalaman_tahun`
  final int? pengalamanTahun;

  /// `dokter.bio`, or `null`.
  final String? bio;

  /// `dokter.biaya_konsultasi_online`
  final double? biayaKonsultasiOnline;

  /// `dokter.biaya_luar_jam`
  final double? biayaLuarJam;

  /// `dokter.durasi_default_menit`
  final int? durasiDefaultMenit;

  /// `dokter.rating_rata_rata`
  final double? ratingRataRata;

  /// `dokter.jumlah_ulasan`
  final int? jumlahUlasan;

  /// `dokter.jumlah_konsultasi`
  final int? jumlahKonsultasi;

  /// `dokter.tersedia_telemedisin`
  final bool tersediaTelemedisin;

  /// `dokter.status_verifikasi`
  final DokterStatusVerifikasi? statusVerifikasi;

  /// `dokter.status_aktif`
  final bool statusAktif;

  /// `dokter_spesialisasi` joined to `master_spesialisasi`, primary first.
  ///
  /// `null` when the relation was not eager-loaded. Note the key is
  /// `spesialisasi_id` here, not `id`: the pivot column is what this projection
  /// publishes, because it is a different projection from [DokterSpesialisasi],
  /// which joins out to the master's `id`.
  final List<DokterAkunSpesialisasi>? spesialisasi;

  /// `dokter_pendidikan`, most recent qualification first.
  ///
  /// `null` when the relation was not eager-loaded. There is no `id` in this
  /// projection, unlike [DokterPendidikan], because the account holder does
  /// not address their own education rows.
  final List<DokterPendidikan>? pendidikan;

  /// `dokter.dibuat_at`
  final DateTime? dibuatAt;

  /// `dokter.diubah_at`
  ///
  /// Published here, unlike on [DokterDetail]. This projection is the doctor's
  /// own back-office view of their row, so "when was my profile last touched" is
  /// a question it is allowed to answer.
  final DateTime? diubahAt;

  @override
  String toString() => 'DokterAccount(id: $id, tipe: ${tipe?.wire})';
}

/// One `dokter_spesialisasi` row as [DokterAccount] publishes it.
///
/// Distinct from [DokterSpesialisasi] because the key is different: this one
/// carries the **pivot's** `spesialisasi_id`, the other carries the **master's**
/// `id`. Both are correct for their own projection and neither is a typo.
class DokterAkunSpesialisasi {
  /// Creates an entry directly, without parsing.
  const DokterAkunSpesialisasi({
    required this.spesialisasiId,
    this.kode,
    this.nama,
    this.tipe,
    this.isUtama = false,
  });

  /// `dokter_spesialisasi.spesialisasi_id`, the **pivot** column.
  final int spesialisasiId;

  /// `master_spesialisasi.kode`, or `null`.
  final String? kode;

  /// `master_spesialisasi.nama`, or `null`.
  final String? nama;

  /// `master_spesialisasi.tipe`, or `null`.
  final MasterSpesialisasiTipe? tipe;

  /// `dokter_spesialisasi.is_utama`
  final bool isUtama;

  @override
  String toString() =>
      'DokterAkunSpesialisasi(spesialisasiId: $spesialisasiId, '
      'nama: $nama)';
}

/// A deleted row's acknowledgement: `data: {"deleted": true, "id": 7}`.
///
/// Both Module 1 delete endpoints answer 200 with this shape rather than 204, so
/// that a client parsing one envelope does not have to special-case a delete, and
/// so a client that fired several deletes can tell which one answered.
class DeletedRow {
  /// Creates an acknowledgement directly, without parsing.
  const DeletedRow({required this.id, this.deleted = true});

  /// Parses the `data` object of a delete response.
  factory DeletedRow.fromJson(Map<String, Object?> json) {
    return DeletedRow(
      id: jsonInt(json['id']) ?? 0,
      deleted: jsonBool(json['deleted']) ?? false,
    );
  }

  /// The `id` echoed back from the request path.
  final int id;

  /// Always `true` for a successful delete.
  final bool deleted;

  @override
  String toString() => 'DeletedRow(id: $id, deleted: $deleted)';
}

/// Parses a value through [parse] when it is a JSON object, or returns `null`.
///
/// This is `whenLoaded()` on the Dart side. `UserResource` emits the `pasien` and
/// `dokter` keys **only** when the relation was loaded, and omits them otherwise
/// so that "not loaded" is never published as a `null` for a row that exists.
/// Parsing an absent key to `null` is therefore faithful, not lossy.
T? _readNested<T>(Object? value, T Function(Map<String, Object?> json) parse) {
  if (value is! Map<Object?, Object?>) {
    return null;
  }

  return parse(jsonMap(value));
}

/// Parses a value into a list through [parse], or returns `null` when absent.
///
/// The `null` matters for [DokterAccount.spesialisasi] and
/// [DokterAccount.pendidikan], where the server publishes `null` -- not `[]` --
/// for a relation it did not eager-load. See that class's docblock.
List<T>? _readNestedList<T>(
  Object? value,
  T Function(Map<String, Object?> json) parse,
) {
  if (value is! List<Object?>) {
    return null;
  }

  return value
      .map((Object? row) => parse(jsonMap(row)))
      .toList(growable: false);
}

/// Builds a [DokterAkunSpesialisasi] from a `DokterAkunResource` pivot row.
///
/// `spesialisasi_id` is required, so an absent one yields `0` rather than a
/// `null`-typed field: the server always publishes it and a pivot row without it
/// is not addressable.
DokterAkunSpesialisasi _akunSpesialisasi(Map<String, Object?> json) {
  return DokterAkunSpesialisasi(
    spesialisasiId: jsonInt(json['spesialisasi_id']) ?? 0,
    kode: jsonString(json['kode']),
    nama: jsonString(json['nama']),
    tipe: MasterSpesialisasiTipe.fromWire(jsonString(json['tipe'])),
    isUtama: jsonBool(json['is_utama']) ?? false,
  );
}
