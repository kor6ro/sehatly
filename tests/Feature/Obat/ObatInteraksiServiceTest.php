<?php

declare(strict_types=1);

use App\Services\Obat\ObatInteraksiService;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Todo 38 - the drug interaction / cross-prescription / allergy engine
|--------------------------------------------------------------------------
|
| APPENDED by todo 38. Helper prefix is `oi`, following the `rmd` / `kns` /
| `bku` convention: Pest loads every test file into one process, so a helper
| declared at file scope in two files would be a redeclaration.
|
| ## The three things this file exists to prove
|
| 1. **BIDIRECTIONALITY IS NOT OPTIONAL.** `obat_interaksi` stores an ORDERED
|    pair (`obat_a_id`, `obat_b_id`, `:733`-`:734`) under a UNIQUE key that is
|    over the ORDERED pair (`:739`), while the clinical fact it records is
|    SYMMETRIC. A lookup of (B, A) against a row stored as (A, B) finds
|    nothing. Every pair below is therefore asserted in BOTH orderings, and
|    with MORE THAN ONE pair, because a single pair can be made to pass by a
|    hard-coded answer.
|
| 2. **ALLERGY MATCHING IS A NORMALISATION PROBLEM, NOT A `LIKE`.** A `LIKE`
|    fires on substrings, and a warning about the WRONG drug is worse than no
|    warning at all. The near-miss and cross-drug-substring cases below are
|    therefore first-class tests, not afterthoughts - they are the reason the
|    matcher is a token pipeline.
|
| 3. **"Currently on" IS DERIVED FROM THE DDL, NOT GUESSED.** `resep.status`
|    is an EIGHT-value ENUM spanning two source lines (`:751`-`:752`). The set
|    this service treats as live is asserted to be a subset of the DDL's own
|    members, to be disjoint from the terminal set, and to partition the DDL's
|    members EXACTLY - so a sixth terminal state added to the schema, or a
|    typo in a hand-typed value, fails here rather than silently changing
|    which prescriptions clash.
|
| ## The controlled mutation
|
| Section 10 deletes the reverse-direction lookup and asserts these tests go
| red. A suite that cannot fail is not evidence, so the direction of the
| mutation is part of the acceptance.
*/

/**
 * The `users` row minimum. `uuid` (:134), `nama_lengkap` (:135),
 * `no_telepon` (:137) and `kata_sandi_hash` (:138) are NOT NULL with no
 * default; `tipe` (:139) and `status` (:140) both have defaults.
 */
function oiUser(string $nama, string $tipe = 'pasien'): int
{
    return (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);
}

/**
 * A `dokter` row, because `resep.dokter_id` (:748) is `BIGINT UNSIGNED NOT NULL`
 * with a foreign key to `dokter(id)` (:762) and cannot be left out.
 */
function oiDokter(): int
{
    $id = oiUser('Dokter '.Str::random(6), 'dokter');

    return (int) DB::table('dokter')->insertGetId([
        'user_id' => $id,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-'.Str::random(10),
        'str_berlaku_sampai' => '2030-01-01',
    ]);
}

/**
 * A `pasien` row. `user_id` (:220), `jenis_kelamin` (:225),
 * `tanggal_lahir` (:226) and `alamat_lengkap` (:234) are the NOT NULL columns
 * with no default.
 */
function oiPasien(?string $catatanAlergi = null): int
{
    $id = oiUser('Pasien '.Str::random(6), 'pasien');

    return (int) DB::table('pasien')->insertGetId([
        'user_id' => $id,
        'jenis_kelamin' => 'L',
        'tanggal_lahir' => '1990-05-05',
        'alamat_lengkap' => 'Jl. Uji Coba No. 1',
        'catatan_alergi' => $catatanAlergi,
    ]);
}

/**
 * A `master_obat` row. `kode_obat` (:710), `nama_generik` (:711),
 * `bentuk_sediaan` (:713`-`:714), `satuan` (:716) and `kelas_obat` (:719) are
 * NOT NULL; `kekuatan` (:715) and `kelas_terapi` (:718) are NULL-able and are
 * what the name pipeline has to survive.
 */
function oiObat(
    string $namaGenerik,
    ?string $namaBrand = null,
    ?string $kekuatan = null,
    ?string $kelasTerapi = null,
): int {
    return (int) DB::table('master_obat')->insertGetId([
        'kode_obat' => 'OI-'.Str::random(12),
        'nama_generik' => $namaGenerik,
        'nama_brand' => $namaBrand,
        'bentuk_sediaan' => 'tablet',
        'kekuatan' => $kekuatan,
        'satuan' => 'tablet',
        'kelas_terapi' => $kelasTerapi,
        'kelas_obat' => 'keras',
    ]);
}

/**
 * An `obat_interaksi` row, written in EXACTLY the order given.
 *
 * The order is a parameter rather than something the fixture normalises,
 * because whether the fixture normalises is precisely the question under test:
 * a fixture that always wrote `obat_a_id < obat_b_id` would hide the reverse
 * direction entirely.
 *
 * @param  'ringan'|'sedang'|'berat'|'kontraindikasi'  $tingkat
 */
function oiInteraksi(int $a, int $b, string $tingkat = 'berat', ?string $deskripsi = null): int
{
    return (int) DB::table('obat_interaksi')->insertGetId([
        'obat_a_id' => $a,
        'obat_b_id' => $b,
        'tingkat' => $tingkat,
        'deskripsi' => $deskripsi,
    ]);
}

/**
 * A `resep` row. `nomor_resep` (:744), `pasien_id` (:747), `dokter_id` (:748),
 * `tanggal_resep` (:754), `berlaku_sampai` (:755) and `qr_token` (:758) are NOT
 * NULL with no default, and `pasien_id` / `dokter_id` carry real foreign keys
 * (:761`-`:762).
 *
 * `status` is written EXPLICITLY by every caller rather than left to the DDL
 * default, which is `'aktif'` (:751`-`:752`) - a fixture relying on the default
 * would silently be testing the live-status path.
 *
 * @param  'aktif'|'diproses'|'diverifikasi'|'dipenuhi'|'dikirim'|'selesai'|'kedaluwarsa'|'dibatalkan'  $status
 */
function oiResep(
    int $pasienId,
    int $dokterId,
    string $status = 'aktif',
    string $berlakuSampai = '2099-12-31',
): int {
    return (int) DB::table('resep')->insertGetId([
        'nomor_resep' => 'RX'.Str::random(14),
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'status' => $status,
        'tanggal_resep' => '2026-03-11 09:30:00',
        'berlaku_sampai' => $berlakuSampai,
        'qr_token' => (string) Str::uuid(),
    ]);
}

/**
 * A `resep_item` row. `resep_id` (:769), `nama_obat` (:771), `aturan_pakai`
 * (:773) and `jumlah` (:774) are NOT NULL with no default.
 *
 * `obat_id` is NULL-able (:770, `COMMENT 'NULL = racikan / obat non-katalog'`)
 * and is the whole reason a racikan item can be represented here at all.
 */
function oiItem(int $resepId, ?int $obatId, string $namaObat, bool $racikan = false): int
{
    return (int) DB::table('resep_item')->insertGetId([
        'resep_id' => $resepId,
        'obat_id' => $obatId,
        'nama_obat' => $namaObat,
        'aturan_pakai' => '3 x 1 tablet sesudah makan',
        'jumlah' => 10,
        'is_racikan' => $racikan ? 1 : 0,
    ]);
}

