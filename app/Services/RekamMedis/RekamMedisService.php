<?php

declare(strict_types=1);

namespace App\Services\RekamMedis;

use App\Models\RekamMedis;
use App\Models\RekamMedisDiagnosa;
use App\Models\RekamMedisLampiran;
use App\Models\RekamMedisPersetujuan;
use App\Models\RekamMedisTindakan;
use App\Models\User;
use App\Services\Konsultasi\KonsultasiAccess;
use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The medical record: a draft, a signature, and a chain of superseding amendments.
 *
 * ## Every public method takes an ACCOUNT, never a model
 *
 * `simpan`, `ubah`, `finalisasi`, `amandemen` and the four `tambah*` methods all
 * take a `User`. That signature is the second half of the structural guarantee: a
 * caller cannot hand this service an already-loaded `RekamMedis` row, so it cannot
 * slip in a record it opened without a log row, and it cannot decide for itself who
 * is allowed. Authorisation and logging are not steps a caller may forget - they are
 * what each method does first, before it writes anything.
 *
 * ## Exactly ONE logged read per operation, and the write path is why
 *
 * Every write method opens its record ONCE, through
 * {@see RekamMedisAccessLogger::untukTulis()}, and then mutates that loaded instance
 * in place. It does not re-read afterwards, because a re-read is a second read and
 * would be a second log row - and because the loaded instance is the SAME object the
 * `ran` chain publishes ({@see RekamMedisAccessLogger} replaces the chain's own copy
 * with it), a mutation lands in the chain entry too. So:
 *
 * | operation | `akses_rekam_medis_log` rows |
 * | --- | --- |
 * | detail fetch | 1 |
 * | record LIST (`GET /rekam-medis`) | 0 - see {@see daftar()} |
 * | access-log read (`GET /rekam-medis/{id}/akses`) | 0 - see {@see daftarAkses()} |
 * | in-place edit of a draft | 1 |
 * | finalisation | 1 |
 * | amendment | 1 - the parent is opened, the new row is created |
 * | a sub-entity write | 1 - the parent is opened to apply the draft gate |
 * | create | 0 - nothing pre-existing is opened |
 * | a 403, a 404, or a malformed id | 0 - the ownership probe hydrates nothing |
 * | a REFUSED write (422) | 0 - see below |
 * | a read inside a rolled-back transaction | 0 - same reason |
 *
 * ## A refused write logs ZERO, and that is the atomicity rule rather than a gap
 *
 * The log row and the operation share ONE transaction, so a 422 rolls both back. A
 * log row for an operation that did not happen would be a FALSE record, and a false
 * record in an audit table is worse than a missing one: an auditor reading
 * "this doctor opened record 41 at 10:04" for an edit that was rejected would be
 * looking at an event the database itself says did not happen.
 *
 * The obvious counter-argument is that a 422 discloses `status_dokumen`, and a
 * disclosure with no trace is the thing the table exists to prevent. It does not
 * apply on these routes: every refusal above is reached only by the record's OWN
 * doctor (`RekamMedisAccess::untukDokter()`), and a doctor who wrote the record
 * already knows whether it is a draft. The disclosure that matters - a stranger
 * learning that a record exists and what it says - is a READ, and a read either
 * completes and logs or never returns.
 *
 * The property this buys is testable in one line: after any operation, the number of
 * `akses_rekam_medis_log` rows naming a record is the number of COMPLETED operations
 * that opened it, and never more.
 *
 * ## The draft/final gate, and why the DDL's default is the trap
 *
 * `rekam_medis.status_dokumen` is `ENUM('draft','final','diamendemen') NOT NULL
 * DEFAULT 'final'` (`telemedicine_test.sql:645`). The default is the FINAL state, so
 * a create that omits the column produces a record that is immutable from the moment
 * it is born: no edit, no signature, and the only way forward is an amendment chain
 * whose `versi = 1` row already reads `final`. {@see simpan()} therefore writes
 * `'draft'` and `1` EXPLICITLY, and the test reads the DDL default out of the file
 * rather than trusting this paragraph.
 *
 * ## What makes a row current, and what the schema cannot say
 *
 * `rekam_medis` has NO linkage column and NO currency flag. The suite asserts the
 * absence of twelve candidate names - `parent_id`, `is_current`, `superseded_by`,
 * `alasan_amandemen` and eight more - directly against the parsed DDL. The chain is
 * therefore RECONSTRUCTED by grouping on `(pasien_id, dokter_id, tanggal_periksa)`
 * and ordering by `versi`, which is what {@see RekamMedisAccessLogger} does, and
 * "the current version" is DEFINED as the highest `versi` in the group.
 *
 * That definition is a choice, not a fact, and it has one honest consequence worth
 * naming. The plan says an amendment is `old.versi + 1`; for a SUPERSEDED row that
 * number is already in the group, and two rows at one version cannot be ordered -
 * which would make "current = highest version" ambiguous and would make the published
 * chain's order depend on `id`. {@see amandemen()} therefore takes `MAX(versi) + 1`
 * over the group, so an amendment of a superseded revision is strictly later than the
 * current one rather than a duplicate of it. The cost is that an amendment's `versi`
 * is not always `parent.versi + 1`, and the deviation is recorded in the evidence file.
 *
 * `tanggal_periksa` is a `DATETIME` (:630), not a DATE, so the group key is a full
 * timestamp: two visits on the same DAY are two chains unless their timestamps are
 * identical. That is the schema's precision and this service does not round it.
 *
 * ## `versi` is a TINYINT UNSIGNED, so the chain is capped at 255
 *
 * The 256th amendment would be a MySQL 1264 - an opaque driver error, which
 * `bootstrap/app.php` renders as a sanitised 500. {@see amandemen()} refuses it with
 * a 422 naming the limit instead, which is a fact about the record rather than a
 * server fault.
 *
 * ## The four child tables are written through here, so they inherit the gate
 *
 * `rekam_medis_diagnosa`, `rekam_medis_tindakan`, `rekam_medis_lampiran` and
 * `rekam_medis_persetujuan` all declare `FOREIGN KEY (rekam_medis_id) REFERENCES
 * rekam_medis(id) ON DELETE CASCADE`, so a child can never outlive its record. They
 * are written by the four `tambah*` methods, each of which opens the parent through
 * {@see bukaUntukTulis()} - so the draft/final gate is stated ONCE, here, rather than
 * restated four times, and each write logs the parent open exactly once.
 *
 * The plan asks for these four to be written "through the same service so they
 * inherit the draft/final gate" and names no endpoint for any of them - its route list
 * is five routes and a sixth would break the acceptance criterion. They are therefore
 * service methods exercised directly by the test, which is the shape the plan's own
 * wording implies.
 *
 * ## `konsultasi_id` is read off the consultation, never off the body
 *
 * `rekam_medis.konsultasi_id` is `BIGINT UNSIGNED NULL` (:627) with a foreign key to
 * `konsultasi(id)` (:653), so a record is always attributable to a session. The
 * patient and the doctor come from that consultation by way of
 * {@see KonsultasiAccess::untukDokter()} - the rule todo 32 already wrote and this
 * service reuses unmodified - rather than from the request: a body naming a different
 * `pasien_id` would be a cross-tenant write, and no code path here reads one.
 */
