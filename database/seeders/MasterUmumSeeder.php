<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-section 16.2 - the five "master umum" lookup tables.
 *
 * Ported verbatim from `telemedicine_test.sql:1217-1233`:
 *
 * ```sql
 * -- 16.2 Agama, golongan darah, pendidikan, status pernikahan, hubungan keluarga
 * INSERT INTO master_agama VALUES
 * (1,'Islam'),(2,'Kristen Protestan'),(3,'Katolik'),(4,'Hindu'),
 * (5,'Buddha'),(6,'Khonghucu'),(7,'Lainnya');
 * INSERT INTO master_golongan_darah VALUES
 * (1,'A'),(2,'B'),(3,'AB'),(4,'O');
 * INSERT INTO master_pendidikan VALUES
 * (1,'Tidak Sekolah'),(2,'SD/Sederajat'),(3,'SMP/Sederajat'),(4,'SMA/SMK/Sederajat'),
 * (5,'Diploma (D1-D3)'),(6,'Sarjana (S1)'),(7,'Magister (S2)'),(8,'Doktor (S3)');
 * INSERT INTO master_status_pernikahan VALUES
 * (1,'belum_menikah'),(2,'menikah'),(3,'cerai_hidup'),(4,'cerai_mati');
 * INSERT INTO master_hubungan_keluarga VALUES
 * (1,'Pasangan'),(2,'Anak Kandung'),(3,'Orang Tua/Kandung'),(4,'Saudara Kandung'),
 * (5,'Paman/Tante'),(6,'Kakek/Nenek'),(7,'Lainnya');
 * ```
 *
 * | Table | Rows | SQL lines | Owning `CREATE TABLE` |
 * | --- | --- | --- | --- |
 * | `master_agama` | **7** | `:1217-1219` | `:89` |
 * | `master_golongan_darah` | **4** | `:1221-1222` | `:94` |
 * | `master_pendidikan` | **8** | `:1224-1226` | `:99` |
 * | `master_status_pernikahan` | **4** | `:1228-1229` | `:104` |
 * | `master_hubungan_keluarga` | **7** | `:1231-1233` | `:109` |
 *
 * Total **30** rows. Every count was derived by parsing the statements' tuples,
 * not read from a comment or a plan.
 *
 * ## THE FIVE OF THESE TABLES HAVE NO `AUTO_INCREMENT` - explicit ids are mandatory
 *
 * All five declare `id TINYINT UNSIGNED PRIMARY KEY` with **no**
 * `AUTO_INCREMENT` (`:90`, `:95`, `:100`, `:105`, `:110`), and the SQL's inserts
 * are **positional** - `INSERT INTO master_agama VALUES` with no column list at
 * all, so `(1,'Islam')` means `id = 1, nama = 'Islam'`.
 *
 * **Consequences, all of them load-bearing:**
 *
 * - `DB::table(...)->insert([['id' => 1, 'nama' => 'Islam'], ...])` is
 *   **required**. Eloquent's `create()` would send a `NULL` id and fail with
 *   MySQL 1366 (`Field 'id' doesn't have a default value`).
 * - The column names below are `id` plus the single other column, so the
 *   positional form and the named form are equivalent here. **Named columns are
 *   used anyway**, because a positional insert breaks silently if a column is ever
 *   added and breaks *loudly but meaninglessly* if the two columns are swapped.
 * - These ids are referenced by `pasien.agama_id` (`:230`, `TINYINT UNSIGNED`),
 *   `pasien.golongan_darah_id` (`:228`), `pasien.pendidikan_id` (`:231`) and
 *   `pasien.status_pernikahan_id` (`:233`) - all four are `TINYINT UNSIGNED`, which
 *   matches this `TINYINT UNSIGNED` primary key and is why `foreignId()` would
 *   have been wrong on those columns.
 * - `pasien_anggota_keluarga.hubungan_keluarga_id` also points at
 *   `master_hubungan_keluarga`, and `dokter_pendidikan` at
 *   `master_pendidikan`, so a renumbered id here silently repoints those columns.
 *
 * `docs/migration-order.md` rule 2 records this and adds that todo 19's models
 * need `public $incrementing = false` on all five.
 *
 * ## These five tables cannot be truncated by their own seeder
 *
 * All five are parents: `pasien` (FKs `:251`-`:254`),
 * `pasien_anggota_keluarga` and `dokter_pendidikan` all reference them, and
 * **MySQL 1701 refuses `TRUNCATE` on a table named by any foreign key** even when
 * the referencing table is empty. The reset is therefore done once by
 * {@see DatabaseSeeder}, with `FOREIGN_KEY_CHECKS` disabled around it — the same
 * technique `telemedicine_test.sql`'s own `[0] RESET` section uses at `:20` and
 * `:51`.
 *
 * For these five specifically the `AUTO_INCREMENT` question is moot: none has one,
 * and the explicit ids are re-supplied on every run, so the tables are
 * bit-identical after any number of runs.
 */
class MasterUmumSeeder extends Seeder
{
    /**
     * `(1,'Islam'),(2,'Kristen Protestan'),(3,'Katolik'),(4,'Hindu'),
     *  (5,'Buddha'),(6,'Khonghucu'),(7,'Lainnya')` - `:1218-1219`, 7 tuples.
     *
     * @var list<array{id: int, nama: string}>
     */
    private const AGAMA = [
        ['id' => 1, 'nama' => 'Islam'],
        ['id' => 2, 'nama' => 'Kristen Protestan'],
        ['id' => 3, 'nama' => 'Katolik'],
        ['id' => 4, 'nama' => 'Hindu'],
        ['id' => 5, 'nama' => 'Buddha'],
        ['id' => 6, 'nama' => 'Khonghucu'],
        ['id' => 7, 'nama' => 'Lainnya'],
    ];

