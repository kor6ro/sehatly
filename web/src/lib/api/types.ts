/**
 * The domain types, transcribed from the Laravel resources that publish them.
 *
 * ## Provenance, field by field
 *
 * Every shape below is a transcription of a specific `App\Http\Resources\*` class. The
 * file each one came from is named above it, because a hand-written client type that
 * nobody traces back to a resource is how a corrupted identifier survives: nothing on the
 * wire would fail, the value would simply be `undefined` at runtime, and a screen would
 * render a blank where a name belongs.
 *
 * | type | resource |
 * | --- | --- |
 * | `User` | `UserResource` |
 * | `PasienProfile` | `PasienResource` |
 * | `AnggotaKeluarga` | `PasienAnggotaKeluargaResource` |
 * | `Alergi` | `PasienAlergiResource` |
 * | `DokterRingkas` | `DokterResource` |
 * | `DokterDetail` | `DokterDetailResource` |
 * | `Spesialisasi` | `MasterSpesialisasiResource` |
 * | `UserDevice` | `UserDeviceResource` |
 * | `Booking` | `BookingResource` |
 * | `IssuedToken` | `AuthTokenResource` (re-declared in `lib/http.ts`) |
 * | `OtpChallenge` | the inline `otp` array in `AuthController` |
 * | `Konsultasi`, `KonsultasiPesan` | `KonsultasiResource`, `KonsultasiChatResource` |
 * | `RekamMedis`, `RekamMedisRantai` | `RekamMedisResource` |
 * | `Resep`, `ResepItem` | `ResepResource`, `ResepItemResource` |
 * | `ResepVerifikasi` | `ResepVerifikasiResource` |
 * | `MasterObat` | `MasterObatResource` |
 * | `PeringatanPeringatan` | `ObatInteraksiService::susunInteraksi()` / `::susunAlergi()` |
 *
 * ## Nullable is spelled honestly
 *
 * A column that is `NULL` in the DDL is `T | null` here even when the server will not
 * send it. `PhotoProfile` is the clearest case: `PasienResource` publishes
 * `nomor_ihs_satusehat` never, but `foto_profil` is a nullable column on a seeded row
 * that may never have been set, and a type that claimed otherwise would push an
 * `undefined` into `img src` on a seeded database.
 */

/**
 * A MySQL `DECIMAL` reaches the client as a JSON **string** unless the Eloquent model
 * casts it, and which of the two happens is a property of the model rather than of the
 * resource. Every decimal below is therefore `number | string | null` and is read through
 * {@link toNumber}. Treating it as a bare `number` is the single most likely way to
 * render `Rp NaN` on a real response.
 */
export type Decimal = number | string | null;

export type Iso = string | null;

/** A `DATE` column, published as `Y-m-d` and never zone-shifted. */
export type Tanggal = string | null;

// ============================================================================
// users
// ============================================================================

/** `users.tipe`, the seven-value ENUM at `telemedicine_test.sql:139`. */
export type UserTipe =
    | 'pasien'
    | 'dokter'
    | 'perawat'
    | 'bidan'
    | 'apoteker'
    | 'admin'
    | 'superadmin';

/** `users.status`, the four-value ENUM at `telemedicine_test.sql:140`. */
export type UserStatus =
    | 'pending_verifikasi'
    | 'aktif'
    | 'nonaktif'
    | 'ditangguhkan';

export type User = {
    id: number;
    uuid: string;
    nama_lengkap: string;
    no_telepon: string;
    email: string | null;
    tipe: UserTipe;
    status: UserStatus;
    bahasa: string;
    foto_profil: string | null;
    telepon_terverifikasi: boolean;
    email_terverifikasi: boolean;
    last_login_at: Iso;
    dibuat_at: Iso;
    /**
     * Present only when the relation was eager-loaded, which today means only on
     * `GET /api/v1/me`. `UserResource` uses `whenLoaded()`, so `POST /auth/register` and
     * `POST /auth/otp/verify` omit the key entirely rather than sending `null` for a row
     * that exists. Optional, not nullable, for exactly that reason.
     */
    pasien?: PasienProfile;
    dokter?: DokterAkun | null;
};

/**
 * The caller's own `dokter` row, published by `UserResource` on `/me` only.
 *
 * Deliberately narrower than the directory's {@link DokterDetail}: this is the account's
 * own view of itself, and `DokterAkunResource` withholds `nomor_str`, `nomor_sip` and the
 * `file_*_url` document links for the same reason `DokterDetailResource` does.
 */
export type DokterAkun = {
    id: number;
    tipe: string;
    pengalaman_tahun: number | null;
    bio: string | null;
    durasi_default_menit: number | null;
    tersedia_telemedisin: boolean;
    status_verifikasi: string;
};

// ============================================================================
// pasien
// ============================================================================

export type PasienProfile = {
    id: number;
    nomor_rm: string | null;
    /**
     * **Always masked.** `PasienResource` runs this through `App\Support\NikMasker`,
     * which keeps the first four and the last four characters and replaces everything
     * between them with U+2022 BULLET, so the published length always equals the stored
     * length. The value is not a NIK and must never be treated as one: it is not
     * editable, it is not a valid `digits:16` input, and there is no operation in this
     * client that can unmask it.
     */
    nik: string | null;
    /** Masked for the same reason, and for the same reason it is not editable here. */
    nomor_kk: string | null;
    nama_lengkap: string | null;
    jenis_kelamin: 'L' | 'P';
    tanggal_lahir: Tanggal;
    tempat_lahir: string | null;
    golongan_darah_id: number | null;
    rhesus: string | null;
    agama_id: number | null;
    pendidikan_id: number | null;
    pekerjaan: string | null;
    status_pernikahan_id: number | null;
    alamat_lengkap: string;
    provinsi_id: number | null;
    kabupaten_kota_id: number | null;
    kecamatan_id: number | null;
    kelurahan_id: number | null;
    rt: string | null;
    rw: string | null;
    kode_pos: string | null;
    tinggi_badan_cm: Decimal;
    berat_badan_kg: Decimal;
    is_meninggal: boolean;
    tanggal_meninggal: Tanggal;
    dibuat_at: Iso;
    diubah_at: Iso;
};

// ============================================================================
// pasien_anggota_keluarga
// ============================================================================

export type AnggotaKeluarga = {
    id: number;
    hubungan_id: number;
    /**
     * `master_hubungan_keluarga.nama`, present only when the relation was
     * eager-loaded. The list endpoint loads it, so this is populated in practice; it stays
     * optional because the resource's contract is `whenLoaded()`.
     */
    hubungan?: string | null;
    /** Masked, like {@link PasienProfile.nik}. Write-only from a client's point of view. */
    nik: string | null;
    nama_lengkap: string;
    jenis_kelamin: 'L' | 'P';
    tanggal_lahir: Tanggal;
    no_telepon: string | null;
    catatan_alergi: string | null;
    dibuat_at: Iso;
};

// ============================================================================
// pasien_alergi
// ============================================================================

/** `pasien_alergi.tipe_alergen`, the four-value ENUM at `telemedicine_test.sql:277`. */
export type TipeAlergen = 'obat' | 'makanan' | 'lingkungan' | 'lainnya';

/** `pasien_alergi.keparahan`, the four-value ENUM at `telemedicine_test.sql:280`. */
export type Keparahan = 'ringan' | 'sedang' | 'berat' | 'anafilaksis';

export type Alergi = {
    id: number;
    tipe_alergen: TipeAlergen;
    /**
     * Free text, and deliberately **not** validated against `master_obat`: an allergy is
     * frequently to a food, a latex or a household chemical that has no row in a
     * medicine catalogue. The published string is the stored string, unnormalised.
     */
    nama_alergen: string;
    reaksi: string | null;
    keparahan: Keparahan;
    /**
     * A bare column with no foreign key (`telemedicine_test.sql:281`), published as the
     * raw id. Equal to the caller's own `id` means the row is self-reported.
     */
    dicatat_oleh_user_id: number | null;
    dibuat_at: Iso;
};

// ============================================================================
// The doctor directory
// ============================================================================

/** `dokter.tipe`, the seven-value ENUM at `telemedicine_test.sql:412`. */
export type DokterTipe =
    | 'dokter_umum'
    | 'dokter_spesialis'
    | 'dokter_gigi'
    | 'psikolog'
    | 'bidan'
    | 'perawat'
    | 'apoteker';

