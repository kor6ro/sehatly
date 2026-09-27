<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SQL section `[16]` sub-section 16.3 - the 16 doctor specialisations.
 *
 * Ported verbatim from `telemedicine_test.sql:1236-1252`:
 *
 * ```sql
 * -- 16.3 Spesialisasi dokter
 * INSERT INTO master_spesialisasi (kode, nama, tipe) VALUES
 * ('UMUM','Dokter Umum','dokter_umum'),
 * ('SP.PD','Spesialis Penyakit Dalam','spesialis'),
 * ...
 * ('GIGI','Dokter Gigi','spesialis');
 * ```
 *
 * **Row count: 16**, derived by parsing the statement's tuples. I resolved the
 * range by searching the file for `master_spesialisasi` rather than trusting the
 * plan's `:1234-1240` citation, which is wrong - `:1234` is inside the
 * `master_hubungan_keluarga` insert and the statement really is `:1236-1252`.
 * (That same wrong citation is recorded as a batch-D finding in
 * `docs/schema-notes.md`; the migration for this table cites the true range.)
 *
 * ## `tipe` is a THREE-value ENUM and this seed exercises only TWO of them
 *
 * `master_spesialisasi.tipe` is
 * `ENUM('dokter_umum','spesialis','subspesialis') NOT NULL` at `:406`. The 16
 * seed rows supply `'dokter_umum'` **once** (the `UMUM` row) and `'spesialis'`
 * **fifteen times**; **`'subspesialis'` has no seed row at all**. That is the
 * DDL's choice and it is recorded rather than "fixed" - inventing a
 * `'subspesialis'` row would make the seeded catalogue disagree with the
 * reference, and a 16-row count is part of the fidelity claim.
 *
 * **Do not confuse this ENUM with `dokter.tipe`**, which is
 * `ENUM('dokter_umum','dokter_spesialis','dokter_gigi','psikolog','bidan',
 * 'perawat','apoteker')` at `:412` - seven values sharing exactly **one** member
 * (`'dokter_umum'`) with this one. In particular `'spesialis'` here is **not**
 * `'dokter_spesialis'` there, and no string comparison between the two columns
 * matches anything. `docs/migration-order.md` and `docs/schema-notes.md` both
 * carry this warning; the same trap is why `v_dokter_katalog` (`:1170-1187`)
 * exposes `d.tipe` under a bare column name `tipe` and must not be joined to
 * `master_spesialisasi.tipe`.
 *
 * ## `id` is AUTO_INCREMENT here, unlike the five master-umum tables
 *
 * `master_spesialisasi.id` is `SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT`
 * (`:403`) and the column list is `(kode, nama, tipe)` with no `id`, so the server
 * assigns 1..16. This is why {@see DevFixtureSeeder} can look a specialisation up
 * by `kode` rather than by a hard-coded id - the codes are the stable handle, and
 * they are what the DDL actually supplies.
 *
 * `kode` is `VARCHAR(10) NOT NULL UNIQUE` with `COMMENT 'SP.PD, SP.A, SP.OG, dst'`
 * (`:404`), so the dotted codes are data. Note the dot: `'SP.PD'`, not `'SPPD'`.
 */
class SpesialisasiSeeder extends Seeder
{
    /**
     * The 16 tuples of `:1237-1252`, in the DDL's order.
     *
     * @var list<array{kode: string, nama: string, tipe: string}>
     */
    private const SPESIALISASI = [
        ['kode' => 'UMUM', 'nama' => 'Dokter Umum', 'tipe' => 'dokter_umum'],
        ['kode' => 'SP.PD', 'nama' => 'Spesialis Penyakit Dalam', 'tipe' => 'spesialis'],
        ['kode' => 'SP.A', 'nama' => 'Spesialis Anak', 'tipe' => 'spesialis'],
        ['kode' => 'SP.OG', 'nama' => 'Spesialis Obstetri & Ginekologi', 'tipe' => 'spesialis'],
        ['kode' => 'SP.M', 'nama' => 'Spesialis Mata', 'tipe' => 'spesialis'],
        ['kode' => 'SP.THT', 'nama' => 'Spesialis Telinga Hidung Tenggorokan', 'tipe' => 'spesialis'],
        ['kode' => 'SP.KJ', 'nama' => 'Spesialis Kedokteran Jiwa', 'tipe' => 'spesialis'],
        ['kode' => 'SP.B', 'nama' => 'Spesialis Bedah', 'tipe' => 'spesialis'],
        ['kode' => 'SP.BP', 'nama' => 'Spesialis Bedah Plastik', 'tipe' => 'spesialis'],
        ['kode' => 'SP.JP', 'nama' => 'Spesialis Jantung & Pembuluh Darah', 'tipe' => 'spesialis'],
        ['kode' => 'SP.P', 'nama' => 'Spesialis Paru', 'tipe' => 'spesialis'],
        ['kode' => 'SP.KK', 'nama' => 'Spesialis Kulit & Kelamin', 'tipe' => 'spesialis'],
        ['kode' => 'SP.S', 'nama' => 'Spesialis Orthopaedi & Traumatologi', 'tipe' => 'spesialis'],
        ['kode' => 'SP.N', 'nama' => 'Spesialis Saraf', 'tipe' => 'spesialis'],
        ['kode' => 'SP.U', 'nama' => 'Spesialis Urologi', 'tipe' => 'spesialis'],
        ['kode' => 'GIGI', 'nama' => 'Dokter Gigi', 'tipe' => 'spesialis'],
    ];

    /**
     * Run the database seeds.
     *
     * {@see DevFixtureSeeder} reads these rows back by `kode` to build
     * `dokter_spesialisasi`, so it must run after this seeder. It is the only
     * seeded table with a foreign key pointing into it from fixture data.
     */
    public function run(): void
    {
        DB::table('master_spesialisasi')->insert(self::SPESIALISASI);
    }
}
