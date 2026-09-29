<?php

declare(strict_types=1);

namespace App\Services\SuratKeterangan;

use App\Enums\SuratKeteranganTipe;
use App\Models\Dokter;
use App\Models\Faskes;
use App\Models\Konsultasi;
use App\Models\Pasien;
use App\Models\Rujukan;
use App\Models\SuratKeterangan;
use App\Models\User;
use App\Services\Konsultasi\KonsultasiAccess;
use App\Services\Pasien\PasienRecordAccess;
use App\Services\Pdp\PdpConsent;
use App\Support\Dokumen\NomorDokumen;
use App\Support\NamaMasker;
use App\Support\WaktuIndonesia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Issues medical letters, writes the referral behind one of them, and verifies a
 * letter's QR token for a scanner that has no account.
 *
 * ## THE COLLISION RETRY, and why it is the centre of this class
 *
 * A letter carries TWO collision-shaped identifiers and they are protected by TWO
 * different mechanisms, one of which does not exist:
 *
 * | identifier | DDL | what stops a duplicate |
 * | --- | --- | --- |
 * | `nomor_surat` | `VARCHAR(50) NOT NULL UNIQUE` (:583) | the DATABASE - a real MySQL 1062 |
 * | `qr_token` | `VARCHAR(100) NOT NULL` (:592), **no UNIQUE and no index** | NOTHING, except the code below |
 *
 * The migration's own docblock for table 40 calls the second one "A GENUINE GAP" and
 * names the mitigation: generate with `Str::uuid()` at write time and add a duplicate
 * check at that point, accepting the race. This class is that mitigation.
 *
 * **The loop, and why it is inside the transaction.** {@see tulisDenganNomorUnik()} runs
 * INSIDE the write transaction, exactly as `BookingService::simpanDenganNomorUnik()`
 * does, because a rolled-back attempt must leave no `surat_keterangan` and no `rujukan`
 * row behind. MySQL does not abort a transaction on a duplicate-key error - only the
 * statement fails - so catching the exception and going round again inside the
 * transaction is safe and is what the booking service already relies on.
 *
 * **Two sources, one budget, and the budget is bounded.** Both collisions consume
 * {@see PERCOBAAN_TOKEN_MAKS}, and a spent budget raises
 * {@see SuratKeteranganTokenHabisException} rather than looping. An unbounded loop
 * against a generator that always returns a taken value is a hung request holding a row
 * lock, so the bound is a correctness property and not a politeness one - and the test
 * that forces a collision asserts the generator was called EXACTLY that many times, so
 * a removed bound would hang rather than fail.
 *
 * **The residual race, stated rather than hidden.** The `qr_token` check is a SELECT
 * followed by an INSERT with nothing serialising them, so two concurrent writers can
 * both see the token as free and both insert. A UNIQUE index would close that, and one
 * **cannot** be added: `telemedicine_test.sql` is read-only law and a UNIQUE here would
 * be permanent `extra_index` drift reported by `sehatly:verify-schema`. This is
 * therefore reported as a schema finding, and the mitigation is the entropy of a v4
 * UUID - a 122-bit space against a table of letters a telemedicine platform issues in
 * the hundreds - plus a QR that is scanned a handful of times, not a busy credential.
 *
 * ## The patient and the doctor are read off the CONSULTATION and the CALLER
 *
 * `pasien_id` is `konsultasi.pasien_id` and `dokter_id` is the authenticated doctor's
 * own `dokter` row. Neither is ever read from the request body, and
 * `BuatSuratKeteranganRequest` marks both `prohibited` so a caller who sends one is
 * told by name rather than having it silently dropped. Without that, a doctor could
 * issue a letter about somebody else's patient and the letter would be a perfectly
 * valid document about the wrong human being.
 *
 * `surat_keterangan.konsultasi_id` carries **no foreign key** (`:584`) - it is on the
 * plan's authoritative list of reference-shaped columns with no constraint - so the
 * letter's link back to its consultation is an application invariant and the doctor's
 * ownership of the consultation is what makes the letter legitimate.
 *
 * ## `jumlah_hari` is the INCLUSIVE difference, and NULL is not zero
 *
 * `jumlah_hari` is `TINYINT UNSIGNED NULL` (`:590`) and is neither generated nor checked
 * by the database, so a value disagreeing with its own two dates is representable. Two
 * consequences are enforced here:
 *
 * - **Inclusive.** A one-day rest is 1, not 0, and an exclusive `diffInDays` would say 0
 *   for the most common letter there is. The acceptance criterion is this difference.
 * - **NULL means "no period".** A `surat_sehat` describes a moment, so all three period
 *   columns are absent. Collapsing the null to 0 would claim the period is zero days
 *   long, which is a different and false statement.
 *
 * The unsigned column also caps the value at 255, so a period longer than that is a 422
 * here rather than a raw MySQL 1264.
 *
 * ## The verifier is PUBLIC, and this is the decision it rests on
 *
 * {@see verifikasi()} takes no account at all. A QR code is a physical artifact: it is
 * printed on a letter, handed to a patient, carried to another facility, photographed
 * by whoever is standing there, and scanned by a receptionist who has no account and
 * never will. A `permission:` gate on it would answer 401 for an anonymous caller and
 * 403 for `perawat` and `kurir` (real `users.tipe` values at `:139` that hold no role),
 * which makes the capability useless in the only situation it exists for.
 *
 * **What a stranger learns from a VALID token, exactly:**
 * that the document number is real; what KIND of document it is; which doctor signed
 * it, in full; the day it was issued; and the SHAPE of the patient's name.
 *
 * **What it does not learn, and this is checked by byte search rather than by
 * enumeration:** the patient's name, their NIK, their `nomor_kk`, their birth date, the
 * letter's body, its clinical period, its referral, or any surrogate id. The NIK is not
 * published masked - it is published NOT AT ALL, which is the strongest form the
 * requirement admits and the only one consistent with a response any stranger may
 * obtain.
 *
 * **What a stranger learns from an INVALID token: nothing at all.** Every field but
 * `valid` is `null`, the key set is identical to the valid answer so a client parses one
 * shape, and a document number that does not exist is byte-for-byte the same response
 * as a wrong token - so the endpoint is not an existence oracle over the number space.
 *
 * ## 403 for the caller, 404 for the row, and the rule is not re-stated here
 *
 * Both are delegated. A `dokter`-typed account with no `dokter` row is 403 from
 * `KonsultasiAccess::untukDokter()`, a doctor attached to a different consultation is
 * 404, and an account with no `pasien` row is 403 from
 * `PasienRecordAccess::ownPasien()`. A 403 where a 404 belongs would confirm the row
 * exists, which is a cross-tenant existence oracle over a sequential `BIGINT` key, and
 * three previous todos each re-derived that rule independently. This class has no
 * `if` about ownership at all.
 */
