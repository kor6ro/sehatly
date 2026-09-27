<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-section 16.8, second and third statements - the 10
 * `master_lab_tindakan` rows and the 3 `master_lab_paket` rows.
 *
 * Ported verbatim from `telemedicine_test.sql:1319-1335`:
 *
 * ```sql
 * INSERT INTO master_lab_tindakan (kode, nama, kelompok, satuan, nilai_rujukan_laki,
 *                                 nilai_rujukan_perempuan, harga) VALUES
 * ('LAB-001','Hemoglobin (Hb)','darah','g/dL','13.0-17.0','12.0-15.5',35000.00),
 * ...
 * ('LAB-010','Widal Test','serologi','titer','negatif','negatif',60000.00);
 *
 * INSERT INTO master_lab_paket (nama, deskripsi, harga) VALUES
 * ('Medical Check Up Dasar','Hb, LED, Golongan Darah, Urinalisa',120000.00),
 * ('Cek Gula & Kolesterol','Glukosa Puasa, Kolesterol Total, Trigliserida, HDL, LDL',180000.00),
 * ('Fungsi Hati Lengkap','SGOT, SGPT, Bilirubin Total & Direk, Albumin',250000.00);
 * ```
 *
 * | Table | Rows | SQL lines | Owning `CREATE TABLE` |
 * | --- | --- | --- | --- |
 * | `master_lab_tindakan` | **10** | `:1319-1330` | `:847` |
 * | `master_lab_paket` | **3** | `:1332-1335` | `:860` |
 *
 * Total **13** rows. Both counts were derived by parsing the statements' tuples;
 * the `master_lab_tindakan` header spans two physical lines (`:1319`-`:1320`),
 * which is the same multi-line-header parsing trap {@see ObatSeeder} documents.
 *
 * ## The `LAB-*` codes are `LAB-001` .. `LAB-010` and ALL TEN exist
 *
 * `master_lab_tindakan.kode` is `VARCHAR(20) NOT NULL UNIQUE` (`:849`). I verified
 * all ten codes against the DDL before {@see DevFixtureSeeder} used any of them:
 * `LAB-001` Hemoglobin (Hb), `LAB-002` Laju Endap Darah (LED),
 * `LAB-003` Glukosa Darah Puasa, `LAB-004` Kolesterol Total,
 * `LAB-005` Fungsi Hati (SGOT), `LAB-006` Fungsi Hati (SGPT), `LAB-007` Ureum,
 * `LAB-008` Kreatinin, `LAB-009` Urinalisa Lengkap, `LAB-010` Widal Test. There is
 * no `LAB-011` and no gap in the sequence. The codes are zero-padded to three
 * digits and are **not** the same vocabulary as `kode_obat`'s `OBT-0001`.
 *
 * ## `nilai_rujukan_*` are `VARCHAR(100)` free text, NOT numbers and NOT ranges
 *
 * This is the single most misreadable pair of columns in this seeder, and it is
 * why a "normalise the reference ranges" pass would be a defect:
 *
 * - `'13.0-17.0'` and `'12.0-15.5'` are **strings** with a hyphen, not numeric
 *   ranges. `'0-10'`, `'<200'`, `'<37'`, `'17-43'`, `'0.7-1.3'` likewise.
 * - `'<200'`, `'<37'`, `'<40'`, `'<31'` and `'<32'` begin with a **less-than
 *   sign**, so they are not even symmetric ranges.
 * - `'negatif'` (LAB-010) is not a range at all.
 * - **`LAB-009` supplies `NULL` for both** (`:1329`) - the only row that does.
 *   Urinalysis has no numeric reference interval, and `NULL` here means "no
 *   reference published", which is different from an empty string.
 *
 * The columns are `VARCHAR(100) NULL` (`:853`, `:854`) and are compared by nothing
 * in the database. A todo that needs to *evaluate* a result against its reference
 * must parse these strings in PHP, and the three shapes (range, upper-bound-only,
 * non-numeric) are three parsing cases.
 *
 * `kelompok` is `ENUM('darah','urine','hormon','kimia_darah','serologi',
 * 'mikrobiologi','lainnya') NOT NULL` at `:851` - **seven** members, of which the
 * 10 rows use four: `darah` **2** (LAB-001, LAB-002), `kimia_darah` **6**
 * (LAB-003 through LAB-008), `urine` **1** (LAB-009), `serologi` **1** (LAB-010).
 * `hormon`, `mikrobiologi` and `lainnya` are unseeded.
 *
 * `satuan` is `VARCHAR(50) NULL` and is a **real unit with a slash** - `'g/dL'`,
 * `'mm/jam'`, `'mg/dL'`, `'U/L'` - except `LAB-009`'s `'-'` (a hyphen meaning "no
 * unit") and `LAB-010`'s `'titer'` (a dilution titre, not a unit at all). Do not
 * normalise `'g/dL'` to `'g/dl'`.
 *
 * ## `master_lab_paket` has NO code column, and its descriptions are not the item lists
 *
 * The column list is `(nama, deskripsi, harga)`; there is **no `kode`**. So the
 * three packages are addressed by `nama`, and {@see DevFixtureSeeder} must look
 * them up by name rather than by a code. `id` is `BIGINT UNSIGNED AUTO_INCREMENT`
 * (`:861`), giving 1..3.
 *
 * **The `deskripsi` text lists analytes that the package does not contain.**
 * `'Medical Check Up Dasar'` is described as `'Hb, LED, Golongan Darah,
 * Urinalisa'` - four items - but there is **no `LAB-*` code for "Golongan Darah"**;
 * blood typing is not one of the ten `master_lab_tindakan` rows. Similarly
 * `'Cek Gula & Kolesterol'` claims Trigliserida, HDL and LDL, and
 * `'Fungsi Hati Lengkap'` claims Bilirubin and Albumin, none of which are seeded
 * actions. The `deskripsi` is **prose, not a manifest**: the actual membership is
 * whatever `lab_paket_item` rows say, and `telemedicine_test.sql` seeds
 * **none**. That is why the three `lab_paket_item` rows are **unsourced fixture
 * data** created by {@see DevFixtureSeeder} and are explicitly outside the 1:1
 * fidelity claim - see `docs/schema-notes.md`.
 *
 * The `nama` values contain spaces, an ampersand and a slash-free comma list
 * (`'Cek Gula & Kolesterol'`), and they are reproduced exactly.
 */
