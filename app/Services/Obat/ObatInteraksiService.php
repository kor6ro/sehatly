<?php

declare(strict_types=1);

namespace App\Services\Obat;

use App\Support\WaktuIndonesia;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The doctor's safety net: drug interactions inside a prescription, clashes with
 * what the patient is already taking, and recorded allergies.
 *
 * ## It WARNS. It cannot refuse.
 *
 * Every public method returns a `list` of warning arrays and never throws on any
 * DOMAIN condition - a missing prescription, a drug that is not in the
 * catalogue, a degenerate id, an allergy row that names nothing. That is the
 * point: the schema has no place to record a refusal and no column in which to
 * acknowledge one, so the only thing the application can do is tell the doctor,
 * and todo 39 is where the acknowledgement is written.
 *
 * **A deliberate limit on "never throwing": an infrastructure failure is NOT
 * swallowed.** A database error propagates rather than becoming an empty list,
 * because an empty list is a POSITIVE claim - "we checked, and there is
 * nothing" - and returning that when the check never ran would be a false
 * record in a medical system. The plan's "never throwing" is honoured for every
 * input a caller can construct; it is not honoured for the database being down.
 *
 * ## The stored pair is ORDERED; the fact it records is SYMMETRIC
 *
 * `obat_interaksi` (:731`-`:740) has `obat_a_id` (:733) and `obat_b_id` (:734),
 * both `BIGINT UNSIGNED NOT NULL`, under `UNIQUE KEY uq_interaksi (obat_a_id,
 * obat_b_id)` (:739). Two consequences follow, and the second is the one that
 * bites:
 *
 * 1. the schema stores an **ordered** pair;
 * 2. the clinical fact - "these two drugs interact" - is **unordered**.
 *
 * A lookup of `(B, A)` against a row stored as `(A, B)` finds nothing, so an
 * engine that queries the stored order alone silently misses half of every
 * interaction in the table.
 *
 * ### The decision: canonicalise at READ time, in ONE place
 *
 * {@see pasangan()} is the single function that turns a set of drug ids into the
 * ordered tuples to look up, and it emits BOTH orderings of every unordered
 * pair. Everything else - {@see cekAntarObat()}, {@see cekRiwayatPasien()},
 * {@see cekAntarItem()} - goes through it, so there is exactly one place where
 * bidirectionality could be got wrong and one mutation to catch it.
 *
 * Canonicalisation is done at READ time rather than at WRITE time for three
 * reasons, each a fact about this repository rather than a preference:
 *
 * 1. **The schema does not enforce an order.** There is no `CHECK`, and the
 *    unique key is over the ORDERED pair, so `(A, B)` and `(B, A)` are two
 *    different legal rows. A write-time convention is a convention.
 * 2. **This service owns no write path.** The only writer of `obat_interaksi` in
 *    the repository is `DevFixtureSeeder::seedObatInteraksi()`, which adopts
 *    `obat_a_id < obat_b_id` and THROWS if it is violated - a good convention,
 *    enforced at one call site, and worth nothing to a row inserted by any other
 *    client, import, migration or future endpoint.
 * 3. **Existing rows cannot be rewritten safely from here.** A pair stored "the
 *    wrong way round" is not wrong data; it is a correct fact in a spelling the
 *    reader has to understand.
 *
 * ### The cost, stated plainly
 *
 * | cost | size | why it is worth paying |
 * | --- | --- | --- |
 * | twice the index probes | 2 per unordered pair | both tuples pin `obat_a_id` by equality, and `obat_a_id` is the LEADING column of `uq_interaksi` (:739), so both are index lookups rather than a scan |
 * | the unique key permits a DUPLICATE row | unbounded | `(A, B)` and `(B, A)` are both legal, so read-time canonicalisation cannot PREVENT the duplicate - it can only de-duplicate the consequence |
 * | no write-time guard for a future writer | one function | {@see pasanganKanonik()} is public and is the spelling a writer should use; nothing forces its use |
 *
 * The duplicate-row case is not hypothetical and it is tested: two rows, one
 * unordered pair, opposite stored order. The engine emits ONE warning - because
 * there is ONE clinical fact - at the WORST of the two severities, and says in
 * `rincian.ganda` that it saw two rows. Reporting it twice would tell the doctor
 * there are two interactions where there is one; reporting it once silently
 * would hide that the table disagrees with itself.
 *
 * ## "Currently on" is DERIVED, and the derivation is 3 exclusions out of 8
 *
 * `resep.status` is `ENUM('aktif','diproses','diverifikasi','dipenuhi',
 * 'dikirim','selesai','kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif'` at
 * `:751`-`:752` - a declaration that WRAPS onto a second line, so reading `:751`
 * alone yields five members and makes the column look like a different one.
 * Eight members:
 *
 * | state | meaning | currently on? |
 * | --- | --- | --- |
 * | `aktif` | written, not yet processed | **yes** - and it is the DDL's own `DEFAULT`, so a prescription is born live |
 * | `diproses` | being handled by the pharmacy | **yes** |
 * | `diverifikasi` | pharmacist-verified, not yet handed over | **yes** - the patient is about to be on it |
 * | `dipenuhi` | dispensed | **yes** |
 * | `dikirim` | in transit | **yes** - the patient has it, or will have it today |
 * | `selesai` | course COMPLETED | no - the plan's own acceptance criterion singles this one out |
 * | `kedaluwarsa` | EXPIRED | no |
 * | `dibatalkan` | CANCELLED | no |
 *
 * Three exclusions, five inclusions, and the two sets PARTITION the enum
 * exactly. The test asserts that partition against the PARSED DDL rather than
 * against a transcription of it, so a fourth terminal state added to the schema
 * fails the suite instead of silently joining the live set.
 *
 * The plan names `('aktif','diproses')` - two values, both included here. The
 * gap is `diverifikasi`, `dipenuhi` and `dikirim`: prescriptions the patient is
 * receiving or has received, which clash exactly as much as one that is merely
 * queued. Excluding them would make the check quietest precisely when the
 * patient is most likely to be holding the drug.
 *
 * ### The date guard, and why the status set alone is not enough
 *
 * `resep.berlaku_sampai` is `DATE NOT NULL COMMENT 'E-resep berlaku 7 hari'`
 * (`:755`) and **nothing in the schema reacts to it** - no trigger, no generated
 * column, no event. A prescription whose validity lapsed three days ago can
 * therefore read `aktif` and be "currently on" at the same time, and the status
 * column cannot be the whole answer. The date is compared in PHP rather than in
 * SQL, because a `CURDATE()` in the query and a `Carbon::today()` in the test are
 * two different clocks whenever the application and the database disagree about a
 * timezone - and the resulting silent mismatch is a warning that appears and
 * disappears. The comparison is INCLUSIVE: a prescription valid through today
 * is still being taken today.
 *
 * **The day it is compared against is the ASIA/JAKARTA calendar day**, read once
 * through {@see WaktuIndonesia::tanggal()} and applied to every row in the loop,
 * so two prescriptions cannot be judged against two different days inside one
 * answer. `Carbon::today()` is a UTC day (`config/app.php` is `UTC`) and named
 * the day BEFORE the pharmacy's for the seven hours from 00:00 to 07:00 WIB,
 * which silently promoted a lapsed prescription back into "currently on" and
 * demoted a live one out of it. This is the same inclusive boundary
 * {@see \App\Services\Resep\ResepStateMachine::kedaluwarsa()} decides, and the
 * same reference day; the guard is repeated here because this method filters
 * rows in PHP rather than a single model.
 *
 * ## Allergy matching has NO join key, and this is the documented reason
 *
 * No table joins a patient's recorded allergy to a catalogue drug.
 * `pasien_alergi` (`:274`-`:284`) has exactly ONE foreign key and it is
 * `pasien_id` (`:283`); `nama_alergen` (`:278`) is `VARCHAR(150) NOT NULL` free
 * text. The other side is equally free text, and `resep_item.obat_id` is NULL for
 * a racikan (`:770`). So matching is a NAME problem, and it is solved by
 * {@see NamaObat} rather than by a `LIKE` - see that class for why a substring
 * test is the wrong instrument and what it would cost against the DDL's own
 * data.
 *
 * {@see cekAlergi()} accepts ids and raw names in the same list, which is what
 * lets a stored `resep_item.nama_obat` snapshot (`:771`) be checked without the
 * catalogue row that produced it still existing.
 *
 * ### The `kelas_terapi` fallback, and its two gates
 *
 * The plan asks for a fallback to `master_obat.kelas_terapi` (`VARCHAR(100)
 * NULL COMMENT 'Antibiotik, Analgetik, dll'`, `:718`) for `ringan`/`sedang`
 * severity only. It is implemented as EQUALITY on the normalised core, never as
 * containment, and it carries that severity gate for the reason the gate exists:
 * a class-level match is weaker evidence than a name match, so it is not allowed
 * to fire at `berat` or `anafilaksis`. All four seeded class values contain
 * `Anti`, which is the trap the equality closes.
 *
 * ## `pasien.catatan_alergi` is deliberately NOT read
 *
 * `pasien.catatan_alergi` is `TEXT NULL` at `:242` - free PROSE, not a record,
 * and nothing keeps it in step with `pasien_alergi`. Matching on it would fire on
 * any drug name a doctor happened to type in a note, and would double-report
 * every structured allergy. It is a second, unsynchronised source and the
 * service treats it as data it does not interpret.
 *
 * ## The warning shape
 *
 * One shape for all three sources, so a caller can group by `sumber` and render
 * one panel:
 *
 * ```
 * sumber                one of SUMBER
 * kunci                 stable identity, unique within a result set
 * tingkat               one of TINGKAT - ordered by PERINGKAT, worst first
 * deskripsi             the row's own TEXT, or a built sentence for an allergy
 * obat_a, obat_b        {id, nama}; canonical, lower id first; obat_b null for an allergy
 * rincian               per-source detail
 * wajib_catatan_dokter  bool - true only for a `kontraindikasi`
 * ```
 *
 * `kunci` is the de-duplication key, so one clinical fact cannot appear twice in
 * a set however many database rows produced it. Severity is taken from the row,
 * never invented: an interaction from `obat_interaksi.tingkat` (`:735`), an
 * allergy from `pasien_alergi.keparahan` (`:280`) through
 * {@see KEPARAHAN_TINGKAT} - `anafilaksis` is a FOURTH member of that ENUM and
 * is NOT a member of `tingkat`, so it needs a mapping and not a cast.
 */