export type DokterRingkas = {
    /** `v_dokter_katalog.dokter_id`, not `dokter.id`; see `DokterKatalog`'s docblock. */
    id: number;
    nama_lengkap: string;
    tipe: DokterTipe;
    /**
     * The view's `GROUP_CONCAT(s.nama SEPARATOR ', ')` string, passed through untouched.
     * **`null`, not `[]`,** for a doctor with no `dokter_spesialisasi` row, because
     * `GROUP_CONCAT` over nothing is `NULL`. It is also silently truncated at
     * `group_concat_max_len` (the server default, 1024) for a doctor with many
     * specialisations, which is why the detail endpoint publishes a structured list.
     */
    spesialisasi: string | null;
    biaya_konsultasi_online: Decimal;
    rating_rata_rata: Decimal;
    jumlah_konsultasi: number;
    /** A constant in this projection: a row here is `terverifikasi` by construction. */
    status_verifikasi: string;
};

export type DokterSpesialisasi = {
    id: number | null;
    kode: string | null;
    nama: string | null;
    /**
     * `master_spesialisasi.tipe`, the **three**-value ENUM at
     * `telemedicine_test.sql:406` - not `dokter.tipe`. The two share exactly one member
     * and `'spesialis'` here is not `'dokter_spesialis'` there.
     */
    tipe: 'dokter_umum' | 'spesialis' | 'subspesialis' | null;
    is_utama: boolean;
};

export type DokterPendidikan = {
    id: number;
    jenjang: string | null;
    institusi: string | null;
    /** `dokter_pendidikan.tahun_lulus`; the plan's prose says "tahun_lullah" and the column does not. */
    tahun_lulus: number | null;
};

export type DokterFaskes = {
    /** `dokter_faskes` is a composite PK, so there is no surrogate id to publish. */
    faskes_id: number;
    is_utama: boolean;
    status_aktif: boolean;
    kode_faskes: string | null;
    nama: string | null;
    tipe: string | null;
    kelas_rs: string | null;
    alamat: string | null;
};

export type DokterDetail = {
    id: number;
    nama_lengkap: string;
    foto_profil: string | null;
    tipe: DokterTipe;
    pengalaman_tahun: number | null;
    bio: string | null;
    biaya_konsultasi_online: Decimal;
    biaya_luar_jam: Decimal;
    durasi_default_menit: number | null;
    rating_rata_rata: Decimal;
    jumlah_ulasan: number | null;
    jumlah_konsultasi: number;
    tersedia_telemedisin: boolean;
    status_verifikasi: string;
    spesialisasi: DokterSpesialisasi[];
    pendidikan: DokterPendidikan[];
    faskes: DokterFaskes[];
    dibuat_at: Iso;
};

export type Spesialisasi = {
    id: number;
    kode: string;
    nama: string;
    tipe: 'dokter_umum' | 'spesialis' | 'subspesialis';
};

// ============================================================================
// user_devices
// ============================================================================

export type UserDevice = {
    device_id: string;
    platform: string;
    fcm_token: string | null;
    app_versi: string | null;
    aktif: boolean;
    last_active_at: Iso;
    dibuat_at: Iso;
};

// ============================================================================
// The OTP challenge
// ============================================================================

/** The two purposes Module 1 actually issues, from `OtpService::TUJUAN_DI_TERBITKAN`. */
export type OtpTujuan = 'verifikasi_telepon' | 'login';

/**
 * The `otp` block that `POST /auth/register` and `POST /auth/login` return inline.
 *
 * ## `kode` is intentionally absent, and that is the point
 *
 * `AuthController` publishes the plaintext code as `$issued->plainTextForClient()`,
 * which returns it **only** when `app()->environment('local')`; `phpunit.xml` forces
 * `testing`, so under the test suite the key is `null` and in production it is absent.
 * Declaring it here would be a standing invitation to render a one-box bypass, and it
 * would be a dev-only affordance shipping to real users the moment it was wired up.
 *
 * Omitting the key makes reading it a **compile error** rather than a review comment, so
 * the rule is enforced by the type system instead of by anybody remembering it.
 * `Todo 24`'s Dart client has the same obligation and this is the reason.
 */
export type OtpChallenge = {
    tujuan: OtpTujuan;
    kedaluwarsa_at: Iso;
    /** `OtpService::TTL_MENIT * 60`. Rendered as a countdown, not as a number. */
    ttl_detik: number;
};

// ============================================================================
// booking
// ============================================================================

/**
 * `booking.tipe_layanan` ENUM('chat','video_call','kunjungan_klinik','home_visit')
 * at `telemedicine_test.sql:506`, in the DDL's own order.
 *
 * The same four values, in the same order, are `BookingRequest::TIPE_LAYANAN` on the
 * server, and `Rule::in` refuses anything else with a 422 naming the field. A closed
 * vocabulary fixed by the schema is therefore a safe thing for a `Select` to enumerate:
 * the client cannot offer an option the server would reject, and it must not invent one
 * it would accept.
 */
export type TipeLayanan =
    | 'chat'
    | 'video_call'
    | 'kunjungan_klinik'
    | 'home_visit';

/**
 * `dokter_jadwal.tipe_layanan` ENUM('online','klinik','home_visit') at
 * `telemedicine_test.sql:473` - a **different** ENUM from {@link TipeLayanan}.
 *
 * This is the trap the plan's own enum list invites. The two share exactly one member,
 * `home_visit`, and the two names a client would most expect to overlap - `online` and
 * `chat`/`video_call` - are mutually exclusive. A slot's `tipe_layanan` is a
 * *schedule window* classification and a booking's is a *service* classification, so
 * neither is a subset of the other and neither may be widened into the other.
 *
 * `SlotAvailabilityService` publishes the schedule row's own string without narrowing it,
 * which is why {@link Slot}'s field is typed as this union or the raw string.
 */
export type TipeLayananJadwal = 'online' | 'klinik' | 'home_visit';

/**
 * `booking.status`, the EIGHT-value ENUM at `telemedicine_test.sql:515`-`:516`, in the
 * DDL's own order.
 *
 * Eight, and the order matters twice over. `BookingRequest::STATUS_SEMUA` is the server's
 * list-filter vocabulary and `STATUS_TIDAK_BISA_DIBATALKAN` is its cancel guard, both
 * subsets of this one, and `SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI` is the
 * two that RELEASE a slot. Declaring eight named members rather than `string` is what
 * makes a client that renders "eight distinct badges" a compile error when a ninth value
 * appears, instead of a badge that silently falls through to a default colour.
 */
export type StatusBooking =
    | 'menunggu_pembayaran'
    | 'terjadwal'
    | 'check_in'
    | 'berlangsung'
    | 'selesai'
    | 'dibatalkan'
    | 'no_show'
    | 'kadaluarsa';

/**
 * `booking.dibatalkan_oleh` ENUM('pasien','dokter','sistem') at `:517`.
 *
 * Nullable, and only ever set together with `status = 'dibatalkan'`.
 * `BookingService::dibatalkanOleh()` maps the seven `users.tipe` values onto exactly these
 * three, so the column is closed even though its input is not.
 */
export type DibatalkanOleh = 'pasien' | 'dokter' | 'sistem';

/**
 * One element of `booking.lampiran_keluhan`, the JSON column at `:512`.
 *
 * `BookingResource` re-emits each element in `{nama, url}` order because MySQL sorts JSON
 * object keys by length and reads a stored `{nama, url}` back as `{url, nama}`; the
 * `{nama, url}` shape is therefore the API's contract rather than the storage order. Both
 * members are typed nullable because the resource publishes `?? null` for either, and the
 * write rule (`StoreBookingRequest`) requires both as non-empty, so a half-filled element
 * can only come from another writer.
 */
export type LampiranKeluhan = {
    nama: string | null;
    url: string | null;
};

/**
 * `booking.pasien` as `BookingResource` publishes it **only when the relation was
 * eager-loaded**, which today means only on `GET /api/v1/dokter/booking`.
 *
 * The patient list loads `['dokter', 'jadwal']` and never `pasien`, so a patient reading
 * their own bookings gets no `pasien` key at all - not `null`, absent. That is why this is
 * optional rather than nullable.
 *
 * `nik` is **always masked** by `App\Support\NikMasker`, for the same reason and with the
 * same consequences as {@link PasienProfile.nik}: it is not a NIK, it is not editable, and
 * no operation in this client can unmask it.
 */
export type BookingPasien = {
    id: number;
    /** Masked: first four characters, U+2022 bullets, last four. Never a bare NIK. */
    nik: string | null;
    nama_lengkap: string | null;
};