final class SuratKeteranganService
{
    /**
     * Attempts at ONE letter's identifiers before the write gives up.
     *
     * **Five**, and the number is a considered one rather than a copy:
     * `BookingService::NOMOR_PERCOBAAN_MAX` is 3, which is the right budget for a
     * shared 6-character random sequence. Here each attempt draws 60 bits of a v4 UUID
     * for the token and 30 bits for the number, so three attempts is already a
     * probability below 2^-150 and the bound is not doing arithmetic work - it is
     * bounding a HANG. Five buys a margin over the 3 without becoming a budget anyone
     * would set by reflex, and it is asserted by a test both ways: `> 0`, `<= 10`, and
     * `> 3` so a future edit to 1 or 2 fails rather than passes unnoticed.
     */
    public const PERCOBAAN_TOKEN_MAKS = 5;

    /**
     * `jumlah_hari`'s ceiling, and it is the COLUMN's.
     *
     * `TINYINT UNSIGNED` (`telemedicine_test.sql:590`) holds 0..255, so 255 is the
     * arithmetic maximum and not a business rule this todo invented. A longer period is
     * a 422 naming `tanggal_selesai` rather than a MySQL 1264 at insert time.
     */
    public const JUMLAH_HARI_MAKS = 255;

    /**
     * How long a referral is valid when the caller does not say.
     *
     * The plan's number. Measured from the letter's OWN `tanggal_mulai` rather than
     * from `now()`: a referral written for a visit two weeks out should expire relative
     * to the visit, and `berlaku_sampai` (`:608`) is a different date from the medical
     * window - it is the date after which the receiving facility stops honouring the
     * referral, and nothing in the schema ties it to the window.
     */
    public const BERLAKU_SAMPAIL_HARI = 14;