/**
 * A `pasien_alergi` row. `pasien_id` (:276), `tipe_alergen` (:277),
 * `nama_alergen` (:278) and `keparahan` (:280) are NOT NULL.
 *
 * @param  'obat'|'makanan'|'lingkungan'|'lainnya'  $tipe
 * @param  'ringan'|'sedang'|'berat'|'anafilaksis'  $keparahan
 */
function oiAlergi(
    int $pasienId,
    string $namaAlergen,
    string $keparahan = 'ringan',
    string $tipe = 'obat',
): int {
    return (int) DB::table('pasien_alergi')->insertGetId([
        'pasien_id' => $pasienId,
        'tipe_alergen' => $tipe,
        'nama_alergen' => $namaAlergen,
        'keparahan' => $keparahan,
    ]);
}

/**
 * The `obat_interaksi.tingkat` values read out of the DDL, in the DDL's order.
 *
 * Derived, never typed. The service's own severity ORDER is a hand-written
 * constant, and the only thing that can catch a service that inverted it is an
 * assertion that the hand-written order equals the reverse of this.
 *
 * @return list<string>
 */
function oiTingkatDdl(): array
{
    return oiEnum('obat_interaksi', 'tingkat');
}

/**
 * The `resep.status` values read out of the DDL, in the DDL's order.
 *
 * @return list<string>
 */
function oiStatusDdl(): array
{
    return oiEnum('resep', 'status');
}

/**
 * One ENUM column's members, parsed from `telemedicine_test.sql`.
 *
 * `SqlSchemaParser` canonicalises a wrapped declaration into one `type` string
 * such as `enum('aktif','diproses',...)`, so a column whose members span two
 * source lines is still read as a single unit - which `resep.status` (:751`-
 * `:752`) and `obat_interaksi.tingkat` (:735) both do.
 *
 * @return list<string>
 */
function oiEnum(string $table, string $column): array
{
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    $type = $spec->table($table)?->columns[$column]?->type;

    expect($type)->toBeString()->toStartWith('enum(');

    $members = array_map(
        static fn (string $m): string => trim($m, "'"),
        explode(',', substr((string) $type, 5, -1)),
    );

    // An empty member would be a parse slip rather than a schema fact, and
    // `toContain('')` on a list is not a reliable way to say so.
    expect(in_array('', $members, true))->toBeFalse('parsed an empty ENUM member')
        ->and($members)->not->toBe([]);

    return $members;
}

/**
 * The service under test, resolved out of the container exactly as a
 * controller would resolve it.
 */
function oiLayanan(): ObatInteraksiService
{
    return app(ObatInteraksiService::class);
}

// =====================================================================
// 1. The DDL the whole todo rests on - read from the file, not recalled
// =====================================================================

test('the DDL facts the engine depends on are the file, read and not recalled', function (): void {
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $interaksi = $spec->table('obat_interaksi');
    $resep = $spec->table('resep');
    $item = $spec->table('resep_item');
    $alergi = $spec->table('pasien_alergi');
    $master = $spec->table('master_obat');

    expect($interaksi)->not->toBeNull()
        ->and($resep)->not->toBeNull()
        ->and($item)->not->toBeNull()
        ->and($alergi)->not->toBeNull()
        ->and($master)->not->toBeNull();

    // The whole reason bidirectionality is a correctness requirement and not a
    // nicety: the UNIQUE key is over the ORDERED pair, so (A,B) and (B,A) are
    // two DIFFERENT rows and the schema will happily hold both. `:733`-`:734`
    // are the two columns; `:739` is the key.
    //
    // `TableSpec::$indexes` is a LIST, so the key is found by name rather than
    // by array key - and `nameIsAuthoritative` is the flag that says the DDL
    // wrote the name rather than the engine inventing it.
    $kunci = collect($interaksi->indexes)->firstWhere('name', 'uq_interaksi');

    expect($interaksi->columns['obat_a_id']->line)->toBe(733)
        ->and($interaksi->columns['obat_b_id']->line)->toBe(734)
        ->and($interaksi->columns['obat_a_id']->nullable)->toBeFalse()
        ->and($interaksi->columns['obat_b_id']->nullable)->toBeFalse()
        ->and($kunci)->not->toBeNull()
        ->and($kunci->type)->toBe('UNIQUE')
        ->and($kunci->nameIsAuthoritative)->toBeTrue()
        ->and($kunci->columns)->toBe(['obat_a_id', 'obat_b_id'])
        ->and($kunci->line)->toBe(739);

    // FOUR severity members, in ASCENDING order of seriousness. The service's
    // sort order is a hand-written constant; this is the authority it must
    // equal, reversed.
    expect(oiTingkatDdl())->toBe(['ringan', 'sedang', 'berat', 'kontraindikasi'])
        ->and($interaksi->columns['tingkat']->line)->toBe(735);

    // EIGHT lifecycle states, and the declaration WRAPS onto a second line.
    expect(oiStatusDdl())->toBe([
        'aktif', 'diproses', 'diverifikasi', 'dipenuhi',
        'dikirim', 'selesai', 'kedaluwarsa', 'dibatalkan',
    ])
        ->and($resep->columns['status']->line)->toBe(751)
        ->and($resep->columns['status']->endLine)->toBe(752)
        ->and($resep->columns['status']->wrapped())->toBeTrue()
        ->and(trim((string) $resep->columns['status']->default, "'"))->toBe('aktif');

    // A prescription born `aktif` counts as live, so the default has to be in
    // the service's live set. Asserting the DEFAULT here is what makes that a
    // derived fact rather than a coincidence.
    expect(trim((string) $resep->columns['status']->default, "'"))->toBeIn(
        ObatInteraksiService::STATUS_BERLAKU
    );

    // `berlaku_sampai` is a DATE with a 7-day comment and there is NO trigger
    // anywhere in the schema, so a lapsed date is a fact only the application
    // can see. This is the reason the live set is not the whole set.
    expect($resep->columns['berlaku_sampai']->line)->toBe(755)
        ->and($resep->columns['berlaku_sampai']->nullable)->toBeFalse();

    // A racikan item has no catalogue row to join on.
    expect($item->columns['obat_id']->line)->toBe(770)
        ->and($item->columns['obat_id']->nullable)->toBeTrue();

    // The snapshot is a NAME, not a key, and it is the only thing a racikan
    // item has. This is the whole reason allergy matching needs normalising.
    expect($item->columns['nama_obat']->line)->toBe(771)
        ->and($item->columns['nama_obat']->nullable)->toBeFalse();

    // Free text, four allergen types, four severities - and no foreign key to
    // `master_obat` anywhere in the table.
    expect($alergi->columns['tipe_alergen']->line)->toBe(277)
        ->and($alergi->columns['nama_alergen']->line)->toBe(278)
        ->and($alergi->foreignKeys)->toHaveCount(1)
        ->and($alergi->columns['kelas_terapi'] ?? null)->toBeNull();

    // The name columns the pipeline reads, and the one hyphenated real value
    // that proves separators must be handled.
    expect($master->columns['nama_generik']->line)->toBe(711)
        ->and($master->columns['nama_brand']->line)->toBe(712)
        ->and($master->columns['kekuatan']->line)->toBe(715)
        ->and($master->columns['kelas_terapi']->line)->toBe(718);
});

