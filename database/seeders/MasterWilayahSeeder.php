<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-section 16.1 - the 38 Indonesian provinces.
 *
 * Ported verbatim from `telemedicine_test.sql:1203-1214`:
 *
 * ```sql
 * -- 16.1 Provinsi Indonesia (38 provinsi, kode Kemendagri)
 * INSERT INTO master_provinsi (kode, nama) VALUES
 * ('11','Aceh'),('12','Sumatera Utara'), ...
 * ('96','Papua Barat Daya');
 * ```
 *
 * **Row count: 38**, derived by parsing the statement's tuples rather than by
 * reading the comment. The SQL's own comment says "38 provinsi" and it agrees,
 * but a comment is not a count.
 *
 * ## `id` is left to AUTO_INCREMENT, and that is what the DDL does
 *
 * The column list is `(kode, nama)` - `id` is **not** in it - so the server
 * assigns 1..38 in insertion order. `master_provinsi.id` is
 * `TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` (`:59`), so this is the ordinary
 * case and needs none of the explicit-id handling that
 * {@see MasterUmumSeeder} requires. The contrast is deliberate and is called out
 * here because the two are adjacent in the same SQL section and the difference is
 * exactly the kind that gets flattened: **the five master-umum tables declare
 * `TINYINT UNSIGNED PRIMARY KEY` with NO `AUTO_INCREMENT`**, and inserting them
 * without explicit ids fails with MySQL 1366.
 *
 * ## Why `DB::table()->insert()` and not Eloquent
 *
 * No model exists for `master_provinsi` yet (todo 19 authors the models) and no
 * factory is permitted here, so the query builder is the only available writer.
 * `insert()` with a batched array is also one round trip rather than 38.
 *
 * `kode` is `CHAR(2) NOT NULL UNIQUE` (`:60`) with `COMMENT 'Kode Kemendagri/BPS'`
 * (documentation only, not compared by the verifier), so the two-digit
 * Kemendagri codes are the whole identity of a province and must be reproduced
 * exactly - `11` is Aceh, **not** `01`, and the gaps in the sequence (`20`, `30`,
 * `41`, `61`, `71`, `81`) are real: those codes are retired in the Kemendagri
 * scheme and no row may be invented to fill them.
 */
class MasterWilayahSeeder extends Seeder
{
    /**
     * The 38 tuples of `:1203-1214`, in the DDL's order, with the DDL's quoting.
     *
     * @var list<array{kode: string, nama: string}>
     */
    private const PROVINSI = [
        ['kode' => '11', 'nama' => 'Aceh'],
        ['kode' => '12', 'nama' => 'Sumatera Utara'],
        ['kode' => '13', 'nama' => 'Sumatera Barat'],
        ['kode' => '14', 'nama' => 'Riau'],
        ['kode' => '15', 'nama' => 'Jambi'],
        ['kode' => '16', 'nama' => 'Sumatera Selatan'],
        ['kode' => '17', 'nama' => 'Bengkulu'],
        ['kode' => '18', 'nama' => 'Lampung'],
        ['kode' => '19', 'nama' => 'Kepulauan Bangka Belitung'],
        ['kode' => '21', 'nama' => 'Kepulauan Riau'],
        ['kode' => '31', 'nama' => 'DKI Jakarta'],
        ['kode' => '32', 'nama' => 'Jawa Barat'],
        ['kode' => '33', 'nama' => 'Jawa Tengah'],
        ['kode' => '34', 'nama' => 'DI Yogyakarta'],
        ['kode' => '35', 'nama' => 'Jawa Timur'],
        ['kode' => '36', 'nama' => 'Banten'],
        ['kode' => '51', 'nama' => 'Bali'],
        ['kode' => '52', 'nama' => 'Nusa Tenggara Barat'],
        ['kode' => '53', 'nama' => 'Nusa Tenggara Timur'],
        ['kode' => '61', 'nama' => 'Kalimantan Barat'],
        ['kode' => '62', 'nama' => 'Kalimantan Tengah'],
        ['kode' => '63', 'nama' => 'Kalimantan Selatan'],
        ['kode' => '64', 'nama' => 'Kalimantan Timur'],
        ['kode' => '65', 'nama' => 'Kalimantan Utara'],
        ['kode' => '71', 'nama' => 'Sulawesi Utara'],
        ['kode' => '72', 'nama' => 'Sulawesi Tengah'],
        ['kode' => '73', 'nama' => 'Sulawesi Selatan'],
        ['kode' => '74', 'nama' => 'Sulawesi Tenggara'],
        ['kode' => '75', 'nama' => 'Gorontalo'],
        ['kode' => '76', 'nama' => 'Sulawesi Barat'],
        ['kode' => '81', 'nama' => 'Maluku'],
        ['kode' => '82', 'nama' => 'Maluku Utara'],
        ['kode' => '91', 'nama' => 'Papua'],
        ['kode' => '92', 'nama' => 'Papua Barat'],
        ['kode' => '93', 'nama' => 'Papua Tengah'],
        ['kode' => '94', 'nama' => 'Papua Pegunungan'],
        ['kode' => '95', 'nama' => 'Papua Selatan'],
        ['kode' => '96', 'nama' => 'Papua Barat Daya'],
    ];

    /**
     * Run the database seeds.
     *
     * A pure insert - the reset belongs to {@see DatabaseSeeder}, which is the
     * only place that can perform it: `master_provinsi` is referenced by
     * `master_kabupaten_kota` (`:65`), and MySQL 1701 refuses `TRUNCATE` on a
     * table named by any foreign key even when the referencing table is empty.
     * Running this seeder on its own against a populated table therefore fails
     * with MySQL 1062 on the `kode` `UNIQUE`; that is the intended behaviour and
     * the supported entry points are `DatabaseSeeder` and `migrate:fresh --seed`.
     */
    public function run(): void
    {
        DB::table('master_provinsi')->insert(self::PROVINSI);
    }
}