/**
 * One `booking` row, transcribed field by field from `App\Http\Resources\BookingResource`.
 *
 * The three fields that most often get typed wrongly are called out here because each has
 * been measured against the live API rather than inferred:
 *
 * - `slot_mulai` / `slot_selesai` are **strings**, not `Date`s. `booking.slot_mulai` is
 *   `TIME NOT NULL` (`:508`) and `Booking` declares no cast for either, so
 *   `BookingResource` publishes the raw `H:i:s` string. Observed on the wire as
 *   `"09:00:00"` / `"09:15:00"`. These are **Asia/Jakarta wall clock** and are never
 *   zone-converted, so handing them to `new Date(...)` would shift the displayed time by
 *   the reader's offset - which is the entire reason `formatWaktu` is the wrong formatter
 *   for them and `formatJam` is the right one.
 * - `nomor_antrian` is `number | null` and is **null in practice**. The DDL declares the
 *   column and nothing enforces it, so a newly created booking publishes `null`. A UI that
 *   renders it as though it were assigned shows a blank where a number belongs.
 * - `tanggal_kunjungan` is `toDateString()`, so `Y-m-d` and a calendar date, never an
 *   instant. It is the **consultation date**, which is the day `StrBerlaku` evaluates the
 *   doctor's licence against and never today.
 */
export type Booking = {
    id: number;
    /** `BK<Ymd><suffix>`, e.g. `BK20261028P8UTGR`; `VARCHAR(30) UNIQUE` at `:500`. */
    nomor_booking: string;
    pasien_id: number;
    /** `NULL` means "for the patient themselves", which is the common case. */
    anggota_keluarga_id: number | null;
    dokter_id: number;
    /**
     * `NULL` on an instant booking. Observed `null` for a doctor with no
     * `dokter_jadwal` row, which is the case the `dokter` row lock exists for.
     */
    jadwal_id: number | null;
    /** `NULL` for an instant booking: the venue is not part of a telemedicine slot. */
    faskes_id: number | null;
    tipe_layanan: TipeLayanan;
    /** `Y-m-d`. The CONSULTATION date. */
    tanggal_kunjungan: Tanggal;
    /** `H:i:s`, Asia/Jakarta wall clock, uncast. */
    slot_mulai: string;
    /** `H:i:s`, Asia/Jakarta wall clock, uncast. */
    slot_selesai: string;
    nomor_antrian: number | null;
    keluhan: string | null;
    lampiran_keluhan: LampiranKeluhan[] | null;
    is_rujukan: boolean;
    is_konsultasi_lanjutan: boolean;
    status: StatusBooking;
    dibatalkan_oleh: DibatalkanOleh | null;
    alasan_pembatalan: string | null;
    dibuat_oleh_user_id: number;
    dibuat_at: Iso;
    /** Present only on the doctor-side list. See {@link BookingPasien}. */
    pasien?: BookingPasien;
};

// ============================================================================
// konsultasi
// ============================================================================

/**
 * `konsultasi.status`, the **SIX**-value ENUM at `telemedicine_test.sql:542`, in
 * the DDL's own order.
 *
 * The plan's todo 35 says "the 5 consultation statuses". That is wrong, and the
 * DDL is the authority: the column declares six, `KonsultasiStatus` declares six
 * cases, and `KonsultasiStatus::STATUS_AKHIR` is `['selesai', 'dibatalkan',
 * 'gagal']` - so the three terminal states alone would not fit in five either. This
 * union carries all six; a `Switch` over it is exhaustive by the type checker, and
 * a seventh value is a compile error rather than a badge that falls through to a
 * default colour.
 */
export type StatusKonsultasi =
    | 'menunggu_dokter'
    | 'berlangsung'
    | 'menunggu_resep'
    | 'selesai'
    | 'dibatalkan'
    | 'gagal';

/** `konsultasi.tipe`, read from `KonsultasiTipe` on the server. */
export type TipeKonsultasi = 'chat' | 'video_call' | 'telepon';

/**
 * `konsultasi_chat.pengirim_tipe`, the three-value ENUM at
 * `telemedicine_test.sql:567`.
 *
 * Not `users.tipe`, which is a seven-value ENUM at `:139`: `KonsultasiService::kirim()`
 * derives this from which PROFILE row the caller owns, so a `superadmin` who is
 * party to nothing is refused and never reaches a value here.
 */
export type PengirimTipe = 'pasien' | 'dokter' | 'sistem';

/**
 * `konsultasi_chat.tipe_pesan`, the **EIGHT**-value ENUM at
 * `telemedicine_test.sql:568`-`:569`.
 *
 * Three of the eight - `resep`, `surat_keterangan` and `sistem` - are
 * `KonsultasiService::TIPE_PESAN_SISTEM` and are written by the server when the
 * corresponding document is created. `KirimPesanRequest` accepts all eight on the
 * way in and the SERVICE rejects the three system ones with a 422 on `tipe_pesan`,
 * so a client that only ever offers the other five is refused by nothing it can
 * reach. That is why {@link TIPE_PESAN_KIRIM} exists beside this union.
 */
export type TipePesan =
    | 'teks'
    | 'gambar'
    | 'dokumen'
    | 'audio'
    | 'video_note'
    | 'resep'
    | 'surat_keterangan'
    | 'sistem';

/** The four `TIPE_PESAN_BERKAS` members: the types that need an upload. */
export type TipePesanBerkas = Extract<
    TipePesan,
    'gambar' | 'dokumen' | 'audio' | 'video_note'
>;

/**
 * One `konsultasi_chat` row, transcribed field by field from
 * `App\Http\Resources\KonsultasiChatResource`.
 *
 * ## `id` is the dedupe key, and that is why it is the first field
 *
 * The same row reaches a client by two transports: the REST history page, and the
 * `chat.pesan` broadcast that `KonsultasiController::siarkan()` dispatches with
 * `(new KonsultasiChatResource($pesan))->resolve($request)` - the *same array*, so
 * the two transports carry a byte-identical `id`. A re-subscribe can also make the
 * broker replay a frame. All three collapse to one render because both transports
 * resolve to this field and nothing else. See `lib/realtime/dedupe.ts`.
 *
 * ## `pengirim_nama` is not published, deliberately
 *
 * The resource's docblock is explicit: the sender's name would need an eager load
 * of `pengirimUser` on the hot path of a chat send, and `pengirim_tipe` already
 * answers the only question a renderer asks of it. A bubble therefore labels itself
 * "Anda" or the other side, never a name.
 */
export type KonsultasiPesan = {
    id: number;
    /**
     * `konsultasi_chat.konsultasi_id` at `:565`, named after the column because the
     * column name IS the wire key. A client reading `konsultasi` here would score
     * every message in the transcript as consultation `0`.
     */
    konsultasi_id: number;
    /**
     * `NOT NULL` with a real FK to `users` (`:577`), so a `sistem` row carries one
     * too. Keying "did I write this" off the id alone would claim a system notice
     * as your own, which is why {@link PengirimTipe} is the first thing a bubble
     * asks.
     */
    pengirim_user_id: number;
    pengirim_tipe: PengirimTipe;
    tipe_pesan: TipePesan;
    /** `TEXT NULL` at `:570`; `null` is a state a bubble has to handle. */
    isi: string | null;
    /** Public-disk URL. A descriptor only - no file body is ever inlined. */
    file_url: string | null;
    /** The sanitised basename the service truncated to 255 characters. */
    file_nama: string | null;
    file_ukuran_kb: number | null;
    /**
     * `DATETIME` at `:574` with no offset, read only as "has the other party read
     * it" - the resource publishes `toISOString()` but the *instant* is never the
     * question, and a formatted `DATETIME` is off by the reader's offset.
     */
    dibaca_at: Iso;
    /**
     * `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` at `:575`, so `toISOString()`
     * ends in `Z`. One-second resolution: a burst ties, and the list is therefore
     * ordered `(terkirim_at, id)`.
     */
    terkirim_at: Iso;
};

/**
 * The nested `pasien` block, as `KonsultasiResource` publishes it.
 *
 * `nik` is masked through `App\Support\NikMasker` before it leaves PHP, so it is
 * not a NIK and no operation in this client can unmask it - the same rule as
 * {@link PasienProfile.nik}.
 */
export type KonsultasiPasien = {
    id: number;
    nik: string | null;
    nama_lengkap: string | null;
};

/** The nested `dokter` block. Nothing beyond the name is published. */
export type KonsultasiDokter = {
    id: number;
    nama_lengkap: string | null;
};

/** The nested `booking` block, or `null` for an instant consultation. */
export type KonsultasiBooking = {
    id: number;
    nomor_booking: string;
    tipe_layanan: TipeLayanan;
    tanggal_kunjungan: Tanggal;
    slot_mulai: string;
    slot_selesai: string;
    status: StatusBooking;
};