    /**
     * The keys a request may carry that belong to the REFERRAL, and therefore exist
     * only for a `surat_rujukan`.
     *
     * Published rather than written out inside the validator, because the same list has
     * to be consulted twice - once to require them, once to refuse them on a
     * non-referral letter - and a list that existed only inside one of those could not
     * be compared with the other. The refusal is the point: silently dropping a
     * `faskes_tujuan_id` the doctor supplied is the `array_key_exists` defect todo 32
     * found in `KonsultasiService::tulisSoap()`, where a misspelled key validated, was
     * accepted, and was written nowhere.
     *
     * @var list<string>
     */
    public const KOLOM_RUJUKAN = [
        'faskes_tujuan_id',
        'diagnosis_kerja',
        'icd10_kode',
        'alasan_rujukan',
        'berlaku_sampai',
        'nomor_sep',
    ];

    /**
     * The `rujukan` columns a caller may NEVER set.
     *
     * `surat_keterangan_id` is written by the service from the letter it just created,
     * `dokter_perujuk_id` from the authenticated doctor's own row, and `status` is left
     * to the DDL default `'aktif'` (`:610`) so a caller cannot create a referral that
     * is already `terpakai`. `faskes_asal_id` is not in the list because the column is
     * nullable with NO foreign key (`:602`) and this endpoint has no referring facility
     * to record - a doctor's own practice is not a `faskes` row in this schema, and
     * inventing one would be a row no deployment has.
     *
     * @var list<string>
     */
    public const KOLOM_RUJUKAN_MILIK_SISTEM = [
        'surat_keterangan_id',
        'dokter_perujuk_id',
        'status',
    ];

    public function __construct(
        private readonly KonsultasiAccess $konsultasi,
        private readonly PasienRecordAccess $pasien,
        private readonly PdpConsent $consent,
        private readonly QrTokenGenerator $token,
        private readonly NomorDokumen $nomor,
    ) {}
    /**
     * Issue one letter for consultation `$konsultasiId`, signed by `$dokter`.
     *
     * @param  array<string, mixed>  $data  already validated, with the referral keys AS SENT
     *
     * @throws AccessDeniedHttpException|ValidationException
     */
    public function buat(User $dokter, int $konsultasiId, array $data): SuratKeterangan
    {
        return DB::transaction(function () use ($dokter, $konsultasiId, $data): SuratKeterangan {
            $sesi = $this->kunciKonsultasi($konsultasiId, $dokter);
            $profil = $this->pasien->ownDokterOrFail($dokter);
            $pasien = Pasien::query()->findOrFail((int) $sesi->pasien_id);

            $tipe = SuratKeteranganTipe::from((string) $data['tipe']);

            // BOTH rule sets are evaluated before either throws, so a request that
            // breaks a period rule AND two referral rules answers ONE 422 carrying all
            // three rather than whichever check happened to run first. The order the
            // fields appear in is period first, then referral, and it is asserted by a
            // test so a reordering is visible.
            [$periode, $periodeGagal] = $this->periode($tipe, $data);
            [$rujukan, $rujukanGagal] = $this->dataRujukan($tipe, $data);

            $gagal = array_merge($periodeGagal, $rujukanGagal);

            if ($gagal !== []) {
                throw ValidationException::withMessages($gagal);
            }

            // PDP consent is checked BEFORE the write and inside the same transaction, so
            // a refusal leaves neither a letter nor a referral. It is the PATIENT's
            // consent, read off `pasien.user_id`: the data being shared is theirs, and
            // UU PDP asks the data subject rather than the clinician.
            if ($rujukan !== null) {
                $this->consent->require($pasien->user, PdpConsent::JENIS_BERBAGI_DATA);
            }

            return $this->tulisDenganNomorUnik(
                $sesi,
                $profil,
                $pasien,
                $tipe,
                $data,
                $periode,
                $rujukan,
            );
        });
    }