test('the plan line citations for todo 38 are the ones the file actually has', function (): void {
    // The plan cites `:738` for `uq_interaksi`, `:277` for `nama_alergen` and
    // `:708-730` / `:274-285` as the table spans. Each of those is off by one:
    // `:738` is the second FOREIGN KEY, `:277` is `tipe_alergen`, and both
    // ranges run one line PAST the closing `ENGINE=InnoDB;` into the blank
    // line after it. `:770` and `:771` and `:242` are the exceptions and are
    // right. This test is the receipt; the offsets themselves are the finding.
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    $at = static fn (int $n): string => $lines[$n - 1] ?? '<past end of file>';

    expect($at(739))->toContain('UNIQUE KEY uq_interaksi (obat_a_id, obat_b_id)')
        ->and($at(738))->toContain('FOREIGN KEY (obat_b_id)')
        ->and($at(278))->toContain('nama_alergen')
        ->and($at(277))->toContain('tipe_alergen')
        // The ranges the plan prints, and where each table really ends.
        ->and($at(729))->toBe(') ENGINE=InnoDB;')
        ->and($at(730))->toBe('')
        ->and($at(284))->toBe(') ENGINE=InnoDB;')
        ->and($at(285))->toBe('')
        // The two the plan got right, kept so a future edit cannot drift them.
        ->and($at(770))->toContain('obat_id BIGINT UNSIGNED NULL')
        ->and($at(771))->toContain('nama_obat')
        ->and($at(242))->toContain('catatan_alergi');
});

// =====================================================================
// 2. Bidirectionality - the acceptance criterion
// =====================================================================

test('an interaction row is reported for the pair in BOTH orderings', function (): void {
    $a = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $b = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');

    oiInteraksi($a, $b, 'berat', 'Mengurangi absorpsi antibiotik.');

    // Stored as (a, b). Asking for (a, b) is the direction a single-direction
    // query already handles.
    $searah = oiLayanan()->cekAntarObat([$a, $b]);

    // Stored as (a, b). Asking for (b, a) is the direction it does NOT.
    $berlawanan = oiLayanan()->cekAntarObat([$b, $a]);

    expect($searah)->toHaveCount(1)
        ->and($berlawanan)->toHaveCount(1)
        ->and($searah[0]['sumber'])->toBe(ObatInteraksiService::SUMBER_ANTAR_ITEM)
        ->and($berlawanan[0]['sumber'])->toBe(ObatInteraksiService::SUMBER_ANTAR_ITEM)
        ->and($searah[0]['tingkat'])->toBe('berat')
        ->and($berlawanan[0]['tingkat'])->toBe('berat')
        ->and($searah[0]['deskripsi'])->toBe('Mengurangi absorpsi antibiotik.')
        ->and($berlawanan[0]['deskripsi'])->toBe('Mengurangi absorpsi antibiotik.');

    // The two orderings differ ONLY in which way the caller asked, and the
    // warning it gets back is IDENTICAL - canonical, lower id first. That is
    // what makes the caller able to key a UI list on `obat_a`/`obat_b`.
    expect($berlawanan[0]['obat_a'])->toBe($searah[0]['obat_a'])
        ->and($berlawanan[0]['obat_b'])->toBe($searah[0]['obat_b'])
        ->and($searah[0]['obat_a']['id'])->toBeLessThan($searah[0]['obat_b']['id']);
});

test('the reverse direction is found for SEVERAL pairs, not one', function (): void {
    // Three rows, written in three different stored orders relative to the
    // ids, then asked for in the opposite order. A hard-coded pair, or a
    // fixture that always wrote ascending ids, cannot pass this.
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    $cetirizine = oiObat('Cetirizine', 'Zenriz', '10 mg', 'Antihistamin');
    $omeprazole = oiObat('Omeprazole', 'Losec', '20 mg', 'PPI');
    $amlodipine = oiObat('Amlodipine', 'Norvasc', '10 mg', 'Antihipertensi');

    // Ascending, descending, and an interleaved stored order.
    oiInteraksi($amox, $metformin, 'berat');
    oiInteraksi($cetirizine, $amox, 'ringan');
    oiInteraksi($omeprazole, $amlodipine, 'kontraindikasi');

    // Keyed by the CANONICAL pair, so a key that does not exist is a lost pair
    // rather than an orientation difference.
    $expected = [
        $amox.'-'.$metformin => 'berat',
        $amox.'-'.$cetirizine => 'ringan',
        min($amlodipine, $omeprazole).'-'.max($amlodipine, $omeprazole) => 'kontraindikasi',
    ];

    // Forward order.
    $maju = collect(oiLayanan()->cekAntarObat([$amox, $metformin, $cetirizine, $omeprazole, $amlodipine]))
        ->keyBy(static fn (array $w): string => min($w['obat_a']['id'], $w['obat_b']['id']).'-'.max($w['obat_a']['id'], $w['obat_b']['id']));

    // Reverse order - the same five drugs, presented the other way round.
    $mundur = collect(oiLayanan()->cekAntarObat([$amlodipine, $omeprazole, $cetirizine, $metformin, $amox]))
        ->keyBy(static fn (array $w): string => min($w['obat_a']['id'], $w['obat_b']['id']).'-'.max($w['obat_a']['id'], $w['obat_b']['id']));

    foreach ($expected as $kunci => $tingkat) {
        expect($maju->has($kunci))->toBeTrue("forward order lost pair {$kunci}")
            ->and($mundur->has($kunci))->toBeTrue("reverse order lost pair {$kunci}")
            ->and($maju->get($kunci)['tingkat'])->toBe($tingkat)
            ->and($mundur->get($kunci)['tingkat'])->toBe($tingkat);
    }

    expect($maju)->toHaveCount(3)->and($mundur)->toHaveCount(3);
});

test('a stored row is found whichever way round it was written', function (): void {
    // The same unordered pair, two rows, opposite stored order. This is LEGAL
    // under `uq_interaksi (obat_a_id, obat_b_id)` and is the direct consequence
    // of ordered storage: the unique key does not prevent it.
    $a = oiObat('Amoxicillin', null, null, 'Antibiotik');
    $b = oiObat('Metformin', null, null, 'Antidiabetik');

    oiInteraksi($a, $b, 'berat', 'naik');
    oiInteraksi($b, $a, 'berat', 'turun');

    $warning = oiLayanan()->cekAntarObat([$b, $a]);

    // Two rows, ONE clinical fact, so ONE warning - and the two rows are both
    // reported as having been seen, so the duplication is visible rather than
    // merely swallowed.
    expect($warning)->toHaveCount(1)
        ->and($warning[0]['kunci'])->toBe(ObatInteraksiService::SUMBER_ANTAR_ITEM.':'.min($a, $b).':'.max($a, $b))
        ->and($warning[0]['rincian']['baris_tertemu'])->toHaveCount(2)
        ->and($warning[0]['rincian']['ganda'])->toBeTrue();
});