/**
 * One `konsultasi` row, transcribed field by field from
 * `App\Http\Resources\KonsultasiResource`.
 *
 * The four SOAP columns are named `catatan_subjektif`, `catatan_objektif`,
 * `catatan_asessment` and `catatan_plan` - and `catatan_asessment` is the DDL's
 * spelling at `telemedicine_test.sql:550`, **one `s` short of the English word**.
 * It is not a typo in this client: the column, the migration and the live schema
 * all carry that spelling, and `KonsultasiService::KOLOM_SOAP` publishes it as the
 * writable list. The spelling is called out here because it is the single most
 * likely field in this file to be "corrected" by a reader, which would then send a
 * key the server does not know.
 *
 * `pasien`, `dokter` and `booking` are `whenLoaded()`: `KonsultasiAccess::muatan()`
 * eager-loads them for every `konsultasi` response, but the resource is documented
 * as safe on a bare row, so they stay optional rather than nullable.
 */
export type Konsultasi = {
    id: number;
    booking_id: number | null;
    pasien_id: number;
    dokter_id: number;
    tipe: TipeKonsultasi;
    status: StatusKonsultasi;
    /** `UUID4`, assigned by the service on every start. */
    room_id: string;
    /**
     * `DATETIME` at `:545`, `toISOString()`. The resource narrows with
     * `DateTimeInterface` and not `Illuminate\Support\Carbon` because the cast
     * returns a `Carbon\CarbonImmutable`, a sibling - a naive check publishes
     * `null` for a real start time. That trap is measured server-side and is not
     * repeated here.
     */
    mulai_at: Iso;
    selesai_at: Iso;
    /**
     * The **stored** `INT UNSIGNED` at `:547`, not a live `now() - mulai_at`. It is
     * `null` until the doctor completes the session, which is the truthful answer
     * before there is a duration.
     */
    total_durasi_detik: number | null;
    catatan_subjektif: string | null;
    catatan_objektif: string | null;
    catatan_asessment: string | null;
    catatan_plan: string | null;
    /** `VARCHAR(255) NULL` at `:552` - the only SOAP field with a DDL length. */
    diagnosis_kerja: string | null;
    saran_tindak_lanjut: string | null;
    /**
     * `DECIMAL`, so it arrives as a JSON **string** (`"150000.00"`). Read through
     * `formatRupiah`; typing it `number` is how a screen renders `Rp NaN`.
     */
    biaya_konsultasi: Decimal;
    dibuat_at: Iso;
    diubah_at: Iso;
    pasien?: KonsultasiPasien;
    dokter?: KonsultasiDokter;
    booking?: KonsultasiBooking | null;
};

// ============================================================================
// rekam_medis
// ============================================================================

/**
 * `rekam_medis.status_dokumen`, the three-value ENUM at
 * `telemedicine_test.sql:645`, in the DDL's own order.
 *
 * The DDL's DEFAULT is `'final'`, which is the trap this union exists to make
 * visible: a record born without an explicit status is born SIGNED and immutable.
 * `RekamMedisService::simpan()` therefore writes `'draft'` explicitly, and a client
 * that renders the value it was given must be able to tell the three apart.
 */
export type StatusDokumen = 'draft' | 'final' | 'diamendemen';

/** `rekam_medis.tipe_kunjungan`, the five-value ENUM at `:629`. */
export type TipeKunjungan =
    | 'telemedisin'
    | 'rawat_jalan'
    | 'rawat_inap'
    | 'igd'
    | 'home_visit';

/** `rekam_medis.status_tindak_lanjut`, the five-value ENUM at `:643`. */
export type StatusTindakLanjut =
    | 'pulang_dengan_obat'
    | 'kontrol'
    | 'rujuk'
    | 'rawat_inap'
    | 'ke_igd';

/** `rekam_medis_diagnosa.jenis`, the four-value ENUM at `:662`. */
export type JenisDiagnosa = 'utama' | 'sekunder' | 'diferensial' | 'komplikasi';

/** `rekam_medis_diagnosa.tipe_kasus`, the two-value ENUM at `:663`. */
export type TipeKasus = 'baru' | 'lama';

/** `rekam_medis_lampiran.tipe`, the four-value ENUM at `:686`. */
export type TipeLampiran = 'hasil_lab' | 'radiologi' | 'foto_klinis' | 'dokumen_lain';

/** `rekam_medis_persetujuan.tipe`, the three-value ENUM at `:695`. */
export type TipePersetujuan =
    | 'general_consent'
    | 'persetujuan_tindakan'
    | 'penolakan_tindakan';

export type RekamMedisDiagnosa = {
    id: number;
    /** A bare indexed string with NO foreign key (`:660`), validated in the app. */
    icd10_kode: string;
    deskripsi: string | null;
    jenis: JenisDiagnosa;
    tipe_kasus: TipeKasus;
    is_terkonfirmasi: boolean;
};

export type RekamMedisTindakan = {
    id: number;
    /** A bare indexed string with NO foreign key (`:672`), validated in the app. */
    icd9cm_kode: string | null;
    nama_tindakan: string;
    keterangan: string | null;
    /** `DATETIME`, a rule-(1) instant, `toISOString()`. */
    tanggal_tindakan: Iso;
    dokter_pelaksana_id: number | null;
};

export type RekamMedisLampiran = {
    id: number;
    nama_file: string;
    file_url: string;
    tipe: TipeLampiran;
};

export type RekamMedisPersetujuan = {
    id: number;
    tipe: TipePersetujuan;
    isi_persetujuan: string;
    ditandatangani_oleh: string;
    hubungan_dengan_pasien: string | null;
    tanda_tangan_url: string | null;
    /** `DATETIME NOT NULL`, a rule-(1) instant, `toISOString()`. */
    ditandatangani_at: Iso;
};

/**
 * One entry of the `ran` block: a **reduced** projection, not a nested record.
 *
 * `rekam_medis` has no linkage column, so the amendment chain is reconstructed by
 * `(pasien_id, dokter_id, tanggal_periksa)` ordered by `versi` - a convention, not
 * a constraint. The resource publishes every revision ascending, deliberately not
 * truncated, and this is the only place a client can see the history: there is no
 * chain endpoint, and addressing a revision's own id is a SEPARATE logged read.
 */
export type RekamMedisRantai = {
    id: number;
    uuid: string;
    /** `TINYINT UNSIGNED` at `:646`, cast to `int` by the resource. */
    versi: number;
    status_dokumen: StatusDokumen;
    keluhan_utama: string | null;
    diagnosis_kerja: string | null;
    ditandatangani_at: Iso;
    dibuat_at: Iso;
};

/**
 * One `rekam_medis` row, transcribed field by field from
 * `App\Http\Resources\RekamMedisResource`.
 *
 * ## `adalah_versi_terkini` is derived, not stored
 *
 * There is no `is_current` column. The resource derives it from the loaded `ran`
 * collection - `(int) max('versi') === (int) versi` - and says why it does not
 * re-query: a resource that answered this with its own query would be a read, and a
 * read that did not log is the one thing this design refuses.
 *
 * ## `jadwal_kontrol` is a wall-clock DAY, never an instant
 *
 * It is a `DATE` at `:644` and the resource publishes `toDateString()`. Rendering
 * it through `formatWaktu` would move a next-day appointment to the previous
 * evening in any negative-offset locale. Use `formatTanggal`.
 *
 * The four child collections and `ran` are `whenLoaded()`; `pasien` and `dokter`
 * likewise. The service sets all six relations on every one of its five entry
 * points, so they are populated in practice and optional by contract.
 */
export type RekamMedis = {
    id: number;
    /** `CHAR(36) UNIQUE` at `:623`; a fresh UUID4 per version, which is what makes an amendment a NEW document. */
    uuid: string;
    pasien_id: number;
    faskes_id: number | null;
    dokter_id: number;
    /** `NULL` on a record with no consultation - and then there is no chain key. */
    konsultasi_id: number | null;
    satusehat_encounter_id: string | null;
    tipe_kunjungan: TipeKunjungan;
    /** `DATETIME NOT NULL` at `:630`, a rule-(1) instant, `toISOString()`. */
    tanggal_periksa: Iso;
    keluhan_utama: string | null;
    riwayat_penyakit_sekarang: string | null;
    riwayat_penyakit_dahulu: string | null;
    riwayat_keluarga: string | null;
    riwayat_psikososial: string | null;
    hasil_pemeriksaan_fisik: string | null;
    subjektif: string | null;
    objektif: string | null;
    asesmen: string | null;
    plan: string | null;
    diagnosis_kerja: string | null;
    instruksi_tindak_lanjut: string | null;
    status_tindak_lanjut: StatusTindakLanjut | null;
    /** `Y-m-d`, Asia/Jakarta wall clock. See {@link RekamMedis.jadwal_kontrol}. */
    jadwal_kontrol: Tanggal;
    status_dokumen: StatusDokumen;
    versi: number;
    ditandatangani_at: Iso;
    dibuat_at: Iso;
    diubah_at: Iso;
    adalah_versi_terkini: boolean;
    pasien?: KonsultasiPasien;
    dokter?: KonsultasiDokter;
    diagnosa?: RekamMedisDiagnosa[];
    tindakan?: RekamMedisTindakan[];
    lampiran?: RekamMedisLampiran[];
    persetujuan?: RekamMedisPersetujuan[];
    /** Ascending by `versi`, never truncated. See {@link RekamMedisRantai}. */
    ran?: RekamMedisRantai[];
};

