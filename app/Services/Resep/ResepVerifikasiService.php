<?php

declare(strict_types=1);

namespace App\Services\Resep;

use App\Enums\ResepStatus;
use App\Enums\ResepVerifikasiStatus;
use App\Models\Resep;
use App\Models\ResepItem;
use App\Models\ResepVerifikasi;
use App\Models\User;
use App\Services\Notifikasi\NotificationService;
use App\Services\Obat\ObatInteraksiService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Pharmacist verification, the interaction re-check that runs with it, and the
 * checkout precondition that verification is the gate for.
 *
 * ## THE REJECTION IS TERMINAL, and the DDL is why
 *
 * `resep_verifikasi.resep_id` is `BIGINT UNSIGNED NOT NULL UNIQUE`
 * (`telemedicine_test.sql:788`). A prescription can be verified **exactly once,
 * ever**. There is no second row, no `ditolak` row that a later `sesuai` row
 * supersedes, and no UPDATE anywhere in this application that rewrites a
 * verification.
 *
 * A pharmacist who answers `ditolak` has therefore spent the only verification
 * the row can hold. The plan's todo 40 says the same thing from the other
 * direction: there is **no `resep` status meaning "returned for correction"**
 * among the eight members at `:751`-`:752`, so a rejected prescription has
 * nowhere to go but `dibatalkan` - and from `dibatalkan` the state machine
 * offers nothing.
 *
 * So the terminality is THREE properties, each enforced in a different layer,
 * and a test proves each one:
 *
 * 1. **The row is unique.** The pre-check answers the common case with a
 *    readable 422; the constraint is the MECHANISM, and {@see tulis()} catches
 *    the `UniqueConstraintViolation` a race produces and turns it into the SAME
 *    422 rather than letting it escape as the sanitised 500 the envelope would
 *    otherwise render.
 * 2. **The status is terminal.** A rejection walks `resep.status` forward to
 *    `dibatalkan` through {@see ResepStateMachine}, so nothing can walk it back,
 *    and `dipenuhi` is refused, so a rejected prescription can never be
 *    dispensed.
 * 3. **The engine forgets it.** `dibatalkan` is one of the three
 *    `ObatInteraksiService::STATUS_AKHIR` (`:249`), so a rejected
 *    prescription's drugs stop being reported as "what the patient is on".
 *    That is the reason a rejection MOVES rather than staying put: leaving it
 *    at `diproses` would make the next doctor's safety check assert something
 *    the pharmacy has just refused.
 *
 * ## The `aktif -> diverifikasi` SHORTCUT is walked, not taken
 *
 * `ResepStateMachine::TRANSISI` has no `aktif -> diverifikasi` edge. A
 * prescription is born `aktif` (`resep.status ... DEFAULT 'aktif'`, `:752`) and
 * a pharmacist takes it into `diproses` before signing it off, so
 * {@see tulis()} performs TWO transitions, and TWO `UPDATE`s, when the row is
 * still `aktif`. The alternative - writing `diverifikasi` directly - would make
 * "a pharmacist handled this" and "somebody wrote `diverifikasi`"
 * indistinguishable in the only record the schema keeps.
 *
 * ## THE DECISION: a warning that appears only at verification time
 *
 * The interaction engine is re-run against the **STORED** items on every
 * verification, through {@see ObatInteraksiService::peringatan()} - the engine
 * itself, unchanged. None of its rules is re-derived here: bidirectionality is
 * still `pasangan()`'s, de-duplication still resolves to the worst severity,
 * and allergy matching is still equality after the engine's eight-step
 * normalisation.
 *
 * `obat_interaksi` (`:731`-`:740`) and `pasien_alergi` (`:274`-`:284`) are LIVE
 * tables. A `kontraindikasi` that did not exist when the doctor signed - a new
 * interaction row, a newly recorded allergy, a second prescription the patient
 * started since - is a fact `resep.catatan_dokter` (`:753`) does NOT cover,
 * because the doctor never saw it. Todo 39's acknowledgement rule fires on the
 * doctor's OWN warning set; it cannot fire on a fact that arrived afterwards.
 *
 * **So: a pharmacist answering `sesuai` or `ada_koreksi` must acknowledge a
 * `kontraindikasi` on `resep_verifikasi.catatan` (`:791`). Without a note the
 * verification is a 422 and nothing is written.** Two messages on `catatan` -
 * the mirror of todo 39's `catatan_dodio`, applied to the second reader.
 *
 * `ditolak` is ALWAYS accepted without a note, because a rejection is the safe
 * direction and demanding an acknowledgement before recording one would be
 * perverse.
 *
 * The alternative - accepting `sesuai` and returning the warning in the
 * response body - was rejected: the pharmacist's click is the record, and a
 * response field nobody is required to read is not an acknowledgement. The
 * engine still "WARNS, it cannot refuse" - that contract is about the ENGINE,
 * and the refusal here belongs to a service that has a place to record a
 * refusal, which the engine does not.
 *
 * ## `diverifikasi_at` is the ONLY record of when
 *
 * `resep_verifikasi` declares no `dibuat_at` and no `diubah_at`
 * (`telemedicine_test.sql:786`-`:795`), so the model is not timestamped and
 * `diverifikasi_at` (`:792`) carries the whole audit story: who, when, and what
 * they said. It is written from the clock and is `prohibited` on the request, so
 * a caller cannot backdate a signature.
 *
 * ## The write is one transaction, and the row goes in FIRST
 *
 * {@see tulis()} inserts the verification and then walks the status, in that
 * order, inside `DB::transaction`. The order matters: if a status transition
 * refuses, the transaction rolls back and no verification row remains, so a
 * refused verification leaves the prescription still verifiable. The reverse
 * order would spend the one verification the row can hold on a call that then
 * failed.
 */