final class RekamMedisService
{
    /**
     * The clinical content columns a doctor may write.
     *
     * ## The allow-list is the security control, and it is also a typo trap
     *
     * `telemedicine_test.sql:631-644` is the block of `rekam_medis` columns between
     * the identity columns and `status_dokumen`, in the DDL's own order. They are
     * listed rather than derived, because the alternative - diffing the payload
     * against the identity columns - would let a MISSPELLED key through to be written
     * nowhere with no error at all. That is the exact defect todo 32 found in
     * `KonsultasiService::tulisSoap()`, where `catatan_subjektif` and
     * `catatan_asessment` sit close enough together for a typo to pass validation and
     * reach no column. {@see tolakKolomAsing()} closes it here, and the SOAPS pair
     * (`asesmen` and `plan`, :639-640) is the one to watch: `asesment` is the
     * spelling a reader expects and it is NOT a column.
     *
     * @var list<string>
     */
    public const KOLOM_ISI = [
        'keluhan_utama',
        'riwayat_penyakit_sekarang',
        'riwayat_penyakit_dahulu',
        'riwayat_keluarga',
        'riwayat_psikososial',
        'hasil_pemeriksaan_fisik',
        'subjektif',
        'objektif',
        'asesmen',
        'plan',
        'diagnosis_kerja',
        'instruksi_tindak_lanjut',
        'status_tindak_lanjut',
        'jadwal_kontrol',
    ];

    /**
     * The one identity column a create or a draft edit may move: the visit instant.
     *
     * It is offered at `simpan` (before any amendment exists to be orphaned) and at
     * `ubah` (a draft has no history yet), and REFUSED inside an amendment's
     * `perubahan` - see {@see amandemen()}, where moving it would file the new
     * revision in a different chain from the one it supersedes.
     */
    public const KOLOM_TANGGAL_PERIKSA = 'tanggal_periksa';

    /**
     * The visit type a telemedicine record is born with.
     *
     * `tipe_kunjungan` is a FIVE-value ENUM at `telemedicine_test.sql:629`:
     * `telemedisin`, `rawat_jalan`, `rawat_inap`, `igd`, `home_visit`. A record
     * created from a telemedicine consultation is `telemedisin` and the other four
     * describe visits this API cannot have had, so the value is WRITTEN rather than
     * accepted from the body. The plan says the same.
     */
    public const TIPE_KUNJUNGAN = 'telemedisin';

    /**
     * The document states, from `telemedicine_test.sql:645`, in the DDL's order.
     *
     * @var list<string>
     */
    public const STATUS_DOKUMEN = ['draft', 'final', 'diamendemen'];

    /**
     * The state an in-place edit requires, and the only one.
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * The state {@see finalisasi()} writes.
     */
    public const STATUS_FINAL = 'final';

    /**
     * The state {@see amandemen()} writes.
     */
    public const STATUS_DIAMENDEMEN = 'diamendemen';

    /**
     * The highest `versi` a TINYINT UNSIGNED can hold (`telemedicine_test.sql:646`).
     */
    public const VERSI_MAKS = 255;

    public function __construct(
        private readonly RekamMedisAccess $access,
        private readonly RekamMedisAccessLogger $logger,
        private readonly KonsultasiAccess $konsultasi,
        private readonly PasienRecordAccess $pasien,
    ) {}

