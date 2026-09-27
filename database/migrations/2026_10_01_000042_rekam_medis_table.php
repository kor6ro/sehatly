<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 42 of 75 — `telemedicine_test.sql:621-655`.
 *
 * First table of batch G, and the hub the four sub-tables below it hang off. **28
 * columns**, four foreign keys and one named index. The body is `:622-654` (33
 * lines); no ENUM wraps onto a second line here, so 33 = 28 columns + 4
 * `FOREIGN KEY` lines + 1 `INDEX` line. Measured with the project's own
 * `SqlSchemaParser`, it is the **second**-widest table in the schema: behind
 * `pasien` (31 columns, table 20) and ahead of `dokter` (23, table 31), with
 * `booking` (22, table 37) fourth.
 *
 * **(a) TRAP — `status_dokumen` DEFAULTS TO `'final'`, NOT `'draft'` (`:645`).**
 * The DDL reads
 *
 *     status_dokumen ENUM('draft','final','diamendemen') NOT NULL DEFAULT 'final',
 *
 * three values in that exact order, and the default is the **second** of the
 * three, not the first. `draft` sorts first because ENUM member order is the sort
 * index, which makes the default easy to mis-transcribe as `draft` on a fast
 * read; live `information_schema.COLUMNS.COLUMN_DEFAULT` is the string `final`.
 *
 * **Consequence, and it is the whole point of this note: todo 33's record service
 * MUST pass `status_dokumen` explicitly on create.** A record inserted without the
 * column lands in the immutable `final` state, and the schema offers no way back
 * other than writing `diamendemen` — there is no `updated_at`-gated edit
 * permission and no trigger — so a mistake at creation time is not recoverable by
 * omitting the field again. Anything that creates records in bulk (seeders,
 * importers, the todo 32 consultation-completion path) inherits the same trap.
 *
 * **(b) TRAP — `versi` IS AN AMENDMENT COUNTER WITH NO LINKAGE COLUMN (`:646`).**
 * `versi TINYINT UNSIGNED NOT NULL DEFAULT 1` is the amendment counter and the
 * statement declares **four** `FOREIGN KEY` clauses (`:650`-`:653`, on
 * `pasien_id`, `faskes_id`, `dokter_id`, `konsultasi_id`) and **not one** of them
 * is a self-reference: there is no `parent_id`, no `rekam_medis_id`, no
 * `amends_id`, and no self-referencing `FOREIGN KEY` on `id`. **The amendment
 * chain is therefore a convention, not a constraint, and the database cannot
 * enforce it in any direction:**
 *
 *   - it cannot stop two rows both being `versi = 1`;
 *   - it cannot stop two rows both being `versi = 1, status_dokumen = 'final'`;
 *   - it cannot stop `versi = 5` existing with no `versi = 4`;
 *   - it cannot stop a chain from being written out of order.
 *
 * The chain must be reconstructed by grouping on
 * `(pasien_id, dokter_id, tanggal_periksa)` — the triple above is the only thing
 * the schema holds constant across versions of one record, and it is a
 * **convention**: nothing in the database guarantees that two rows sharing it are
 * versions of the same record, so a doctor who examines the same patient twice on
 * the same `tanggal_periksa` is representable and would be misread as a second
 * version of the first. `konsultasi_id` (`:627`, nullable) is a *better* thread
 * key when it is present, because a consultation is one encounter, but it is
 * nullable, so it cannot be the only key. Todo 19's model docblock and
 * `docs/schema-notes.md` both carry this; it is todo 33 that owns the write
 * path.
 *
 * `versi` is declared `TINYINT UNSIGNED`, so the signed `tinyInteger()` helper is
 * wrong twice: it would emit a signed column, and it would admit a negative
 * version number. `unsignedTinyInteger()` is the only correct call.
 *
 * **(c) The SOAP block is `subjektif` / `objektif` / `asesmen` / `plan`
 * (`:637`-`:640`) — `plan`, not `rencana`, and `asesmen`, not `assesmen`.** All
 * four are `TEXT NULL`, all four carry the DDL's own `COMMENT` (`SOAP - S`,
 * `SOAP - O`, `SOAP - A`, `SOAP - P`), copied verbatim below per rule 12. They
 * are a **different vocabulary** from `konsultasi`'s four SOAP columns
 * (`:548`-`:551`), which are `catatan_subjektif`, `catatan_objektif`,
 * `catatan_asessment` (one `s`) and `catatan_plan` — a `catatan_` prefix and a
 * different `a`-spelling on both tables. Nothing in the schema couples the two
 * sets, so `rekam_medis` is the authoritative copy and todo 32 must write the
 * consultation-level four as a denormalised copy at completion time. There is no
 * trigger, so a partial write is representable.
 *
 * The six-column narrative block immediately above the SOAP block is
 * `:631`-`:636`, in this order: `keluhan_utama` (`:631`),
 * `riwayat_penyakit_sekarang` (`:632`), `riwayat_penyakit_dahulu` (`:633`),
 * `riwayat_keluarga` (`:634`), `riwayat_psikososial` (`:635`),
 * `hasil_pemeriksaan_fisik` (`:636`). **Only four of those six are named
 * `riwayat_*`** — `riwayat_penyakit_sekarang`, `riwayat_penyakit_dahulu`,
 * `riwayat_keluarga`, `riwayat_psikososial` — the other two being the presenting
 * complaint and the physical-exam result. All six are `TEXT NULL`.
 * `riwayat_psikososial` is the only one of the six with a DDL comment,
 * `'Merokok, alkohol, aktivitas fisik'` (`:635`), copied verbatim below.
 *
 * **(d) `satusehat_encounter_id VARCHAR(50) NULL` (`:628`) IS A BARE EXTERNAL
 * IDENTIFIER, AND NO MIGRATION MAY CONSTRAIN IT.** It is a SATUSEHAT encounter id
 * — a string minted by a national health system outside this schema — so a
 * foreign key would be meaningless even if a lookup table existed, and none does.
 * It is the only reference-shaped column on this table with no constraint, and
 * it is **not** named in the plan's todo-13 prose at all, which is why it is
 * recorded here. The width is `VARCHAR(50)`, deliberately different from this
 * table's own `uuid CHAR(36)` (`:623`); the DDL states no reason for the 50, so
 * the SATUSEHAT-width reading is an inference, not a verified fact.
 *
 * `uuid CHAR(36) NOT NULL UNIQUE` (`:623`) is `CHAR`, **not** `VARCHAR`, and the
 * distinction is not cosmetic: `CHAR(36)` is fixed-width and MySQL pads and
 * strips trailing spaces on comparison and retrieval, so it is the right type for
 * a canonical 36-character UUID. `VARCHAR(36)` would store the same value but
 * compare differently for anything with trailing whitespace. Reproduced with
 * `char('uuid', 36)`. The `UNIQUE` is **inline** — the DDL writes no `UNIQUE KEY`
 * name — so per `docs/migration-order.md` rule 10 the two spellings are the same
 * constraint: importing the DDL directly makes MySQL name the index `uuid`,
 * while `$table->unique()` (the idiomatic call used below) makes Laravel name it
 * `rekam_medis_uuid_unique`, which is the name the live schema actually shows.
 * The verifier compares an inline `UNIQUE` by semantics, so both are correct.
 *
 * **(e) `tanggal_periksa` is `DATETIME` (`:630`), not `TIMESTAMP`, and the batch
 * has exactly four `DATETIME` columns, three of them `NOT NULL`** — this one,
 * `rekam_medis_tindakan.tanggal_tindakan` (`:675`) and
 * `rekam_medis_persetujuan.ditandatangani_at` (`:700`). The only nullable
 * `DATETIME` in the batch is this table's own `ditandatangani_at` (`:647`).
 * `jadwal_kontrol` (`:644`) is a `DATE`, not a `DATETIME`, and nothing in the
 * batch is a `TIMESTAMP` except the two `dibuat_at`/`diubah_at` columns here and
 * `rekam_medis_lampiran.dibuat_at` (`:688`).
 * `DATETIME` versus `TIMESTAMP` is a real type difference in MySQL — the
 * `TIMESTAMP` columns carry an implicit `NOT NULL DEFAULT CURRENT_TIMESTAMP` and
 * a 2038 ceiling — so the verifier reports a swap. Declared with
 * `$table->dateTime(...)`, never `timestamp(...)`.
 *
 * `tipe_kunjungan` is a **five**-value ENUM in the DDL's exact order (`:629`) —
 * `telemedisin`, `rawat_jalan`, `rawat_inap`, `igd`, `home_visit` — and it has
 * **no** `DEFAULT` clause, so the column is genuinely required at insert time.
 * `telemedisin` sorts first and is the only telemedicine-specific member; `igd`
 * and `rawat_inap` are the two members that make this a five-value list rather
 * than a telemedicine/clinic pair. `status_tindak_lanjut` (`:643`) is a
 * separate **five**-value ENUM in its own order — `pulang_dengan_obat`,
 * `kontrol`, **`rujuk`**, `rawat_inap`, `ke_igd` — the third value is spelled
 * with a **k**, and the plan's todo-13 prose says `rujak`, which the DDL does
 * not — and it is **nullable with no
 * default**, so a record with no follow-up simply leaves it `NULL`. Note
 * `rawat_inap` appears in **both** lists with different meanings: as a visit type
 * and as a follow-up action, and the two are not linked by anything.
 *
 * **(f) The four foreign keys carry no `ON DELETE` clause (`:650`-`:653`)**, so
 * each materialises MySQL's implicit `NO ACTION` — `RESTRICT` for DML. All four
 * targets exist long before this migration runs: `pasien` is table 20 (batch C),
 * `faskes` is 28 and `dokter` is 31 (both batch D), `konsultasi` is 38 (batch F,
 * immediately before this batch). **Nothing here is deferred**, so the
 * *Deferred constraints* registry in `docs/schema-notes.md` gains no row and
 * still holds exactly its one `fk_vital_rm` entry, which belongs to migration
 * `2026_10_01_000076` per SQL section `[14]` (`:1161-1163`) and is **not** this
 * batch's to add.
 *
 * `INDEX idx_rm_pasien (pasien_id, tanggal_periksa)` (`:654`) is the only
 * name-bearing key besides the primary key and the inline `UNIQUE`, so it is
 * compared **by name** on `(TABLE_NAME, INDEX_NAME)` and its column order is
 * part of the contract: `pasien_id` first, `tanggal_periksa` second. It is the
 * "this patient's records over time" access path, and the `pasien_id` leftmost
 * position is what makes the per-patient history an index range scan. Do not
 * reorder it and do not add anything to it.
 *
 * `dibuat_at` and `diubah_at` are both present (`:648`-`:649`), and `diubah_at`
 * carries `ON UPDATE CURRENT_TIMESTAMP`, so this table is one of only 16 with a
 * `dibuat_at`/`diubah_at` pair and needs the raw `ALTER` that
 * `docs/migration-order.md` rule 5 mandates — Laravel 13 has no Blueprint helper
 * for it. There is **no** `dihapus_at` here: only `users` (`:148`) and `pasien`
 * (`:249`) get soft deletes, so `$table->softDeletes()` must not be used. Nothing
 * in the schema *prevents* a hard `DELETE` of a record, and the four sub-tables'
 * `ON DELETE CASCADE` will take the entire clinical history with it — so
 * hard-deletion is a last resort, and the amendment flow (todo 33) is the only
 * non-destructive alternative the schema offers.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rekam_medis', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // CHAR(36), NOT VARCHAR(36) - fixed-width, so it pads/strips on
            // comparison. The DDL writes the UNIQUE inline (:623) with no
            // UNIQUE KEY name, so importing the SQL directly makes MySQL name
            // the index `uuid`; rule 10 compares an inline UNIQUE by semantics,
            // so ->unique() is correct and yields `rekam_medis_uuid_unique`.
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('pasien_id');
            $table->unsignedBigInteger('faskes_id')->nullable();
            $table->unsignedBigInteger('dokter_id');
            $table->unsignedBigInteger('konsultasi_id')->nullable();

            // NO FOREIGN KEY, BY CONTRACT, and NOT NAMED IN THE PLAN'S TODO-13
            // PROSE AT ALL. :628 is a bare nullable VARCHAR(50) holding a
            // SATUSEHAT encounter id - a string minted by a national health
            // system outside this schema. There is no lookup table to point at,
            // and adding one would be extra_foreign_key drift. See (d).
            $table->string('satusehat_encounter_id', 50)->nullable();

            // FIVE values, in the DDL's exact order (:629), and NO default: the
            // DDL declares no DEFAULT clause, so the column is required at
            // insert. `igd` and `rawat_inap` are why this is five, not two.
            $table->enum('tipe_kunjungan', [
                'telemedisin',
                'rawat_jalan',
                'rawat_inap',
                'igd',
                'home_visit',
            ]);

            // DATETIME, NOT TIMESTAMP. A real type difference in MySQL (implicit
            // NOT NULL + 2038 ceiling on TIMESTAMP), and the verifier reports a
            // swap. See (e).
            $table->dateTime('tanggal_periksa');

            // The six-column narrative block, :631-:636, in DDL order. Only FOUR
            // of the six are named riwayat_* - keluhan_utama and
            // hasil_pemeriksaan_fisik are the other two. All six are TEXT NULL.
            // riwayat_psikososial's DDL comment (:635) is copied verbatim.
            $table->text('keluhan_utama')->nullable();
            $table->text('riwayat_penyakit_sekarang')->nullable();
            $table->text('riwayat_penyakit_dahulu')->nullable();
            $table->text('riwayat_keluarga')->nullable();
            $table->text('riwayat_psikososial')->nullable()->comment('Merokok, alkohol, aktivitas fisik');
            $table->text('hasil_pemeriksaan_fisik')->nullable();

            // The four SOAP columns, :637-:640. `plan`, not `rencana`; `asesmen`,
            // not `assesmen`. TEXT NULL (all four), so the verifier flags any of
            // them emitted as NOT NULL. A different vocabulary from
            // konsultasi's four (:548-:551). DDL comments copied verbatim (rule 12).
            $table->text('subjektif')->nullable()->comment('SOAP - S');
            $table->text('objektif')->nullable()->comment('SOAP - O');
            $table->text('asesmen')->nullable()->comment('SOAP - A');
            $table->text('plan')->nullable()->comment('SOAP - P');

            // Short working-diagnosis label (:641), nullable. The same label is
            // copied three times in the schema - here (:641), on konsultasi
            // (:552) and on rujukan (:605) - and nothing keeps any of them in
            // step. Note surat_keterangan does NOT carry it, despite a sibling
            // batch-F migration's comment claiming that it does.
            $table->string('diagnosis_kerja', 255)->nullable();
            $table->text('instruksi_tindak_lanjut')->nullable();

            // FIVE values in their own order (:643), NULLABLE with no default,
            // so a record with no follow-up leaves it NULL. The third value is
            // 'rujuk' (referral) with a K, exactly as the DDL spells it - the
            // plan's todo-13 prose says 'rujak' and is wrong. `rawat_inap` means
            // something different here than in tipe_kunjungan above.
            $table->enum('status_tindak_lanjut', [
                'pulang_dengan_obat',
                'kontrol',
                'rujuk',
                'rawat_inap',
                'ke_igd',
            ])->nullable();

            // DATE, not DATETIME and not TIMESTAMP (:644).
            $table->date('jadwal_kontrol')->nullable();

            // TRAP (a). THREE values in the DDL's exact order and the default is
            // 'final' - the SECOND member, not the first. Anything that omits this
            // column on insert lands immutable. Todo 33 MUST pass it explicitly.
            // See the class docblock (a).
            $table->enum('status_dokumen', ['draft', 'final', 'diamendemen'])->default('final');

            // TRAP (b). The amendment counter. UNSIGNED, so a negative version is
            // impossible. There is no parent/amendment-linkage column anywhere in
            // the statement, so the chain is reconstructed by grouping on
            // (pasien_id, dokter_id, tanggal_periksa). See the class docblock (b).
            $table->unsignedTinyInteger('versi')->default(1);

            // DATETIME NULL (:647) - NULL means "not signed yet". The DDL states
            // no reason, so read it as the signature time being absent rather
            // than as a rule tying it to status_dokumen.
            $table->dateTime('ditandatangani_at')->nullable();

            // Both timestamp columns exist (:648-:649), so this table IS one of
            // the 16 that needs rule 5's raw ON UPDATE ALTER below.
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // No ON DELETE clause on any of the four (DDL :650-:653) -> implicit
            // RESTRICT. All four targets pre-date this batch. Nothing deferred.
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('faskes_id')->references('id')->on('faskes');
            $table->foreign('dokter_id')->references('id')->on('dokter');
            $table->foreign('konsultasi_id')->references('id')->on('konsultasi');

            // Named in the DDL, so compared by name. Column order is the
            // contract: pasien_id first, tanggal_periksa second. See (f).
            $table->index(['pasien_id', 'tanggal_periksa'], 'idx_rm_pasien');
        });

        DB::statement('ALTER TABLE rekam_medis MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rekam_medis');
    }
};