    /**
     * `(1,'A'),(2,'B'),(3,'AB'),(4,'O')` - `:1222`, 4 tuples.
     *
     * The second column is `kode`, not `nama`, and it is an ENUM:
     * `ENUM('A','B','AB','O') NOT NULL` at `:96`. The order is semantic (ENUM order
     * is the sort index), so `'AB'` at position 3 is not the same as an
     * alphabetical ordering and the ids follow the ENUM order, not the alphabet.
     *
     * @var list<array{id: int, kode: string}>
     */
    private const GOLONGAN_DARAH = [
        ['id' => 1, 'kode' => 'A'],
        ['id' => 2, 'kode' => 'B'],
        ['id' => 3, 'kode' => 'AB'],
        ['id' => 4, 'kode' => 'O'],
    ];

    /**
     * `:1225-1226`, 8 tuples, ending `(8,'Doktor (S3)')`.
     *
     * Note `D1-D3`, `S1`, `S2` and `S3` contain a hyphen and parentheses; they are
     * data, not syntax, and are reproduced verbatim.
     *
     * @var list<array{id: int, nama: string}>
     */
    private const PENDIDIKAN = [
        ['id' => 1, 'nama' => 'Tidak Sekolah'],
        ['id' => 2, 'nama' => 'SD/Sederajat'],
        ['id' => 3, 'nama' => 'SMP/Sederajat'],
        ['id' => 4, 'nama' => 'SMA/SMK/Sederajat'],
        ['id' => 5, 'nama' => 'Diploma (D1-D3)'],
        ['id' => 6, 'nama' => 'Sarjana (S1)'],
        ['id' => 7, 'nama' => 'Magister (S2)'],
        ['id' => 8, 'nama' => 'Doktor (S3)'],
    ];

    /**
     * `(1,'belum_menikah'),(2,'menikah'),(3,'cerai_hidup'),(4,'cerai_mati')` -
     * `:1229`, 4 tuples.
     *
     * The second column is `nama` but its type is an ENUM, not a `VARCHAR`:
     * `ENUM('belum_menikah','menikah','cerai_hidup','cerai_mati') NOT NULL` at
     * `:106`. The four seeded values are exactly the four ENUM members, in the
     * same order, so the ids and the sort indices coincide. **No fifth value may
     * be added**, and the underscore spelling (`belum_menikah`, not
     * `belum-menikah`) is part of the stored data.
     *
     * @var list<array{id: int, nama: string}>
     */
    private const STATUS_PERNIKAHAN = [
        ['id' => 1, 'nama' => 'belum_menikah'],
        ['id' => 2, 'nama' => 'menikah'],
        ['id' => 3, 'nama' => 'cerai_hidup'],
        ['id' => 4, 'nama' => 'cerai_mati'],
    ];

    /**
     * `:1232-1233`, 7 tuples, ending `(7,'Lainnya')`.
     *
     * The `nama` values are **display strings with spaces, slashes and
     * parentheses** - `Anak Kandung`, `Orang Tua/Kandung`, `Paman/Tante`,
     * `Kakek/Nenek` - unlike `master_status_pernikahan`'s machine tokens. The
     * column's `COMMENT` at `:111` is `Pasangan/Anak/Orang Tua/Saudara/Lainnya`,
     * which is documentation and is **not** the data; the data abbreviates two of
     * those words (`Anak` -> `Anak Kandung`, `Orang Tua` -> `Orang Tua/Kandung`) and
     * omits `Saudara` as a standalone entry. The comment is not a fifth column and
     * must not be seeded.
     *
     * @var list<array{id: int, nama: string}>
     */
    private const HUBUNGAN_KELUARGA = [
        ['id' => 1, 'nama' => 'Pasangan'],
        ['id' => 2, 'nama' => 'Anak Kandung'],
        ['id' => 3, 'nama' => 'Orang Tua/Kandung'],
        ['id' => 4, 'nama' => 'Saudara Kandung'],
        ['id' => 5, 'nama' => 'Paman/Tante'],
        ['id' => 6, 'nama' => 'Kakek/Nenek'],
        ['id' => 7, 'nama' => 'Lainnya'],
    ];

    /**
     * Run the database seeds.
     *
     * Insert order matches the DDL's. These five tables have **no** foreign keys
     * among themselves and nothing in this seeder references them, so the order is
     * for fidelity to `:1217-1233` rather than for correctness. What *is*
     * load-bearing is that {@see DevFixtureSeeder} runs after this seeder, because
     * it looks up `master_golongan_darah` and `master_agama` by `kode` and `nama`.
     */
    public function run(): void
    {
        DB::table('master_agama')->insert(self::AGAMA);
        DB::table('master_golongan_darah')->insert(self::GOLONGAN_DARAH);
        DB::table('master_pendidikan')->insert(self::PENDIDIKAN);
        DB::table('master_status_pernikahan')->insert(self::STATUS_PERNIKAHAN);
        DB::table('master_hubungan_keluarga')->insert(self::HUBUNGAN_KELUARGA);
    }
}