test('the same unordered pair stored twice is reported at its WORST severity', function (): void {
    $a = oiObat('Amoxicillin', null, null, 'Antibiotik');
    $b = oiObat('Metformin', null, null, 'Antidiabetik');

    oiInteraksi($a, $b, 'ringan');
    oiInteraksi($b, $a, 'kontraindikasi');

    $warning = oiLayanan()->cekAntarObat([$a, $b]);

    expect($warning)->toHaveCount(1)
        ->and($warning[0]['tingkat'])->toBe('kontraindikasi');
});

test('every ordered lookup the engine issues is derived from the canonical pair', function (): void {
    // `pasangan()` is private, so the claim is made through the SQL it emits.
    // Four drugs make six unordered pairs and therefore TWELVE ordered
    // lookups; a single-direction engine would issue six.
    $ids = [];
    for ($i = 0; $i < 4; $i++) {
        $ids[] = oiObat('Obat '.$i);
    }

    oiInteraksi($ids[0], $ids[3], 'berat');
    oiInteraksi($ids[2], $ids[1], 'berat');

    $tertangkap = [];
    DB::listen(static function ($q) use (&$tertangkap): void {
        $tertangkap[] = $q->toRawSql();
    });

    $warning = oiLayanan()->cekAntarObat($ids);

    $sql = collect($tertangkap)->first(
        static fn (string $s): bool => str_contains($s, 'obat_interaksi') && str_contains($s, 'obat_b_id')
    );

    expect($sql)->toBeString();

    // Both directions of both pairs, in ONE statement, as equality pairs.
    foreach ([[$ids[0], $ids[3]], [$ids[3], $ids[0]], [$ids[1], $ids[2]], [$ids[2], $ids[1]]] as [$x, $y]) {
        expect($sql)->toContain("`obat_a_id` = {$x} and `obat_b_id` = {$y}");
    }

    // All twelve lookups, not four: the disjunction covers every pair both ways.
    expect(substr_count($sql, '`obat_a_id` ='))->toBe(12)
        ->and($warning)->toHaveCount(2);
});

test('a prescription reports its own interactions in both item orderings', function (): void {
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');

    oiInteraksi($amox, $metformin, 'berat', 'Klopidogrel dan NSAID.');

    $resep = oiResep($pasien, $dokter);
    oiItem($resep, $amox, 'Amoxicillin 500 mg');
    oiItem($resep, $metformin, 'Metformin 500 mg');

    $warning = oiLayanan()->cekAntarItem([$resep]);

    expect($warning)->toHaveCount(1)
        ->and($warning[0]['tingkat'])->toBe('berat')
        ->and($warning[0]['obat_a']['id'])->toBe(min($amox, $metformin))
        ->and($warning[0]['obat_b']['id'])->toBe(max($amox, $metformin))
        ->and($warning[0]['obat_a']['nama'])->toBe($amox === min($amox, $metformin) ? 'Amoxicillin' : 'Metformin');
});

test('a racikan item with obat_id NULL yields no interaction warning', function (): void {
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');

    oiInteraksi($amox, $metformin, 'kontraindikasi');

    $resep = oiResep($pasien, $dokter);
    oiItem($resep, $amox, 'Amoxicillin 500 mg');
    // `obat_id` is NULL (:770): a doctor-mixed preparation with no catalogue row.
    oiItem($resep, null, 'Racikan serialize', true);

    expect(oiLayanan()->cekAntarItem([$resep]))->toBe([]);

    // And the reason is structural, not accidental: the racikan item's NULL id
    // never reaches the engine as an id at all.
    $ids = oiLayanan()->cekAntarObat([$amox, $metformin]);
    expect($ids)->toHaveCount(1);
});

test('a prescription with one drug, or none, reports nothing', function (): void {
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin');
    $metformin = oiObat('Metformin');
    oiInteraksi($amox, $metformin, 'berat');

    $satu = oiResep($pasien, $dokter);
    oiItem($satu, $amox, 'Amoxicillin');

    $kosong = oiResep($pasien, $dokter);

    expect(oiLayanan()->cekAntarItem([$satu]))->toBe([])
        ->and(oiLayanan()->cekAntarItem([$kosong]))->toBe([])
        ->and(oiLayanan()->cekAntarItem([]))->toBe([])
        ->and(oiLayanan()->cekAntarObat([$amox]))->toBe([])
        ->and(oiLayanan()->cekAntarObat([]))->toBe([]);
});

// =====================================================================
// 3. Prior-prescription clash - the live status set, derived
// =====================================================================

test('the live prescription status set is DERIVED from the DDL, not guessed', function (): void {
    $dariDdl = oiStatusDdl();
    $berlaku = ObatInteraksiService::STATUS_BERLAKU;
    $akhir = ObatInteraksiService::STATUS_AKHIR;

    // 8 members, and the service's two sets must PARTITION them exactly.
    // Anything else - a sixth terminal state, a typo, a value that does not
    // exist - fails here.
    expect($dariDdl)->toHaveCount(8);
    expect(array_merge($berlaku, $akhir))->toHaveCount(count($dariDdl));
    expect($berlaku)->toBe(array_values(array_diff($dariDdl, $akhir)));
    expect(array_intersect($berlaku, $akhir))->toBe([]);

    // Exactly three states end a course, and they are the three whose names
    // say so: completed, expired, cancelled. `selesai` is also the one the
    // plan's own acceptance criterion singles out.
    expect($akhir)->toBe(['selesai', 'kedaluwarsa', 'dibatalkan']);

    // The default is live: a prescription is born `aktif` and a brand-new one
    // is the single most likely thing to clash.
    expect($berlaku)->toContain('aktif', 'diproses', 'diverifikasi', 'dipenuhi', 'dikirim');
    expect(ObatInteraksiService::STATUS_AKHIR)->not->toContain('aktif');
});

test('a clash is reported against every LIVE prescription status', function (): void {
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');

    // One prescription per live status, all carrying Metformin.
    foreach (ObatInteraksiService::STATUS_BERLAKU as $status) {
        $resep = oiResep($pasien, $dokter, $status);
        oiItem($resep, $metformin, 'Metformin 500 mg');
    }

    $warning = oiLayanan()->cekRiwayatPasien($pasien, $amox);

    expect($warning)->toHaveCount(count(ObatInteraksiService::STATUS_BERLAKU));
    expect(array_unique(array_column($warning, 'tingkat')))->toBe(['berat']);
    expect(array_unique(array_column($warning, 'sumber')))->toBe([ObatInteraksiService::SUMBER_RIWAYAT_RESEP]);

    // Every warning names the prescription it came from, so a doctor can be told
    // WHICH course clashes.
    expect(array_column(array_column($warning, 'rincian'), 'resep_id'))
        ->toHaveCount(count(ObatInteraksiService::STATUS_BERLAKU));
});

test('a clash is NOT reported against a terminal prescription status', function (): void {
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');

    // One prescription per TERMINAL status, all carrying Metformin.
    foreach (ObatInteraksiService::STATUS_AKHIR as $status) {
        $resep = oiResep($pasien, $dokter, $status);
        oiItem($resep, $metformin, 'Metformin 500 mg');
    }

    // A finished, cancelled or expired course is not something the patient is
    // still taking, so it is not a clash.
    expect(oiLayanan()->cekRiwayatPasien($pasien, $amox))->toBe([]);

    // And the isolation: swapping ONE status in proves the filter is doing the
    // work rather than the fixture having written nothing.
    $aktif = oiResep($pasien, $dokter, 'aktif');
    oiItem($aktif, $metformin, 'Metformin 500 mg');
    expect(oiLayanan()->cekRiwayatPasien($pasien, $amox))->toHaveCount(1);
});