final class ObatInteraksiService
{
    /** Interaction between two items of the prescription being written. */
    public const SUMBER_ANTAR_ITEM = 'antar_item';

    /** Interaction against a prescription the patient is ALREADY on. */
    public const SUMBER_RIWAYAT_RESEP = 'riwayat_resep';

    /** Interaction against a recorded `pasien_alergi` row. */
    public const SUMBER_ALERGI = 'alergi';

    /**
     * The sources, in the order a caller should render them.
     *
     * @var list<string>
     */
    public const SUMBER = [
        self::SUMBER_ANTAR_ITEM,
        self::SUMBER_RIWAYAT_RESEP,
        self::SUMBER_ALERGI,
    ];

    /**
     * The four severities, in the DDL's order, which is ASCENDING seriousness.
     *
     * `obat_interaksi.tingkat` is `ENUM('ringan','sedang','berat',
     * 'kontraindikasi') NOT NULL` at `:735`. Sorting therefore means walking this
     * list BACKWARDS, and {@see PERINGKAT} is that reversal written out. The test
     * derives the reversal from the parsed DDL rather than trusting this
     * constant, so an inversion here is caught rather than shipped.
     *
     * @var list<string>
     */
    public const TINGKAT = ['ringan', 'sedang', 'berat', 'kontraindikasi'];

