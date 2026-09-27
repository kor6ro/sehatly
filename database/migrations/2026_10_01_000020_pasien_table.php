<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 20 of 75 — `telemedicine_test.sql:218-256`.
 *
 * The widest table in batch C (31 columns, 5 foreign keys, 1 named index), and the
 * only one in this batch with all three of the schema's timestamp shapes at once.
 * Four decisions here are not expressible by reaching for the obvious helper:
 *
 *  1. **`dihapus_at` is declared with `$table->softDeletes('dihapus_at')`.**
 *     `:249` says `TIMESTAMP NULL DEFAULT NULL`. `Blueprint::softDeletes()` is
 *     `timestamp($column)->nullable()` in this framework version, so it emits
 *     exactly `timestamp NULL`. A hand-rolled `$table->dateTime('dihapus_at')`
 *     emits `datetime`, which is *the* parity break this call exists to prevent,
 *     and the verifier would report it as a `column_type` difference. Same rule as
 *     `users` in `2026_10_01_000012_users_table.php`.
 *  2. **The four master FKs are `TINYINT UNSIGNED`, not `BIGINT UNSIGNED`.**
 *     `$table->foreignId('golongan_darah_id')` would emit `BIGINT UNSIGNED` and
 *     InnoDB would reject the constraint outright, because the referencing column
 *     type must match the referenced `master_golongan_darah.id` exactly. They are
 *     therefore declared with `unsignedTinyInteger()` + an explicit `foreign()`,
 *     matching `master_agama.id`, `master_pendidikan.id` and
 *     `master_status_pernikahan.id` (all `TINYINT UNSIGNED`, batch A).
 *  3. **The address block carries NO foreign keys.** `provinsi_id`,
 *     `kabupaten_kota_id`, `kecamatan_id` and `kelurahan_id` (`:235-238`) look
 *     like references to the four `master_*` wilayah tables and are not, even
 *     though those tables already exist at this point in the order. Reproduced as
 *     bare nullable unsigned columns; the SQL has no `FOREIGN KEY` line for any
 *     of them, and adding one would be a parity break, not a nicety. Note the
 *     widths differ per level — TINYINT / SMALLINT / SMALLINT / MEDIUMINT — and
 *     mirror `master_kelurahan.id`'s MEDIUMINT, so they still line up if todo 18
 *     or a later reader ever needs to index them.
 *  4. **The two DECIMAL columns have different scales and both matter.**
 *     `tinggi_badan_cm DECIMAL(5,1)` (`:243`) and `berat_badan_kg DECIMAL(5,2)`
 *     (`:244`). Laravel's `decimal()` takes (precision, scale), so writing
 *     `decimal('tinggi_badan_cm', 5, 2)` "harmonises" them and silently breaks
 *     parity on the *height* column only — the one a reader is least likely to
 *     look at. This is the exact drift the plan's failure QA probes.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER` that
 * `docs/migration-order.md` rule 5 mandates: Laravel 13 has no Blueprint helper
 * for it, and `pasien` is one of only 16 tables with a `dibuat_at`/`diubah_at`
 * pair. `INDEX idx_pasien_lahir (tanggal_lahir)` (`:255`) is declared with its
 * SQL name because named keys are compared by name on `(TABLE_NAME, INDEX_NAME)`.
 *
 * `nik CHAR(16) NULL UNIQUE` (`:222`) is reproduced **exactly as declared, without
 * widening it**, even though the column cannot hold ciphertext (see the plan's
 * "Must NOT have" list): AES-256-CBC of a 16-byte plaintext is 32 bytes, which
 * does not fit `CHAR(16)` and raises MySQL 1406 in strict mode. Todo 50 owns the
 * fix (ciphertext in a `TEXT` column plus a fixed-width 16-char HMAC carrying the
 * unique index). "Helping" here would be an undocumented, unrequested schema
 * change, and the deterministic-encryption design in todo 50 depends on this
 * column being left alone.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pasien', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('nomor_rm', 20)->nullable()->unique()
                ->comment('Nomor rekam medis aplikasi: RM-YYYYMM-XXXXXX');
            $table->char('nik', 16)->nullable()->unique()
                ->comment('WAJIB dienkripsi (application-level/TDE) sesuai UU PDP');
            $table->char('nomor_kk', 16)->nullable();
            $table->string('nomor_ihs_satusehat', 50)->nullable()->unique()
                ->comment('Nomor Induk Satu Sehat (Kemenkes)');
            $table->enum('jenis_kelamin', ['L', 'P']);
            $table->date('tanggal_lahir');
            $table->string('tempat_lahir', 100)->nullable();

            // TINYINT UNSIGNED, matching the four master tables' own `id` width.
            // `foreignId()` here would be a parity break *and* an InnoDB error.
            $table->unsignedTinyInteger('golongan_darah_id')->nullable();
            $table->enum('rhesus', ['positif', 'negatif', 'tidak_diketahui'])
                ->default('tidak_diketahui');
            $table->unsignedTinyInteger('agama_id')->nullable();
            $table->unsignedTinyInteger('pendidikan_id')->nullable();
            $table->string('pekerjaan', 100)->nullable();
            $table->unsignedTinyInteger('status_pernikahan_id')->nullable();
            $table->text('alamat_lengkap');

            // Address block. The SQL declares no FOREIGN KEY for any of these
            // four, so none is added here - see the class docblock.
            $table->unsignedTinyInteger('provinsi_id')->nullable();
            $table->unsignedSmallInteger('kabupaten_kota_id')->nullable();
            $table->unsignedSmallInteger('kecamatan_id')->nullable();
            $table->unsignedMediumInteger('kelurahan_id')->nullable();
            $table->string('rt', 5)->nullable();
            $table->string('rw', 5)->nullable();
            $table->char('kode_pos', 5)->nullable();
            $table->text('catatan_alergi')->nullable();

            // Two scales, deliberately different. Do not "harmonise" these.
            $table->decimal('tinggi_badan_cm', 5, 1)->nullable();
            $table->decimal('berat_badan_kg', 5, 2)->nullable();

            $table->boolean('is_meninggal')->default(false);
            $table->date('tanggal_meninggal')->nullable();
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();
            $table->softDeletes('dihapus_at');

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('golongan_darah_id')->references('id')->on('master_golongan_darah');
            $table->foreign('agama_id')->references('id')->on('master_agama');
            $table->foreign('pendidikan_id')->references('id')->on('master_pendidikan');
            $table->foreign('status_pernikahan_id')->references('id')->on('master_status_pernikahan');
            $table->index('tanggal_lahir', 'idx_pasien_lahir');
        });

        DB::statement('ALTER TABLE pasien MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pasien');
    }
};