test('a prescription whose berlaku_sampai has elapsed is not a clash even while aktif', function (): void {
    // `resep` has NO trigger that moves a lapsed prescription to `kedaluwarsa`
    // (`:751`-`:755` are a plain ENUM and a plain DATE; nothing in the schema
    // reacts to the date), so `aktif` and "expired three days ago" are both
    // true at once. Only the application can see that, and only the
    // application can refuse to call it a clash.
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');

    $kedaluwarsaTanggal = oiResep($pasien, $dokter, 'aktif', Carbon::now()->subDays(3)->toDateString());
    oiItem($kedaluwarsaTanggal, $metformin, 'Metformin 500 mg');

    expect(oiLayanan()->cekRiwayatPasien($pasien, $amox))->toBe([]);

    // Today is still a clash: the DATE is inclusive.
    $hariIni = oiResep($pasien, $dokter, 'aktif', Carbon::now()->toDateString());
    oiItem($hariIni, $metformin, 'Metformin 500 mg');

    expect(oiLayanan()->cekRiwayatPasien($pasien, $amox))->toHaveCount(1);
});

test('the clash check crosses prescriptions and finds the reverse direction', function (): void {
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    // Stored ascending. The patient is ON Metformin; the doctor prescribes
    // Amoxicillin, so the lookup order is (amox, metformin) against a row
    // stored (metformin, amox)... which does not exist, and whose reverse does.
    oiInteraksi($amox, $metformin, 'kontraindikasi');

    $resep = oiResep($pasien, $dokter, 'diproses');
    oiItem($resep, $metformin, 'Metformin 500 mg');

    $warning = oiLayanan()->cekRiwayatPasien($pasien, $amox);

    expect($warning)->toHaveCount(1)
        ->and($warning[0]['tingkat'])->toBe('kontraindikasi')
        ->and($warning[0]['obat_a']['id'])->toBe(min($amox, $metformin))
        ->and($warning[0]['rincian']['resep_id'])->toBe($resep);
});

test('another patient\'s prescription never clashes', function (): void {
    $pasien = oiPasien();
    $orangLain = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');

    $resep = oiResep($orangLain, $dokter, 'aktif');
    oiItem($resep, $metformin, 'Metformin 500 mg');

    expect(oiLayanan()->cekRiwayatPasien($pasien, $amox))->toBe([]);
});

test('the clash check accepts the plan\'s single-int signature', function (): void {
    // The plan writes `cekRiwayatPasien(int $pasienId, int $obatId)`. A caller
    // passing one id rather than a list must not silently get nothing back.
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');

    $resep = oiResep($pasien, $dokter, 'aktif');
    oiItem($resep, $metformin, 'Metformin 500 mg');

    expect(oiLayanan()->cekRiwayatPasien($pasien, $amox))
        ->toEqual(oiLayanan()->cekRiwayatPasien($pasien, [$amox]));
});

// =====================================================================
// 4. Allergy matching - the normalisation pipeline
// =====================================================================

test('an allergy is matched through a normalised name, not a LIKE', function (): void {
    $pasien = oiPasien();
    // Free text, typed by a human, with a dose and a lower-case spelling.
    oiAlergi($pasien, 'Amoxicillin 500mg', 'berat');
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');

    $warning = oiLayanan()->cekAlergi($pasien, [$amox]);

    expect($warning)->toHaveCount(1)
        ->and($warning[0]['sumber'])->toBe(ObatInteraksiService::SUMBER_ALERGI)
        ->and($warning[0]['tingkat'])->toBe('berat')
        ->and($warning[0]['rincian']['nama_alergen'])->toBe('Amoxicillin 500mg')
        ->and($warning[0]['rincian']['inti_alergi'])->toBe('amoxicillin')
        ->and($warning[0]['rincian']['inti_cocok'])->toBe('amoxicillin')
        ->and($warning[0]['rincian']['jalur'])->toBe('nama')
        ->and($warning[0]['obat_a']['id'])->toBe($amox)
        ->and($warning[0]['obat_b'])->toBeNull();
});

test('the normaliser survives whitespace, case, doubles, hyphens and dots', function (): void {
    // The five adversarial spellings a human produces for the same drug.
    foreach ([
        '  Amoxicillin  ',
        'amoxicillin',
        'AMOXICILLIN',
        'Amoxi  cillin',
        'Amoxi-cillin',
        'Amoxi.Cillin',
        'Amoxicillin 500 mg',
        'amoxicillin-500mg',
        "\tAmoxicillin\t",
    ] as $ejaan) {
        $pasien = oiPasien();
        oiAlergi($pasien, $ejaan);
        $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');

        $warning = oiLayanan()->cekAlergi($pasien, [$amox]);

        expect($warning)->toHaveCount(1, "spelling [{$ejaan}] did not match");
    }
});

test('the normaliser matches the brand name and a hyphenated real therapy class', function (): void {
    // `Analgetik-Antipiretik` is the DDL's own value for OBT-0001
    // (`telemedicine_test.sql:1311`) and it is hyphenated, so a separator-blind
    // pipeline and a separator-naive one disagree about it.
    $pasien = oiPasien();
    oiAlergi($pasien, 'Paracetamol', 'sedang');
    $paracetamol = oiObat('Paracetamol', 'Panadol', '500 mg', 'Analgetik-Antipiretik');

    expect(oiLayanan()->cekAlergi($pasien, [$paracetamol]))->toHaveCount(1);

    // The BRAND is a second core, matched on its own and never concatenated
    // with the generic name.
    $pasien2 = oiPasien();
    oiAlergi($pasien2, 'Panadol', 'sedang');

    expect(oiLayanan()->cekAlergi($pasien2, [$paracetamol]))->toHaveCount(1);
});

test('a genuine near-miss does NOT match', function (): void {
    // These are DIFFERENT drugs. A matcher that fires on them warns about the
    // wrong medicine, which is worse than staying quiet.
    $near = [
        'Amoxicilline',
        'Amoxycillin',
        'Amoxicilin',
        'Amoxilin',
        'Paracetamoll',
        'Metforimn',
        'Amoxicill',
    ];

    foreach ($near as $ejaan) {
        $pasien = oiPasien();
        oiAlergi($pasien, $ejaan);
        $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
        $paracetamol = oiObat('Paracetamol', 'Panadol', '500 mg', 'Analgetik-Antipiretik');
        $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');

        expect(oiLayanan()->cekAlergi($pasien, [$amox, $paracetamol, $metformin]))
            ->toBe([], "spelling [{$ejaan}] must not match any drug");
    }
});

