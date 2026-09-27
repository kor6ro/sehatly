<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-section 16.9 - the 6 health-article categories.
 *
 * Ported verbatim from `telemedicine_test.sql:1338-1344`:
 *
 * ```sql
 * -- 16.9 Kategori artikel kesehatan
 * INSERT INTO artikel_kategori (nama, slug) VALUES
 * ('Kesehatan Umum','kesehatan-umum'),
 * ('Kesehatan Ibu & Anak','kesehatan-ibu-anak'),
 * ('Penyakit Dalam','penyakit-dalam'),
 * ('Kesehatan Mental','kesehatan-mental'),
 * ('Gizi & Diet','gizi-diet'),
 * ('Covid-19','covid-19');
 * ```
 *
 * **Row count: 6**, derived by parsing the statement's tuples. This is the **last**
 * statement in the whole file: `:1345` is blank, `:1346`-`:1348` are the
 * `SELESAI` banner and `:1349` is a bare `SELECT ... AS status`. **There is
 * nothing after `artikel_kategori`**, which is the mechanical basis for the
 * statement that `lab_paket_item` and `obat_interaksi` are never seeded anywhere
 * in the DDL - see {@see DevFixtureSeeder}.
 *
 * ## This is the narrowest table in the contract: three columns, no ENUM, no timestamps
 *
 * `artikel_kategori` is `CREATE TABLE` at `:1068` and spans `:1068`-`:1072`:
 * `id` (`:1069`, `SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT`), `nama`
 * (`:1070`, `VARCHAR(100) NOT NULL`) and `slug` (`:1071`, `VARCHAR(100) NOT NULL
 * UNIQUE`). **Three columns, no `ENUM` of any kind, and no `dibuat_at` or
 * `diubah_at`.**
 *
 * **It is one of only 39 contract tables with neither timestamp column**, so
 * `$table->timestamps()` is wrong (it would emit `created_at`/`updated_at` and
 * produce six drift rows) and todo 19's model needs `public $timestamps = false`.
 * `docs/migration-order.md` rule 4 carries the 39-table split.
 *
 * ## `artikel_kategori` has NO column called `jenis` - do not attribute one to it
 *
 * Plan appendix **A.23** exists because the plan itself attributed the wrapped
 * `jenis ENUM(...)` declaration at `:1137`-`:1138` to `artikel_kategori.jenis`, a
 * column this table does not have, while getting the line numbers right. `:1137`
 * is inside `persetujuan_pdp` (`CREATE TABLE` at `:1134`), and
 * `persetujuan_pdp.jenis` has **five** values counted from **both** `:1137` and
 * `:1138`: `syarat_ketentuan`, `kebijakan_privasi`, `berbagi_data_medis`,
 * `pemasaran`, `komunikasi_tindak_lanjut`. Reading `:1137` alone yields **four**
 * and makes the column look nullable with no default. This seeder is in the same
 * batch as that correction, so the statement is repeated here: **an executor
 * resolving `:1137` by searching for `artikel_kategori.jenis` would find nothing.**
 *
 * ## `slug` is UNIQUE and the six slugs are kebab-case derivations, not always literal
 *
 * `slug` is `VARCHAR(100) NOT NULL UNIQUE` (`:1071`) - the only key on this table
 * besides the primary. Four of the six are a straight kebab-case of `nama`
 * (`Kesehatan Umum` -> `kesehatan-umum`, `Penyakit Dalam` -> `penyakit-dalam`,
 * `Kesehatan Mental` -> `kesehatan-mental`, `Gizi & Diet` -> `gizi-diet`), but two
 * are **not**:
 *
 * - `'Kesehatan Ibu & Anak'` -> `'kesehatan-ibu-anak'`. The `&` becomes
 *   `-anak`, giving **three** hyphen-separated parts where the name has two
 *   ampersand-joined parts. A slugifier that dropped the `&` would produce
 *   `kesehatan-ibu-anak` too, but one that turned `&` into `dan` would produce
 *   `kesehatan-ibu-dan-anak` and be wrong.
 * - `'Covid-19'` -> `'covid-19'`. The hyphen is **kept** rather than collapsed to
 *   `covid19`, so this slug is not a pure kebab-case transform of the name.
 *
 * A "regenerate slugs from names" pass would therefore change at least these two,
 * and `artikel` rows pointing at them by `kategori_id` are unaffected but any
 * URL built from the slug would break. The two spellings are the contract.
 *
 * ## `id` is `SMALLINT UNSIGNED AUTO_INCREMENT` and it is genuinely referenced
 *
 * `artikel.kategori_id` is `SMALLINT UNSIGNED` with a real
 * `FOREIGN KEY (kategori_id) REFERENCES artikel_kategori(id)` at `:1089`, and the
 * 16-bit width is chosen to match this table's `id` (`:1069`), so `foreignId()`
 * would have been wrong there too. This is the only foreign key pointing at
 * `artikel_kategori` in the whole contract.
 *
 * `artikel` itself is never seeded by the DDL, so this seeder leaves the category
 * table populated and empty of articles - which is a complete and correct
 * reproduction of the reference state, not an omission.
 */
class ArtikelKategoriSeeder extends Seeder
{
    /**
     * The 6 tuples of `:1339-1344`, in the DDL's order.
     *
     * @var list<array{nama: string, slug: string}>
     */
    private const KATEGORI = [
        ['nama' => 'Kesehatan Umum', 'slug' => 'kesehatan-umum'],
        ['nama' => 'Kesehatan Ibu & Anak', 'slug' => 'kesehatan-ibu-anak'],
        ['nama' => 'Penyakit Dalam', 'slug' => 'penyakit-dalam'],
        ['nama' => 'Kesehatan Mental', 'slug' => 'kesehatan-mental'],
        ['nama' => 'Gizi & Diet', 'slug' => 'gizi-diet'],
        ['nama' => 'Covid-19', 'slug' => 'covid-19'],
    ];

    /**
     * Run the database seeds.
     *
     * Nothing references this table in any seeded row, so the order is for fidelity
     * only. `artikel` is never seeded by the DDL and is not seeded here.
     */
    public function run(): void
    {
        DB::table('artikel_kategori')->insert(self::KATEGORI);
    }
}