class LabSeeder extends Seeder
{
    /**
     * The 10 tuples of `:1321-1330`, in the DDL's order, with the DDL's 7 columns.
     *
     * @var list<array{kode: string, nama: string, kelompok: string, satuan: string, nilai_rujukan_laki: string|null, nilai_rujukan_perempuan: string|null, harga: string}>
     */
    private const TINDAKAN = [
        ['kode' => 'LAB-001', 'nama' => 'Hemoglobin (Hb)', 'kelompok' => 'darah', 'satuan' => 'g/dL', 'nilai_rujukan_laki' => '13.0-17.0', 'nilai_rujukan_perempuan' => '12.0-15.5', 'harga' => '35000.00'],
        ['kode' => 'LAB-002', 'nama' => 'Laju Endap Darah (LED)', 'kelompok' => 'darah', 'satuan' => 'mm/jam', 'nilai_rujukan_laki' => '0-10', 'nilai_rujukan_perempuan' => '0-20', 'harga' => '25000.00'],
        ['kode' => 'LAB-003', 'nama' => 'Glukosa Darah Puasa', 'kelompok' => 'kimia_darah', 'satuan' => 'mg/dL', 'nilai_rujukan_laki' => '70-100', 'nilai_rujukan_perempuan' => '70-100', 'harga' => '30000.00'],
        ['kode' => 'LAB-004', 'nama' => 'Kolesterol Total', 'kelompok' => 'kimia_darah', 'satuan' => 'mg/dL', 'nilai_rujukan_laki' => '<200', 'nilai_rujukan_perempuan' => '<200', 'harga' => '45000.00'],
        ['kode' => 'LAB-005', 'nama' => 'Fungsi Hati (SGOT)', 'kelompok' => 'kimia_darah', 'satuan' => 'U/L', 'nilai_rujukan_laki' => '<37', 'nilai_rujukan_perempuan' => '<31', 'harga' => '40000.00'],
        ['kode' => 'LAB-006', 'nama' => 'Fungsi Hati (SGPT)', 'kelompok' => 'kimia_darah', 'satuan' => 'U/L', 'nilai_rujukan_laki' => '<40', 'nilai_rujukan_perempuan' => '<32', 'harga' => '40000.00'],
        ['kode' => 'LAB-007', 'nama' => 'Ureum', 'kelompok' => 'kimia_darah', 'satuan' => 'mg/dL', 'nilai_rujukan_laki' => '17-43', 'nilai_rujukan_perempuan' => '17-43', 'harga' => '35000.00'],
        ['kode' => 'LAB-008', 'nama' => 'Kreatinin', 'kelompok' => 'kimia_darah', 'satuan' => 'mg/dL', 'nilai_rujukan_laki' => '0.7-1.3', 'nilai_rujukan_perempuan' => '0.6-1.1', 'harga' => '35000.00'],
        ['kode' => 'LAB-009', 'nama' => 'Urinalisa Lengkap', 'kelompok' => 'urine', 'satuan' => '-', 'nilai_rujukan_laki' => null, 'nilai_rujukan_perempuan' => null, 'harga' => '50000.00'],
        ['kode' => 'LAB-010', 'nama' => 'Widal Test', 'kelompok' => 'serologi', 'satuan' => 'titer', 'nilai_rujukan_laki' => 'negatif', 'nilai_rujukan_perempuan' => 'negatif', 'harga' => '60000.00'],
    ];

    /**
     * The 3 tuples of `:1333-1335`, in the DDL's order.
     *
     * @var list<array{nama: string, deskripsi: string, harga: string}>
     */
    private const PAKET = [
        ['nama' => 'Medical Check Up Dasar', 'deskripsi' => 'Hb, LED, Golongan Darah, Urinalisa', 'harga' => '120000.00'],
        ['nama' => 'Cek Gula & Kolesterol', 'deskripsi' => 'Glukosa Puasa, Kolesterol Total, Trigliserida, HDL, LDL', 'harga' => '180000.00'],
        ['nama' => 'Fungsi Hati Lengkap', 'deskripsi' => 'SGOT, SGPT, Bilirubin Total & Direk, Albumin', 'harga' => '250000.00'],
    ];

    /**
     * Run the database seeds.
     *
     * `master_lab_tindakan` first, then `master_lab_paket` - which is also the
     * DDL's order. Nothing in `master_lab_paket` references
     * `master_lab_tindakan`; the link is `lab_paket_item`, a **composite-PK join
     * table with no `id` column** (`:868-874`), which `telemedicine_test.sql`
     * never seeds.
     *
     * {@see DevFixtureSeeder} runs after this seeder and is the only thing that
     * populates `lab_paket_item`.
     *
     * `harga` is `DECIMAL(12,2) NOT NULL DEFAULT 0` and is written as a string for
     * the same exact-decimal reason as {@see ObatSeeder}.
     */
    public function run(): void
    {
        DB::table('master_lab_tindakan')->insert(self::TINDAKAN);
        DB::table('master_lab_paket')->insert(self::PAKET);
    }
}
