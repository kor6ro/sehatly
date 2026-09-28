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
