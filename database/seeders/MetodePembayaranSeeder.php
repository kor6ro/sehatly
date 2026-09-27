<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-section 16.5 - the 14 Indonesian payment methods.
 *
 * Ported verbatim from `telemedicine_test.sql:1264-1278`:
 *
 * ```sql
 * -- 16.5 Metode pembayaran Indonesia
 * INSERT INTO master_metode_pembayaran (kode, nama, tipe, penyedia) VALUES
 * ('VA_BCA','Virtual Account BCA','va_bank','BCA'),
 * ...
 * ('BPJS','BPJS Kesehatan','bpjs','BPJS Kesehatan');
 * ```
 *
 * **Row count: 14**, derived by parsing the statement's tuples.
 *
 * ## `tipe` is a NINE-value ENUM and this seed exercises only SIX of them
 *
 * `master_metode_pembayaran.tipe` is
 * `ENUM('va_bank','e_wallet','qris','kartu_kredit','gerai_retail','cod','tunai',
 * 'bpjs','asuransi') NOT NULL` at `:929` - nine members. The 14 rows supply:
 *
 * | `tipe` | rows |
 * | --- | --- |
 * | `va_bank` | **5** (BCA, Mandiri, BNI, BRI, Permata) |
 * | `e_wallet` | **5** (GoPay, OVO, DANA, ShopeePay, LinkAja) |
 * | `qris` | **1** (QRIS) |
 * | `cod` | **1** (COD) |
 * | `tunai` | **1** (TUNAI) |
 * | `bpjs` | **1** (BPJS) |
 *
 * **`kartu_kredit`, `gerai_retail` and `asuransi` have no seed row at all**, so
 * three legal payment methods are unrepresented. That is the DDL's choice and it
 * is recorded rather than "fixed": a card, a retail counter and an insurance
 * product are all real, and the ENUM permits all three, but
 * `telemedicine_test.sql` does not populate them and the 14-row count is part of
 * the fidelity claim. Any todo that needs a credit-card method must insert one
 * explicitly and say that it is doing so.
 *
 * ## Every row inherits `biaya_admin_flat = 0` and `biaya_admin_persen = 0`
 *
 * The column list is `(kode, nama, tipe, penyedia)`, so the two admin-fee columns
 * (`:931`, `:932`, both `NOT NULL DEFAULT 0`) are never supplied and all 14 rows
 * carry **no fee at all**. The scales differ and both matter: `(12,2)` flat and
 * `(5,2)` percent. **A checkout must read the fee from the row and must not treat
 * `0` as "fall back to a default"** - there is no third "unconfigured" state here,
 * because both columns are `NOT NULL`.
 *
 * `penyedia` is `VARCHAR(50) NULL` (`:930`) and every one of the 14 rows supplies
 * it, so no seeded method has a `NULL` provider.
 *
 * ## `kode` mixes two naming conventions and both are the contract
 *
 * Five rows use a `VA_` prefix with an underscore (`VA_BCA`), one uses bare
 * uppercase (`GOPAY`, `OVO`, `DANA`, `QRIS`, `COD`, `TUNAI`, `BPJS`) and four use
 * `SH` + CamelCase with no separator (`SHOPEEPAY`, `LINKAJA`). `kode` is
 * `VARCHAR(30) NOT NULL UNIQUE` (`:927`), so these are three distinct spellings
 * that all have to be reproduced - a "tidy the codes" pass would break
 * `pembayaran.metode_id` lookups and be invisible to the verifier, which
 * compares schema and not data.
 *
 * The last row is a genuine duplicate pair: `kode = 'BPJS'` with
 * `nama = 'BPJS Kesehatan'`, which is the same string as `master_penjamin`'s first
 * row name (`:1256`). The two tables are independent - nothing joins them - but
 * the collision is worth knowing before a later todo tries to unify them.
 */
class MetodePembayaranSeeder extends Seeder
{
    /**
     * The 14 tuples of `:1265-1278`, in the DDL's order.
     *
     * @var list<array{kode: string, nama: string, tipe: string, penyedia: string}>
     */
    private const METODE = [
        ['kode' => 'VA_BCA', 'nama' => 'Virtual Account BCA', 'tipe' => 'va_bank', 'penyedia' => 'BCA'],
        ['kode' => 'VA_MANDIRI', 'nama' => 'Virtual Account Mandiri', 'tipe' => 'va_bank', 'penyedia' => 'Mandiri'],
        ['kode' => 'VA_BNI', 'nama' => 'Virtual Account BNI', 'tipe' => 'va_bank', 'penyedia' => 'BNI'],
        ['kode' => 'VA_BRI', 'nama' => 'Virtual Account BRI', 'tipe' => 'va_bank', 'penyedia' => 'BRI'],
        ['kode' => 'VA_PERMATA', 'nama' => 'Virtual Account Permata', 'tipe' => 'va_bank', 'penyedia' => 'Permata'],
        ['kode' => 'GOPAY', 'nama' => 'GoPay', 'tipe' => 'e_wallet', 'penyedia' => 'Gojek'],
        ['kode' => 'OVO', 'nama' => 'OVO', 'tipe' => 'e_wallet', 'penyedia' => 'OVO'],
        ['kode' => 'DANA', 'nama' => 'DANA', 'tipe' => 'e_wallet', 'penyedia' => 'DANA'],
        ['kode' => 'SHOPEEPAY', 'nama' => 'ShopeePay', 'tipe' => 'e_wallet', 'penyedia' => 'Shopee'],
        ['kode' => 'LINKAJA', 'nama' => 'LinkAja', 'tipe' => 'e_wallet', 'penyedia' => 'LinkAja'],
        ['kode' => 'QRIS', 'nama' => 'QRIS', 'tipe' => 'qris', 'penyedia' => 'GPN'],
        ['kode' => 'COD', 'nama' => 'Bayar di Tempat', 'tipe' => 'cod', 'penyedia' => 'Internal'],
        ['kode' => 'TUNAI', 'nama' => 'Tunai', 'tipe' => 'tunai', 'penyedia' => 'Internal'],
        ['kode' => 'BPJS', 'nama' => 'BPJS Kesehatan', 'tipe' => 'bpjs', 'penyedia' => 'BPJS Kesehatan'],
    ];

    /**
     * Run the database seeds.
     *
     * `pembayaran.metode_id` is `SMALLINT UNSIGNED NOT NULL` with a real
     * `FOREIGN KEY (metode_id) REFERENCES master_metode_pembayaran(id)` at
     * `:971`, matching this table's `SMALLINT UNSIGNED` primary key (`:926`).
     * `id` is `AUTO_INCREMENT`, so the server assigns 1..14 and no explicit id is
     * supplied; the `kode` is the stable handle for a caller.
     */
    public function run(): void
    {
        DB::table('master_metode_pembayaran')->insert(self::METODE);
    }
}