// ============================================================================
// resep / resep_item / resep_verifikasi / master_obat
// ============================================================================

/**
 * `obat_interaksi.tingkat`, the FOUR-value ENUM at `telemedicine_test.sql:735`, in the
 * DDL's own order, which is **ASCENDING seriousness**.
 *
 * That ordering is the whole reason the union is declared rather than `string`:
 * {@link ResepPeringatan} is sorted worst-first by the server, and a client that maps a
 * level to a colour with a `switch` on `string` will silently fall through to a default
 * on a fifth value. Four named members make that a compile error instead.
 */
export type TingkatPeringatan = 'ringan' | 'sedang' | 'berat' | 'kontraindikasi';

/**
 * The severity at which a doctor's acknowledgement is required.
 *
 * `ObatInteraksiService::TINGKAT_KONTRAINDIKASI`. It is a `const` rather than a boolean
 * because the same level appears in two independent places in a response - `tingkat` and
 * the precomputed `wajib_catatan_dokter` - and a client that compared the two by string
 * literal would be duplicating a rule the server already evaluated.
 */
export const TINGKAT_KONTRAINDIKASI = 'kontraindikasi' as const;

/**
 * `obat_interaksi.tingkat` rank, worst LAST so a descending sort puts the worst first.
 *
 * Byte-for-byte the mirror of `ObatInteraksiService::PERINGKAT`. The server already
 * returns its output in that order; this copy exists so a client can order a group it
 * built itself and can rank severities without re-deriving the rule.
 */
export const PERINGKAT: Readonly<Record<TingkatPeringatan, number>> = {
    ringan: 0,
    sedang: 1,
    berat: 2,
    kontraindikasi: 3,
};

/**
 * The three `sumber` values, in the order `ObatInteraksiService::SUMBER` declares them
 * and therefore the order every panel should render them in.
 *
 * A closed vocabulary of exactly three, and the plan's acceptance criterion that
 * `WarningPanel` renders three visually distinct `sumber` groups is only checkable
 * because it is closed: an `antar_item` row and an `alergi` row describe different
 * clinical facts about the same prescription and must not be rendered as one list.
 */
export type SumberPeringatan = 'antar_item' | 'riwayat_resep' | 'alergi';

export const SUMBER_PERINGATAN: readonly SumberPeringatan[] = [
    'antar_item',
    'riwayat_resep',
    'alergi',
] as const;

/** One side of a two-drug interaction, as `ObatInteraksiService::sisi()` builds it. */
export type SisiPeringatan = {
    id: number;
    /** `master_obat.nama_generik`, resolved by the server so no client join is needed. */
    nama: string;
};

/**
 * `rincian` for an `antar_item` / `riwayat_resep` warning, i.e. one backed by an
 * `obat_interaksi` row.
 *
 * Two fields exist to make a correctness claim visible on the wire rather than only in a
 * test. `arah_tersimpan` is the orientation the row is stored in and `arah_diminta` the
 * orientation the lookup asked for; when they differ, the REVERSE-direction probe is what
 * found the row, which is the property `ObatInteraksiService::pasangan()` exists to
 * guarantee. `ganda` says more than one row claimed the same pair at different severities,
 * in which case the panel is showing the worst of them.
 */
export type RincianInteraksi = {
    baris_tertemu: number[];
    ganda: boolean;
    arah_tersimpan: [number, number];
    arah_diminta: [number, number];
    /** `[resepId]` for `riwayat_resep`, `[]` for `antar_item`. */
    resep_id: number[];
};

/**
 * `rincian` for an `alergi` warning, i.e. one backed by a `pasien_alergi` row.
 *
 * `inti_alergi` / `inti_kandidat` / `inti_cocok` are the three normalised name cores the
 * best-effort match decided on. `pasien_alergi.nama_alergen` is free text and is NOT a
 * foreign key to `master_obat`, so a panel that names only `inti_cocok` leaves a doctor
 * unable to answer "why did this fire?" - which is exactly the question a warning exists
 * to raise. `jalur` says whether the match came from the catalogue row or its
 * `kelas_terapi`.
 */
export type RincianAlergi = {
    alergi_id: number;
    nama_alergen: string;
    /** `pasien_alergi.keparahan`, four values - `anafilaksis` is among them. */
    keparahan: 'ringan' | 'sedang' | 'berat' | 'anafilaksis';
    inti_alergi: string;
    inti_kandidat: string;
    inti_cocok: string;
    jalur: string;
};

export type ResepPeringatanRincian = Partial<RincianInteraksi> & Partial<RincianAlergi>;

/**
 * One drug-interaction or allergy warning, exactly as
 * `ObatInteraksiService::susunInteraksi()` / `::susunAlergi()` assemble it.
 *
 * ## `obat_b` is `null` on an `alergi` warning, and that is not a gap
 *
 * An allergy warning names ONE drug against a recorded allergen, so the pair shape does
 * not apply. Typing it `SisiPeringatan` would make a panel that prints
 * `obat_a x obat_b` render a literal "null" beside every allergy warning. It is nullable,
 * and a panel branches on `obat_b === null` instead of string-coercing it.
 *
 * ## `wajib_catatan_dokter` is the server's own verdict, not a client re-derivation
 *
 * It is computed once, in the engine, from the same row that produced `tingkat`. A client
 * that compared `tingkat === 'kontraindikasi'` itself would be right today and wrong the
 * day a fifth severity is added to the ENUM; reading the flag keeps the rule in one place.
 */
export type ResepPeringatan = {
    sumber: SumberPeringatan;
    /**
     * De-duplication key, and it already carries the source - so the same clinical fact
     * from two sources stays two entries while one fact from two database rows collapses
     * to one. Used as a React key precisely because the server defines it as unique.
     */
    kunci: string;
    tingkat: TingkatPeringatan;
    deskripsi: string | null;
    obat_a: SisiPeringatan | null;
    obat_b: SisiPeringatan | null;
    rincian: ResepPeringatanRincian;
    wajib_catatan_dokter: boolean;
};

/**
 * The `warning_grup` block: the SAME warnings keyed by `sumber`.
 *
 * Every key is always present, with an empty list where a source found nothing -
 * `peringatanGrup()` walks `ObatInteraksiService::SUMBER` and fills all three before
 * returning, and the create response builds the map the same way. So this is a total
 * record over {@link SUMBER_PERINGATAN}, not a partial one, and a panel can render three
 * regions unconditionally instead of guessing what it was given.
 */
export type PeringatanGrup = Record<SumberPeringatan, ResepPeringatan[]>;

/**
 * `data.acknowledgement` on the create response, and the answer to "was the override
 * recorded?".
 *
 * `diminta` is `ObatInteraksiService::wajibCatatanDokter()` over the warning set, and
 * `catatan_dodio` is what the service actually stored in `resep.catatan_dokter`. The pair
 * is the load-bearing fact: **`diminta === true` with a `catatan_dodio` present is proof
 * that the prescriber knowingly overrode a contraindication, and it is the only place in
 * the whole schema where that is recorded.** There is no `resep_interaksi` table and no
 * acknowledgement column, so this free-text note IS the record.
 */
export type Acknowledgement = {
    diminta: boolean;
    catatan_dodio: string | null;
    jumlah_peringatan: number;
};

/**
 * `resep.status`, the **EIGHT**-value ENUM at `telemedicine_test.sql:751`-`:752`, in the
 * DDL's own order.
 *
 * Eight, and the split matters more than the count: `ObatInteraksiService::STATUS_BERLAKU`
 * is the first FIVE (a course the patient is still on, and therefore the set a new
 * prescription is checked against) and `STATUS_AKHIR` is the last three (a course that
 * ended). Typing this as `string` would let a panel colour a `selesai` prescription with
 * the "active" treatment and tell a patient their finished course is still running.
 */