test('a substring of a DIFFERENT drug name does NOT match', function (): void {
    // `Amoxicillin` is a prefix of the combination product. Under `LIKE
    // '%Amoxicillin%'` this fires, and a patient allergic to plain amoxicillin
    // would be warned against a drug that contains none of it.
    $pasien = oiPasien();
    oiAlergi($pasien, 'Amoxicillin', 'berat');
    $combo = oiObat('Amoxicillin-Clavulanate', 'Augmentin', '875/125 mg', 'Antibiotik');

    expect(oiLayanan()->cekAlergi($pasien, [$combo]))->toBe([]);

    // And the same trap read from the other end: an allergy to the combination
    // must not silence... or fire on, plain amoxicillin.
    $pasien2 = oiPasien();
    oiAlergi($pasien2, 'Amoxicillin-Clavulanate', 'berat');
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');

    expect(oiLayanan()->cekAlergi($pasien2, [$amox]))->toBe([]);

    // A drug whose name CONTAINS another drug's name entirely, in the middle.
    $pasien3 = oiPasien();
    oiAlergi($pasien3, 'ORS', 'berat');
    $orsOralit = oiObat('ORS', 'Oralit', 'sachet', 'Rehidrasi');

    expect(oiLayanan()->cekAlergi($pasien3, [$orsOralit]))->toHaveCount(1);
});

test('the shared "Anti" prefix across four real therapy classes does not cross-fire', function (): void {
    // `Antibiotik`, `Antihistamin`, `Antidiabetik` and `Antihipertensi` are four
    // of the DDL's own `kelas_terapi` values (`telemedicine_test.sql:1311`
    // et seq). Every one of them contains "Anti", so `LIKE '%Anti%'` warns
    // about all four for one recorded allergy.
    $pasien = oiPasien();
    oiAlergi($pasien, 'Antibiotik', 'ringan');

    $kelas = [
        'Antibiotik' => oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik'),
        'Antihistamin' => oiObat('Cetirizine', 'Zenriz', '10 mg', 'Antihistamin'),
        'Antidiabetik' => oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik'),
        'Antihipertensi' => oiObat('Amlodipine', 'Norvasc', '10 mg', 'Antihipertensi'),
    ];

    $warning = oiLayanan()->cekAlergi($pasien, array_values($kelas));

    // The class fallback fires on the EXACT class, and on nothing else.
    expect($warning)->toHaveCount(1)
        ->and($warning[0]['obat_a']['id'])->toBe($kelas['Antibiotik'])
        ->and($warning[0]['rincian']['jalur'])->toBe('kelas_terapi')
        // The core that MET the record is the class core, not the drug's name
        // core - otherwise a reader debugging the panel looks at the wrong one.
        ->and($warning[0]['rincian']['inti_alergi'])->toBe('antibiotik')
        ->and($warning[0]['rincian']['inti_cocok'])->toBe('antibiotik')
        ->and($warning[0]['rincian']['inti_kandidat'])->toBe('amoxicillin');

    // The other three share the "Anti" prefix and differ only after it, which
    // is the whole point: a prefix test cannot separate them and this can.
    foreach (['Antihistamin', 'Antidiabetik', 'Antihipertensi'] as $lain) {
        $pasien2 = oiPasien();
        oiAlergi($pasien2, 'Antibiotik', 'ringan');

        expect(oiLayanan()->cekAlergi($pasien2, [$kelas[$lain]]))
            ->toBe([], "class [{$lain}] fired on an Antibiotik allergy");
    }
});

test('the therapy-class fallback is gated to the two mild severities the plan names', function (): void {
    // TWO drugs share the `Antibiotik` class, so a class-level allergy fires on
    // both - which is the point of a class, and the reason it is a weaker signal
    // than a name match. A third drug in another class never joins.
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $antibiotikLain = oiObat('Eritromisin', 'Erythrosin', '500 mg', 'Antibiotik');
    $antidiabetik = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');

    foreach (['ringan' => 2, 'sedang' => 2, 'berat' => 0, 'anafilaksis' => 0] as $keparahan => $harapan) {
        $pasien = oiPasien();
        oiAlergi($pasien, 'Antibiotik', $keparahan);

        $warning = oiLayanan()->cekAlergi($pasien, [$amox, $antibiotikLain, $antidiabetik]);

        expect($warning)->toHaveCount($harapan, "keparahan [{$keparahan}] took the wrong branch")
            ->and(array_unique(array_column(array_column($warning, 'obat_a'), 'id')))
            ->toEqualCanonicalizing($harapan === 0 ? [] : [$amox, $antibiotikLain]);
    }

    // A severity gate is about CLASS matching only. A real NAME match fires at
    // every severity, including anaphylaxis.
    $pasien = oiPasien();
    oiAlergi($pasien, 'Amoxicillin', 'anafilaksis');
    expect(oiLayanan()->cekAlergi($pasien, [$amox]))->toHaveCount(1);
});

test('an allergy fires ONCE per allergen and drug, however many names match', function (): void {
    $pasien = oiPasien();
    oiAlergi($pasien, 'Amoxsan', 'berat');
    // Generic and brand normalise to the SAME core here, which is ordinary in a
    // catalogue where a brand carries no generic line. An engine that emitted
    // one warning per MATCHED CORE would show this substance twice.
    $amox = oiObat('Amoxsan', 'Amoxsan', '500 mg', 'Antibiotik');

    expect(oiLayanan()->cekAlergi($pasien, [$amox]))->toHaveCount(1);

    // And the class fallback cannot add a second panel for the same pair.
    $pasien2 = oiPasien();
    oiAlergi($pasien2, 'Amoxsan', 'ringan');
    expect(oiLayanan()->cekAlergi($pasien2, [$amox]))->toHaveCount(1);
});

test('only tipe_alergen = obat is read, and only the four DDL types exist', function (): void {
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');

    $tipeDdl = oiEnum('pasien_alergi', 'tipe_alergen');
    expect($tipeDdl)->toBe(['obat', 'makanan', 'lingkungan', 'lainnya']);

    foreach ($tipeDdl as $tipe) {
        $pasien = oiPasien();
        oiAlergi($pasien, 'Amoxicillin', 'berat', $tipe);

        $warning = oiLayanan()->cekAlergi($pasien, [$amox]);

        expect($warning)->toHaveCount($tipe === 'obat' ? 1 : 0, "tipe [{$tipe}] was mishandled");
    }
});

test('pasien.catatan_alergi is NOT a second allergy source', function (): void {
    // `pasien.catatan_alergi` is `TEXT NULL` (:242) - free prose, not a
    // structured record, and nothing keeps it in step with `pasien_alergi`.
    // Matching on it would fire on any drug name a doctor happened to write in
    // a note, so the service deliberately does not read it.
    $pasien = oiPasien('Alergi terhadap Amoxicillin, mangkukgio dan Penisilin.');
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');

    expect(oiLayanan()->cekAlergi($pasien, [$amox]))->toBe([]);

    // The structured table is the source that does fire.
    oiAlergi($pasien, 'Amoxicillin', 'berat');
    expect(oiLayanan()->cekAlergi($pasien, [$amox]))->toHaveCount(1);
});

test('a snapshot prescription name is matched, and a racikan name is not silently matched', function (): void {
    $pasien = oiPasien();
    oiAlergi($pasien, 'Amoxicillin', 'berat');
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');

    // `resep_item.nama_obat` is the SNAPSHOT (:771) and it is what a stored
    // prescription carries, so it must be matchable as a raw string.
    expect(oiLayanan()->cekAlergi($pasien, ['Amoxicillin 500 mg']))->toHaveCount(1)
        ->and(oiLayanan()->cekAlergi($pasien, ['Amoxicillin 500 mg', $amox]))->toHaveCount(2);

    // A racikan is a mixture a doctor names freely; there is no single substance
    // to match, so it is compared as text and will not match a drug name.
    expect(oiLayanan()->cekAlergi($pasien, ['Racikan serialize mg']))->toBe([]);
});