    /**
     * The one and only way to READ a medical record.
     *
     * The public name the plan gives it, and the only method in the application that
     * hands back a hydrated `RekamMedis` to a caller outside this namespace. It
     * resolves the caller's side first - which can answer 403 or 404 without opening
     * anything - and then delegates to {@see RekamMedisAccessLogger::baca()}, which
     * writes the log row and performs the read in one transaction.
     *
     * `$sertaRantai` widens what is PUBLISHED, not what is LOGGED. The chain's other
     * revisions are history of the document the caller addressed, they are loaded
     * inside the same permit, and their own ids travel in the `ran` block - so one
     * request produces exactly one `akses_rekam_medis_log` row naming exactly one
     * record. It is on by default because a client rendering a document needs its
     * history and a separate endpoint for it would be a sixth route, which the plan's
     * acceptance criterion forbids.
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException
     */
    public function findForAccess(int $id, User $user, bool $sertaRantai = true): RekamMedis
    {
        [$sisi, $identitas] = $this->access->sisiUntukBaca($user, $id);

        return $this->logger->baca(
            $id,
            $identitas,
            $user,
            $this->access->tujuanUntuk($sisi),
            $sertaRantai,
        );
    }

    /**
     * `GET /api/v1/rekam-medis` - the caller patient's OWN records, newest first.
     *
     * ## This method writes ZERO `akses_rekam_medis_log` rows, and that is the point
     *
     * The records list is the one read of the `rekam_medis` family that does NOT log,
     * and the reasons are structural rather than a relaxation of the one-log-per-read
     * rule:
     *
     * 1. **A list cannot be logged.** `akses_rekam_medis_log.rekam_medis_id` is
     *    `BIGINT UNSIGNED NOT NULL` (`telemedicine_test.sql:1149`) with a foreign key
     *    to ONE `rekam_medis` row. There is no "page opened" row this table can hold,
     *    and writing one row per listed record would make the trail say the patient
     *    opened every file on the page at once, which is false.
     * 2. **This is an index, not the record.** The response publishes the visit
     *    instant, the presenting complaint and the working-diagnosis label - the
     *    minimum needed to choose a record. The SOAP note, the six-column narrative,
     *    the four child collections and the amendment chain are reachable only through
     *    {@see findForAccess()}, which logs exactly one row.
     * 3. **The model guard is not tripped.** The query is built with `DB::table()`, so
     *    no `RekamMedis` model is hydrated and `GuardsMedicalRecordRead` never fires.
     *    That is deliberate: the alternative - opening a `RekamMedisReadScope` per row
     *    to hydrate models - would produce the per-record log rows the owner ruled out.
     *    This is the only non-hydrating read of the root table outside
     *    {@see RekamMedisAccess::probe()}, and it lives here, inside the namespace the
     *    structural grep permits.
     *
     * The test asserts the count before and after the request, so "the list logs
     * nothing" is a checked property rather than this paragraph.
     *
     * ## The tenant filter IS the query
     *
     * `$user` is resolved to its own `pasien` row with
     * {@see PasienRecordAccess::ownPasien()} - 403 for an account with no profile -
     * and `where('rm.pasien_id', $pasien->id)` runs before any filter. Another
     * patient's records cannot be selected, searched into, or paged into: there is no
     * post-filter to forget, and another patient's id is not accepted from the wire.
     *
     * ## Filters, ordering and the tie-breaker
     *
     * `q` searches `keluhan_utama` and `diagnosis_kerja` only, as a `LIKE` over an
     * ESCAPED pattern (`%`, `_` and `\` match literally) and never across patients.
     * `tanggal_dari`/`tanggal_sampai` are whole days over `tanggal_periksa`, expanded
     * to `00:00:00`/`23:59:59` so MySQL can range-scan `idx_rm_pasien` instead of
     * calling `DATE()` on the column.
     *
     * Ordering is `tanggal_periksa DESC, id DESC`. The tie-breaker is explicit
     * because `tanggal_periksa` is a `DATETIME` with second precision and the chain
     * group is DEFINED by that same second: every amendment to one encounter shares
     * it, so without `id DESC` the newest revision of a record could sort below its
     * predecessor. `id` is `AUTO_INCREMENT`, so it is insertion order and cannot tie.
     *
     * @param  array<string, mixed>  $filter  validated `IndexRekamMedisRequest` payload
     *
     * @throws AccessDeniedHttpException when the account owns no `pasien` row
     */
    public function daftar(User $user, array $filter, int $perPage): LengthAwarePaginator
    {
        $pasien = $this->pasien->ownPasien($user);

        $halaman = DB::table('rekam_medis as rm')
            ->join('dokter as d', 'd.id', '=', 'rm.dokter_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('rm.pasien_id', $pasien->getKey())
            ->select([
                'rm.id',
                'rm.uuid',
                'rm.tanggal_periksa',
                'rm.keluhan_utama',
                'rm.diagnosis_kerja',
                'rm.status_dokumen',
                'rm.versi',
                'rm.dokter_id',
                'u.nama_lengkap as dokter_nama',
                // The detail resource defines "current" as max(versi) in the chain
                // group, read off the loaded `ran`. This is the same definition,
                // computed before hydration is possible at all.
                DB::raw(
                    '(SELECT MAX(rm2.versi) FROM rekam_medis AS rm2'
                    .' WHERE rm2.pasien_id = rm.pasien_id'
                    .' AND rm2.dokter_id = rm.dokter_id'
                    .' AND rm2.tanggal_periksa = rm.tanggal_periksa) AS versi_tertinggi'
                ),
            ]);

        $q = trim((string) ($filter['q'] ?? ''));

        if ($q !== '') {
            $pola = '%'.addcslashes($q, '%_\\').'%';

            $halaman->where(static function (QueryBuilder $sub) use ($pola): void {
                $sub->where('rm.keluhan_utama', 'like', $pola)
                    ->orWhere('rm.diagnosis_kerja', 'like', $pola);
            });
        }

        if (($filter['tanggal_dari'] ?? null) !== null) {
            $halaman->where('rm.tanggal_periksa', '>=', $filter['tanggal_dari'].' 00:00:00');
        }

        if (($filter['tanggal_sampai'] ?? null) !== null) {
            $halaman->where('rm.tanggal_periksa', '<=', $filter['tanggal_sampai'].' 23:59:59');
        }

        return $halaman
            ->orderByDesc('rm.tanggal_periksa')
            ->orderByDesc('rm.id')
            ->paginate($perPage);
    }

    /**
     * `GET /api/v1/rekam-medis/{id}/akses` - who opened ONE record, and why.
     *
     * ## The caller is resolved by the record's own read rule
     *
     * {@see RekamMedisAccess::sisiUntukBaca()} is the SAME resolver
     * `GET /rekam-medis/{id}` uses: a non-party gets 404 (never 403, so the endpoint
     * is not an existence oracle over a sequential id), an account owning neither
     * profile row and not an oversight type gets 403. It probes through `DB::table()`
     * and hydrates no `RekamMedis` row, so the refusal writes no log row either.
     *
     * ## This reads the log ABOUT the record, not the record
     *
     * The response is `AksesRekamMedisResource` - `waktu`, `peran` (the actor's
     * `users.tipe`) and `tujuan_akses`, and NOT the actor's name. The schema stores no
     * role column and no name column; `peran` is joined from `users.tipe` and the name
     * is deliberately left out under UU PDP No. 27/2022 data minimisation. The
     * resource docblock carries the full reasoning.
     *
     * Writing an `akses_rekam_medis_log` row here would be FALSE: the five ENUM
     * purposes all describe reading the clinical record, and this endpoint opens none
     * of it. The log therefore gains no row per access-log fetch.
     *
     * Ordering is `dibuat_at DESC, id DESC`; `dibuat_at` is a second-precision
     * `TIMESTAMP`, so `id` is the tie-breaker for two accesses in one second.
     *
     * @throws ModelNotFoundException 404 for "not yours" and for "no such record"
     * @throws AccessDeniedHttpException 403 for an account that owns no profile row
     */
    public function daftarAkses(User $user, int $id, int $perPage): LengthAwarePaginator
    {
        [, $identitas] = $this->access->sisiUntukBaca($user, $id);

        return DB::table('akses_rekam_medis_log as log')
            ->join('users as pengakses', 'pengakses.id', '=', 'log.pengakses_user_id')
            ->where('log.rekam_medis_id', $identitas['id'])
            ->select([
                'log.id',
                'log.dibuat_at',
                'log.tujuan_akses',
                'pengakses.tipe as peran',
            ])
            ->orderByDesc('log.dibuat_at')
            ->orderByDesc('log.id')
            ->paginate($perPage);
    }

    /**
     * Create a DRAFT record for a consultation, as that consultation's doctor.
     *
     * `status_dokumen = 'draft'` and `versi = 1` are written EXPLICITLY even though
     * the DDL defaults `status_dokumen` to `'final'` (:645) and `versi` to 1 (:646).
     * The first is the trap this class's docblock opens with; the second is written
     * for the same reason - a state a record is born in is a decision this service
     * makes, and a reader should not have to know a column default to know it.
     *
     * `uuid` is a fresh UUID4 from the `HasUuid` trait, because `uuid` is
     * `CHAR(36) NOT NULL UNIQUE` (:623) with no default and it is the property that
     * makes an amendment a NEW document rather than a second name for this one.
     *
     * `tanggal_periksa` is `DATETIME NOT NULL` (:630) and is supplied by the caller
     * or defaults to now.
     *
     * ## Why this writes ZERO access-log rows
     *
     * A create opens nothing that already existed, so there is nothing to disclose
     * and the plan's "every read writes a row" rule has nothing to attach to. The
     * log's five ENUM values (:1151) are all read purposes and `create` is not among
     * them; a creation's trail belongs to `audit_log` (:1118, whose `aksi` ENUM does
     * carry `create`), which this plan does not ask for.
     *
     * The row is returned with its relations SET rather than re-read, because
     * re-reading it would be a read - and would trip the very guard that says a read
     * must log.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    public function simpan(int $konsultasiId, array $data, User $dokter): RekamMedis
    {
        $this->tolakKolomAsing($data, array_merge(self::KOLOM_ISI, [self::KOLOM_TANGGAL_PERIKSA]), 'simpan');

        return DB::transaction(function () use ($konsultasiId, $data, $dokter): RekamMedis {
            // Reuses todo 32's rule verbatim: 403 for a `dokter`-typed account with no
            // profile row, 404 for a doctor attached to a different consultation.
            $konsultasi = $this->konsultasi->untukDokter($dokter, $konsultasiId);

            $baris = new RekamMedis;
            $baris->pasien_id = $konsultasi->pasien_id;
            $baris->faskes_id = null;
            $baris->dokter_id = $konsultasi->dokter_id;
            $baris->konsultasi_id = $konsultasi->getKey();
            $baris->satusehat_encounter_id = null;
            $baris->tipe_kunjungan = self::TIPE_KUNJUNGAN;
            $baris->tanggal_periksa = $data[self::KOLOM_TANGGAL_PERIKSA] ?? Carbon::now();
            $baris->status_dokumen = self::STATUS_DRAFT;
            $baris->versi = 1;
            $baris->ditandatangani_at = null;

            $this->tulisIsi($baris, $data);

            $baris->save();

            // `muatan()` is todo 32's loader and eager-loads `pasien.user` and
            // `dokter.user`, which is what the resource publishes. `Konsultasi` and
            // `Pasien` are not guarded models, so reading them here is not a
            // disclosure of a medical record.
            $konsultasiDimuat = $this->konsultasi->muatan($konsultasi->getKey());

            $baris->setRelation('pasien', $konsultasiDimuat->pasien);
            $baris->setRelation('dokter', $konsultasiDimuat->dokter);
            $baris->setRelation('rekamMedisDiagnosa', new Collection);
            $baris->setRelation('rekamMedisTindakan', new Collection);
            $baris->setRelation('rekamMedisLampiran', new Collection);
            $baris->setRelation('rekamMedisPersetujuan', new Collection);
            $baris->setRelation('ran', new Collection([$baris]));

            return $baris;
        });
    }

    /**
     * Edit a DRAFT in place, as its doctor.
     *
     * In-place editing is permitted while `status_dokumen === 'draft'` and NOWHERE
     * else, and the refusal names the amendment path so the caller is not left
     * guessing. This is the rule the whole amendment chain exists to protect: a
     * record that has been signed must not be able to change under the signature.
     *
     * The row is opened through {@see RekamMedisAccessLogger::untukTulis()}, so a
     * COMPLETED edit logs. A refused one logs nothing, because the log row and the
     * edit share one transaction and the 422 rolls both back - see the class
     * docblock for why a false record is worse than a missing one.
     *
     * ## Why the refusal leaves the row BYTE identical
     *
     * The gate is checked before any assignment, inside the same transaction, and
     * the exception rolls the transaction back. `diubah_at` is `ON UPDATE
     * CURRENT_TIMESTAMP` (:649), so a write that happened and was then rolled back
     * would still have moved it on some paths; checking first means no UPDATE is
     * issued at all. The test compares the whole row including `diubah_at`.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    public function ubah(int $rekamMedisId, array $data, User $dokter): RekamMedis
    {
        $this->tolakKolomAsing($data, array_merge(self::KOLOM_ISI, [self::KOLOM_TANGGAL_PERIKSA]), 'ubah');

        return DB::transaction(function () use ($rekamMedisId, $data, $dokter): RekamMedis {
            $identitas = $this->access->untukDokter($dokter, $rekamMedisId);
            $baris = $this->logger->untukTulis($rekamMedisId, $identitas, $dokter, 'perawatan', true);

            $this->tolakKecualiDraft(
                $baris,
                'Rekam medis yang sudah final atau diamendemen tidak dapat diubah langsung. Gunakan endpoint amandemen.',
            );

            $this->tulisIsi($baris, $data);

            if (array_key_exists(self::KOLOM_TANGGAL_PERIKSA, $data)) {
                $baris->tanggal_periksa = $data[self::KOLOM_TANGGAL_PERIKSA];
            }

            $baris->save();

            return $baris;
        });
    }

    /**
     * Stamp `status_dokumen = 'final'` and `ditandatangani_at`, once.
     *
     * `ditandatangani_at` is `DATETIME NULL` (:647) and this is its only writer, so
     * "has this record been signed" has exactly one answer in the application. A
     * second call is a 422 and does not re-stamp: the column would otherwise record
     * the most recent finalisation ATTEMPT rather than the moment the document was
     * signed.
     *
     * A `diamendemen` row is already signed - {@see amandemen()} stamps it - so it is
     * refused here too. `status_dokumen` is the three-value ENUM at :645 and
     * `diamendemen` is the third of them, which is why it exists: a superseded record
     * stays readable and distinguishable from one that is still a draft.
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    public function finalisasi(int $rekamMedisId, User $dokter): RekamMedis
    {
        return DB::transaction(function () use ($rekamMedisId, $dokter): RekamMedis {
            $identitas = $this->access->untukDokter($dokter, $rekamMedisId);
            $baris = $this->logger->untukTulis($rekamMedisId, $identitas, $dokter, 'perawatan', true);

            $this->tolakKecualiDraft(
                $baris,
                'Rekam medis hanya dapat difinalisasi satu kali, dan hanya dari status draft.',
            );

            $baris->status_dokumen = self::STATUS_FINAL;
            $baris->ditandatangani_at = Carbon::now();
            $baris->save();

            return $baris;
        });
    }

    /**
     * Supersede a SIGNED record with a new one.
     *
     * ## The rule, and the two ways it can be got wrong
     *
     * A final record is never overwritten. Inside one transaction, with the parent
     * already locked by the logging read:
     *
     * 1. the parent is opened and logged through
     *    {@see RekamMedisAccessLogger::untukTulis()};
     * 2. the gate refuses a `draft` - a draft is not signed, so amending it is the
     *    wrong verb and the 422 says which verb to use instead;
     * 3. the new version is `MAX(versi) + 1` over the chain GROUP, not
     *    `parent.versi + 1`. See the class docblock: the plan's rule produces a
     *    duplicate when the parent is already superseded;
     * 4. `MAX(versi)` is read as a CURRENT read, so two concurrent amendments of the
     *    same document cannot both read the same maximum and both write `versi = n+1`.
     *    Nothing in the schema forbids two rows at one version, so this read is the
     *    only thing that does;
     * 5. a NEW row is inserted with a fresh `uuid`, `status_dokumen = 'diamendemen'`
     *    and `ditandatangani_at` stamped, carrying every column of the parent with the
     *    changed fields MERGED over it.
     *
     * The merge is what makes the chain readable as a document rather than as a diff.
     * A field the caller did not mention is carried over, and a field the caller sent
     * as `null` is written as `null` - `array_key_exists`, not `isset`, so "clear this
     * field" and "leave this field alone" are different requests and the difference
     * is the caller's.
     *
     * `tanggal_periksa` is REFUSED inside `perubahan` and reported as such, because it
     * is part of the chain group: moving it would file the new revision in a different
     * chain from the one it supersedes, and the two rows would then be unrelated
     * records carrying consecutive version numbers.
     *
     * ## The new row's relations are SET, never loaded
     *
     * The amendment has the same patient and the same doctor as its parent by
     * construction, and no children, and it is the last member of its chain - so all
     * four facts are carried over from the parent's already-loaded relations and the
     * chain collection rather than read. Loading them would be a second read and
     * therefore a second log row, and it would trip the guard that a read must log.
     *
     * @param  array<string, mixed>  $perubahan
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    public function amandemen(int $rekamMedisId, array $perubahan, User $dokter): RekamMedis
    {
        return DB::transaction(function () use ($rekamMedisId, $perubahan, $dokter): RekamMedis {
            $identitas = $this->access->untukDokter($dokter, $rekamMedisId);
            $tertulis = $this->logger->untukTulis($rekamMedisId, $identitas, $dokter, 'perawatan', true);

            $violasi = $this->periksaAmandemen($tertulis, $perubahan, $identitas);

            // Every rule is evaluated before anything throws, so a request that breaks
            // two of them answers ONE 422 carrying both rather than whichever check
            // happened to run first.
            if ($violasi !== []) {
                throw ValidationException::withMessages($violasi);
            }

            $versiBerikut = $this->versiBerikut($identitas);

            $baru = new RekamMedis;
            $baru->pasien_id = $tertulis->pasien_id;
            $baru->faskes_id = $tertulis->faskes_id;
            $baru->dokter_id = $tertulis->dokter_id;
            $baru->konsultasi_id = $tertulis->konsultasi_id;
            $baru->satusehat_encounter_id = $tertulis->satusehat_encounter_id;
            $baru->tipe_kunjungan = $tertulis->tipe_kunjungan;

            // Copied, never taken from the payload: it is the chain group key.
            $baru->tanggal_periksa = $tertulis->tanggal_periksa;

            foreach (self::KOLOM_ISI as $kolom) {
                $baru->{$kolom} = $tertulis->{$kolom};
            }

            foreach ($perubahan as $kolom => $nilai) {
                $baru->{$kolom} = $nilai;
            }

            $baru->status_dokumen = self::STATUS_DIAMENDEMEN;
            $baru->versi = $versiBerikut;
            $baru->ditandatangani_at = Carbon::now();
            $baru->save();

            $rantai = $tertulis->getRelation('ran');
            $rantai->push($baru);

            $baru->setRelation('pasien', $tertulis->getRelation('pasien'));
            $baru->setRelation('dokter', $tertulis->getRelation('dokter'));
            $baru->setRelation('rekamMedisDiagnosa', new Collection);
            $baru->setRelation('rekamMedisTindakan', new Collection);
            $baru->setRelation('rekamMedisLampiran', new Collection);
            $baru->setRelation('rekamMedisPersetujuan', new Collection);
            $baru->setRelation('ran', $rantai);

            return $baru;
        });
    }

    /**
     * Attach a diagnosis to a DRAFT record, as its doctor.
     *
     * `jenis` is a FOUR-value ENUM (:662) and `tipe_kasus` a TWO-value ENUM (:663);
     * the service takes the value the request validated and does not re-derive it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    public function tambahDiagnosa(int $rekamMedisId, array $data, User $dokter): RekamMedisDiagnosa
    {
        return DB::transaction(function () use ($rekamMedisId, $data, $dokter): RekamMedisDiagnosa {
            $this->bukaUntukTulis($rekamMedisId, $dokter);

            $anak = new RekamMedisDiagnosa;
            $anak->rekam_medis_id = $rekamMedisId;
            $anak->icd10_kode = $data['icd10_kode'];
            $anak->deskripsi = $data['deskripsi'] ?? null;
            $anak->jenis = $data['jenis'];
            $anak->tipe_kasus = $data['tipe_kasus'] ?? 'baru';
            $anak->is_terkonfirmasi = (bool) ($data['is_terkonfirmasi'] ?? false);
            $anak->save();

            return $anak;
        });
    }

    /**
     * Attach a procedure to a DRAFT record, as its doctor.
     *
     * `dokter_pelaksana_id` (:676) is filled from the record's OWN `dokter_id` rather
     * than from the request: a procedure performed on this patient's record by this
     * record's doctor is the only fact available, and accepting a second doctor's id
     * would let one record credit a procedure to somebody who was not its author.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    public function tambahTindakan(int $rekamMedisId, array $data, User $dokter): RekamMedisTindakan
    {
        return DB::transaction(function () use ($rekamMedisId, $data, $dokter): RekamMedisTindakan {
            $induk = $this->bukaUntukTulis($rekamMedisId, $dokter);

            $anak = new RekamMedisTindakan;
            $anak->rekam_medis_id = $rekamMedisId;
            $anak->icd9cm_kode = $data['icd9cm_kode'] ?? null;
            $anak->nama_tindakan = $data['nama_tindakan'];
            $anak->keterangan = $data['keterangan'] ?? null;
            $anak->tanggal_tindakan = $data['tanggal_tindakan'] ?? $induk->tanggal_periksa;
            $anak->dokter_pelaksana_id = $induk->dokter_id;
            $anak->save();

            return $anak;
        });
    }

    /**
     * Attach an attachment to a DRAFT record, as its doctor.
     *
     * `diunggah_oleh` is `BIGINT UNSIGNED NOT NULL` (:687) and carries **NO foreign
     * key** (:689 declares only the `rekam_medis` one), so the schema does not check
     * it. It is written from the authenticated account for the same reason
     * `booking.dibuat_oleh_user_id` is (todo 27): the column is a `users.id`, and
     * nothing in the database would catch a profile id being passed instead.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    public function tambahLampiran(int $rekamMedisId, array $data, User $dokter): RekamMedisLampiran
    {
        return DB::transaction(function () use ($rekamMedisId, $data, $dokter): RekamMedisLampiran {
            $this->bukaUntukTulis($rekamMedisId, $dokter);

            $anak = new RekamMedisLampiran;
            $anak->rekam_medis_id = $rekamMedisId;
            $anak->nama_file = $data['nama_file'];
            $anak->file_url = $data['file_url'];
            $anak->tipe = $data['tipe'];
            $anak->diunggah_oleh = $dokter->getKey();
            $anak->save();

            return $anak;
        });
    }

    /**
     * Attach a consent record to a DRAFT record, as its doctor.
     *
     * `tipe` is a THREE-value ENUM (:695): `general_consent`,
     * `persetujuan_tindakan`, `penolakan_tindakan`. `isi_persetujuan`,
     * `ditandatangani_oleh` and `ditandatangani_at` are all NOT NULL with no default
     * (:696, :697, :700), so a signature captured without them is a 1364.
     *
     * **This is a clinical consent on the record, and it is NOT the platform's PDP
     * consent.** `persetujuan_pdp` (:1134) is a separate table with its own `jenis`
     * ENUM and it is todo 34's gate on `surat_rujukan`; nothing here consults it,
     * because reading and writing a telemedicine record is not cross-faskes sharing.
     * The two being easy to confuse is the reason the distinction is written down.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    public function tambahPersetujuan(int $rekamMedisId, array $data, User $dokter): RekamMedisPersetujuan
    {
        return DB::transaction(function () use ($rekamMedisId, $data, $dokter): RekamMedisPersetujuan {
            $this->bukaUntukTulis($rekamMedisId, $dokter);

            $anak = new RekamMedisPersetujuan;
            $anak->rekam_medis_id = $rekamMedisId;
            $anak->tipe = $data['tipe'];
            $anak->isi_persetujuan = $data['isi_persetujuan'];
            $anak->ditandatangani_oleh = $data['ditandatangani_oleh'];
            $anak->hubungan_dengan_pasien = $data['hubungan_dengan_pasien'] ?? null;
            $anak->tanda_tangan_url = $data['tanda_tangan_url'] ?? null;
            $anak->ditandatangani_at = $data['ditandatangani_at'] ?? Carbon::now();
            $anak->save();

            return $anak;
        });
    }

    /**
     * Open a record for a write, logging the open, and refuse unless it is a draft.
     *
     * The one place the draft/final gate is stated, which is what makes the four
     * `tambah*` methods inherit it rather than each restate it.
     *
     * @throws ModelNotFoundException|AccessDeniedHttpException|ValidationException
     */
    private function bukaUntukTulis(int $rekamMedisId, User $dokter): RekamMedis
    {
        $identitas = $this->access->untukDokter($dokter, $rekamMedisId);
        $baris = $this->logger->untukTulis($rekamMedisId, $identitas, $dokter, 'perawatan');

        $this->tolakKecualiDraft(
            $baris,
            'Isi rekam medis hanya dapat ditambah pada rekam medis berstatus draft.',
        );

        return $baris;
    }

    /**
     * Every way an amendment can be refused, collected before any of them throws.
     *
     * `ValidationException::withMessages()` APPENDS when the same key is given twice,
     * which is what lets an empty change set answer with both "kosong" and "mengubah
     * tidak ada kolom" rather than with whichever check ran first. Collecting rather
     * than throwing on the first violation is what produces the two messages on the
     * SAME `perubahan` key that the suite asserts, and it is why neither hides the
     * other.
     *
     * The order is load-bearing and not alphabetical: an UNKNOWN column is reported
     * before a "nothing changed" verdict, because a set containing only a misspelled
     * key trivially changes nothing and the caller needs the real problem first.
     *
     * @param  array<string, mixed>  $perubahan
     * @param  array{id: int, pasien_id: int, dokter_id: int, tanggal_periksa: string}  $identitas
     * @return array<string, list<string>>
     */
    private function periksaAmandemen(RekamMedis $tertulis, array $perubahan, array $identitas): array
    {
        $violasi = [];

        if ($tertulis->status_dokumen === self::STATUS_DRAFT) {
            $violasi['status_dokumen'] = ['Hanya rekam medis yang sudah final atau diamendemen dapat diamendemen.'];
        }

        $takDikenal = array_values(array_diff(array_keys($perubahan), self::KOLOM_ISI));

        if ($takDikenal !== []) {
            $violasi['perubahan'][] = 'Kolom rekam medis tidak dikenal: '.implode(', ', $takDikenal).'.';
        }

        if ($perubahan === []) {
            $violasi['perubahan'][] = 'Perubahan tidak boleh kosong.';
        }

        if ($takDikenal === [] && ! $this->mengubahSesuatu($tertulis, $perubahan)) {
            $violasi['perubahan'][] = 'Perubahan tidak mengubah nilai kolom mana pun.';
        }

        if ($this->versiBerikut($identitas) > self::VERSI_MAKS) {
            $violasi['versi'] = [
                'Rekam medis ini sudah mencapai versi maksimum yang diizinkan skema ('.self::VERSI_MAKS.').',
            ];
        }

        return $violasi;
    }

    /**
     * Does this change set actually change anything?
     *
     * A loose `==` rather than `===`, because `$perubahan` arrives from JSON and `1`
     * and `'1'` are the same value in a column MySQL will store the same way.
     * Reporting "no column changed" for a change MySQL would have applied would be
     * wrong, and creating a version that differs from its parent in nothing would be
     * a chain entry with no content.
     *
     * @param  array<string, mixed>  $perubahan
     */
    private function mengubahSesuatu(RekamMedis $baris, array $perubahan): bool
    {
        foreach ($perubahan as $kolom => $nilai) {
            if ($baris->{$kolom} != $nilai) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next free `versi` in this chain group, read as a CURRENT read.
     *
     * `lockForUpdate()` on the projection rather than a plain `max()`: under
     * `REPEATABLE READ` a consistent read sees the transaction's snapshot, so two
     * concurrent amendments would both read `MAX(versi) = 3` and both write 4 - a
     * duplicate the schema cannot prevent, because `rekam_medis` declares no unique
     * constraint over the group (its only index is `idx_rm_pasien (pasien_id,
     * tanggal_periksa)`, :654). A locking read always observes the latest committed
     * version. This is the same idiom `BookingService::pastikanKapasitas()` uses and
     * the same reason it is written there.
     *
     * @param  array{id: int, pasien_id: int, dokter_id: int, tanggal_periksa: string}  $identitas
     */
    private function versiBerikut(array $identitas): int
    {
        $maksimum = DB::table('rekam_medis')
            ->select('versi')
            ->where('pasien_id', $identitas['pasien_id'])
            ->where('dokter_id', $identitas['dokter_id'])
            ->where('tanggal_periksa', $identitas['tanggal_periksa'])
            ->lockForUpdate()
            ->pluck('versi')
            ->map(static fn ($versi): int => (int) $versi)
            ->max();

        return (int) $maksimum + 1;
    }

    /**
     * Refuse any record that is not a draft.
     *
     * @throws ValidationException
     */
    private function tolakKecualiDraft(RekamMedis $baris, string $pesan): void
    {
        if ($baris->status_dokumen === self::STATUS_DRAFT) {
            return;
        }

        throw ValidationException::withMessages(['status_dokumen' => [$pesan]]);
    }

    /**
     * Refuse a payload key the schema does not have.
     *
     * **A `LogicException` and not a 422, deliberately.** The key did not come from a
     * client that could have got it wrong about the domain - it came from a
     * controller passing its validated payload to the wrong service method, or from a
     * column renamed in a migration. A 422 would tell the caller to fix its request,
     * which is not the thing that is broken; the message names the method and the
     * writable columns so the developer is. `KonsultasiService::tulisSoap()` makes
     * the same call for the same reason, and the underlying defect - a misspelled key
     * accepted and then written nowhere - is the one this closes.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $boleh
     *
     * @throws LogicException
     */
    private function tolakKolomAsing(array $data, array $boleh, string $metode): void
    {
        $takDikenal = array_values(array_diff(array_keys($data), $boleh));

        if ($takDikenal === []) {
            return;
        }

        throw new LogicException(
            self::class.'::'.$metode.'() was given a key the schema does not have: '.implode(', ', $takDikenal)
            .'. The writable columns are: '.implode(', ', $boleh).'.'
        );
    }

    /**
     * Write the {@see KOLOM_ISI} keys that are actually PRESENT in `$data`.
     *
     * `array_key_exists` and not `isset`, so a key the caller sent as `null` is
     * written as `null` - "clear this field" and "leave this field alone" are
     * different requests and the difference belongs to the caller. Combined with
     * {@see tolakKolomAsing()} running at each entry point, the misspelled-key hole
     * that `tulisSoap()` had is closed from both sides: a KNOWN key is honoured, an
     * UNKNOWN key is refused.
     *
     * @param  array<string, mixed>  $data
     */
    private function tulisIsi(RekamMedis $baris, array $data): void
    {
        foreach (self::KOLOM_ISI as $kolom) {
            if (array_key_exists($kolom, $data)) {
                $baris->{$kolom} = $data[$kolom];
            }
        }
    }
}