export type StatusResep =
    | 'aktif'
    | 'diproses'
    | 'diverifikasi'
    | 'dipenuhi'
    | 'dikirim'
    | 'selesai'
    | 'kedaluwarsa'
    | 'dibatalkan';

/** `resep.tipe`, the two-value ENUM; this client only ever writes `digital`. */
export type TipeResep = 'digital' | 'manual';

/** `resep_verifikasi.status`, the three-value ENUM at `:790`, in the DDL's order. */
export type StatusVerifikasiResep = 'sesuai' | 'ada_koreksi' | 'ditolak';

/** `master_obat.kelas_obat`, the six-value ENUM at `:713`-`:714`. */
export type KelasObat =
    | 'bebas'
    | 'bebas_terbatas'
    | 'keras'
    | 'fitofarmaka'
    | 'narkotika'
    | 'psikotropika';

/**
 * One `master_obat` row, allow-listed by `MasterObatResource`.
 *
 * ## `harga_jual` is a `DECIMAL` and therefore a STRING
 *
 * `ResepItemResource` publishes `harga_satuan` and `subtotal` the same way, and both
 * reach the client as JSON strings. See {@link Decimal}. A screen that renders
 * `resep_item.subtotal` as a bare number prints either `NaN` or a string.
 *
 * ## `nama_brand`, `kelas_terapi` and the clinical columns are genuinely nullable
 *
 * They are `VARCHAR`/`TEXT` NULL at `:715`-`:729`, and a seeded catalogue row has every
 * one of them empty. The composer therefore treats `nama_brand` as a suffix and never as
 * the identity: the identity is `nama_generik` + `kekuatan` + `satuan`.
 */
export type MasterObat = {
    id: number;
    kode_obat: string;
    nama_generik: string;
    nama_brand: string | null;
    /** `master_obat.bentuk_sediaan`, the twelve-value ENUM at `:714`. */
    bentuk_sediaan: string;
    kekuatan: string | null;
    satuan: string;
    pabrikan: string | null;
    kelas_terapi: string | null;
    kelas_obat: KelasObat;
    requires_resep: boolean;
    aturan_pakai_umum: string | null;
    indikasi: string | null;
    kontraindikasi: string | null;
    harga_jual: Decimal;
    status_aktif: boolean;
};

/**
 * One `resep_item` row, publishing the STORED snapshot.
 *
 * ## `nama_obat` is a snapshot, and that is the point
 *
 * `resep_item.nama_obat` is `VARCHAR(255) NOT NULL COMMENT 'Snapshot nama saat
 * diresepkan'` (`:771`): written explicitly from the catalogue at creation and published
 * from the row, never through a live join. Renaming a drug in `master_obat` must not
 * rewrite a prescription a patient is holding, and this field is what makes that true. A
 * client that "fixed" a blank-looking name by re-fetching the catalogue would defeat it.
 *
 * ## `obat_id` is `null` for a racikan, and a racikan is structurally uncheckable
 *
 * `ObatInteraksiService` skips NULL ids with a `whereNotNull`, so a racikan can never
 * produce an interaction or allergy warning. A panel must not imply otherwise.
 */
export type ResepItem = {
    id: number;
    obat_id: number | null;
    nama_obat: string;
    kekuatan: string | null;
    aturan_pakai: string;
    jumlah: number;
    satuan: string | null;
    is_racikan: boolean;
    racikan_nama: string | null;
    harga_satuan: Decimal;
    subtotal: Decimal;
    /** Pharmacist-owned. `prohibited` on the create request, so only the queue writes it. */
    catatan_apoteker: string | null;
};

/**
 * One `resep_verifikasi` row, as `ResepVerifikasiResource` publishes it.
 *
 * ## `terminal` is `true` for ALL THREE outcomes, and that surprises people
 *
 * `resep_verifikasi.resep_id` is `UNIQUE` (`:788`), so the existence of the row at all
 * means the ONE verification is spent. `ditolak` additionally means the prescription is
 * closed forever: there is no `resep.status` meaning "returned for correction" among the
 * eight, so a rejected prescription cannot be revised and re-submitted. Rendering a
 * "resubmit" affordance on a rejection would advertise a flow the schema cannot honour.
 */
export type ResepVerifikasi = {
    id: number;
    resep_id: number | null;
    apoteker_user_id: number | null;
    apoteker: {
        id: number | null;
        nama_lengkap: string | null;
    } | null;
    status: StatusVerifikasiResep;
    catatan: string | null;
    diverifikasi_at: Iso;
    terminal: boolean;
    ditolak: boolean;
};

/**
 * One `resep` row, transcribed field by field from `ResepResource`.
 *
 * ## `is_kedaluwarsa` and `terminal` are on EVERY surface, deliberately
 *
 * Both are computed on the model by `ResepStateMachine` and published from the one
 * resource, so the create response, the detail, the verification response and the history
 * all carry an identical shape. The reason is recorded in the resource's own docblock: on
 * laravel/framework 13, `JsonResource::additional()` on a nested resource is a silent
 * no-op, so a flag added per-endpoint would read `null` from a 200. One component and one
 * "can I still act on this" rule therefore cannot depend on which endpoint answered.
 */
export type Resep = {
    id: number;
    nomor_resep: string;
    konsultasi_id: number | null;
    rekam_medis_id: number | null;
    pasien_id: number;
    dokter_id: number;
    apotek_id: number | null;
    tipe: TipeResep;
    status: StatusResep;
    /**
     * `TEXT NULL` at `:753` - and the ONLY place a doctor's decision to prescribe despite
     * a `kontraindikasi` can be recorded. There is no `resep_interaksi` table and no
     * acknowledgement column, so a non-null value here is the durable evidence that the
     * warning was shown and knowingly overridden. See {@link Acknowledgement}.
     */
    catatan_dokter: string | null;
    /** `DATETIME` at `:754`, an ISO-8601 instant. Read with `formatWaktu`. */
    tanggal_resep: Iso;
    /** `DATE` at `:755`, `Y-m-d`, Asia/Jakarta wall clock. Read with `formatTanggal`. */
    berlaku_sampai: Tanggal;
    is_kedaluwarsa: boolean;
    terminal: boolean;
    is_iter: boolean;
    jumlah_iter: number;
    /** `NOT NULL` but deliberately NOT `UNIQUE` at `:758`; see the plan's schema notes. */
    qr_token: string;
    dibuat_at: Iso;
    /** Absent unless the relation was eager-loaded, which every `resep` response does. */
    items?: ResepItem[];
};

// ============================================================================
// invoice / pembayaran / pesanan_obat / apotek_stok / master_metode_pembayaran
// ============================================================================

/**
 * `invoice.status`, the seven-value ENUM at `telemedicine_test.sql`, in DDL order.
 *
 * Only the first three are reachable from anything a patient can trigger in this
 * client: `InvoiceService::buat()` writes `menunggu_pembayaran` and nothing else, and
 * `PaymentService` writes exactly one transition, `lunas`. The remaining four are a refund
 * lifecycle that Module 5 never wires to a route, and they are declared rather than
 * collapsed to `string` so a screen that colours five states cannot silently fall through
 * to a default on a sixth.
 */
export type StatusInvoice =
    | 'draft'
    | 'menunggu_pembayaran'
    | 'lunas'
    | 'kadaluarsa'
    | 'dibatalkan'
    | 'refund_sebagian'
    | 'refund_penuh';

/**
 * `invoice.referensi_tipe`, six values, of which **four are reachable**.
 *
 * `InvoiceService::SUMBER_REFERENSI` maps four of them onto a real model and
 * `DI_LUAR_LINGKUP` rejects the other two with a 422 whose message names the value. So a
 * value outside the four reachable members is not a corrupt identifier - it is a
 * documented refusal, and the UI copy for it must not say "unknown".
 */
export type TipeReferensiInvoice =
    | 'booking'
    | 'konsultasi'
    | 'resep'
    | 'pesanan_obat'
    | 'lab_permintaan'
    | 'home_care';

/** The four `InvoiceService::SUMBER_REFERENSI` members, i.e. the implementable ones. */
export const TIPE_REFERENSI_DAPAT_DIBUAT: ReadonlyArray<TipeReferensiInvoice> = [
    'booking',
    'konsultasi',
    'resep',
    'pesanan_obat',
];