    /** The severity at which the plan requires a doctor's note. */
    public const TINGKAT_KONTRAINDIKASI = 'kontraindikasi';

    /**
     * `obat_interaksi.tingkat` rank, worst LAST so a descending sort by rank
     * puts the worst first.
     *
     * @var array<string, int>
     */
    public const PERINGKAT = [
        'ringan' => 0,
        'sedang' => 1,
        'berat' => 2,
        'kontraindikasi' => 3,
    ];

    /**
     * The `resep.status` values that count as CURRENTLY ON: the DDL's eight less
     * the three that end a course. See the class docblock for the derivation.
     *
     * In the DDL's own order, so the test's partition assertion is positional
     * and would catch a reordering as well as a wrong member.
     *
     * @var list<string>
     */
    public const STATUS_BERLAKU = ['aktif', 'diproses', 'diverifikasi', 'dipenuhi', 'dikirim'];

    /**
     * The `resep.status` values that END a course: completed, expired, cancelled.
     *
     * @var list<string>
     */
    public const STATUS_AKHIR = ['selesai', 'kedaluwarsa', 'dibatalkan'];

    /** The only `pasien_alergi.tipe_alergen` this engine reads (`:277`). */
    public const TIPE_ALERGEN_OBAT = 'obat';

    /**
     * The `pasien_alergi.keparahan` values at which the `kelas_terapi`
     * fallback is allowed to fire.
     *
     * @var list<string>
     */
    public const KEPARAHAN_KELAS = ['ringan', 'sedang'];

    /**
     * `pasien_alergi.keparahan` -> `obat_interaksi.tingkat`.
     *
     * `keparahan` is a FOUR-value ENUM - `ringan`, `sedang`, `berat`,
     * `anafilaksis` (`:280`) - and `tingkat` is a FOUR-value ENUM of which
     * `anafilaksis` is NOT a member (`:735`). Assigning one to the other would
     * produce a severity the `tingkat` column cannot hold, and
     * `kontraindikasi` is the right clinical reading of anaphylaxis. This is a
     * MAPPING and not a cast, and the test asserts its keys against the DDL.
     *
     * @var array<string, string>
     */
    public const KEPARAHAN_TINGKAT = [
        'ringan' => 'ringan',
        'sedang' => 'sedang',
        'berat' => 'berat',
        'anafilaksis' => 'kontraindikasi',
    ];

    /**
     * Interactions among the drugs in the prescription being written.
     *
     * The plan names `cekAntarItem(array $resepIds)`, and that method exists.
     * THIS one is the engine underneath it, taking drug ids, because todo 39
     * composes a prescription that is **not yet written** - it has an id only
     * after the save, and a check that needs a save cannot run before the thing
     * it is checking.
     *
     * Both orderings of every unordered pair are looked up - see the class
     * docblock and {@see pasangan()}, the one place that is decided.
     *
     * @param  list<int>  $obatIds
     * @return list<array<string, mixed>>
     */
    public function cekAntarObat(array $obatIds): array
    {
        $pasangan = $this->pasangan($this->bersihId($obatIds));

        if ($pasangan === []) {
            return [];
        }

        return $this->susunInteraksi(
            $this->barisInteraksi($pasangan),
            $pasangan,
            self::SUMBER_ANTAR_ITEM,
            self::SUMBER_ANTAR_ITEM.':',
        );
    }

