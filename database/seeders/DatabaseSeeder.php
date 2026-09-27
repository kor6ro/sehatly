<?php

namespace Database\Seeders;

use App\Support\Rbac\RbacCatalog;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for `php artisan db:seed` and
 * `php artisan migrate:fresh --seed`.
 *
 * Rewritten in todo 18. The scaffold version called
 * `User::factory()->create(['name' => ..., 'email' => ...])`, which is
 * **broken against this schema and was left broken by todos 8-17** because
 * seeding was todo 18's job: `telemedicine_test.sql`'s `users` has no `name`
 * column. It has `nama_lengkap VARCHAR(150) NOT NULL` (`:135`),
 * `uuid CHAR(36) NOT NULL UNIQUE` (`:134`),
 * `no_telepon VARCHAR(20) NOT NULL UNIQUE` (`:137`) and
 * `kata_sandi_hash VARCHAR(255) NOT NULL` (`:138`). A factory is also forbidden
 * here - no factory is created by this todo.
 *
 * ## This class owns the reset; the individual seeders are pure inserts
 *
 * Each seeder used to `truncate()` its own tables first. **That does not work, and
 * the reason is worth recording because it is not obvious: MySQL refuses
 * `TRUNCATE` on a table that is referenced by ANY foreign key constraint, whether
 * or not the referencing table holds any rows.** Error **1701**, raised here as:
 *
 * ```text
 * SQLSTATE[42000]: Syntax error or access violation: 1701 Cannot truncate a table
 * referenced in a foreign key constraint (`telemedisin_db`.`master_kabupaten_kota`,
 * CONSTRAINT `master_kabupaten_kota_provinsi_id_foreign`)
 * ```
 *
 * `master_provinsi` is referenced by `master_kabupaten_kota` (`:65`), and that
 * table is created by migration 2 whether or not it holds rows. **Most of the
 * fifteen seeded master tables are in the same position** - each is the parent of
 * at least one contract foreign key - so per-seeder `TRUNCATE` is unusable across
 * most of this project's seed data, not just for one table.
 *
 * So the reset lives here, once, with `FOREIGN_KEY_CHECKS` disabled around it -
 * which is exactly what `telemedicine_test.sql`'s own `[0] RESET` section does at
 * `:20` and `:51`, so the technique is the reference's and not an invention.
 *
 * **Foreign key checking is re-enabled before any row is inserted.** That ordering
 * is the whole point: the guard exists to empty tables, not to let a bad fixture
 * insert a dangling reference. Every insert in every seeder below therefore runs
 * with constraints enforced, and a fixture naming a master row that does not exist
 * still fails loudly - which is what {@see DevFixtureSeeder}'s lookups guarantee.
 *
 * `TRUNCATE` (rather than `DELETE`) is kept because it also resets
 * `AUTO_INCREMENT`, which is what makes two consecutive `migrate:fresh --seed`
 * runs produce identical ids and not merely identical row counts.
 *
 * ## Consequence for running an individual seeder
 *
 * A seeder invoked on its own - `php artisan db:seed --class=ObatSeeder` - against
 * an already-populated table now fails with MySQL **1062** on `kode_obat`'s
 * `UNIQUE`, rather than resetting first. That is intentional and the error is
 * self-explanatory: the supported entry points are this class and
 * `migrate:fresh --seed`. A seeder that silently reset its table on every direct
 * invocation would be more dangerous, because it would empty a developer's data
 * with no indication that it had.
 *
 * ## The order below is FK-SAFE, and it is not the same as the DDL's order
 *
 * `telemedicine_test.sql` section `[16]` lists its 15 inserts in a reading order
 * that is **not** dependency order, because in the DDL there are no dependencies:
 * it is one import into an empty schema where nothing references anything yet.
 * Once the inserts are split across nine seeders, two dependencies become real and
 * neither is visible from the DDL's ordering:
 *
 * | # | Seeder | Read back by the fixture? | Referenced by |
 * | --- | --- | --- | --- |
 * | 1 | {@see MasterWilayahSeeder} | yes - `kode` `31` | `faskes.provinsi_id` (FK `:381`) |
 * | 2 | {@see MasterUmumSeeder} | yes - 4 tables by `nama`/`kode` | `pasien.agama_id`, `golongan_darah_id`, `pendidikan_id`, `status_pernikahan_id` (FKs `:251`-`:254`); `pasien_anggota_keluarga.hubungan_id` (`:262`); `dokter_pendidikan` |
 * | 3 | {@see SpesialisasiSeeder} | yes - 4 codes | `dokter_spesialisasi.spesialisasi_id` (FK `:443`) |
 * | 4 | {@see PenjaminSeeder} | no | `pasien_penjamin.penjamin_id` (FK `:352`) |
 * | 5 | {@see MetodePembayaranSeeder} | no | `pembayaran.metode_id` (FK `:971`) |
 * | 6 | {@see IcdSeeder} | no | **nothing** - `rekam_medis_diagnosa.icd10_kode` and `rekam_medis_tindakan.icd9cm_kode` are deliberately bare columns |
 * | 7 | {@see ObatSeeder} | yes - 4 codes | `obat_interaksi.obat_a_id`/`obat_b_id` (FKs `:737`-`:738`) |
 * | 8 | {@see LabSeeder} | yes - 6 codes, 3 names | `lab_paket_item.tindakan_id`/`paket_id` (FKs `:872`-`:873`) |
 * | 9 | {@see ArtikelKategoriSeeder} | no | `artikel.kategori_id` (FK `:1089`); `artikel` is never seeded |
 * | 10 | {@see RbacSeeder} | no | `role_permissions` (FKs `:167`-`:168`), `user_roles` (FK `:176`) |
 * | **11** | **{@see DevFixtureSeeder}** | - | **must be last**: it reads 2, 3, 7 and 8 back and writes `users`, `pasien`, `faskes`, `dokter`, `dokter_spesialisasi`, `lab_paket_item`, `obat_interaksi` |
 *
 * `RbacSeeder` sits between the two groups because it has no dependency on either:
 * it writes only `roles`, `permissions` and `role_permissions` and resolves every id
 * by natural key, so it reads nothing. Its position is for readability.
 *
 * ## Row totals
 *
 * | Source | Tables | Rows |
 * | --- | --- | --- |
 * | `telemedicine_test.sql` section `[16]` (9 seeders) | 15 | **151** |
 * | {@see RbacSeeder} (no DDL source) | 3 | **98** |
 * | {@see DevFixtureSeeder} (no DDL source) | 7 | **25** |
 * | **total written** | **25** | **274** |
 *
 * **The 26th owned table, `user_roles`, is written by nobody in this tree.** It is
 * listed in `SEEDED_TABLES` so a re-seed clears a developer's manual grants, and
 * {@see RbacSeeder} deliberately creates none: assigning a role to a real account is
 * an application action taken through {@see RoleAssigner}, which
 * todo 20 wires into registration. "Owned" here means "emptied by this chain", not
 * "populated by it".
 *
 * The **151** is derived by parsing the DDL's own INSERT tuples - 15 statements,
 * one per table - and every per-table count is recorded in the individual
 * seeder's docblock. The **25** fixture rows are 5 `users` (three doctor accounts
 * and two patient accounts), 2 `pasien`, 2 `faskes`, 3 `dokter`,
 * 4 `dokter_spesialisasi`, **7** `lab_paket_item` and 2 `obat_interaksi` - note the
 * `users` count is **5, not 3**, because `dokter.user_id` is `NOT NULL UNIQUE`
 * with a real foreign key to `users(id)` (`:411`, `:433`) and a doctor is not a
 * patient. The **98** RBAC rows are 5 `roles`, 24 `permissions` and 69
 * `role_permissions`; the 24 and the 69 are the counts of
 * {@see RbacCatalog::PERMISSIONS} and of the sum of
 * {@see RbacCatalog::ROLE_PERMISSIONS}.
 *
 * ### Corrected while adding `RbacSeeder`: the fixture total was 21, and 3 `lab_paket_item` was wrong
 *
 * This table previously read 7 fixture tables / 21 rows / 22 tables / 172 total, and
 * attributed 3 rows to `lab_paket_item`. **`DevFixtureSeeder`'s package map links
 * seven kode** - three to `Medical Check Up Dasar`, two to `Cek Gula & Kolesterol`
 * and two to `Fungsi Hati Lengkap` - so the measured `COUNT(*)` is 7, and
 * 151 + 25 + 98 = 274. The stale "3" also survives in
 * {@see LabSeeder}'s docblock and in {@see DevFixtureSeeder}'s; both are left alone
 * here because they are not this todo's files, and both are recorded in
 * `.omo/evidence/task-4-sehatly.md`. Nothing in the **code** was wrong: only the
 * prose was, which is plan appendix A.15's defect class exactly - a comment that
 * nobody executes, so nothing fails when it drifts.
 *
 * **Those figures are arithmetic, not measurements.** The authoritative
 * numbers are the `COUNT(*)` values read back after seeding, recorded in
 * `.omo/evidence/task-18-sehatly.md` and `.omo/evidence/task-4-sehatly.md`.
 *
 * ### Second correction: `pasien_anggota_keluarga.hubungan_id`, not `..._keluarga_id`
 *
 * The same table previously named the column
 * `pasien_anggota_keluarga.hubungan_id` + `_keluarga_id` - the referenced table's
 * suffix pasted onto the column name. The DDL calls it **`hubungan_id`**
 * (`:262`, `TINYINT UNSIGNED NOT NULL`, `FOREIGN KEY (hubungan_id) REFERENCES
 * master_hubungan_keluarga(id)` at `:271`) - the `_keluarga` belongs to the *table* it
 * references, not to the column name. Found by this todo's A.26 token audit, which
 * extracts every `snake_case` token from a file and checks it against the DDL: a
 * corrupted-ASCII identifier is invisible to an encoding scan and to the parity
 * verifier, which only compares columns it can see. The same wrong name is still in
 * `database/seeders/MasterUmumSeeder.php:62`; that file is not this todo's, so it is
 * reported rather than edited, per A.15's rule that a stale claim is only fixed in
 * the places you happen to look.
 *
 * **The wrong spelling is deliberately not reproduced in full anywhere in this
 * file.** A correction that quotes the error puts the corrupt token back into the
 * very file a token audit reads, so the next run reports it again and the next
 * reader greps for it. The heading above carries the truncated form, which is
 * enough to identify the mistake and not enough to be a false positive.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Every table this seeder tree owns, parents before children.
     *
     * "Owns" means "empties on every seed run", which is a superset of "writes": the
     * 26th entry, `user_roles`, is listed so a developer's manual grants do not
     * survive a re-seed, but no seeder in this tree creates a row in it. See
     * {@see RbacSeeder}, which explains why.
     *
     * The order is a convenience, not a requirement: `TRUNCATE` with foreign key
     * checking disabled does not care. It is parents-first so that a reader
     * scanning the list sees the same dependency order the call list uses.
     *
     * @var list<string>
     */
    private const SEEDED_TABLES = [
        // 15 section-[16] tables
        'master_provinsi',
        'master_agama',
        'master_golongan_darah',
        'master_pendidikan',
        'master_status_pernikahan',
        'master_hubungan_keluarga',
        'master_spesialisasi',
        'master_penjamin',
        'master_metode_pembayaran',
        'master_icd10',
        'master_icd9cm',
        'master_obat',
        'master_lab_tindakan',
        'master_lab_paket',
        'artikel_kategori',
        // 3 RBAC tables (todo 4)
        'roles',
        'permissions',
        'role_permissions',
        // 7 fixture tables
        'lab_paket_item',
        'obat_interaksi',
        'faskes',
        'dokter_spesialisasi',
        'dokter',
        'pasien',
        'users',
        // Owned by the seed tree but written by nobody in it (todo 4)
        'user_roles',
    ];

    /**
     * Run the database seeds.
     *
     * `$this->call()` rather than direct instantiation, so each seeder's output is
     * reported and a failure names the seeder that failed. The order is the table
     * above and must not be alphabetised.
     */
    public function run(): void
    {
        $this->resetSeededTables();

        // --- SQL section [16]: the nine seeders with a source in the DDL -------
        $this->call([
            MasterWilayahSeeder::class,       // 1
            MasterUmumSeeder::class,          // 2
            SpesialisasiSeeder::class,        // 3
            PenjaminSeeder::class,            // 4
            MetodePembayaranSeeder::class,    // 5
            IcdSeeder::class,                 // 6
            ObatSeeder::class,                // 7
            LabSeeder::class,                 // 8
            ArtikelKategoriSeeder::class,     // 9
        ]);

        // --- No DDL source: the RBAC kernel (todo 4) --------------------------
        //
        // Between the DDL seeders and the fixtures because it has no dependency on
        // either: it writes only `roles`, `permissions` and `role_permissions`,
        // resolves every id by natural key, and reads nothing. The order is a
        // readability choice, not a constraint - see the call table above.
        $this->call(RbacSeeder::class);

        // --- NOT from the DDL: development fixtures, and they must be last -----
        $this->call(DevFixtureSeeder::class);
    }

    /**
     * Empty every table this seeder tree writes, with `AUTO_INCREMENT` reset.
     *
     * `SET FOREIGN_KEY_CHECKS = 0` is required because MySQL 1701 refuses
     * `TRUNCATE` on a referenced table even when the referencing table is empty -
     * see the class docblock for the error this replaced. It is switched back on
     * **before** any insert, so every seeded row is still validated against its
     * foreign keys; only the emptying runs unchecked.
     *
     * `try`/`finally` so a failure part-way through the list cannot leave the
     * session with foreign key checking off, which would silently disable the
     * constraint for every later statement on the same connection.
     */
    private function resetSeededTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::SEEDED_TABLES as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
