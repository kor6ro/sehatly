<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-section 16.4 - the 6 payment guarantors.
 *
 * Ported verbatim from `telemedicine_test.sql:1255-1261`:
 *
 * ```sql
 * -- 16.4 Penjamin bayar
 * INSERT INTO master_penjamin (nama, tipe) VALUES
 * ('BPJS Kesehatan','bpjs'),
 * ('Tunai / Mandiri','tunai'),
 * ('Prudential','asuransi_swasta'),
 * ('AXA Mandiri','asuransi_swasta'),
 * ('Allianz','asuransi_swasta'),
 * ('Astra Life','asuransi_swasta');
 * ```
 *
 * **Row count: 6**, derived by parsing the statement's tuples. The comment above
 * the statement carries no count, so there was nothing to cross-check against -
 * which is why the derivation is mechanical.
 *
 * ## `tipe` is a FOUR-value ENUM and this seed exercises only THREE of them
 *
 * `master_penjamin.tipe` is
 * `ENUM('bpjs','asuransi_swasta','perusahaan','tunai') NOT NULL` at `:336`. The 6
 * rows supply `'bpjs'` once, `'tunai'` once and `'asuransi_swasta'` **four
 * times**; **`'perusahaan'` has no seed row**. Recorded, not fixed: a corporate
 * guarantor is a legal shape the ENUM permits and the reference catalogue does
 * not populate, and inventing one would make the seeded rows disagree with
 * `telemedicine_test.sql`.
 *
 * ## The `tunai` row's name is `'Tunai / Mandiri'`, spaces and slash included
 *
 * That is the DDL's spelling and it is the *only* row in this seeder containing
 * a slash. It is a display name, not a type - the type is the `'tunai'` beside it -
 * and the two must not be normalised into each other.
 *
 * ## `pasien_penjamin.penjamin_id` is `SMALLINT UNSIGNED`, which is why this id is 16-bit
 *
 * `:343` declares `penjamin_id SMALLINT UNSIGNED NOT NULL` with a real
 * `FOREIGN KEY (penjamin_id) REFERENCES master_penjamin(id)` at `:352`, matching
 * this table's `SMALLINT UNSIGNED` primary key (`:334`). `foreignId()` would have
 * produced a `BIGINT` and been drift twice over.
 *
 * The companion column `pasien_penjamin.faskes_rujukan_id` (`:346`) has **no**
 * foreign key and none is owed - plan appendices A.10 and A.11 settled that, and
 * migration 76 records the prohibition. Nothing in this seeder touches it.
 */
class PenjaminSeeder extends Seeder
{
    /**
     * The 6 tuples of `:1256-1261`, in the DDL's order.
     *
     * @var list<array{nama: string, tipe: string}>
     */
    private const PENJAMIN = [
        ['nama' => 'BPJS Kesehatan', 'tipe' => 'bpjs'],
        ['nama' => 'Tunai / Mandiri', 'tipe' => 'tunai'],
        ['nama' => 'Prudential', 'tipe' => 'asuransi_swasta'],
        ['nama' => 'AXA Mandiri', 'tipe' => 'asuransi_swasta'],
        ['nama' => 'Allianz', 'tipe' => 'asuransi_swasta'],
        ['nama' => 'Astra Life', 'tipe' => 'asuransi_swasta'],
    ];

    /**
     * Run the database seeds.
     *
     * `status_aktif` is not in the column list, so all 6 rows inherit
     * `status_aktif = 1` from the default at `:337`. That is the DDL's intent and
     * is why a seeded guarantor is always available to `pasien_penjamin`.
     *
     * `id` is `SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT` (`:334`), so the
     * server assigns 1..6 and no explicit id is supplied.
     */
    public function run(): void
    {
        DB::table('master_penjamin')->insert(self::PENJAMIN);
    }
}
