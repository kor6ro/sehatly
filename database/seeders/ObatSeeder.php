<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-section 16.8, first statement - the 7 `master_obat` rows.
 *
 * Ported verbatim from `telemedicine_test.sql:1308-1317`:
 *
 * ```sql
 * INSERT INTO master_obat (kode_obat, nama_generik, nama_brand, bentuk_sediaan, kekuatan,
 *                          satuan, pabrikan, kelas_terapi, kelas_obat, requires_resep,
 *                          aturan_pakai_umum, harga_jual) VALUES
 * ('OBT-0001','Paracetamol','Panadol','tablet','500 mg','tablet','GSK','Analgetik-Antipiretik','bebas_terbatas',0,'3 x 1 tablet sesudah makan',4500.00),
 * ...
 * ('OBT-0007','ORS','Oralit','sirup','sachet','sachet','Kimia Farma','Rehidrasi','bebas',0,'1 sachet dilarutkan 200ml air, diminum bertahap',1500.00);
 * ```
 *
 * **Row count: 7**, derived by parsing the statement's tuples. The statement
 * spans three physical lines (`:1308`-`:1310`) for its column list, which is a
 * parsing trap - a first attempt at the count that assumed a single-line header
 * silently reported **zero** `master_obat` rows and would have shipped a seeder
 * that inserted nothing. That is recorded because a wrong count that reads as
 * "no rows at all" is invisible: an empty insert is not an error.
 *
 * ## `OBT-0002` Amoxicillin: `requires_resep = 1` and `kelas_obat = 'keras'`
 *
 * `:1312` is `('OBT-0002','Amoxicillin','Amoxsan','kapsul','500 mg','kapsul',
 * 'Sanbe','Antibiotik','keras',1,'3 x 1 kapsul sesudah makan',7500.00)`. Read
 * positionally against the column list, position 9 is `kelas_obat` = `'keras'`
 * and position 10 is `requires_resep` = `1`. **Both are load-bearing** and they
 * are consistent with each other:
 *
 * - `requires_resep` is `TINYINT(1) NOT NULL DEFAULT 1` (`:720`) - the default is
 *   **1**, so a `keras` drug that omitted the column would still require a
 *   prescription. The 7 rows split **2 with `requires_resep = 0`**
 *   (`OBT-0001` Paracetamol `bebas_terbatas`, `OBT-0007` ORS `bebas`) and
 *   **5 with `1`**.
 * - `kelas_obat` is `ENUM('bebas','bebas_terbatas','keras','fitofarmaka',
 *   'n|caption','psikotropika')` at `:719` - **six** values, in that order. The 7
 *   rows use `'bebas_terbatas'` once, `'keras'` **five** times and `'bebas'`
 *   once; `'fitofarmaka'`, `'n|caption'` and `'psikotropika'` have **no** row.
 *
 *   **The fifth member is `'n|caption'`, and I verified it by hex rather than by
 *   reading it.** Plan appendix A.17 records that the plan file once carried a
 *   corrupted value in this very position (`'n <CJK>iktropika'`) and that
 *   `telemedicine_test.sql:719` is the authority. Reading `:719` in a console
 *   produced a plausible-looking but wrong rendering, so I dumped the member's
 *   bytes instead: `006E 0061 0072 006B 006F 0074 0069 006B 0061`, which is
 *   `n|caption` in pure ASCII with no non-ASCII codepoint anywhere on the line.
 *   **No seeded row uses any member beyond the first three**, so this seeder is
 *   unaffected either way - but a later todo adding a narcotics or fitofarmaka row
 *   must read `:719` byte-wise rather than trusting prose, a console, or this file.
 *   The first draft of this docblock asserted a fourth member that does not exist
 *   in the DDL; that was caught by re-reading against the SQL and is recorded here
 *   because a fabricated ENUM member in a comment is the exact defect class this
 *   project keeps finding in prose.
 *
 * ## `bentuk_sediaan` is one of only FIVE wrapped ENUMs in the contract
 *
 * `bentuk_sediaan` opens `ENUM(` on `:713` and does **not** close it on that line;
 * the value list continues onto `:714` with twelve members
 * (`tablet`, `kaplet`, `kapsul`, `sirup`, `salep`, `krim`, `gel`, `tetes`,
 * `injeksi`, `inhaler`, `suppositoria`, `lainnya`). Reading `:713` alone yields
 * **one** value and makes the column look nullable with no default.
 * `docs/migration-order.md` rule 6 lists it as one of the five; the verifier's
 * `wrapped decls 11` is a *different* question (declarations spanning two lines,
 * of which there are eleven) and only these five answer the parity question.
 *
 * Distribution over the 7 rows: **4 `tablet`** (`OBT-0001`, `OBT-0003`,
 * `OBT-0005`, `OBT-0006`), **2 `kapsul`** (`OBT-0002`, `OBT-0004`) and
 * **1 `sirup`** (`OBT-0007`). The other nine members are unseeded.
 *
 * ## `satuan` is a SEPARATE eight-value ENUM and it is not the same vocabulary
 *
 * `satuan` is `ENUM('tablet','kapsul','botol','tube','ampul','sachet','strip',
 * 'box') NOT NULL` at `:716` - eight members, and it overlaps `bentuk_sediaan` on
 * only `tablet` and `kapsul`. `OBT-0007` ORS is the row that shows they are
 * independent: its `bentuk_sediaan` is `'sirup'` while its `satuan` is
 * `'sachet'`, which is a real and slightly odd combination (a sachet of oral
 * rehydration salts is counted as a syrup formulation sold per sachet). It is the
 * DDL's data and is reproduced exactly; **do not "correct" it to make the two
 * columns agree.**
 *
 * `kekuatan` is `VARCHAR(50) NULL` (`:715`) and is free text with a space -
 * `'500 mg'`, `'10 mg'` - not a number and not a unit the database understands.
 * `OBT-0007`'s value is the word `'sachet'`, not a strength at all.
 *
 * ## Omitted columns all take defaults, and one of them is not obvious
 *
 * The column list has 12 of the table's 20 columns, so `indikasi` (`:722`),
 * `kontraindikasi` (`:723`) and `status_aktif` (`:725`) are never supplied and are
 * `NULL`, `NULL` and `1` respectively. `harga_jual` **is** supplied for all 7 rows
 * even though it defaults to `0`, so no seeded drug is free.
 *
 * `dibuat_at` and `diubah_at` (`:726`, `:727`) are supplied by the server; this is
 * one of the 16 contract tables carrying both, so it needed the raw
 * `ON UPDATE CURRENT_TIMESTAMP` `ALTER` in its migration.
 *
 * {@see DevFixtureSeeder} reads these rows back by `kode_obat` to build the
 * unsourced `obat_interaksi` pairs, so it must run after this seeder.
 */