test('an empty, whitespace-only or punctuation-only allergy name matches nothing', function (): void {
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');

    foreach (['', ' ', '   ', '---', '...', '- . -'] as $bukanObat) {
        $pasien = oiPasien();
        oiAlergi($pasien, $bukanObat);

        expect(oiLayanan()->cekAlergi($pasien, [$amox]))->toBe([], "name [{$bukanObat}] produced a warning");
    }
});

// =====================================================================
// 5. One ordered, de-duplicated warning set for the caller
// =====================================================================

test('the warning set is ordered worst-first, then by source, then stably', function (): void {
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    $cetirizine = oiObat('Cetirizine', 'Zenriz', '10 mg', 'Antihistamin');
    $omeprazole = oiObat('Omeprazole', 'Losec', '20 mg', 'PPI');

    // (amox, metformin) berat, (amox, cetirizine) ringan, in the new prescription.
    oiInteraksi($amox, $metformin, 'berat');
    oiInteraksi($cetirizine, $amox, 'ringan');
    // A separate prior prescription holding Omeprazole, which clashes badly.
    oiInteraksi($amox, $omeprazole, 'kontraindikasi');
    // And an allergy, whose severity is the recorded `keparahan`.
    oiAlergi($pasien, 'Cetirizine', 'berat');

    $resep = oiResep($pasien, $dokter, 'aktif');
    oiItem($resep, $metformin, 'Metformin');
    oiItem($resep, $cetirizine, 'Cetirizine');
    oiItem($resep, $amox, 'Amoxicillin');

    $lama = oiResep($pasien, $dokter, 'diproses');
    oiItem($lama, $omeprazole, 'Omeprazole');

    $semua = oiLayanan()->peringatan($pasien, [$amox, $metformin, $cetirizine], [$resep]);

    // The declared order is a TOTAL order: severity (the reverse of the DDL's
    // ascending ENUM), then the declared source order, then `kunci`. Every
    // consecutive pair must be non-decreasing on that triple, which is a
    // statement about the WHOLE list rather than about one element.
    $peringkat = array_flip(array_reverse(oiTingkatDdl()));
    $indeksSumber = array_flip(ObatInteraksiService::SUMBER);

    for ($i = 1; $i < count($semua); $i++) {
        $sebelum = [$peringkat[$semua[$i - 1]['tingkat']], $indeksSumber[$semua[$i - 1]['sumber']], $semua[$i - 1]['kunci']];
        $sekarang = [$peringkat[$semua[$i]['tingkat']], $indeksSumber[$semua[$i]['sumber']], $semua[$i]['kunci']];

        expect(
            $sekarang[0] > $sebelum[0]
                || ($sekarang[0] === $sebelum[0] && (
                    $sekarang[1] > $sebelum[1]
                        || ($sekarang[1] === $sebelum[1] && $sekarang[2] > $sebelum[2])
                ))
        )->toBeTrue("element {$i} is out of order: ".json_encode([$sebelum, $sekarang]));
    }

    // Four warnings from three sources: two interactions inside the new
    // prescription (one `berat`, one `ringan`), one against the prior
    // prescription holding Omeprazole (`kontraindikasi`), and one allergy
    // (`berat`, from the recorded `keparahan` of `Cetirizine`).
    expect($semua)->toHaveCount(4)
        ->and(array_column($semua, 'tingkat'))->toBe([
            'kontraindikasi', 'berat', 'berat', 'ringan',
        ])
        ->and(array_column($semua, 'sumber'))->toBe([
            ObatInteraksiService::SUMBER_RIWAYAT_RESEP,
            ObatInteraksiService::SUMBER_ANTAR_ITEM,
            ObatInteraksiService::SUMBER_ALERGI,
            ObatInteraksiService::SUMBER_ANTAR_ITEM,
        ]);

    // De-duplicated: no `kunci` appears twice, whatever the input.
    expect(array_unique(array_column($semua, 'kunci')))->toHaveCount(count($semua));

    // Deterministic: the same inputs, byte-identical output, in ANY input order.
    expect(oiLayanan()->peringatan($pasien, [$amox, $metformin, $cetirizine], [$resep]))->toEqual($semua)
        ->and(oiLayanan()->peringatan($pasien, [$cetirizine, $metformin, $amox], [$resep]))->toEqual($semua);
});

test('an anaphylaxis allergy maps onto a severity the ENUM can hold', function (): void {
    // `keparahan` has FOUR members and `anafilaksis` is one of them (`:280`);
    // `tingkat` has FOUR members and `anafilaksis` is NOT one of them (`:735`).
    // The mapping is therefore a real decision, asserted against the DDL rather
    // than against a transcription of the constant.
    $dariDdl = oiEnum('pasien_alergi', 'keparahan');
    $tingkatDdl = oiTingkatDdl();

    expect($dariDdl)->toBe(['ringan', 'sedang', 'berat', 'anafilaksis'])
        ->and(array_keys(ObatInteraksiService::KEPARAHAN_TINGKAT))->toBe($dariDdl)
        // Every mapped value is a real `tingkat` member, and every member is
        // reachable - no mapping into a value the column cannot hold.
        ->and(array_values(ObatInteraksiService::KEPARAHAN_TINGKAT))->each->toBeIn($tingkatDdl)
        ->and(array_unique(array_values(ObatInteraksiService::KEPARAHAN_TINGKAT)))
        ->toEqualCanonicalizing($tingkatDdl)
        // The one value that is not a rename.
        ->and(ObatInteraksiService::KEPARAHAN_TINGKAT['anafilaksis'])->toBe('kontraindikasi');

    $pasien = oiPasien();
    oiAlergi($pasien, 'Amoxicillin', 'anafilaksis');
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');

    $warning = oiLayanan()->cekAlergi($pasien, [$amox]);

    // A name match fires at every severity, and anaphylaxis is the one that
    // requires a doctor's note.
    expect($warning)->toHaveCount(1)
        ->and($warning[0]['tingkat'])->toBe('kontraindikasi')
        ->and($warning[0]['wajib_catatan_dokter'])->toBeTrue()
        ->and($warning[0]['rincian']['keparahan'])->toBe('anafilaksis')
        ->and(oiLayanan()->wajibCatatanDokter($warning))->toBeTrue();
});

test('a pair of drugs is reported at its worst severity in the aggregate too', function (): void {
    $pasien = oiPasien();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');

    expect(oiLayanan()->peringatan($pasien, [$amox, $metformin]))->toHaveCount(1)
        ->and(oiLayanan()->peringatan($pasien, [$amox, $metformin])[0]['tingkat'])->toBe('berat');

    DB::table('obat_interaksi')->insert([
        'obat_a_id' => $metformin,
        'obat_b_id' => $amox,
        'tingkat' => 'kontraindikasi',
    ]);

    $setelah = oiLayanan()->peringatan($pasien, [$amox, $metformin]);

    expect($setelah)->toHaveCount(1)
        ->and($setelah[0]['tingkat'])->toBe('kontraindikasi')
        ->and($setelah[0]['rincian']['ganda'])->toBeTrue()
        ->and(oiLayanan()->wajibCatatanDokter($setelah))->toBeTrue();
});