    /**
     * The caller's own letters, newest first, paginated.
     *
     * The tenant filter IS the query, so another patient's letter is simply not found
     * rather than refused - the property `PasienRecordAccess` documents and this
     * endpoint inherits by calling {@see PasienRecordAccess::ownPasien()} for the root.
     *
     * The order is `dibuat_at, id`: `dibuat_at` is a `TIMESTAMP` (`:594`) at ONE SECOND
     * of resolution, so a burst of letters ties and `id` alone would leave MySQL free to
     * return a tied block in any order, which could repeat or drop a row between two
     * pages. The same reasoning `KonsultasiService::riwayat()` gives for `terkirim_at`.
     */
    public function daftarUntukPasien(User $panggil, int $perPage): LengthAwarePaginator
    {
        return $this->querySurat($this->pasien->ownPasien($panggil))
            ->with(['pasien.user', 'dokter.user', 'rujukan'])
            ->orderByDesc('dibuat_at')
            ->orderByDesc('id')
            ->paginate($this->pasien->perPage($perPage))
            ->withQueryString();
    }

    /**
     * Verify `(nomor_surat, token)` and publish the minimum that answers it.
     *
     * ## The lookup is by BOTH keys, which is what makes the token a token
     *
     * A single WHERE on `nomor_surat` would make the token decorative - anyone who
     * guessed or read a document number could verify any letter. The two together are
     * the check, and the query is what makes an INVALID answer carry no information:
     * a number that does not exist and a wrong token produce the same null row, so the
     * response cannot be used to probe the number space.
     *
     * ## The token comparison is EXACT, in the application
     *
* MySQL's default `utf8mb4_unicode_ci` collation is CASE-INSENSITIVE, so a bare
 * `where` predicate on the token column alone would accept `ABCDEF` for a stored
 * `abcdef` and would accept a case variant of a token. Both are forgeries of a
 * bearer secret.
     * The value is therefore fetched by `nomor_surat` and compared with
     * {@see hash_equals()} on the raw strings, which is also constant-time - a
     * byte-by-byte early-exit comparison leaks how much of a guessed secret was right.
     *
     * ## Nothing about the letter is read for an invalid token
     *
     * The `with()` clauses are only reached when a row matched, so an invalid token
     * reads exactly one indexed-ish row and no patient, no doctor and no profile. That
     * is not a performance claim; it is the statement that a stranger who guesses wrong
     * causes this endpoint to touch no personal data at all.
     *
     * @return array{valid: bool, nomor_surat: string|null, tipe: string|null, dokter: string|null, tanggal: string|null, pasien_nama_masked: string|null}
     */
    public function verifikasi(string $nomorSurat, string $token): array
    {
        $kosong = [
            'valid' => false,
            'nomor_surat' => null,
            'tipe' => null,
            'dokter' => null,
            'tanggal' => null,
            'pasien_nama_masked' => null,
        ];

        $surat = SuratKeterangan::query()
            ->where('nomor_surat', $nomorSurat)
            ->first();

        if ($surat === null || ! hash_equals((string) $surat->qr_token, $token)) {
            return $kosong;
        }

        // A matched row, and only then: the two relations that name a human being, and
        // nothing else. `pasien.nik`, `pasien.nomor_kk`, `pasien.tanggal_lahir`, the
        // letter's `isi` and its period columns are never read, so they cannot be
        // published by accident from here.
        $surat->loadMissing(['dokter.user', 'pasien.user']);

        return [
            'valid' => true,
            'nomor_surat' => (string) $surat->nomor_surat,
            'tipe' => (string) $surat->tipe,
            'dokter' => $surat->dokter?->user?->nama_lengkap,
            // The calendar day the letter was ISSUED, read on the clinic's clock.
            // `dibuat_at` is a `TIMESTAMP`, so it is an instant and the instant
            // itself is not in question; the question is which DAY it falls on,
            // and "which day was this issued" is a question about the clinic's
            // wall clock. It used to be the UTC day, so a letter issued at 01:00
            // WIB scanned its own date as the day before. `tanggal_mulai` and
            // `tanggal_selesai` are the letter's CLINICAL period and are
            // withheld.
            'tanggal' => Carbon::instance($surat->dibuat_at)
                ->setTimezone(WaktuIndonesia::ZONA)
                ->toDateString(),
            'pasien_nama_masked' => NamaMasker::mask($surat->pasien?->user?->nama_lengkap),
        ];
    }