/**
 * `pembayaran.status`, the five-value ENUM, in DDL order.
 *
 * ## `pending` is the only NON-terminal member, and that is the whole idempotency story
 *
 * `PembayaranStatus::KEADAAN_AKHIR` is `['berhasil','gagal','kedaluwarsa','refund']`, so
 * a payment that is still `pending` is one the server has not decided about yet. A second
 * delivery of the same gateway event finds a terminal status and is answered with
 * `duplicate: true` and no write. Read {@link Pembayaran.terminal} - the server's own
 * answer - rather than comparing this string in the client, because the server already
 * evaluated the rule.
 */
export type StatusPembayaran =
    | 'pending'
    | 'berhasil'
    | 'gagal'
    | 'kedaluwarsa'
    | 'refund';

/** `pembayaran.gateway`, the four-value ENUM, in DDL order. */
export type GatewayPembayaran = 'midtrans' | 'xendit' | 'doku' | 'flip';

/**
 * `master_metode_pembayaran.tipe`, the **NINE**-value ENUM, in DDL order.
 *
 * The plan's todo 48 names five of them (`va_bank`, `e_wallet`, `qris`, `cod`, `tunai`)
 * and that list is incomplete: the column also declares `kartu_kredit`, `gerai_retail`,
 * `bpjs` and `asuransi`, and the seeder ships rows for all of them. A picker built from
 * the plan's five would silently hide four of the fourteen seeded methods. Declared as
 * nine, and the picker groups over the values the server actually returns.
 */
export type TipeMetodePembayaran =
    | 'va_bank'
    | 'e_wallet'
    | 'qris'
    | 'kartu_kredit'
    | 'gerai_retail'
    | 'cod'
    | 'tunai'
    | 'bpjs'
    | 'asuransi';

/** `pesanan_obat.tipe`, the three-value ENUM. Only `resep_dokter` is ever written. */
export type TipePesananObat = 'resep_dokter' | 'obat_bebas' | 'produk_kesehatan';

/** `pesanan_obat.kurir`, the six-value ENUM. */
export type KurirPesanan =
    | 'internal'
    | 'grab_express'
    | 'gojek'
    | 'jne'
    | 'jnt'
    | 'sicepat';

/**
 * `pesanan_obat.status`, the six-value ENUM, in DDL order.
 *
 * ## Four of the six are UNREACHABLE over HTTP in this tree
 *
 * `PesananObatStateMachine::TRANSISI` can reach all six, but the state machine is not
 * wired to a route, so the only automatic transition is the one `PaymentService::LANJUT`
 * performs on settlement: `menunggu_pembayaran` -> `diproses`. `siap`, `sedang_dikirim`,
 * `selesai` and `dibatalkan` therefore cannot be produced by any endpoint that exists. A
 * tracking screen still renders all six, because the column is six-valued and a client
 * that coloured three would be wrong the day a pharmacy route lands.
 */
export type StatusPesananObat =
    | 'menunggu_pembayaran'
    | 'diproses'
    | 'siap'
    | 'sedang_dikirim'
    | 'selesai'
    | 'dibatalkan';

/** `PesananObatStateMachine::TERMINAL` - the two an order never leaves. */
export const STATUS_PESANAN_TERMINAL: ReadonlyArray<StatusPesananObat> = [
    'selesai',
    'dibatalkan',
];

/** `notifikasi.tipe`, the seven-value ENUM, in DDL order. */
export type TipeNotifikasi =
    | 'booking'
    | 'pembayaran'
    | 'resep'
    | 'chat'
    | 'lab'
    | 'promo'
    | 'sistem';

/**
 * The four `NotifikasiTipe::nilaiYangDipakai()` members: the only `tipe` values
 * `NotificationService` has a producer for.
 *
 * `lab` and `promo` are legal DDL values that nothing in the application can emit, so a
 * panel that groups by them would render two permanently empty sections and read as a
 * broken filter rather than as an honest one.
 */
export const TIPE_NOTIFIKASI_DIPAKAI: ReadonlyArray<TipeNotifikasi> = [
    'booking',
    'pembayaran',
    'resep',
    'chat',
];

/**
 * The invoice block as `PembayaranController::bayar()` and `::webhook()` compose it.
 *
 * ## There is no `InvoiceResource`, and this shape is therefore NOT one resource
 *
 * Two different controllers hand-assemble the invoice with **different keys**:
 * `bayar()` publishes `{id, nomor_invoice, status, total}` and `webhook()` publishes
 * `{id, nomor_invoice, status, lunas_at}`. So they are declared as two separate types
 * rather than merged into one, because merging them would make a screen read
 * `invoice.total` off a webhook response and print `-` for a field that was never sent.
 */
export type InvoiceDibayar = {
    id: number;
    nomor_invoice: string;
    status: StatusInvoice;
    /** `DECIMAL(14,2)` - a JSON string. See {@link Decimal}. */
    total: Decimal;
};

export type InvoiceSelesai = {
    id: number;
    nomor_invoice: string;
    status: StatusInvoice;
    /** ISO-8601 UTC. The only proof that settlement was applied exactly once. */
    lunas_at: Iso;
};

/**
 * One `pembayaran` row, as `PembayaranResource` publishes it: 11 keys, every one allow-listed.
 *
 * ## `webhook_payload` is deliberately NOT here
 *
 * The column exists and the service writes the first delivery's raw body into it, but the
 * resource never publishes it. A client type that carried it would invite rendering a
 * gateway payload to a patient.
 *
 * ## `kadaluwarsa_at` is DERIVED, not stored
 *
 * There is no such column. `PembayaranResource` computes `dibuat_at + config('payment.kedaluwarsa_detik')`
 * (default 86400) and publishes that, so a countdown rendered from it is counting down to
 * the server's own deadline and not to a guess.
 *
 * ## `terminal` is the server's verdict on idempotency
 *
 * It is `PembayaranStatus::adalahAkhir($status)`, computed server-side. A screen that
 * compared the status string itself would be re-deriving a rule the API already publishes.
 */
export type Pembayaran = {
    id: number;
    invoice_id: number | null;
    metode_id: number | null;
    /** `DECIMAL(15,2)`, a JSON string. */
    jumlah: Decimal;
    nomor_referensi: string;
    gateway: GatewayPembayaran | null;
    /** `VARCHAR(30) NULL`. Populated for a VA method, `null` for a QR method. */
    va_number: string | null;
    status: StatusPembayaran;
    dibayar_at: Iso;
    kadaluwarsa_at: Iso;
    terminal: boolean;
};

/**
 * `data.toko` off the payment-initiation response, as a DISCRIMINATED union.
 *
 * The branch is `config('payment.metode_tipe_qr')` = `['qris','gerai_retail']`: those two
 * method types get a QR, every other type gets a virtual account. `pembayaran` has one
 * `va_number VARCHAR(30)` column and **no** `qr_string` column at all, so the two shapes
 * are genuinely exclusive and typing this as one flat object would make a QR panel read
 * `va_number` and print `null`.
 */
export type TokoPembayaran =
    | {
          va_number: string;
          nama_bank: string;
          nama_pemilik: string;
          qr_string?: undefined;
          nama_penyedia?: undefined;
      }
    | {
          va_number?: undefined;
          nama_bank?: undefined;
          nama_pemilik?: undefined;
          qr_string: string;
          nama_penyedia: string;
      };

/** `data` on `POST /invoice/{id}/bayar` (201). */
export type MulaiPembayaranData = {
    invoice: InvoiceDibayar;
    pembayaran: Pembayaran;
    gateway: {
        nama: string;
        nomor_referensi: string;
    };
    toko: TokoPembayaran;
    /** The gateway's own steps, as published strings. Rendered verbatim, never re-ordered. */
    instruksi: string[];
};

/**
 * `data` on `POST /webhook/payment/{gateway}` (200).
 *
 * ## `duplicate` is the only field a browser can never see
 *
 * The webhook is unauthenticated and HMAC-signed, so it is called by the gateway, not by a
 * client. This type exists so the screen's docblock can point at the field that answers
 * "was this applied twice", and so the e2e spec has a shape to assert on.
 */
export type WebhookPembayaranData = {
    duplicate: boolean;
    pembayaran: Pembayaran;
    invoice: InvoiceSelesai;
    referensi: {
        tipe: TipeReferensiInvoice;
        id: number;
        status: string | null;
        /**
         * Whether the REFERENCED row now sits in its post-payment state.
         *
         * A fact about the row, not about this delivery: a late delivery for a cancelled
         * booking records the money and leaves `advanced: false`, because the source state
         * no longer matches the guard.
         */
        advanced: boolean;
    };
};

