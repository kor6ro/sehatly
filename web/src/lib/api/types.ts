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
 * | `IssuedToken` | `AuthTokenResource` (re-declared in `lib/http.ts`) |
 * | `OtpChallenge` | the inline `otp` array in `AuthController` |
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