final class ResepVerifikasiService
{
    public function __construct(
        private readonly ResepAccess $akses,
        private readonly ResepStateMachine $mesin,
        private readonly ObatInteraksiService $interaksi,
        private readonly NotificationService $notifikasi,
    ) {}

    /**
     * Verify one prescription once, and only once.
     *
     * @param  array{status: string, catatan?: ?string}  $data
     * @return array{resep: Resep, verifikasi: ResepVerifikasi, warning: list<array<string, mixed>>, warning_grup: array<string, list<array<string, mixed>>>, terminal: bool}
     *
     * @throws AccessDeniedHttpException|ValidationException
     */
    public function verifikasi(User $apoteker, int $resepId, array $data): array
    {
        // 1. WHO. Ownership, and the "no doctor verifies their own" rule that a
        //    `tipe:` gate cannot express. Both BEFORE the warning re-check,
        //    because a caller with no business here learns nothing about the
        //    prescription's contents.
        $resep = $this->akses->untukVerifikasi($apoteker, $resepId);

        // 2. ALREADY. The pre-check in front of the UNIQUE key. A readable 422
        //    here; the constraint is what catches the race, in {@see tulis()}.
        if ($this->akses->sudahDiverifikasi($resepId) !== null) {
            $this->gagalSudahDiverifikasi();
        }

        $hasil = ResepVerifikasiStatus::from((string) $data['status']);
        $catatan = $this->catatan($data['catatan'] ?? null);

        // 3. WHERE. A prescription that has moved past the pharmacy cannot be
        //    signed at all, and a terminal one never can again.
        $this->pastikanBisaDiverifikasi($resep);

        // 4. THE RE-CHECK, against the stored items and the engine unchanged.
        $peringatan = $this->peringatan($resep);

        // 5. THE ACKNOWLEDGEMENT. A `kontraindikasi` the doctor never saw
        //    demands a note from the pharmacist.
        $this->pastikanCatatan($hasil, $catatan, $peringatan);

        return $this->tulis($resep, $apoteker, $hasil, $catatan);
    }