    /**
     * Interactions among the items of prescriptions that ALREADY EXIST.
     *
     * Pairs are formed WITHIN a prescription and never across two of them: a
     * doctor prescribing two drugs side by side is the case this catches, and
     * treating two unrelated prescriptions as one basket would warn about every
     * drug the patient has ever been given.
     *
     * ## A racikan item is skipped, STRUCTURALLY
     *
     * `resep_item.obat_id` is `BIGINT UNSIGNED NULL COMMENT 'NULL = racikan /
     * obat non-katalog'` (`:770`), so a racikan row has no catalogue id and
     * `obat_interaksi` is keyed on catalogue ids (`:733`-`:734`). The item is
     * dropped by the `whereNotNull` below, which means a racikan is not merely
     * un-warned - it never reaches the pair construction at all. A racikan's only
     * text is `nama_obat` (`:771`), a snapshot of a mixture a doctor named
     * freely, and there is no single substance in it to pair up.
     *
     * @param  list<int>  $resepIds
     * @return list<array<string, mixed>>
     */
    public function cekAntarItem(array $resepIds): array
    {
        $ids = $this->bersihId($resepIds);

        if ($ids === []) {
            return [];
        }

        $baris = DB::table('resep_item')
            ->select('resep_id', 'obat_id')
            ->whereIn('resep_id', $ids)
            ->whereNotNull('obat_id')
            ->orderBy('resep_id')
            ->orderBy('id')
            ->get();

        $perResep = [];
        foreach ($baris as $item) {
            $perResep[(int) $item->resep_id][] = (int) $item->obat_id;
        }

        $kumpulan = [];

        foreach ($perResep as $obatIds) {
            $pasangan = $this->pasangan($this->bersihId($obatIds));

            if ($pasangan === []) {
                continue;
            }

            foreach ($this->susunInteraksi(
                $this->barisInteraksi($pasangan),
                $pasangan,
                self::SUMBER_ANTAR_ITEM,
                self::SUMBER_ANTAR_ITEM.':',
            ) as $w) {
                // A pair appearing in two prescriptions is ONE warning, and the
                // `resep_id` list in its `rincian` says it is in two.
                $kumpulan[$w['kunci']] ??= $w;
            }
        }

        $hasil = [];

        foreach ($kumpulan as $kunci => $w) {
            $asal = [];
            foreach ([$w['obat_a']['id'], $w['obat_b']['id']] as $obatId) {
                foreach ($perResep as $resepId => $obatIds) {
                    if (in_array($obatId, $obatIds, true)) {
                        $asal[] = (int) $resepId;
                    }
                }
            }
            $w['rincian']['resep_id'] = array_values(array_unique($asal));
            $hasil[$kunci] = $w;
        }

        return $this->urutkan(array_values($hasil));
    }

    /**
     * Interactions between drugs being prescribed NOW and drugs the patient is
     * ALREADY on.
     *
     * ## Which prior prescriptions count
     *
     * {@see STATUS_BERLAKU} - five of the DDL's eight `resep.status` members
     * (`:751`-`:752`) - and additionally only those whose `berlaku_sampai`
     * (`:755`) has not elapsed, for the reason the class docblock gives. Both
     * filters are load-bearing and each has a test that isolates it.
     *
     * ## The prescription being written
     *
     * A patient's own current prescription is part of "what they are on", so a
     * caller composing a prescription names the one it is writing in
     * `$abaikanResepIds`; otherwise the pairs inside it are reported twice, once
     * as {@see SUMBER_ANTAR_ITEM} and once as {@see SUMBER_RIWAYAT_RESEP}. This
     * service cannot infer which prescription is the new one, and guessing would
     * be a silent double warning.
     *
     * ## The single-int signature
     *
     * The plan writes `cekRiwayatPasien(int $pasienId, int $obatId)`. An `int` is
     * accepted and treated as a one-element list, so the plan's call shape works
     * unchanged and cannot silently return nothing.
     *
     * @param  list<int>|int  $obatIds
     * @param  list<int>  $abaikanResepIds
     * @return list<array<string, mixed>>
     */
    public function cekRiwayatPasien(int $pasienId, array|int $obatIds, array $abaikanResepIds = []): array
    {
        $baru = $this->bersihId(is_int($obatIds) ? [$obatIds] : $obatIds);

        if ($baru === []) {
            return [];
        }

        $saring = $this->bersihId($abaikanResepIds);

        $query = DB::table('resep_item')
            ->join('resep', 'resep.id', '=', 'resep_item.resep_id')
            ->select('resep.id as resep_id', 'resep.berlaku_sampai', 'resep_item.obat_id')
            ->where('resep.pasien_id', $pasienId)
            ->whereIn('resep.status', self::STATUS_BERLAKU)
            ->whereNotNull('resep_item.obat_id')
            ->orderBy('resep.id');

        if ($saring !== []) {
            $query->whereNotIn('resep.id', $saring);
        }

        $hariIni = WaktuIndonesia::tanggal();

        // drug id -> the prescriptions holding it, after the date guard.
        $dimiliki = [];
        foreach ($query->get() as $row) {
            // `:755` is a DATE and nothing in the schema reacts to it, so a
            // lapsed prescription can still read `aktif`. Inclusive: valid
            // through today means taken today.
            if ((string) $row->berlaku_sampai < $hariIni) {
                continue;
            }

            $dimiliki[(int) $row->obat_id][] = (int) $row->resep_id;
        }

        if ($dimiliki === []) {
            return [];
        }

        // The union is what makes this check bidirectional for free: the pair is
        // formed once and looked up in both orders by the same helper the
        // in-prescription path uses.
        $pasangan = $this->pasangan($this->bersihId([...$baru, ...array_keys($dimiliki)]));

        if ($pasangan === []) {
            return [];
        }

        // One warning per PRESCRIPTION, so a doctor is told WHICH course clashes
        // and two prescriptions holding the same clashing drug are two facts.
        $perResep = [];
        foreach ($this->barisInteraksi($pasangan) as $row) {
            foreach ([(int) $row->obat_a_id, (int) $row->obat_b_id] as $obatId) {
                foreach ($dimiliki[$obatId] ?? [] as $resepId) {
                    $perResep[$resepId][] = $row;
                }
            }
        }

        $hasil = [];

        foreach ($perResep as $resepId => $rows) {
            foreach ($this->susunInteraksi(
                collect($rows),
                $pasangan,
                self::SUMBER_RIWAYAT_RESEP,
                self::SUMBER_RIWAYAT_RESEP.':'.$resepId.':',
            ) as $w) {
                $w['rincian']['resep_id'] = $resepId;
                $hasil[$w['kunci']] ??= $w;
            }
        }

        return $this->urutkan(array_values($hasil));
    }