    /**
     * Every `surat_keterangan` row of one patient, and nothing else.
     *
     * PRIVATE, and the reflection test 'the service takes an account and an id, never a
     * row the caller opened' is what forces it: that test requires every public method
     * to take a `User` first, so a public query builder taking a `Pasien` would be a
     * public method whose first argument is a row the caller resolved itself - the exact
     * shape the rule exists to forbid. {@see daftarUntukPasien()} is the public door and
     * it resolves the `Pasien` itself.
     *
     * @return Builder<SuratKeterangan>
     */
    private function querySurat(Pasien $pasien): Builder
    {
        return SuratKeterangan::query()->whereBelongsTo($pasien);
    }

    /**
     * Lock the consultation `FOR UPDATE`, after the ownership answer.
     *
     * The ownership decision is {@see KonsultasiAccess::untukDokter()}'s - 403 for a
     * `dokter`-typed account with no `dokter` row, 404 for a doctor attached to a
     * different consultation. The row is then re-read under a lock because those are
     * two statements, and the consultation read in between them must be the one whose
     * patient the letter names. `KonsultasiService::kunciUntukDokter()` is the same two
     * steps for the same reason.
     */
    private function kunciKonsultasi(int $konsultasiId, User $dokter): Konsultasi
    {
        $sesi = $this->konsultasi->untukDokter($dokter, $konsultasiId);

        return Konsultasi::query()
            ->whereKey($sesi->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The period triple for a letter, or nulls for a letter that states no period,
     * TOGETHER WITH every period violation found.
     *
     * The violations are RETURNED rather than thrown, because {@see buat()} merges them
     * with the referral block's before raising a single 422 - a request that breaks a
     * period rule and two referral rules must answer all three, not whichever check ran
     * first. The mechanism by which one field can carry two messages is the repeated
     * key APPENDING: `$violasi['tanggal_mulai'][]` after an assignment. The case the
     * envelope contract names is a period type with an end date and no start date, which
     * breaks two independent rules with one input.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array{0: string|null, 1: string|null, 2: int|null}, 1: array<string, list<string>>}
     */
    private function periode(SuratKeteranganTipe $tipe, array $data): array
    {
        $mulai = $this->tanggal($data, 'tanggal_mulai');
        $selesai = $this->tanggal($data, 'tanggal_selesai');

        if (! SuratKeteranganTipe::punyaPeriode($tipe)) {
            // A moment, not a window. Sending dates is refused rather than ignored for
            // the same reason a referral key on a non-referral letter is refused.
            $violasi = [];

            foreach (['tanggal_mulai', 'tanggal_selesai'] as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $violasi[$kolom] = ['Hanya surat dengan periode yang memiliki tanggal mulai dan tanggal selesai.'];
                }
            }

            return [[null, null, null], $violasi];
        }

        $violasi = [];

        if ($mulai === null) {
            $violasi['tanggal_mulai'] = ['Surat dengan periode wajib menyertakan tanggal mulai.'];
        }

        if ($mulai !== null && $selesai === null) {
            $violasi['tanggal_selesai'] = ['Tanggal selesai wajib diisi bila tanggal mulai diisi.'];
        }

        if ($mulai === null && $selesai !== null) {
            $violasi['tanggal_mulai'][] = 'Tanggal selesai diberikan tanpa tanggal mulai.';
        }

        if ($mulai !== null && $selesai !== null && $selesai->lessThan($mulai)) {
            $violasi['tanggal_selesai'][] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        }

        $hari = null;

        if ($mulai !== null && $selesai !== null && ! isset($violasi['tanggal_selesai'])) {
            // `diffInDays` on Carbon 3 is signed and returns a float, so both dates are
            // floored to whole days first and the INCLUSIVE +1 is applied here rather
            // than by asking the framework for a period.
            $hari = (int) $mulai->startOfDay()->diffInDays($selesai->startOfDay()) + 1;

            if ($hari > self::JUMLAH_HARI_MAKS) {
                $violasi['tanggal_selesai'][] = 'Periode surat maksimal '.self::JUMLAH_HARI_MAKS.' hari.';
            }
        }

        return [
            [
                $mulai?->toDateString(),
                $selesai?->toDateString(),
                $hari,
            ],
            $violasi,
        ];
    }