    /**
     * The whole current warning set for a prescription, from its STORED items.
     *
     * `peringatan()` is the engine's own three-check entry point, and its third
     * argument excludes THIS prescription from `cekRiwayatPasien()`'s "what the
     * patient is already on" set - which the engine's docblock REQUIRES,
     * because a prescription is part of what its own patient takes and would
     * otherwise be reported twice: once as `antar_item` and once as
     * `riwayat_resep`.
     *
     * Racikan items (`resep_item.obat_id` NULL, `:770`) contribute nothing here
     * and are skipped STRUCTURALLY by the engine's `whereNotNull`, not by a
     * filter in this class.
     *
     * @return list<array<string, mixed>>
     */
    public function peringatan(Resep $resep): array
    {
        $obatIds = ResepItem::query()
            ->where('resep_id', $resep->getKey())
            ->whereNotNull('obat_id')
            ->orderBy('id')
            ->pluck('obat_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($obatIds === []) {
            return [];
        }

        return $this->interaksi->peringatan(
            (int) $resep->pasien_id,
            $obatIds,
            [(int) $resep->getKey()],
        );
    }

    /**
     * The same warning set, keyed by every `sumber`.
     *
     * The shape todo 39's create response established, kept identical so a
     * client renders the same three panels whichever endpoint it called.
     *
     * @param  list<array<string, mixed>>  $peringatan
     * @return array<string, list<array<string, mixed>>>
     */
    public function peringatanGrup(array $peringatan): array
    {
        $grup = [];

        foreach (ObatInteraksiService::SUMBER as $sumber) {
            $grup[$sumber] = array_values(array_filter(
                $peringatan,
                static fn (array $w): bool => ($w['sumber'] ?? null) === $sumber,
            ));
        }

        return $grup;
    }