class ObatSeeder extends Seeder
{
    /**
     * The 7 tuples of `:1311-1317`, in the DDL's order, with the DDL's 12 columns.
     *
     * @var list<array{kode_obat: string, nama_generik: string, nama_brand: string, bentuk_sediaan: string, kekuatan: string, satuan: string, pabrikan: string, kelas_terapi: string, kelas_obat: string, requires_resep: int, aturan_pakai_umum: string, harga_jual: string}>
     */
    private const OBAT = [
        [
            'kode_obat' => 'OBT-0001',
            'nama_generik' => 'Paracetamol',
            'nama_brand' => 'Panadol',
            'bentuk_sediaan' => 'tablet',
            'kekuatan' => '500 mg',
            'satuan' => 'tablet',
            'pabrikan' => 'GSK',
            'kelas_terapi' => 'Analgetik-Antipiretik',
            'kelas_obat' => 'bebas_terbatas',
            'requires_resep' => 0,
            'aturan_pakai_umum' => '3 x 1 tablet sesudah makan',
            'harga_jual' => '4500.00',
        ],
        [
            'kode_obat' => 'OBT-0002',
            'nama_generik' => 'Amoxicillin',
            'nama_brand' => 'Amoxsan',
            'bentuk_sediaan' => 'kapsul',
            'kekuatan' => '500 mg',
            'satuan' => 'kapsul',
            'pabrikan' => 'Sanbe',
            'kelas_terapi' => 'Antibiotik',
            'kelas_obat' => 'keras',
            'requires_resep' => 1,
            'aturan_pakai_umum' => '3 x 1 kapsul sesudah makan',
            'harga_jual' => '7500.00',
        ],
        [
            'kode_obat' => 'OBT-0003',
            'nama_generik' => 'Cetirizine',
            'nama_brand' => 'Zenriz',
            'bentuk_sediaan' => 'tablet',
            'kekuatan' => '10 mg',
            'satuan' => 'tablet',
            'pabrikan' => 'Novell',
            'kelas_terapi' => 'Antihistamin',
            'kelas_obat' => 'keras',
            'requires_resep' => 1,
            'aturan_pakai_umum' => '1 x 1 tablet malam hari',
            'harga_jual' => '5200.00',
        ],
        [
            'kode_obat' => 'OBT-0004',
            'nama_generik' => 'Omeprazole',
            'nama_brand' => 'Losec',
            'bentuk_sediaan' => 'kapsul',
            'kekuatan' => '20 mg',
            'satuan' => 'kapsul',
            'pabrikan' => 'AstraZeneca',
            'kelas_terapi' => 'PPI',
            'kelas_obat' => 'keras',
            'requires_resep' => 1,
            'aturan_pakai_umum' => '1 x 1 kapsul sebelum makan',
            'harga_jual' => '9800.00',
        ],
        [
            'kode_obat' => 'OBT-0005',
            'nama_generik' => 'Metformin',
            'nama_brand' => 'Glucophage',
            'bentuk_sediaan' => 'tablet',
            'kekuatan' => '500 mg',
            'satuan' => 'tablet',
            'pabrikan' => 'Merck',
            'kelas_terapi' => 'Antidiabetik',
            'kelas_obat' => 'keras',
            'requires_resep' => 1,
            'aturan_pakai_umum' => '2 x 1 tablet sesudah makan',
            'harga_jual' => '6300.00',
        ],
        [
            'kode_obat' => 'OBT-0006',
            'nama_generik' => 'Amlodipine',
            'nama_brand' => 'Norvasc',
            'bentuk_sediaan' => 'tablet',
            'kekuatan' => '10 mg',
            'satuan' => 'tablet',
            'pabrikan' => 'Pfizer',
            'kelas_terapi' => 'Antihipertensi',
            'kelas_obat' => 'keras',
            'requires_resep' => 1,
            'aturan_pakai_umum' => '1 x 1 tablet pagi',
            'harga_jual' => '8700.00',
        ],
        [
            'kode_obat' => 'OBT-0007',
            'nama_generik' => 'ORS',
            'nama_brand' => 'Oralit',
            'bentuk_sediaan' => 'sirup',
            'kekuatan' => 'sachet',
            'satuan' => 'sachet',
            'pabrikan' => 'Kimia Farma',
            'kelas_terapi' => 'Rehidrasi',
            'kelas_obat' => 'bebas',
            'requires_resep' => 0,
            'aturan_pakai_umum' => '1 sachet dilarutkan 200ml air, diminum bertahap',
            'harga_jual' => '1500.00',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * `harga_jual` is `DECIMAL(12,2) NOT NULL DEFAULT 0` (`:724`) and is written as
     * a **string** rather than a float on purpose: `4500.00` as a PHP float is
     * `4500.0` in binary, and the two spellings of the same money value are a
     * recurring source of "the total is off by a cent" reports. The query builder
     * passes the string straight through and MySQL parses it as an exact decimal.
     *
     * `id` is `BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` (`:709`) and is not in
     * the column list, so the server assigns 1..7. `resep_item.obat_id` and
     * `obat_interaksi.obat_a_id` / `obat_b_id` are all `BIGINT UNSIGNED` with real
     * foreign keys to it, so 1..7 is stable across `migrate:fresh` - but
     * {@see DevFixtureSeeder} looks the drugs up by `kode_obat` anyway, which is
     * both stable and self-documenting.
     */
    public function run(): void
    {
        DB::table('master_obat')->insert(self::OBAT);
    }
}