    /**
     * The referral block to write - or null for a letter that is not a referral -
     * TOGETHER WITH every referral violation found.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>|null, 1: array<string, list<string>>}
     */
    private function dataRujukan(SuratKeteranganTipe $tipe, array $data): array
    {
        $dikirim = array_values(array_intersect(self::KOLOM_RUJUKAN, array_keys($data)));

        if (! SuratKeteranganTipe::denganRujukan($tipe)) {
            if ($dikirim === []) {
                return [null, []];
            }

            // Named, not dropped. The message is the same for every offending key
            // because the reason is the same: these columns describe a referral and
            // this letter is not one.
            $violasi = [];

            foreach ($dikirim as $kolom) {
                $violasi[$kolom] = ['Hanya surat rujukan yang dapat memiliki tujuan rujukan.'];
            }

            return [null, $violasi];
        }

        $violasi = [];

        if (! array_key_exists('faskes_tujuan_id', $data) || $data['faskes_tujuan_id'] === null) {
            $violasi['faskes_tujuan_id'] = ['Surat rujukan wajib memiliki faskes tujuan.'];
        } elseif (! Faskes::query()->whereKey((int) $data['faskes_tujuan_id'])->exists()) {
            // Checked here rather than left to the foreign key, so a bad id is a 422
            // naming the field rather than a 1452 the envelope renders as a sanitised
            // 500. `Rule::exists` would do the same thing in the FormRequest; the
            // service is where it is repeated because the service is what a later
            // caller reaches directly.
            $violasi['faskes_tujuan_id'] = ['Faskes tujuan tidak ditemukan.'];
        }

        $alasan = $data['alasan_rujukan'] ?? null;

        if ($alasan === null || trim((string) $alasan) === '') {
            $violasi['alasan_rujukan'] = ['Surat rujukan wajib menyertakan alasan rujukan.'];
        }

        if ($violasi !== []) {
            return [null, $violasi];
        }

        $mulai = $this->tanggal($data, 'tanggal_mulai');

        return [
            [
                'faskes_tujuan_id' => (int) $data['faskes_tujuan_id'],
                'diagnosis_kerja' => $data['diagnosis_kerja'] ?? null,
                'icd10_kode' => $data['icd10_kode'] ?? null,
                'alasan_rujukan' => $alasan === null ? null : (string) $alasan,
                // The plan's default, measured from the letter's own start date.
                // `berlaku_sampai` (:608) is NOT NULL, so a referral always carries one.
                // When no start date was given the anchor is the CLINIC's today,
                // `WaktuIndonesia::now()` - not `Carbon::now()`, which is a UTC day
                // and named the day before for the seven hours from 00:00 to 07:00 WIB,
                // handing the patient a referral that expires a day early.
                'berlaku_sampai' => $this->tanggal($data, 'berlaku_sampai')?->toDateString()
                    ?? ($mulai ?? WaktuIndonesia::now())->copy()->addDays(self::BERLAKU_SAMPAIL_HARI)->toDateString(),
            ],
            [],
        ];
    }