    /**
     * Interactions between drugs being prescribed NOW and the patient's recorded
     * drug ALLERGIES.
     *
     * ## What is read
     *
     * `pasien_alergi` rows with `tipe_alergen = 'obat'` (`:277`) only. The other
     * three members are `makanan`, `lingkungan` and `lainnya`, and a food allergy
     * named after a drug does not make the drug dangerous.
     *
     * ## What a candidate is
     *
     * An `int` is a `master_obat.id` and contributes two cores - `nama_generik`
     * (`:711`) and `nama_brand` (`:712`) - plus its `kelas_terapi` (`:718`) for
     * the fallback. A `string` is a raw name and contributes one core, which is
     * how a `resep_item.nama_obat` snapshot (`:771`) is checked without the
     * catalogue row that produced it still existing. A racikan is passed as its
     * name and will not match a substance name, because a mixture has none.
     *
     * ## What is NOT read
     *
     * `pasien.catatan_alergi` (`:242`) - free prose, unsynchronised with
     * `pasien_alergi`, and matching it would fire on any drug name a doctor wrote
     * in a note. See the class docblock.
     *
     * @param  list<int|string>  $kandidat
     * @return list<array<string, mixed>>
     */
    public function cekAlergi(int $pasienId, array $kandidat): array
    {
        if ($kandidat === []) {
            return [];
        }

        $alergi = DB::table('pasien_alergi')
            ->select('id', 'nama_alergen', 'keparahan')
            ->where('pasien_id', $pasienId)
            ->where('tipe_alergen', self::TIPE_ALERGEN_OBAT)
            ->orderBy('id')
            ->get();

        if ($alergi->isEmpty()) {
            return [];
        }

        // Normalise the RECORDED side once, up front: it is the same handful of
        // rows for every candidate, and doing it per candidate would recompute it
        // N times over. A row whose name normalises to nothing is skipped - see
        // {@see NamaObat::inti()}.
        $intiAlergi = [];
        foreach ($alergi as $row) {
            $inti = NamaObat::inti((string) $row->nama_alergen);

            if ($inti !== '') {
                $intiAlergi[(int) $row->id] = ['inti' => $inti, 'row' => $row];
            }
        }

        if ($intiAlergi === []) {
            return [];
        }

        $katalog = $this->katalog($this->idKandidat($kandidat));

        $hasil = [];

        foreach ($kandidat as $sasaran) {
            foreach ($this->intiKandidat($sasaran, $katalog) as $inti) {
                foreach ($intiAlergi as $idAlergi => $cocok) {
                    $kunci = self::SUMBER_ALERGI.':'.$idAlergi.':'.$inti['tujuan'];

                    // De-duplicated on (allergen, candidate) rather than on
                    // (allergen, candidate, matched core): a drug whose generic
                    // and brand normalise to the same string is ONE drug, and
                    // two panels about one substance is the noise this service
                    // exists to remove.
                    if (isset($hasil[$kunci])) {
                        continue;
                    }

                    if ($cocok['inti'] === $inti['inti']) {
                        $hasil[$kunci] = $this->susunAlergi($cocok['row'], $inti, 'nama', $inti['inti']);
                        continue;
                    }

                    if (
                        $inti['kelas'] !== null
                        && $inti['kelas'] === $cocok['inti']
                        && in_array((string) $cocok['row']->keparahan, self::KEPARAHAN_KELAS, true)
                    ) {
                        $hasil[$kunci] = $this->susunAlergi($cocok['row'], $inti, 'kelas_terapi', $inti['kelas']);
                    }
                }
            }
        }

        return $this->urutkan(array_values($hasil));
    }

    /**
     * The whole warning set, in one deterministic order.
     *
     * The three checks are independent and each is already ordered, so this is a
     * merge rather than a fourth rule: worst severity first, then the declared
     * source order, then `kunci` - a total order, so the same inputs always
     * produce byte-identical output regardless of the order the caller listed its
     * drugs in.
     *
     * @param  list<int>  $obatIds
     * @param  list<int>  $abaikanResepIds
     * @return list<array<string, mixed>>
     */
    public function peringatan(int $pasienId, array $obatIds, array $abaikanResepIds = []): array
    {
        $ids = $this->bersihId($obatIds);

        $gabungan = [
            ...$this->cekAntarObat($ids),
            ...$this->cekRiwayatPasien($pasienId, $ids, $abaikanResepIds),
            ...$this->cekAlergi($pasienId, $ids),
        ];

        // De-duplicated on `kunci`, which already carries the source, so the
        // same clinical fact from two different SOURCES stays two panels while
        // the same fact from two database ROWS collapses to one.
        $unik = [];
        foreach ($gabungan as $w) {
            $unik[$w['kunci']] ??= $w;
        }

        return $this->urutkan(array_values($unik));
    }

