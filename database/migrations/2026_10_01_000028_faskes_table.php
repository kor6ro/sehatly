<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 28 of 75 — `telemedicine_test.sql:360-386`.
 *
 * First table of batch D and the first table in the project whose `id` is
 * referenced by an **earlier** migration: `pasien_penjamin.faskes_rujukan_id`
 * (`:346`, batch C) was deliberately created as a bare nullable `BIGINT UNSIGNED`
 * with no foreign key, because the DDL declares none — see *Batch-C deferred and
 * deliberately unconstrained columns* in `docs/schema-notes.md` and Appendix A.10
 * / A.11 of the plan. That column's bareness is **not** an ordering artefact and
 * is **not** owed a constraint later: migration `2026_10_01_000076` must not add
 * one, and this migration must not add one either. Only `fk_vital_rm`
 * (`pasien_tanda_vital.rekam_medis_id`) is a genuinely deferred constraint in the
 * whole project, and it stays exactly as it is.
 *
 * Three decisions here are not expressible by reaching for the obvious helper:
 *
 *  1. **The two geo columns have different scales, and both matter.**
 *     `latitude DECIMAL(10,8)` (`:372`) and `longitude DECIMAL(11,8)` (`:373`).
 *     Laravel's `decimal()` takes `(precision, scale)`, so writing
 *     `decimal('longitude', 10, 8)` "harmonises" them and silently breaks parity
 *     on longitude only — the wider of the pair is also the one whose 11 digits
 *     are required for three decimal places at 180 degrees.
 *  2. **`akreditasi` is spelled `paripurna`,** and that spelling is the DDL's,
 *     not a transcription. `:376` reads exactly
 *     `ENUM('belum','dasar','utama','maju','paripurna') NULL`. ENUM member order
 *     is the sort index, so the whole five-value list is reproduced in `:376`'s
 *     order.
 *  3. **`jam_operasional` is `JSON`, not `TEXT`.** `:377` declares
 *     `JSON NULL`; `$table->json()` is the only correct builder and `text` would
 *     read as a `column_type` difference. The model-side `array` cast belongs to
 *     todo 19 and is not this migration's business.
 *
 * **Both composite indexes are explicitly named in the DDL and are compared by
 * name** on `(TABLE_NAME, INDEX_NAME)` — `INDEX idx_faskes_tipe (tipe,
 * status_aktif)` (`:384`) and `INDEX idx_faskes_geo (latitude, longitude)`
 * (`:385`) — so both are declared with `->index([...], 'name')` rather than
 * left to the builder. `idx_faskes_tipe` is also the index that makes todo 22's
 * `GET /dokter`-style facility filtering cheap, and `idx_faskes_geo` is what
 * makes a bounding-box query on the pair cheap. Neither may be added twice: the
 * engine would create `tipe_status_aktif` alongside `idx_faskes_tipe` and the
 * verifier would report the extra index.
 *
 * The three master FKs all resolve to tables that already exist from batch A, so
 * **nothing in this migration is deferred** and no row is added to the
 * *Deferred constraints* registry. `provinsi_id` is `TINYINT UNSIGNED`,
 * `kabupaten_kota_id` and `kecamatan_id` are `SMALLINT UNSIGNED` — the widths
 * must match the referenced `id` columns exactly or InnoDB rejects the
 * constraint, so `foreignId()` (which emits `BIGINT UNSIGNED`) is wrong for all
 * three. None of the three has a covering index in the DDL, so each relies on
 * InnoDB's implicit FK-support index, which the verifier's implied-index rule
 * forgives; do not add one by hand.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER` that
 * `docs/migration-order.md` rule 5 mandates: Laravel 13 has no Blueprint helper
 * for it, and `faskes` is one of only 16 tables with a `dibuat_at`/`diubah_at`
 * pair.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('faskes', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();
            $table->string('kode_faskes', 20)->nullable()->unique()
                ->comment('Kode faskes BPJS/Kemenkes');
            $table->string('satusehat_org_id', 50)->nullable();
            $table->string('nama', 200);
            $table->enum('tipe', ['rumah_sakit', 'klinik', 'puskesmas', 'apotek', 'laboratorium']);
            $table->string('kelas_rs', 50)->nullable()
                ->comment('A/B/C/D (khusus rumah sakit)');
            $table->text('alamat');

            // Widths mirror the referenced masters' own `id` columns exactly:
            // master_provinsi.id is TINYINT UNSIGNED, master_kabupaten_kota.id and
            // master_kecamatan.id are SMALLINT UNSIGNED (batch A). `foreignId()`
            // would emit BIGINT UNSIGNED and InnoDB would reject the constraint.
            $table->unsignedTinyInteger('provinsi_id')->nullable();
            $table->unsignedSmallInteger('kabupaten_kota_id')->nullable();
            $table->unsignedSmallInteger('kecamatan_id')->nullable();
            $table->char('kode_pos', 5)->nullable();

            // Two scales, deliberately different. Do not "harmonise" these.
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            $table->string('telepon', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->enum('akreditasi', ['belum', 'dasar', 'utama', 'maju', 'paripurna'])->nullable();
            $table->json('jam_operasional')->nullable()
                ->comment('{"senin":{"buka":"07:00","tutup":"22:00"},...}');
            $table->boolean('status_aktif')->default(true);
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            $table->foreign('provinsi_id')->references('id')->on('master_provinsi');
            $table->foreign('kabupaten_kota_id')->references('id')->on('master_kabupaten_kota');
            $table->foreign('kecamatan_id')->references('id')->on('master_kecamatan');

            // Both are named in the DDL, so both are compared by name.
            $table->index(['tipe', 'status_aktif'], 'idx_faskes_tipe');
            $table->index(['latitude', 'longitude'], 'idx_faskes_geo');
        });

        DB::statement('ALTER TABLE faskes MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faskes');
    }
};