    /**
     * Write the letter and its referral with collision-safe identifiers.
     *
     * The retry loop runs INSIDE the transaction: a rolled-back attempt leaves no
     * `surat_keterangan` and no `rujukan` row, which is what makes a spent budget
     * (the test asserts zero rows in that case) observable at all.
     *
     * Only a genuine duplicate-key collision retries. Anything else - a lock-wait 1205,
     * a foreign-key 1452, a data-truncation 1264 - propagates, so a real fault is never
     * mistaken for bad luck and retried five times.
     *
     * @param  array<string, mixed>  $data
     * @param  array{0: string|null, 1: string|null, 2: int|null}  $periode
     * @param  array<string, mixed>|null  $rujukan
     */
    private function tulisDenganNomorUnik(
        Konsultasi $sesi,
        Dokter $dokter,
        Pasien $pasien,
        SuratKeteranganTipe $tipe,
        array $data,
        array $periode,
        ?array $rujukan,
    ): SuratKeterangan {
        // The document number's date part is the CLINIC's day, not a UTC one.
        // `config/app.php` is `UTC` and WIB is +07:00, so `Carbon::now()` stamped
        // every letter written between 00:00 and 07:00 WIB with the previous day's
        // date. `Carbon::setTestNow()` is honoured either way, which is what lets a
        // test pin a letter's number.
        $hariIni = WaktuIndonesia::tanggal();

        for ($percobaan = 1; $percobaan <= self::PERCOBAAN_TOKEN_MAKS; $percobaan++) {
            // The APPLICATION-level check, and the reason this method exists. The
            // migration's docblock for table 40 records that `qr_token` is `NOT NULL`
            // but NOT `UNIQUE` (`:592`), so MySQL's implicit unique index is not
            // created and two letters can carry the same token. A verification token
            // that two documents share is a security defect: scanning either QR
            // resolves both letters.
            $token = $this->token->next();

            if (SuratKeterangan::query()->where('qr_token', $token)->exists()) {
                continue;
            }

            try {
                $surat = new SuratKeterangan;
                $surat->nomor_surat = $this->nomor->berikutnya(NomorDokumen::PREFIX_SURAT, $hariIni);
                $surat->konsultasi_id = $sesi->getKey();
                $surat->tipe = $tipe->value;
                $surat->pasien_id = $pasien->getKey();
                $surat->dokter_id = $dokter->getKey();
                $surat->tanggal_mulai = $periode[0];
                $surat->tanggal_selesai = $periode[1];
                $surat->jumlah_hari = $periode[2];
                $surat->isi = $data['isi'] ?? null;
                $surat->qr_token = $token;
                // `file_url` (:593) is left NULL: no PDF is rendered by this todo, and
                // a URL to nowhere would be worse than an honest null. The QR carries
                // the verification endpoint, not a document file.
                $surat->file_url = null;
                $surat->save();

                $baris = null;

                if ($rujukan !== null) {
                    $baris = new Rujukan;
                    $baris->surat_keterangan_id = $surat->getKey();
                    $baris->faskes_asal_id = null;
                    $baris->faskes_tujuan_id = (int) $rujukan['faskes_tujuan_id'];
                    $baris->dokter_perujuk_id = $dokter->getKey();
                    $baris->diagnosis_kerja = $rujukan['diagnosis_kerja'];
                    $baris->icd10_kode = $rujukan['icd10_kode'];
                    $baris->alasan_rujukan = $rujukan['alasan_rujukan'];
                    $baris->berlaku_sampai = $rujukan['berlaku_sampai'];
                    $baris->nomor_sep = $data['nomor_sep'] ?? null;
                    // `status` is left to the DDL default `'aktif'` (`:610`), so a
                    // referral is never born already spent.
                    $baris->save();
                    // The DDL default is applied by MySQL, not to the in-memory model,
                    // so refresh the row before it is published: a referral that was
                    // just created must not serialize a null `status`.
                    $baris->refresh();
                }

                return $surat->refresh()->setRelation('rujukan', collect($baris === null ? [] : [$baris]));
            } catch (UniqueConstraintViolationException) {
                // `nomor_surat` IS unique (DDL :583), so its collision is a real 1062 that
                // no pre-check can see: another writer took the number between the
                // generator's draw and this INSERT. The token drawn this attempt is
                // discarded with the row - a retry must redraw BOTH identifiers, since
                // the number is what collided and keeping a token from a rolled-back
                // attempt would waste a draw for no reason.
                if ($percobaan >= self::PERCOBAAN_TOKEN_MAKS) {
                    throw SuratKeteranganTokenHabisException::penuh();
                }
            }
        }

        throw SuratKeteranganTokenHabisException::penuh();
    }

    /**
     * One `date_format:Y-m-d` input as a `Carbon`, or null.
     *
     * `Carbon::hasFormatWithTime` is not used because the FormRequest has already
     * validated the shape; this is the parse, and it is `createFromFormat` rather than
     * `Carbon::parse()` so a value that slipped past validation as `2026-13-45` cannot
     * roll over into a real date.
     *
     * @param  array<string, mixed>  $data
     */
    private function tanggal(array $data, string $kolom): ?Carbon
    {
        $nilai = $data[$kolom] ?? null;

        if ($nilai === null || $nilai === '') {
            return null;
        }

        $parsed = Carbon::createFromFormat('Y-m-d', (string) $nilai);

        return $parsed === false ? null : $parsed;
    }
}