    /**
     * Must a doctor's note accompany this warning set?
     *
     * ## The rule, and where it is NOT enforced
     *
     * The plan puts "a `kontraindikasi` item without `catatan_dokter` returns
     * 422" in todo 38's acceptance criteria, and then says todo 39 enforces it
     * "where the prescription is actually written". Both cannot be true of todo
     * 38: a 422 is an HTTP response, and this service has no request, no
     * response and no route. So the RULE lives here as a predicate and the 422
     * belongs to todo 39's endpoint - which also owns `StoreResepRequest` and is
     * the only place `resep.catatan_dokter` (`:753`) can be written.
     *
     * `kontraindikasi` is one of the four `obat_interaksi.tingkat` members
     * (`:735`) and one of the four `keparahan` values the mapping above can
     * reach, so both sources of a note requirement are covered.
     *
     * @param  list<array<string, mixed>>  $peringatan
     */
    public function wajibCatatanDokter(array $peringatan): bool
    {
        foreach ($peringatan as $w) {
            if (($w['tingkat'] ?? null) === self::TINGKAT_KONTRAINDIKASI) {
                return true;
            }
        }

        return false;
    }

    /**
     * The CANONICAL spelling of an unordered pair: lower id first.
     *
     * Public because it is the spelling every WRITER of `obat_interaksi` should
     * use, and the only thing that makes the table self-consistent. Nothing
     * forces its use - `uq_interaksi` (`:739`) does not, and no constraint in
     * the schema does - which is exactly why the read path cannot rely on it
     * either. See the class docblock.
     *
     * @return array{0: int, 1: int}
     */
    public function pasanganKanonik(int $a, int $b): array
    {
        return $a <= $b ? [$a, $b] : [$b, $a];
    }

    // =============================================================
    // Bidirectionality - the one place it is decided
    // =============================================================

    /**
     * Every ordered tuple that must be looked up for a set of drug ids.
     *
     * BOTH orderings of every unordered pair, from ONE function, so the
     * correctness requirement has exactly one implementation to get wrong and
     * exactly one mutation to catch it. Four ids therefore produce TWELVE
     * tuples, not six.
     *
     * Each tuple pins `obat_a_id` by equality, and `obat_a_id` is the leading
     * column of `uq_interaksi` (:739), so the extra tuples cost index probes and
     * not a scan.
     *
     * A self-pair yields no tuple, which is right: a row `(a, a)` would be a drug
     * interacting with itself, and the ids are de-duplicated before this point
     * anyway.
     *
     * @param  list<int>  $ids  already de-duplicated
     * @return list<array{0: int, 1: int}>
     */
    private function pasangan(array $ids): array
    {
        $n = count($ids);
        $keluar = [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                [$a, $b] = $this->pasanganKanonik($ids[$i], $ids[$j]);

                // Canonical first, so the emitted SQL reads naturally, and the
                // REVERSE second - the direction a single-direction engine
                // omits.
                $keluar[] = [$a, $b];
                $keluar[] = [$b, $a];
            }
        }

