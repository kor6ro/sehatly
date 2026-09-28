<?php

declare(strict_types=1);

namespace App\Services\RekamMedis;

use App\Models\AksesRekamMedisLog;
use App\Models\RekamMedis;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the `akses_rekam_medis_log` row, and is the only thing that may open a
 * read of a medical record.
 *
 * ## `log()` alone is not the plan's guarantee, and this class explains why
 *
 * The plan's todo 33 names `RekamMedisAccessLogger::log(int $rekamMedisId, User
 * $accessor, string $tujuan)` and separately says the log "must be called from
 * every single read path". Two statements, two steps, and the gap between them is
 * the whole risk: a caller can `log()` and then not read, or read and forget to
 * `log()`, and nothing in the type system objects to either.
 *
 * So the read and the log are ONE operation here, {@see baca()}, in ONE
 * transaction, and the order inside it is deliberate:
 *
 * 1. the ownership probe (in {@see RekamMedisAccess}, a non-hydrating
 *    `DB::table()` read of three columns) answers 403 or 404 - and throws BEFORE
 *    anything is logged, which is what makes "a refusal writes zero rows" true;
 * 2. the `akses_rekam_medis_log` row is INSERTED;
 * 3. only then is the gate opened and the record hydrated.
 *
 * If step 3 fails the transaction rolls back and the log row goes with it, so the
 * log can never claim an access that did not complete. If step 2 fails the record
 * is never hydrated, so the log can never be skipped. There is no interleaving in
 * which a record is read without a committed log row naming it.
 *
 * ## `tujuan_akses` is passed in, and it is never a wire value
 *
 * The parameter exists because the plan names it. Its only caller is
 * {@see RekamMedisService}, which derives the value from which side of the record
 * the accessor is - see {@see RekamMedisAccess::tujuanUntuk()} for the mapping and
 * for why two of the five ENUM values have no producer.
 *
 * ## `dibuat_at` is a TIMESTAMP and the log is therefore ordered by `id`
 *
 * `akses_rekam_medis_log.dibuat_at` is `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`
 * (:1152), which is ONE SECOND of resolution. Two reads inside one second are
 * indistinguishable by time, and an auditor reading the trail has to order by the
 * auto-increment `id`. That is also why two reads of one record are two rows and
 * not one deduplicated row: there is no column to deduplicate on, and collapsing a
 * client's retry would answer "was this looked at twice?" with "no".
 */
final class RekamMedisAccessLogger
{
    /**
     * The relations a read publishes, loaded INSIDE the scope.
     *
     * The four child tables are named as classes rather than as relation strings
     * because their models are the ones carrying the read guard, and naming the
     * class is what makes it obvious that all five hydrations happen inside one
     * permit and therefore produce one log row between them.
     *
     * @var list<string>
     */
    public const RELASI_BACA = [
        'pasien.user',
        'dokter.user',
        'rekamMedisDiagnosa',
        'rekamMedisTindakan',
        'rekamMedisLampiran',
        'rekamMedisPersetujuan',
    ];

    /**
     * Write one `akses_rekam_medis_log` row.
     *
     * **The only writer of the table.** `RekamMedisService` never inserts one
     * itself, and neither does anything outside `app/Services/RekamMedis/`, so the
     * INSERT has exactly one call site in the application.
     *
     * `pengakses_user_id` is a foreign key to `users(id)` with NO `ON DELETE` clause
     * (:1154), so the row is RESTRICTed while the account exists and the column
     * cannot be a profile id: it is written from the authenticated principal, never
     * from the request.
     *
     * `rekam_medis_id` is `ON DELETE CASCADE` (:1153), so deleting a record erases
     * the evidence of its access. That is the schema's decision and it is worth
     * knowing: nothing in this application deletes a `rekam_medis` row, but the
     * trail is not tamper-evident against a privileged `DELETE`, and only
     * `audit_log` (:1118, whose `aksi` ENUM includes `read` and `delete`) is
     * append-only by construction. Reported, not worked around.
     */
    public function log(int $rekamMedisId, User $accessor, string $tujuan): AksesRekamMedisLog
    {
        $baris = new AksesRekamMedisLog;
        $baris->rekam_medis_id = $rekamMedisId;
        $baris->pengakses_user_id = $accessor->getKey();
        $baris->tujuan_akses = $tujuan;
        $baris->save();

        return $baris;
    }

    /**
     * Read one record, log the access, and hand back the record - in one
     * transaction, with the read gate open only for the duration.
     *
     * `$sertaRantai` widens the hydrate set to the whole chain group. It does NOT
     * widen the log: the log names `$rekamMedisId`, the record the caller
     * addressed, because `akses_rekam_medis_log.rekam_medis_id` is a single foreign
     * key to a single row (:1149) and the superseded revisions of a chain are not
     * separate documents - they are frozen history whose ids are published inside
     * the `ran` block of the same response.
     *
     * @param  array{id: int, pasien_id: int, dokter_id: int, tanggal_periksa: string}  $identitas
     *                                                                                              the ownership triple from {@see RekamMedisAccess::sisiUntukBaca()}
     *
     * @throws ModelNotFoundException when the row vanished between the probe and the load
     */
    public function baca(int $rekamMedisId, array $identitas, User $accessor, string $tujuan, bool $sertaRantai = false): RekamMedis
    {
        return DB::transaction(function () use ($rekamMedisId, $identitas, $accessor, $tujuan, $sertaRantai): RekamMedis {
            $this->log($rekamMedisId, $accessor, $tujuan);

            $grup = [$identitas['pasien_id'], $identitas['dokter_id'], $identitas['tanggal_periksa']];

            $ids = $sertaRantai
                ? $this->idsRantai($grup)
                : [$rekamMedisId];

            return RekamMedisReadScope::dalam(
                grup: $grup,
                ids: $ids,
                callback: fn (): RekamMedis => $this->muat($rekamMedisId, $sertaRantai),
            );
        });
    }

    /**
     * Open one record for a WRITE, logging the access in the caller's transaction.
     *
     * ## Why a write logs at all
     *
     * A write must open the record - there is no way to answer "is this still a
     * draft?" without reading `status_dokumen` - and an open that leaves no trace
     * is exactly the hole the guard exists to close. So a write is a read for
     * logging purposes, and the log row is written FIRST, inside the same
     * transaction the write will commit in, so the two always describe one event.
     *
     * A 422 that says "this record is final" is itself a disclosure about the
     * record's content, and it is why the refusal cases log rather than staying
     * silent: the refusal could not have been produced without reading the row.
     *
     * ## Why this does not open a transaction of its own
     *
     * {@see RekamMedisService} wraps every write in `DB::transaction()`. Opening a
     * second one here would make the log row commit or roll back on a boundary
     * different from the write's own - and a log row that survives a rolled-back
     * edit would be a false record, which is worse than no log row at all. The
     * transaction is therefore the caller's, and this method only guarantees the
     * ORDER (log, then load) inside it.
     *
     * @param  array{id: int,patient_id: int, dokter_id: int, tanggal_periksa: string}  $identitas
     *
     * @throws ModelNotFoundException when the row vanished between the probe and the load
     */
    public function untukTulis(int $rekamMedisId, array $identitas, User $penulis, string $tujuan, bool $sertaRantai = false): RekamMedis
    {
        $this->log($rekamMedisId, $penulis, $tujuan);

        $grup = [$identitas['pasien_id'], $identitas['dokter_id'], $identitas['tanggal_periksa']];

        return RekamMedisReadScope::dalam(
            grup: $grup,
            ids: $sertaRantai ? $this->idsRantai($grup) : [$rekamMedisId],
            callback: fn (): RekamMedis => $this->muat($rekamMedisId, $sertaRantai),
        );
    }

    /**
     * Every `rekam_medis.id` in one chain group, newest last.
     *
     * `DB::table()->pluck()` and not the model, for the reason
     * {@see RekamMedisAccess::probe()} uses the query builder: a model read would
     * hydrate `rekam_medis` rows, which is precisely what the guard refuses outside
     * a scope, and listing the group must not itself be an unguarded read.
     *
     * @param  array{0: int, 1: int, 2: string}  $grup
     * @return list<int>
     */
    private function idsRantai(array $grup): array
    {
        return DB::table('rekam_medis')
            ->where('pasien_id', $grup[0])
            ->where('dokter_id', $grup[1])
            ->where('tanggal_periksa', $grup[2])
            ->orderBy('versi')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The one record, with the relations a read publishes, and the chain when asked.
     *
     * `firstOrFail()` rather than `first()`: a row that was present in the probe and
     * is absent here was deleted in between, and answering 404 with a rolled-back log
     * row is the truthful result.
     *
     * The addressed row is the SAME INSTANCE the `ran` collection publishes, not a
     * second copy of it. That is what lets a write mutate the row in place and have
     * the chain entry update with it - so `finalisasi()` needs one load, not a load
     * and then a re-read, and therefore writes ONE log row rather than two.
     */
    private function muat(int $rekamMedisId, bool $sertaRantai): RekamMedis
    {
        $muatan = RekamMedis::query()
            ->whereKey($rekamMedisId)
            ->with(self::RELASI_BACA)
            ->firstOrFail();

        if (! $sertaRantai) {
            return $muatan;
        }

        $sibling = $this->siblingRantai($muatan)
            ->map(fn (RekamMedis $row): RekamMedis => (int) $row->getKey() === (int) $muatan->getKey() ? $muatan : $row)
            ->values();

        return $muatan->setRelation('ran', $sibling);
    }

    /**
     * Every revision of this record's document, ascending by `versi` then `id`.
     *
     * The order is TOTAL, so the published chain can never reshuffle between two
     * requests. `versi` is a TINYINT (:646) and the schema has no constraint
     * forbidding two rows at the same version, so `id` is the tiebreaker - the same
     * reasoning `KonsultasiService::riwayat()` and `BookingService::daftar()` give.
     *
     * There is deliberately NO limit and no `latest()`: publishing only the newest
     * revision would be a silent truncation of the audit trail, which is the one
     * thing a medical record's history may not do. A 255-revision chain is the
     * longest this can get, because `versi` is a TINYINT UNSIGNED and
     * {@see RekamMedisService::amandemen()} refuses the 256th.
     *
     * @return Collection<int, RekamMedis>
     */
    private function siblingRantai(RekamMedis $row): Collection
    {
        /** @var Collection<int, RekamMedis> $sibling */
        $sibling = RekamMedis::query()
            ->where('pasien_id', $row->pasien_id)
            ->where('dokter_id', $row->dokter_id)
            ->where('tanggal_periksa', $row->tanggal_periksa)
            ->orderBy('versi')
            ->orderBy('id')
            ->get();

        return $sibling;
    }
}