/**
 * One `pesanan_obat` row, as `PesananObatResource` publishes it: 15 keys.
 *
 * ## `tracking` is OPTIONAL, and the difference is not cosmetic
 *
 * `PesananObatResource` guards it with `whenLoaded()`. `POST /resep/{id}/checkout` returns
 * the order from `buat()`, which never eager-loads the relation, so the serializer drops
 * the key entirely on the 201 - absent, not `null`. `GET /pesanan-obat/{id}` calls
 * `muatan()` and includes it. Optional rather than nullable, for exactly the reason
 * `Booking.pasien` is: a caller must not write `pesanan.tracking.map()` on a 201.
 *
 * ## The order's money is goods plus shipping and NOTHING else
 *
 * `total = subtotal + biaya_kirim`. `pesanan_obat` has no discount column and no admin-fee
 * column, so the order total is NOT the invoice total whenever a promo or a method with a
 * fee applies. The screen that quotes "the total you will pay" must quote the INVOICE.
 */
export type PesananObat = {
    id: number;
    nomor_pesanan: string;
    resep_id: number | null;
    pasien_id: number;
    apotek_id: number;
    tipe: TipePesananObat;
    alamat_kirim: string;
    kurir: KurirPesanan | null;
    no_resi: string | null;
    /** `DECIMAL(12,2)`, a JSON string. */
    subtotal: Decimal;
    /** `DECIMAL(12,2)`, a JSON string. */
    biaya_kirim: Decimal;
    /** `DECIMAL(12,2)`, a JSON string. Equals `subtotal + biaya_kirim`. */
    total: Decimal;
    status: StatusPesananObat;
    dibuat_at: Iso;
    diubah_at: Iso;
    tracking?: PesananObatTracking[];
};

/**
 * One `pesanan_obat_tracking` row: 6 keys, no `created_at`, no `updated_at`.
 *
 * The table has no timestamp columns other than `waktu`, which IS the creation stamp, and
 * the resource publishes no Eloquent timestamps. `status` is `VARCHAR(100)` in the DDL but
 * the application narrows it to the same six values as {@link StatusPesananObat}, so the
 * union is used rather than `string`.
 *
 * ## The trail is history, not the authority
 *
 * The current state of an order is `pesanan_obat.status`. The trail is append-only and is
 * not ordered by the query - it comes back in InnoDB primary-key order, which is
 * oldest-first in practice but is not guaranteed. A screen that derived the current state
 * from the LAST row would be reading an accident.
 */
export type PesananObatTracking = {
    id: number;
    pesanan_obat_id: number;
    status: StatusPesananObat;
    keterangan: string | null;
    lokasi: string | null;
    waktu: Iso;
};

/** The chosen pharmacy's shelf, from `GET /obat/{id}/stok`. */
export type StokDiApotek = {
    apotek_id: number;
    nama: string;
    /**
     * Whether an `apotek_stok` row exists AT ALL for this pair.
     *
     * The distinction the service docblock insists on: a row of zero means "ask again
     * tomorrow", no row means "this pharmacy does not carry it". A panel that renders both
     * as `jumlah_stok: 0` tells a patient to try a pharmacy that has never stocked the
     * drug.
     */
    recorded: boolean;
    /**
     * A **SIGNED** `int` with no `CHECK (jumlah_stok >= 0)`. `ApotekStokService::kurangi()`
     * is the only writer and it refuses rather than going negative, so a negative here
     * would be a backend bug, not a number to display as-is.
     */
    jumlah_stok: number;
    stok_minimum: number;
    /** `DECIMAL(12,2)`, a JSON string. `"0.00"` when `recorded` is false. */
    harga_jual: Decimal;
    /**
     * `Y-m-d`, Asia/Jakarta wall clock - the ONE date-typed field on this endpoint.
     *
     * `StokObatResource` is a pass-through of `PesananObatService::cekStok()`, which calls
     * `toDateString()` here, whereas every other instant in this API is `toISOString()` UTC.
     * Read it with `formatTanggal`, never with `formatWaktu`.
     */
    kedaluwarsa: Tanggal;
    /** The server's own verdict: `recorded && jumlah_stok >= jumlah_diminta`. */
    cukup: boolean;
};

/** One other pharmacy that could serve the request, most plentiful first. */
export type StokAlternatif = {
    apotek_id: number;
    nama: string;
    jumlah_stok: number;
    harga_jual: Decimal;
};

/**
 * `data` on `GET /obat/{id}/stok`.
 *
 * ## With no `?apotek_id=`, `apotek` is null AND `alternatif` is the pharmacy LIST
 *
 * `PesananObatService::cekStok()` skips the chosen-pharmacy block when no id is given, and
 * `ApotekStokService::alternatif()` then excludes only `apotek_id <> 0` - which every real
 * pharmacy passes. So the alternatives array is every active `apotek` facility with enough
 * of that drug, and it is the only pharmacy list this API publishes. There is no
 * `GET /apotek`; a client that needs pharmacies has to read them out of here.
 */
export type StokObat = {
    obat_id: number;
    /** The `jumlah` that was sent, or 1 when the parameter was omitted. */
    jumlah_diminta: number;
    apotek: StokDiApotek | null;
    alternatif: StokAlternatif[];
};

/**
 * `data` on `POST /promo/validasi`.
 *
 * ## A refusal here is a 200 with `valid: false`, NOT a 422
 *
 * `PromoController::validasi()` returns the success envelope in every accepted case, with
 * the reasons in `alasan`. So a promo input must branch on `valid`, never on the status
 * code, and it must render `nilai_diskon` and `total` exactly as they arrive: the server is
 * authoritative for `kuota`, `min_transaksi` and `maks_diskon`, and a client that
 * recomputed a percentage would disagree with the cap.
 */
export type PromoValidasi = {
    promo: {
        kode: string | null;
        nama: string | null;
        tipe_diskon: string | null;
    };
    invoice: {
        id: number;
        nomor_invoice: string;
    };
    valid: boolean;
    /** The server's computed discount, a JSON string. `null` when the promo is unusable. */
    nilai_diskon: Decimal;
    /** The server's computed post-discount total, a JSON string. */
    total: Decimal;
    rincian: {
        subtotal: Decimal;
        diskon: Decimal;
        biaya_admin: Decimal;
        biaya_pengiriman: Decimal;
        total: Decimal;
    };
    /**
     * Why it was refused, empty when `valid` is true.
     *
     * Each entry names the FIELD it failed on (`kode`, `status_aktif`, `jendela_waktu`,
     * `min_transaksi`, `kuota`), so a form can attach each message to the control the
     * patient can actually change.
     */
    alasan: Array<{ kode: string; kolom: string; pesan: string }>;
};

/**
 * One `master_metode_pembayaran` row, as `MetodePembayaranResource` publishes it.
 *
 * ## Both fee columns are published as JSON NUMBERS, not strings
 *
 * `MetodePembayaranResource` casts both to `(float)`, unlike every other money value in
 * this API. That is the resource's choice, not a transcription slip, and it is why
 * `biaya_admin_flat` is a bare `number` here while {@link Decimal} is a union everywhere
 * else. The server still applies the fee inside `InvoiceService`; these two numbers exist
 * so the picker can show the fee before the patient commits.
 */
export type MetodePembayaran = {
    id: number;
    kode: string;
    nama: string;
    tipe: TipeMetodePembayaran;
    /** Free text, and the only hint of a provider the table carries. */
    penyedia: string | null;
    biaya_admin_flat: number;
    /** Percent, applied to `subtotal - diskon` - never to `subtotal`. */
    biaya_admin_persen: number;
    status_aktif: boolean;
};

/**
 * One `notifikasi` row, as `NotifikasiResource` publishes it: 8 keys.
 *
 * ## There is no `channel` and no `user_id`
 *
 * `notifikasi` has no channel column and no delivery-state column, so push-delivery
 * outcome is unrepresentable and is logged to the application log instead. A client cannot
 * show "delivered" or "queued" and must not offer a per-channel toggle.
 *
 * ## `payload` is passed through UNFILTERED
 *
 * It is the raw `JSON` column, so it is typed `unknown` and read defensively. The
 * documented shape for the payment event is `{invoice_id}`, for a booking `{booking_id}`
 * or `{booking_id, alasan}`, for a prescription `{resep_id}` and for a chat message
 * `{konsultasi_id, pengirim_user_id}`.
 */
export type Notifikasi = {
    id: number;
    judul: string;
    isi: string;
    tipe: TipeNotifikasi;
    /** The API PATH, e.g. `/api/v1/booking/1001`, not a route in this SPA. */
    tautan: string | null;
    payload: unknown;
    /** ISO-8601 UTC. `null` is what "unread" means, and it is the only such field. */
    dibaca_at: Iso;
    dibuat_at: Iso;
};
