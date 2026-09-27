<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 31 of 75 — `telemedicine_test.sql:409-435`.
 *
 * The second-widest table in the contract (23 columns) and the hub every later
 * clinical table hangs off (`dokter_spesialisasi`, `dokter_faskes`,
 * `dokter_pendidikan` in this batch; `dokter_jadwal`, `dokter_libur`,
 * `konsultasi`, `resep`, `ulasan_dokter` in later ones). Four things here are
 * not expressible by reaching for the obvious helper:
 *
 *  1. **Four integer columns are UNSIGNED, and the flag is load-bearing.**
 *     `pengalaman_tahun SMALLINT UNSIGNED` (`:418`) and
 *     `durasi_default_menit SMALLINT UNSIGNED` (`:422`) are `SMALLINT UNSIGNED`;
 *     `jumlah_ulasan INT UNSIGNED` (`:424`) and `jumlah_konsultasi INT UNSIGNED`
 *     (`:425`) are `INT UNSIGNED`. Declaring them signed would let MySQL accept
 *     `durasi_default_menit = -1` and `jumlah_ulasan = -1`, which is exactly the
 *     drift the plan's todo-10 failure QA probes. Laravel's
 *     `smallInteger()` / `integer()` are signed; the `unsignedSmallInteger()` /
 *     `unsignedInteger()` builders are the only correct ones.
 *  2. **The two money/precision columns have different precisions.**
 *     `biaya_konsultasi_online` and `biaya_luar_jam` are `DECIMAL(12,2)`
 *     (`:420`, `:421`), while `rating_rata_rata` is `DECIMAL(3,2)` (`:423`) — a
 *     deliberately narrow `0.00`–`9.99` range that can hold a 0–5 rating. Writing
 *     `decimal('rating_rata_rata', 12, 2)` "harmonises" it with the money columns
 *     and is a silent parity break.
 *  3. **`status_verifikasi` and `status_aktif` have independent defaults, and the
 *     combination they permit is `pending` + active.** `:427` defaults
 *     `status_verifikasi` to `'pending'` and `:430` defaults `status_aktif` to
 *     `1`, so a freshly inserted doctor row is *active but unverified*. Nothing in
 *     the schema prevents that; hiding unverified doctors from the directory is
 *     therefore an application-level rule, and todo 22's `v_dokter_katalog`
 *     (`:1170-1187`) is where the spec's own visibility filter
 *     (`status_verifikasi = 'terverifikasi' AND status_aktif = 1 AND
 *     tersedia_telemedisin = 1`) is encoded. Recorded in `docs/schema-notes.md`.
 *  4. **`tipe`'s seven values are a different vocabulary from
 *     `master_spesialisasi.tipe`'s three** (table 30): `'spesialis'` there is not
 *     `'dokter_spesialis'` here, and the two lists share only `'dokter_umum'`.
 *     There is no mapping table, so any translation between them is hand-written
 *     service-layer code.
 *
 * `user_id BIGINT UNSIGNED NOT NULL UNIQUE` (`:411`) means **one doctor row per
 * user account**, enforced by the database — todo 19's `User` model gets a
 * `hasOne` `dokter()` relation, not a `hasMany`. `users` is table 12 (batch B),
 * already migrated, so this FK is not deferred.
 *
 * `INDEX idx_dokter_tipe (tipe, status_aktif, tersedia_telemedisin)` (`:434`) is
 * explicitly named in the DDL and is therefore compared by name on
 * `(TABLE_NAME, INDEX_NAME)`. Its column order is deliberate: `tipe` first
 * because it is the discriminator, then the two boolean-ish flags because they
 * are the directory's filter predicates. Do not reorder it, and do not add it a
 * second time — the engine would create `tipe_status_aktif_tersedia_telemedisin`
 * alongside it and the verifier would report the extra index as drift.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER` that
 * `docs/migration-order.md` rule 5 mandates: Laravel 13 has no Blueprint helper
 * for it, and `dokter` is one of only 16 tables with a `dibuat_at`/`diubah_at`
 * pair. There is **no** `dihapus_at` on this table — only `users` and `pasien`
 * get soft deletes — so `$table->softDeletes()` must not be used.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dokter', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // One doctor row per user account, enforced by the UNIQUE index.
            $table->unsignedBigInteger('user_id')->unique();
            $table->enum('tipe', [
                'dokter_umum',
                'dokter_spesialis',
                'dokter_gigi',
                'psikolog',
                'bidan',
                'perawat',
                'apoteker',
            ]);
            $table->string('nomor_str', 30)->unique();
            $table->date('str_berlaku_sampai');
            $table->string('nomor_sip', 50)->nullable();
            $table->date('sip_berlaku_sampai')->nullable();
            $table->string('nomor_ihs_satusehat', 50)->nullable();

            // UNSIGNED, and the flag is load-bearing: signed would admit -1.
            $table->unsignedSmallInteger('pengalaman_tahun')->default(0);
            $table->text('bio')->nullable();

            // DECIMAL(12,2) money, unlike the DECIMAL(3,2) rating below.
            $table->decimal('biaya_konsultasi_online', 12, 2)->default(0);
            $table->decimal('biaya_luar_jam', 12, 2)->nullable();

            // UNSIGNED again - this is the column the plan's failure QA probes.
            $table->unsignedSmallInteger('durasi_default_menit')->default(15);

            // Narrow on purpose: a 0.00-9.99 scale is all a 0-5 rating needs.
            $table->decimal('rating_rata_rata', 3, 2)->default('0.00');
            $table->unsignedInteger('jumlah_ulasan')->default(0);
            $table->unsignedInteger('jumlah_konsultasi')->default(0);
            $table->boolean('tersedia_telemedisin')->default(true);
            $table->enum('status_verifikasi', ['pending', 'terverifikasi', 'ditolak'])
                ->default('pending');
            $table->string('file_str_url', 500)->nullable();
            $table->string('file_sip_url', 500)->nullable();
            $table->boolean('status_aktif')->default(true);
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users');

            // Named in the DDL, so compared by name. Do not reorder or re-add.
            $table->index(['tipe', 'status_aktif', 'tersedia_telemedisin'], 'idx_dokter_tipe');
        });

        DB::statement('ALTER TABLE dokter MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dokter');
    }
};
