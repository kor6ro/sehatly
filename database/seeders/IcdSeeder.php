<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-sections 16.6 and 16.7 - the ICD-10 and ICD-9-CM samples.
 *
 * Ported verbatim from `telemedicine_test.sql:1281-1305`:
 *
 * ```sql
 * -- 16.6 Sampel ICD-10 (lengkapnya import dari file Kemenkes resmi)
 * INSERT INTO master_icd10 (kode, deskripsi) VALUES
 * ('A09','Diare dan gastroenteritis yang diduga akibat infeksi'),
 * ...
 * ('O80','Persalinan tunggal spontan');
 *
 * -- 16.7 Sampel ICD-9-CM (lengkapan import dari file Kemenkes resmi)
 * INSERT INTO master_icd9cm (kode, deskripsi) VALUES
 * ('88.72','Ultrasonografi diagnostik jantung'),
 * ...
 * ('31.9','Lainnya intervensi pada telinga luar/institusi');
 * ```
 *
 * | Table | Rows | SQL lines | Owning `CREATE TABLE` |
 * | --- | --- | --- | --- |
 * | `master_icd10` | **15** | `:1281-1296` | `:115` |
 * | `master_icd9cm` | **6** | `:1299-1305` | `:122` |
 *
 * Total **21** rows. Both counts were derived by parsing the statements' tuples.
 * Both comments say "Sampel" (sample) and both mention importing the complete
 * Kemenkes file later - so **these 21 rows are explicitly a sample, not the full
 * coding system**, and a consumer must not treat an absent code as an invalid one.
 *
 * ## The dotted codes are data, and `VARCHAR(8)` is what holds them
 *
 * `master_icd10.kode` is `VARCHAR(8) NOT NULL UNIQUE` (`:117`) and
 * `master_icd9cm.kode` is `VARCHAR(8) NOT NULL UNIQUE` (`:124`) - the **same**
 * width, which is why `rekam_medis_diagnosa.icd10_kode VARCHAR(8)` (`:660`) and
 * `rekam_medis_tindakan.icd9cm_kode VARCHAR(8)` (`:672`) could both be foreign
 * keys and deliberately are not.
 *
 * The codes are **not** uniformly dotted. ICD-10 has `A09`, `J06`, `J45`, `I10`,
 * `E11`, `K29`, `K02`, `M54`, `B34`, `K35`, `N39.0`, `K76.9`, `O80` - mostly
 * three characters, two of them with a dot and a fourth character - plus the two
 * four-character `U07.1` and `U07.2`. ICD-9-CM is dotted throughout
 * (`88.72`, `87.44`, `93.94`, `99.04`, `96.04`) except the last, `31.9`. **Do not
 * normalise them**: a "strip the dots" pass would collide `N39.0` with a future
 * `N390`, and neither column has a `CHECK` to stop it.
 *
 * `U07.1` and `U07.2` are the COVID-19 pair and both are present; they differ only
 * in the final character, so the trailing `1`/`2` is load-bearing and a
 * `LIKE 'U07%'` query returns both.
 *
 * ## `master_icd10` carries a REDUNDANT index on purpose - do not "clean it up"
 *
 * `INDEX idx_icd10 (kode)` (`:119`) exists **alongside** `UNIQUE (kode)` at `:117`.
 * Both are part of the contract: `idx_icd10` is a name the DDL wrote, so the
 * verifier compares it by name and dropping it would be `missing_index` drift.
 * `docs/migration-order.md` rule 11 records this. The same index *name* is reused
 * on `pasien_riwayat_penyakit` (`:297`), which is legal because index names are
 * scoped per table.
 *
 * ## `id` is `INT UNSIGNED AUTO_INCREMENT` on both
 *
 * `:116` and `:123`. Neither insert supplies an id, so the server assigns 1..15 and
 * 1..6. Note this is `INT`, not `TINYINT`/`SMALLINT` - the only two master tables
 * in the contract with a 32-bit key, and the reason `rekam_medis_diagnosa.icd10_kode`
 * is a `VARCHAR` code rather than a numeric id.
 */
class IcdSeeder extends Seeder
{
    /**
     * The 15 tuples of `:1282-1296`, in the DDL's order.
     *
     * @var list<array{kode: string, deskripsi: string}>
     */
    private const ICD10 = [
        ['kode' => 'A09', 'deskripsi' => 'Diare dan gastroenteritis yang diduga akibat infeksi'],
        ['kode' => 'J06', 'deskripsi' => 'Infeksi akut pada saluran pernapasan atas, multifokus'],
        ['kode' => 'J45', 'deskripsi' => 'Asma'],
        ['kode' => 'I10', 'deskripsi' => 'Hipertensi esensial (primer)'],
        ['kode' => 'E11', 'deskripsi' => 'Diabetes melitus tipe 2'],
        ['kode' => 'K29', 'deskripsi' => 'Gastritis dan duodenitis'],
        ['kode' => 'K02', 'deskripsi' => 'Karies gigi'],
        ['kode' => 'M54', 'deskripsi' => 'Dorsopati lain'],
        ['kode' => 'B34', 'deskripsi' => 'Infeksi virus dengan lesi lain'],
        ['kode' => 'U07.1', 'deskripsi' => 'COVID-19, virus teridentifikasi'],
        ['kode' => 'U07.2', 'deskripsi' => 'COVID-19, virus tidak teridentifikasi'],
        ['kode' => 'K35', 'deskripsi' => 'Apendisitis akut'],
        ['kode' => 'N39.0', 'deskripsi' => 'Infeksi saluran kemih tanpa lokasi yang ditentukan'],
        ['kode' => 'K76.9', 'deskripsi' => 'Penyakit hati, tidak ditentukan'],
        ['kode' => 'O80', 'deskripsi' => 'Persalinan tunggal spontan'],
    ];

    /**
     * The 6 tuples of `:1300-1305`, in the DDL's order.
     *
     * @var list<array{kode: string, deskripsi: string}>
     */
    private const ICD9CM = [
        ['kode' => '88.72', 'deskripsi' => 'Ultrasonografi diagnostik jantung'],
        ['kode' => '87.44', 'deskripsi' => 'Arteriografi koroner dengan dua kateter'],
        ['kode' => '93.94', 'deskripsi' => 'Rehabilitasi jantung'],
        ['kode' => '99.04', 'deskripsi' => 'Transfusi darah sel darah merah'],
        ['kode' => '96.04', 'deskripsi' => 'Pemasangan pipa endotrakeal'],
        ['kode' => '31.9', 'deskripsi' => 'Lainnya intervensi pada telinga luar/institusi'],
    ];

    /**
     * Run the database seeds.
     *
     * Order matches the DDL's (16.6 then 16.7). Neither table is referenced by any
     * row these seeders insert, and both `rekam_medis_diagnosa.icd10_kode` and
     * `rekam_medis_tindakan.icd9cm_kode` are **bare** columns with no foreign key,
     * so nothing constrains the order - it is for fidelity only.
     */
    public function run(): void
    {
        DB::table('master_icd10')->insert(self::ICD10);
        DB::table('master_icd9cm')->insert(self::ICD9CM);
    }
}