    /**
     * Refuse a checkout attempt, structurally.
     *
     * The plan's "apoteker wajib verifikasi sebelum `dipenuhi`" is the
     * `resep_verifikasi` table's own COMMENT (`telemedicine_test.sql:785`). No
     * route in this todo performs a checkout - that is todo 46's surface - so
     * this is the precondition a later caller must call, and it is a METHOD
     * rather than an `if` at a call site so the rule cannot be skipped by one
     * that forgot it.
     *
     * @throws ValidationException
     */
    public function siapDipenuhi(Resep $resep): void
    {
        if ($this->mesin->bisaDipenuhi((string) $resep->status)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                'Resep belum dapat dipenuhi.',
                sprintf(
                    'Resep berstatus "%s" wajib diverifikasi apoteker sebelum dipenuhi.',
                    (string) $resep->status,
                ),
            ],
        ]);
    }

    /**
     * Is this prescription signed off and therefore dispensable?
     */
    public function bolehDipenuhi(Resep $resep): bool
    {
        return $this->mesin->bisaDipenuhi((string) $resep->status);
    }

    /**
     * The stored verification for a prescription, or `null`.
     *
     * Public, and thin, because a later endpoint (todo 46's checkout) needs the
     * same question answered the same way and a second implementation of it is
     * a second opinion.
     */
    public function sudahDiverifikasi(int $resepId): ?ResepVerifikasi
    {
        return $this->akses->sudahDiverifikasi($resepId);
    }

    /**
     * The 422 for a prescription that is already verified. One refusal, two
     * callers, and it deliberately does NOT re-query: the race path in
     * {@see tulis()} runs AFTER the transaction has rolled back, so the row that
     * caused the collision is already gone and a re-check would answer "no" and
     * report a 500 for a 422.
     *
     * Two messages on `status`, because the caller broke two things at once:
     * the prescription is not verifiable any more, AND the schema has no second
     * row to put the answer in. The second message names the mechanism, so an
     * integrator who wonders why a "harmless" retry fails is told the truth
     * instead of guessing at a race.
     *
     * @return never
     *
     * @throws ValidationException
     */
    private function gagalSudahDiverifikasi(): never
    {
        throw ValidationException::withMessages([
            'status' => [
                'Resep sudah diverifikasi dan tidak dapat diverifikasi ulang.',
                'resep_verifikasi.resep_id bersifat UNIQUE (telemedicine_test.sql:788): '
                .'satu resep hanya dapat diverifikasi sekali, termasuk setelah ditolak.',
            ],
        ]);
    }

    /**
     * Refuse a prescription the pharmacy can no longer sign.
     *
     * @return never
     *
     * @throws ValidationException
     */
    private function pastikanBisaDiverifikasi(Resep $resep): void
    {
        $status = (string) $resep->status;

        if ($this->mesin->bisaDiverifikasi($status)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                'Resep tidak dapat diverifikasi pada statusnya sekarang.',
                sprintf('Status "%s" sudah melewati tahap verifikasi apoteker.', $status),
            ],
        ]);
    }

    /**
     * THE RULE: a `kontraindikasi` demands a note from the SECOND reader.
     *
     * @param  list<array<string, mixed>>  $peringatan
     *
     * @throws ValidationException
     */
    private function pastikanCatatan(
        ResepVerifikasiStatus $hasil,
        ?string $catatan,
        array $peringatan,
    ): void {
        // A rejection is the safe direction, so it is never gated on a note.
        if ($hasil->terminal() || ! $this->interaksi->wajibCatatanDokter($peringatan)) {
            return;
        }

        if ((string) $catatan !== '') {
            return;
        }

        // Two independent rules with one input: the field is blank, AND a
        // `kontraindikasi` the doctor never saw needs the pharmacist's own
        // acknowledgement. Both belong on `catatan`, the field the caller can
        // fill in.
        throw ValidationException::withMessages([
            'catatan' => [
                'Catatan apoteker wajib diisi.',
                'Peringatan kontraindikasi yang muncul saat verifikasi memerlukan catatan apoteker.',
            ],
        ]);
    }

    /**
     * `catatan` is `TEXT NULL` (`:791`), so a NULL is a legal column value; the
     * rule that it is non-empty lives in {@see pastikanCatatan()}, and this
     * method only normalises.
     */
    private function catatan(mixed $mentah): ?string
    {
        $catatan = trim((string) ($mentah ?? ''));

        return $catatan === '' ? null : $catatan;
    }

    /**
     * THE WRITE: one verification row, then the status walk, in one transaction.
     *
     * The row goes FIRST, so a status transition that refuses rolls the
     * verification back with it and the prescription stays verifiable. The
     * reverse order would spend the one verification the row can hold on a call
     * that then failed.
     *
     * {@see ResepStateMachine::pastikan()} is the only thing that may change a
     * status, so the walk is legal edges or it is nothing.
     *
     * The re-check is run AFTER the write, so the response describes the state
     * the caller has just created rather than the one it has just left. For a
     * rejection that difference is the whole point: the refused prescription's
     * drugs have left the live set, so the warning the pharmacist is shown is
     * the one that SURVIVES rather than the one they just resolved.
     *
     * @return array{resep: Resep, verifikasi: ResepVerifikasi, warning: list<array<string, mixed>>, warning_grup: array<string, list<array<string, mixed>>>, terminal: bool}
     */
    private function tulis(
        Resep $resep,
        User $apoteker,
        ResepVerifikasiStatus $hasil,
        ?string $catatan,
    ): array {
        // `diverifikasi` for the two outcomes that advance, `dibatalkan` for the
        // one that closes the prescription for good.
        $tujuan = $hasil->maju()
            ? ResepStatus::Diverifikasi->value
            : ResepStatus::Dibatalkan->value;

        try {
            return DB::transaction(function () use ($resep, $apoteker, $hasil, $catatan, $tujuan): array {
                $baris = new ResepVerifikasi;
                $baris->resep_id = $resep->getKey();
                $baris->apoteker_user_id = $apoteker->getKey();
                $baris->status = $hasil->value;
                $baris->catatan = $catatan;
                $baris->diverifikasi_at = Carbon::now();
                $baris->save();

                foreach ($this->jalur((string) $resep->status, $tujuan) as $langkah) {
                    $this->mesin->pastikan((string) $resep->status, $langkah);

                    $resep->status = $langkah;
                    $resep->save();
                }

                $resep->refresh()->setRelation('resepVerifikasi', $baris);

                // F3-05. `resepSiap` fires on the ADVANCING outcomes only, which
                // is exactly the `$hasil->maju()` set the walk above already
                // decided. The service's own prose is "Resep Anda sudah
                // diverifikasi dan siap", so this is the only domain event that
                // makes that sentence true: a `ditolak` verification closes the
                // prescription for good, and a patient told their prescription
                // is READY after a rejection has been told a falsehood.
                $penerima = $resep->pasien?->user;

                if ($hasil->maju() && $penerima !== null) {
                    $this->notifikasi->resepSiap($penerima, (int) $resep->getKey());
                }

                $setelah = $this->peringatan($resep);

                return [
                    'resep' => $resep,
                    'verifikasi' => $baris,
                    'warning' => $setelah,
                    'warning_grup' => $this->peringatanGrup($setelah),
                    'terminal' => $hasil->terminal() || $this->mesin->adalahTerminal($tujuan),
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // THE RACE. Two pharmacists verified the same prescription between
            // this request's pre-check and its INSERT, and MySQL's 1062 is the
            // mechanism doing its job. It becomes the SAME 422 the pre-check
            // raises, because a second answer to the same question must not
            // read as a different failure, and an unhandled 1062 would render
            // as the sanitised 500 the envelope reserves for the faults nobody
            // planned for.
            //
            // The transaction has already rolled back, so `resep.status` was not
            // moved and the winning verification is untouched. Nothing here
            // re-reads the row, because a re-read would find nothing: the
            // racing row was inserted INSIDE this transaction.
            $this->gagalSudahDiverifikasi();
        }
    }

    /**
     * Every single step from `$dari` to `$tujuan`, in order, excluding `$dari`
     * and including `$tujuan`. An empty list when the two are the same.
     *
     * A breadth-first walk over {@see ResepStateMachine::TRANSISI} rather than
     * a hardcoded ladder, so the path the service takes IS the path the map
     * declares and a new edge changes the walk without changing this method.
     *
     * For the map this class ships, `aktif -> diverifikasi` yields
     * `['diproses', 'diverifikasi']` - two `UPDATE`s, and the two-step is
     * observable in the query log rather than inferred.
     *
     * @return non-empty-list<string>
     */
    private function jalur(string $dari, string $tujuan): array
    {
        if ($dari === $tujuan) {
            return [];
        }

        $orangTua = [$dari => null];
        $antrian = [$dari];

        while ($antrian !== []) {
            $sekarang = array_shift($antrian);

            foreach (ResepStateMachine::TRANSISI[$sekarang] ?? [] as $berikutnya) {
                if (array_key_exists($berikutnya, $orangTua)) {
                    continue;
                }

                $orangTua[$berikutnya] = $sekarang;

                if ($berikutnya === $tujuan) {
                    // `rantai()` walks the parent links back to the root, so
                    // reversing gives the forward path INCLUDING where it
                    // started. The caller wants the steps only, so the root -
                    // which is the state the row is already in - is dropped.
                    $langkah = array_reverse($this->rantai($orangTua, $tujuan));

                    return array_values(array_slice($langkah, 1));
                }

                $antrian[] = $berikutnya;
            }
        }

        // Unreachable while the map is connected, which the state-machine test
        // asserts: this is a bug report, not a client error.
        throw new LogicException(
            sprintf('No legal path from "%s" to "%s" in ResepStateMachine::TRANSISI.', $dari, $tujuan),
        );
    }

    /**
     * `$tujuan` back to `$dari`, following the parent links {@see jalur()} built.
     *
     * @param  array<string, ?string>  $orangTua
     * @return non-empty-list<string>
     */
    private function rantai(array $orangTua, string $tujuan): array
    {
        $rantai = [$tujuan];
        $sekarang = $tujuan;

        while (($orangTua[$sekarang] ?? null) !== null) {
            $sekarang = (string) $orangTua[$sekarang];
            $rantai[] = $sekarang;
        }

        return $rantai;
    }
}