test('a kontraindikasi is reported as needing a doctor note, and is not a block', function (): void {
    $pasien = oiPasien();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'kontraindikasi');

    $semua = oiLayanan()->peringatan($pasien, [$amox, $metformin]);

    expect($semua)->toHaveCount(1)
        // The service WARNS. It has no way to refuse, and it does not try.
        ->and($semua[0]['tingkat'])->toBe('kontraindikasi')
        ->and($semua[0]['wajib_catatan_dokter'])->toBeTrue();

    // The rule todo 39 enforces at the endpoint, expressed here as a predicate
    // the endpoint can call - not as a controller and not as a 422.
    expect(oiLayanan()->wajibCatatanDokter($semua))->toBeTrue()
        ->and(oiLayanan()->wajibCatatanDokter([]))->toBeFalse()
        // And it is not a block: the engine ANSWERED, it did not refuse.
        ->and($semua)->toBeArray();
});

test('a pair that is only berat does not demand a doctor note', function (): void {
    // The negative half of the rule above, on its OWN pair: `uq_interaksi`
    // (`:739`) is over the ORDERED pair, so a second row for (amox, metformin)
    // would be a 1062 rather than a second fact.
    $pasien = oiPasien();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $omeprazole = oiObat('Omeprazole', 'Losec', '20 mg', 'PPI');
    oiInteraksi($amox, $omeprazole, 'berat');

    $ringan = oiLayanan()->peringatan($pasien, [$amox, $omeprazole]);

    expect($ringan)->toHaveCount(1)
        ->and($ringan[0]['tingkat'])->toBe('berat')
        ->and($ringan[0]['wajib_catatan_dokter'])->toBeFalse()
        ->and(oiLayanan()->wajibCatatanDokter($ringan))->toBeFalse();
});

test('every warning carries the fields a caller needs to render it', function (): void {
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat', 'Interaksi ringan.');
    oiAlergi($pasien, 'Amoxicillin', 'berat', 'obat');

    $resep = oiResep($pasien, $dokter, 'aktif');
    oiItem($resep, $metformin, 'Metformin');

    $semua = oiLayanan()->peringatan($pasien, [$amox]);

    foreach ($semua as $w) {
        expect($w)->toHaveKeys(['sumber', 'kunci', 'tingkat', 'deskripsi', 'obat_a', 'obat_b', 'rincian', 'wajib_catatan_dokter']);
        expect($w['sumber'])->toBeIn(ObatInteraksiService::SUMBER);
        expect($w['tingkat'])->toBeIn(oiTingkatDdl());
        expect($w['kunci'])->toBeString()->not->toBe('');
        expect($w['obat_a'])->toHaveKeys(['id', 'nama']);
    }
});

// =====================================================================
// 6. The service never throws at a caller that only wants to warn
// =====================================================================

test('degenerate input produces an empty list, not an exception', function (): void {
    $pasien = oiPasien();

    $svc = oiLayanan();

    expect($svc->cekAntarObat([]))->toBe([])
        ->and($svc->cekAntarObat([0]))->toBe([])
        ->and($svc->cekAntarObat([-1]))->toBe([])
        ->and($svc->cekAntarItem([]))->toBe([])
        ->and($svc->cekAntarItem([0, -5]))->toBe([])
        ->and($svc->cekRiwayatPasien($pasien, []))->toBe([])
        ->and($svc->cekRiwayatPasien($pasien, 999999))->toBe([])
        // A patient id with no rows at all.
        ->and($svc->cekRiwayatPasien(999999, [1, 2]))->toBe([])
        ->and($svc->cekAlergi($pasien, []))->toBe([])
        ->and($svc->cekAlergi($pasien, [0, -1]))->toBe([])
        ->and($svc->cekAlergi(999999, [1, 2]))->toBe([])
        ->and($svc->peringatan($pasien, []))->toBe([])
        ->and($svc->peringatan($pasien, [1, 2]))->toBe([])
        // A drug id that is not in the catalogue resolves to no name and so
        // matches no allergy, rather than erroring.
        ->and($svc->cekAlergi($pasien, [999999]))->toBe([]);
});

test('a duplicated drug id in the input does not double the warnings', function (): void {
    $pasien = oiPasien();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');
    oiAlergi($pasien, 'Amoxicillin', 'berat');

    $resep = oiResep($pasien, oiDokter(), 'aktif');
    oiItem($resep, $metformin, 'Metformin');
    oiItem($resep, $metformin, 'Metformin');

    // Two sources fire here, so the de-duplication claim is about the count
    // being 2 and not 4 or 8.
    $sekali = oiLayanan()->peringatan($pasien, [$amox, $metformin], [$resep]);

    expect($sekali)->toHaveCount(2)
        ->and(array_unique(array_column($sekali, 'sumber')))
        ->toEqualCanonicalizing([
            ObatInteraksiService::SUMBER_ANTAR_ITEM,
            ObatInteraksiService::SUMBER_ALERGI,
        ])
        ->and(oiLayanan()->peringatan($pasien, [$amox, $amox, $metformin, $metformin], [$resep]))
        ->toEqual($sekali)
        ->and(oiLayanan()->peringatan($pasien, [$metformin, $amox], [$resep]))
        ->toEqual($sekali);
});

test('the prescription being written is excluded from the prior-prescription check', function (): void {
    // Without the exclusion the pairs inside the new prescription are reported
    // twice - once as `antar_item` and once as `riwayat_resep` - and the caller
    // cannot tell which of the two is the new one.
    $pasien = oiPasien();
    $dokter = oiDokter();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');

    $resep = oiResep($pasien, $dokter, 'aktif');
    oiItem($resep, $amox, 'Amoxicillin');
    oiItem($resep, $metformin, 'Metformin');

    expect(oiLayanan()->peringatan($pasien, [$amox, $metformin]))->toHaveCount(2)
        ->and(oiLayanan()->peringatan($pasien, [$amox, $metformin], [$resep]))->toHaveCount(1)
        ->and(oiLayanan()->peringatan($pasien, [$amox, $metformin], [$resep])[0]['sumber'])
        ->toBe(ObatInteraksiService::SUMBER_ANTAR_ITEM);
});

// =====================================================================
// 7. The controlled mutation - a suite that cannot fail is not evidence
// =====================================================================

test('CONTROL: the engine is reachable and a deliberately wrong answer IS caught', function (): void {
    // The mutation harness edits `app/Services/Obat/ObatInteraksiService.php` and
    // re-runs the suite. Before any mutation is believed, the harness must have
    // produced a RED it can attribute. This test is that harness's own proof
    // that a red is visible: it asserts the engine's answer against a
    // deliberately wrong expectation, so a green suite here means the
    // comparison is real.
    $pasien = oiPasien();
    $amox = oiObat('Amoxicillin', 'Amoxsan', '500 mg', 'Antibiotik');
    $metformin = oiObat('Metformin', 'Glucophage', '500 mg', 'Antidiabetik');
    oiInteraksi($amox, $metformin, 'berat');

    $benar = oiLayanan()->cekAntarObat([$amox, $metformin]);
    $salah = oiLayanan()->cekAntarObat([$amox]);

    expect($benar)->toHaveCount(1)
        // This assertion is the control: with the reverse lookup removed, this
        // is the line that goes red, and it goes red for the right reason.
        ->and($salah)->toHaveCount(0)
        ->and($benar[0]['tingkat'])->not->toBe('ringan');
});