        return $keluar;
    }

    /**
     * Every `obat_interaksi` row matching ANY of the ordered tuples, in ONE
     * statement.
     *
     * ## Why a disjunction and not a row-value `IN` list
     *
     * `(obat_a_id, obat_b_id) IN ((?,?),(?,?))` is the ideal statement, and
     * Laravel 13 cannot express it. `Builder::whereRowValues()` compiles
     * `parameterize($where['values'])` - a FLAT binding list - and guards with
     * `count($columns) !== count($values)`
     * (`vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:2281`
     * `-`:2293), so `$values` is ONE row. Passing a list of tuples either
     * throws `The number of columns must match the number of values` or, when
     * the tuple count happens to equal the column count, silently emits
     * `(a, b) in (1, 2)` - a statement that returns the wrong rows with no
     * error. It was tried, and the suite caught it; see the evidence file.
     *
     * So the disjunction is spelled out. Every disjunct is an equality pair, and
     * `uq_interaksi` (`:739`) is over exactly those two columns in that order,
     * so each is an index lookup rather than a scan. The cost is statement
     * width: `2 * C(n, 2)` disjuncts for `n` drugs, which is 90 at the ten drugs
     * a composer screen holds and 380 at twenty. A caller with a larger set
     * should chunk its own input; this service does not, because a prescription
     * is a clinician's list and a hundred-item list is a data error.
     *
     * The stored row names the two drugs, so they are resolved here and the
     * caller never joins `master_obat` to render a warning.
     *
     * @param  list<array{0: int, 1: int}>  $pasangan
     * @return Collection<int, object>
     */
    private function barisInteraksi(array $pasangan): Collection
    {
        if ($pasangan === []) {
            return collect();
        }

        $baris = DB::table('obat_interaksi')
            ->where(function (Builder $luar) use ($pasangan): void {
                foreach ($pasangan as $tuple) {
                    $luar->orWhere(function (Builder $dalam) use ($tuple): void {
                        $dalam->where('obat_a_id', $tuple[0])->where('obat_b_id', $tuple[1]);
                    });
                }
            })
            ->orderBy('id')
            ->get();

        if ($baris->isEmpty()) {
            return $baris;
        }

        $id = [];
        foreach ($baris as $row) {
            $id[(int) $row->obat_a_id] = true;
            $id[(int) $row->obat_b_id] = true;
        }

        $nama = $this->katalog(array_keys($id));

        foreach ($baris as $row) {
            $a = (int) $row->obat_a_id;
            $b = (int) $row->obat_b_id;
            $row->nama_a = (string) ($nama[$a]->nama_generik ?? '');
            $row->nama_b = (string) ($nama[$b]->nama_generik ?? '');
        }

        return $baris;
    }

    // =============================================================
    // Assembly
    // =============================================================

    /**
     * Turn matched rows into warnings: canonical orientation, ONE per unordered
     * pair, at the worst severity any row claimed.
     *
     * @param  Collection<int, object>  $baris
     * @param  list<array{0: int, 1: int}>  $pasangan
     * @param  string  $sumber
     * @param  string  $awalanKunci  the `kunci` prefix this source needs
     * @return list<array<string, mixed>>
     */
    private function susunInteraksi(Collection $baris, array $pasangan, string $sumber, string $awalanKunci): array
    {
        $diminta = [];
        foreach ($pasangan as $tuple) {
            $diminta[$tuple[0].':'.$tuple[1]] = true;
        }

        /** @var array<string, array{0: int, 1: int}> $terpakai */
        $terpakai = [];
        /** @var array<string, list<object>> $terkumpul */
        $terkumpul = [];

        foreach ($baris as $row) {
            $a = (int) $row->obat_a_id;
            $b = (int) $row->obat_b_id;
            [$ka, $kb] = $this->pasanganKanonik($a, $b);

            $kunci = $awalanKunci.$ka.':'.$kb;
            $terkumpul[$kunci][] = $row;
            $terpakai[$kunci] = [$ka, $kb];
        }

        $hasil = [];

        foreach ($terkumpul as $kunci => $rows) {
            [$ka, $kb] = $terpakai[$kunci];

            $palingBuruk = $rows[0];
            foreach ($rows as $row) {
                if (self::PERINGKAT[(string) $row->tingkat] > self::PERINGKAT[(string) $palingBuruk->tingkat]) {
                    $palingBuruk = $row;
                }
            }

            $tersimpan = [(int) $palingBuruk->obat_a_id, (int) $palingBuruk->obat_b_id];

            $hasil[] = [
                'sumber' => $sumber,
                'kunci' => $kunci,
                'tingkat' => (string) $palingBuruk->tingkat,
                'deskripsi' => $palingBuruk->deskripsi === null ? null : (string) $palingBuruk->deskripsi,
                'obat_a' => $this->sisi($ka, $tersimpan[0] === $ka ? $palingBuruk->nama_a : $palingBuruk->nama_b),
                'obat_b' => $this->sisi($kb, $tersimpan[0] === $ka ? $palingBuruk->nama_b : $palingBuruk->nama_a),
                'rincian' => [
                    'baris_tertemu' => array_map(static fn (object $r): int => (int) $r->id, $rows),
                    'ganda' => count($rows) > 1,
                    // Which way round the row was STORED, and which way round it
                    // was ASKED for. When these differ, the reverse-direction
                    // lookup is what found it - the acceptance criterion made
                    // visible in the output and not only in the test.
                    'arah_tersimpan' => $tersimpan,
                    'arah_diminta' => isset($diminta[$ka.':'.$kb]) ? [$ka, $kb] : [$kb, $ka],
                    'resep_id' => [],
                ],
                'wajib_catatan_dokter' => (string) $palingBuruk->tingkat === self::TINGKAT_KONTRAINDIKASI,
            ];
        }

        return $this->urutkan($hasil);
    }

    /**
     * One allergy warning, with the severity taken from the RECORDED row.
     *
     * `pasien_alergi` has no `deskripsi` column (`:274`-`:284`), so the text a
     * caller renders is built here rather than read. It is in Indonesian because
     * every other user-facing string in this project is, and it names the
     * recorded severity so the doctor can see which rule fired.
     *
     * `$intiCocok` is the candidate-side core that actually MET the record, and
     * it is passed separately because on the class path it is NOT the candidate's
     * name core - a warning whose `rincian` reported `amoxicillin` for a match
     * decided on `Antibiotik` would send a reader looking in the wrong place
     * when asking why a panel appeared.
     *
     * @param  object  $baris
     * @param  array{tujuan: string, inti: string, nama: string, id: int, kelas: ?string}  $inti
     * @return array<string, mixed>
     */
    private function susunAlergi(object $baris, array $inti, string $jalur, string $intiCocok): array
    {
        $keparahan = (string) $baris->keparahan;
        $tingkat = self::KEPARAHAN_TINGKAT[$keparahan] ?? self::TINGKAT[0];

        return [
            'sumber' => self::SUMBER_ALERGI,
            'kunci' => self::SUMBER_ALERGI.':'.(int) $baris->id.':'.$inti['tujuan'],
            'tingkat' => $tingkat,
            'deskripsi' => 'Alergi obat tingkat '.$keparahan.' tercatat atas nama '.(string) $baris->nama_alergen.'.',
            'obat_a' => $inti['id'] > 0 ? $this->sisi($inti['id'], $inti['nama']) : null,
            'obat_b' => null,
            'rincian' => [
                'alergi_id' => (int) $baris->id,
                'nama_alergen' => (string) $baris->nama_alergen,
                'keparahan' => $keparahan,
                'inti_alergi' => NamaObat::inti((string) $baris->nama_alergen),
                'inti_kandidat' => $inti['inti'],
                'inti_cocok' => $intiCocok,
                'jalur' => $jalur,
            ],
            'wajib_catatan_dokter' => $tingkat === self::TINGKAT_KONTRAINDIKASI,
        ];
    }

    /**
     * @return array{id: int, nama: string}
     */
    private function sisi(int $id, string $nama): array
    {
        return ['id' => $id, 'nama' => $nama];
    }

    /**
     * The one total order every public method returns under.
     *
     * Worst severity first, then the declared source order, then `kunci` byte
     * order. The third term is unique within a set, so the order is TOTAL - which
     * makes "the same inputs produce byte-identical output" a property rather
     * than a hope, whatever order the caller listed its drugs in.
     *
     * @param  list<array<string, mixed>>  $peringatan
     * @return list<array<string, mixed>>
     */
    private function urutkan(array $peringatan): array
    {
        $indeksSumber = array_flip(self::SUMBER);

        usort($peringatan, static function (array $a, array $b) use ($indeksSumber): int {
            $tingkat = self::PERINGKAT[$b['tingkat']] <=> self::PERINGKAT[$a['tingkat']];

            if ($tingkat !== 0) {
                return $tingkat;
            }

            $sumber = $indeksSumber[$a['sumber']] <=> $indeksSumber[$b['sumber']];

            if ($sumber !== 0) {
                return $sumber;
            }

            return strcmp($a['kunci'], $b['kunci']);
        });

        return $peringatan;
    }

    // =============================================================
    // The allergy candidates
    // =============================================================

    /**
     * The catalogue rows for a set of ids, carrying both name columns and the
     * therapy class, KEYED BY `id`.
     *
     * The `keyBy` is load-bearing rather than cosmetic: `get()` returns a
     * collection whose keys are row positions (`0`, `1`, `2`), so an unkeyed
     * result answers `$katalog->get($obatId)` with `null` for every drug whose
     * id is not also its position. That is a silent zero - the engine returns an
     * empty warning list and looks like it found nothing, which for a safety
     * check is the one failure mode that must not happen quietly.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function katalog(array $ids): Collection
    {
        $ids = $this->bersihId($ids);

        if ($ids === []) {
            return collect();
        }

        return DB::table('master_obat')
            ->select('id', 'nama_generik', 'nama_brand', 'kelas_terapi')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * The normalisable cores a candidate offers, one entry per core.
     *
     * `nama_generik` and `nama_brand` are SEPARATE cores rather than one
     * concatenated string - see {@see NamaObat}, which is why `ORS` / `Oralit`
     * does not become the invented core `orsoralit`.
     *
     * Each entry carries the BARE core in `inti` (what is compared) and a
     * `tujuan` key for `kunci` (what identifies the candidate, and carries the
     * `obat:` / `teks:` prefix so a catalogue drug offered twice - once by id
     * and once by its snapshot text - is two facts about one substance rather
     * than one panel about two).
     *
     * @param  Collection<int, object>  $katalog
     * @return list<array{tujuan: string, inti: string, nama: string, id: int, kelas: ?string}>
     */
    private function intiKandidat(int|string $sasaran, Collection $katalog): array
    {
        if (is_string($sasaran)) {
            $inti = NamaObat::inti($sasaran);

            return $inti === '' ? [] : [[
                'tujuan' => 'teks:'.$inti,
                'inti' => $inti,
                'nama' => $sasaran,
                'id' => 0,
                'kelas' => null,
            ]];
        }

        $baris = $katalog->get($sasaran);

        if ($baris === null) {
            return [];
        }

        $intiKelas = NamaObat::inti($baris->kelas_terapi === null ? '' : (string) $baris->kelas_terapi);
        $keluaran = [];

        foreach ([(string) $baris->nama_generik, (string) ($baris->nama_brand ?? '')] as $nama) {
            $inti = NamaObat::inti($nama);

            if ($inti === '') {
                continue;
            }

            $keluaran[] = [
                'tujuan' => 'obat:'.$sasaran,
                'inti' => $inti,
                'nama' => (string) $baris->nama_generik,
                'id' => $sasaran,
                'kelas' => $intiKelas === '' ? null : $intiKelas,
            ];
        }

        return $keluaran;
    }

    // =============================================================
    // Small helpers
    // =============================================================

    /**
     * Integers only, de-duplicated, ascending.
     *
     * A value that is not an integer is DROPPED rather than coerced: a drug id
     * arriving as a numeric string from a JSON body is a real case and is
     * accepted, but `intval('abc')` is `0`, which is a different drug id that
     * happens to exist.
     *
     * @param  array<array-key, mixed>  $mentah
     * @return list<int>
     */
    private function bersihId(array $mentah): array
    {
        $id = [];

        foreach ($mentah as $nilai) {
            if (is_int($nilai)) {
                $id[$nilai] = true;
                continue;
            }

            if (is_string($nilai) && preg_match('/^[0-9]+$/', $nilai) === 1) {
                $id[(int) $nilai] = true;
            }
        }

        $keluar = array_keys($id);
        sort($keluar);

        return $keluar;
    }

    /**
     * The catalogue ids among a mixed candidate list.
     *
     * @param  list<int|string>  $kandidat
     * @return list<int>
     */
    private function idKandidat(array $kandidat): array
    {
        return $this->bersihId(array_values(array_filter(
            $kandidat,
            static fn (mixed $n): bool => is_int($n),
        )));
    }
}
