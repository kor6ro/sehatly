<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL table 37 of 75 — `telemedicine_test.sql:498-530`.
 *
 * Last table of batch E, 22 columns (`:499`-`:521` is 23 lines, and the two-line
 * `status` ENUM at `:515-516` is one column, so 23 - 1 = 22), six foreign keys and
 * two indexes the DDL names.
 * The plan calls this the table where the spec and the schema collide; item (a)
 * below is the one genuine collision, and (b)-(f) are the declarations that carry
 * it. The DDL is read-only law; where the spec and the DDL disagree, the DDL wins
 * and the gap is documented here rather than closed by inventing a constraint.
 *
 * **(a) DOUBLE-BOOKING PREVENTION IS AN APPLICATION LOCK, NOT A UNIQUE INDEX.**
 * There is **no** unique index on `(dokter_id, tanggal_kunjungan, slot_mulai)`.
 * The only two keys the DDL names are `idx_booking_dokter (dokter_id,
 * tanggal_kunjungan)` (`:528`) and `idx_booking_pasien (pasien_id, status)`
 * (`:529`) — both plain non-unique `INDEX` lines, read directly at those lines —
 * plus the inline `UNIQUE` on `nomor_booking` (`:500`) and the primary key. (MySQL
 * additionally creates four FK-support indexes of its own on
 * `anggota_keluarga_id`, `jadwal_id`, `faskes_id` and `dibuat_oleh_user_id`; those
 * are implied by the foreign keys and are not DDL-declared keys.) The
 * three-column unique **must not be added**: it would be `extra_index` drift, and
 * the acceptance criterion for this todo is precisely that
 * `SHOW CREATE TABLE booking` contains no unique index spanning those three
 * columns. A unique index is also the *wrong tool* here, for two independent
 * reasons that are worth stating so nobody "fixes" this later:
 *
 *   1. **It would make a legitimate re-booking impossible.** Cancellation is a
 *      state change on this table — `status` becomes `'dibatalkan'` (`:516`) and
 *      the row stays — so a patient who cancels and immediately re-books the same
 *      doctor, date and slot would collide with their own dead row. Re-booking is
 *      a normal flow, not an abuse case, and an unconditional unique index cannot
 *      distinguish "a live booking" from "a cancelled one".
 *   2. **The uniqueness this table needs is state-dependent, and MySQL has no
 *      partial index.** Occupancy is defined by the *exclusion set*
 *      `('dibatalkan', 'kadaluarsa')` — the six remaining `status` values are the
 *      live ones (see the split below). A constraint that only applies to rows
 *      outside the exclusion set is not expressible as a MySQL `UNIQUE`, which
 *      covers all rows unconditionally. Writing it as a plain unique would
 *      therefore be *both* drift and semantically wrong.
 *
 * So the strategy todo 27 must implement, and the one this migration is written to
 * hand forward, is a **`DB::transaction` closure that takes a pessimistic row
 * lock on the `dokter` row with `lockForUpdate()`** before the overlap query runs:
 *
 *     DB::transaction(function () use ($booking) {
 *         DB::table('dokter')->where('id', $booking->dokter_id)->lockForUpdate()->first();
 *         // ... then re-read this doctor's bookings for $tanggal_kunjungan and
 *         // reject any overlap that is not in ('dibatalkan', 'kadaluarsa').
 *     });
 *
 * The lock target is the **`dokter` row, not the `dokter_jadwal` row**, and that
 * is not a stylistic preference. `jadwal_id` is nullable (`:504`) while
 * `slot_mulai` and `slot_selesai` are both `NOT NULL` (`:508`-`:509`), so an
 * instant `chat` or `video_call` booking — the "Tanya Dokter" flow that needs no
 * scheduled slot — carries `jadwal_id = NULL` and has no `dokter_jadwal` row to
 * lock at all. The `dokter` row exists for every doctor, is the same row for
 * every competing booking at that instant, and locking it serialises all of them
 * correctly. `idx_booking_dokter`'s leftmost column `dokter_id` also means the
 * overlap re-read that follows the lock is an index range scan rather than a
 * table scan.
 *
 * **(b) `tipe_layanan` is a FOUR-value ENUM with NO DEFAULT** (`:506`):
 * `'chat'`, `'video_call'`, `'kunjungan_klinik'`, `'home_visit'`. `:506` writes
 * `NOT NULL` and no `DEFAULT` clause, so the column is genuinely required at
 * insert time and this migration adds no default — supplying one would be drift.
 * This vocabulary is **not** the same as `dokter_jadwal.tipe_layanan` (35), whose
 * three values are `'online'`, `'klinik'`, `'home_visit'` (`:474`): the two share
 * only `home_visit`, and `'klinik'` on this table is spelled
 * `'kunjungan_klinik'`. There is no mapping table, so translating a scheduled
 * service type into a booked one is hand-written service-layer code.
 *
 * **(c) `status` is an EIGHT-value ENUM** (`:515`-`:516`), in the DDL's exact
 * order — ENUM order is the sort index and is compared:
 * `menunggu_pembayaran`, `terjadwal`, `check_in`, `berlangsung`, `selesai`,
 * `dibatalkan`, `no_show`, `kadaluarsa`, `NOT NULL DEFAULT 'menunggu_pembayaran'`.
 * The count is **eight**, not seven; an earlier draft of the plan said seven and
 * was wrong. The eight split into two disjoint groups, and this split is what
 * drives availability and cancellation logic downstream:
 *
 *   - the **exclusion set** `('dibatalkan', 'kadaluarsa')` — the two states that
 *     release the slot, so a row in either does **not** block its doctor;
 *   - the **six live states** `('menunggu_pembayaran', 'terjadwal', 'check_in',
 *     'berlangsung', 'selesai', 'no_show')` — a row in any of these **does** occupy
 *     the slot, and every overlap check must use that exact six-value membership
 *     test rather than `status != 'dibatalkan'`, which would wrongly keep
 *     `kadaluarsa` rows blocking a slot forever.
 *
 * `dibatalkan_oleh ENUM('pasien','dokter','sistem') NULL` (`:517`) records who
 * cancelled and is nullable, so a `NULL` there means the booking was never
 * cancelled — it is not a fourth "unknown" actor, and it must not be used to
 * infer the status. Note that nothing in the schema couples `dibatalkan_oleh` or
 * `alasan_pembatalan` (`:518`) to `status = 'dibatalkan'`, so a well-formed
 * cancellation is a service-layer invariant.
 *
 * **(d) `is_rujukan` and `is_konsultasi_lanjutan` are `TINYINT(1) NOT NULL
 * DEFAULT 0`** (`:513`, `:514`) — both default to **`0`**, i.e. **false**, and both
 * are declared, so a booking that says nothing about referral or follow-up
 * carries neither. Per the spec this is the **no-automatic-discount** default: the
 * schema stores the two flags and computes nothing from them, so any discount
 * attached to a referral is pricing logic in the service layer, never a column
 * default, and it must be applied explicitly rather than inferred from the
 * absence of these flags.
 *
 * **(e) `lampiran_keluhan JSON NULL`** (`:512`) is declared with `$table->json()`,
 * never `$table->text()`: `text` would emit `text` and read as type drift, and it
 * would also lose JSON validation. It is nullable — a complaint with no
 * attachment is normal. The **`array` cast belongs to todo 19's `Booking` model**,
 * not to this migration: the column is batch E's, the cast is todo 19's.
 * `keluhan` immediately above it is `TEXT NULL` (`:511`) and is the free-text
 * complaint, so the two are distinct: prose in one, structured attachments in the
 * other.
 *
 * **(f) `dibuat_oleh_user_id BIGINT UNSIGNED NOT NULL` with a real foreign key to
 * `users(id)`** (`:519`, `:527`) — audit attribution for who created the booking.
 * `users` is table 12 from batch B and already migrated, so this FK is **not**
 * deferred. Note that three of the six FK columns on this table are `NOT NULL`
 * (`pasien_id` `:501`, `dokter_id` `:503` and `dibuat_oleh_user_id` `:519`) and
 * three are nullable (`anggota_keluarga_id` `:502`, `jadwal_id` `:504`,
 * `faskes_id` `:505`). The schema does not couple `faskes_id` to
 * `tipe_layanan`, so a `chat` or `video_call` booking carrying an `faskes_id` is
 * representable and rejecting it is a service-layer rule.
 *
 * `nomor_booking VARCHAR(30) NOT NULL UNIQUE` (`:500`) is an **inline** `UNIQUE`,
 * so MySQL names the index after the column while `$table->unique()` would name it
 * `booking_nomor_booking_unique`. Per `docs/migration-order.md` rule 10 the two are
 * the same constraint and the verifier compares it by semantics, not by name, so
 * the idiomatic `->unique()` is correct here. The index name is *not* free to
 * choose, though: only the two keys at `:528`-`:529` are name-bearing, and those
 * two spellings are compared on `(TABLE_NAME, INDEX_NAME)`. Do not reorder their
 * columns — `idx_booking_dokter` is `(dokter_id, tanggal_kunjungan)` and
 * `idx_booking_pasien` is `(pasien_id, status)`.
 *
 * `nomor_antrian SMALLINT UNSIGNED NULL` (`:510`) is unsigned and nullable, and
 * **unenforced**: there is no per-doctor/per-day counter and no uniqueness, so
 * duplicate queue numbers are representable. The unsigned flag only keeps a
 * negative number out. Assigning queue numbers is todo 27's service concern.
 *
 * The six foreign keys carry no `ON DELETE` clause in the DDL (`:522`-`:527`), so
 * every one of them materialises MySQL's implicit `NO ACTION` — `RESTRICT` for
 * DML — and is declared without a delete rule below. That is deliberate and
 * differs from `dokter_jadwal` (35), whose `dokter_id` cascades: a doctor or
 * patient referenced by a booking cannot be hard-deleted, which is a real
 * constraint the services must respect. All six
 * targets (`pasien` 20, `pasien_anggota_keluarga` 21, `dokter` 31, `faskes` 28,
 * `dokter_jadwal` 35 — this batch — and `users` 12) exist by the time this
 * migration runs, so **nothing here is deferred** and the *Deferred constraints*
 * registry in `docs/schema-notes.md` gains no row.
 *
 * `diubah_at` needs the raw `ON UPDATE CURRENT_TIMESTAMP` `ALTER` that
 * `docs/migration-order.md` rule 5 mandates: Laravel 13 has no Blueprint helper for
 * it, and this table is one of only 16 with a `dibuat_at`/`diubah_at` pair. There
 * is **no** `dihapus_at` here — only `users` (`:148`) and `pasien` (`:249`) get
 * soft deletes — so cancellation is a `status` value, not a soft delete, and
 * `$table->softDeletes()` must not be used.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('booking', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement()->primary();

            // Inline UNIQUE in the DDL (:500) -> compared by semantics, rule 10.
            $table->string('nomor_booking', 30)->unique();
            $table->unsignedBigInteger('pasien_id');

            // The DDL's own comment on :502, copied verbatim (rule 12).
            $table->unsignedBigInteger('anggota_keluarga_id')->nullable()->comment('NULL = untuk pasien sendiri');
            $table->unsignedBigInteger('dokter_id');

            // NULL for the instant chat / video_call flow, which has no scheduled
            // slot - and therefore no dokter_jadwal row to lock. See the class
            // docblock: the lock target is the `dokter` row, not this one.
            $table->unsignedBigInteger('jadwal_id')->nullable();
            $table->unsignedBigInteger('faskes_id')->nullable();

            // Four values, and NO default: :506 declares none.
            $table->enum('tipe_layanan', ['chat', 'video_call', 'kunjungan_klinik', 'home_visit']);
            $table->date('tanggal_kunjungan');
            $table->time('slot_mulai');
            $table->time('slot_selesai');

            // UNSIGNED and nullable; no per-day counter and no uniqueness exist.
            $table->unsignedSmallInteger('nomor_antrian')->nullable();
            $table->text('keluhan')->nullable();

            // json(), never text(). The `array` cast is todo 19's, not ours.
            $table->json('lampiran_keluhan')->nullable();

            // Both default to 0 = false: the spec's no-automatic-discount default.
            $table->boolean('is_rujukan')->default(false);
            $table->boolean('is_konsultasi_lanjutan')->default(false);

            // EIGHT values, in the DDL's exact order (:515-516). The exclusion set
            // is ('dibatalkan','kadaluarsa'); the other six are the live states.
            $table->enum('status', [
                'menunggu_pembayaran',
                'terjadwal',
                'check_in',
                'berlangsung',
                'selesai',
                'dibatalkan',
                'no_show',
                'kadaluarsa',
            ])->default('menunggu_pembayaran');
            $table->enum('dibatalkan_oleh', ['pasien', 'dokter', 'sistem'])->nullable();
            $table->string('alasan_pembatalan', 255)->nullable();
            $table->unsignedBigInteger('dibuat_oleh_user_id');
            $table->timestamp('dibuat_at')->useCurrent();
            $table->timestamp('diubah_at')->useCurrent();

            // No ON DELETE clause on any of the six (DDL :522-:527) -> implicit RESTRICT.
            $table->foreign('pasien_id')->references('id')->on('pasien');
            $table->foreign('anggota_keluarga_id')->references('id')->on('pasien_anggota_keluarga');
            $table->foreign('dokter_id')->references('id')->on('dokter');
            $table->foreign('jadwal_id')->references('id')->on('dokter_jadwal');
            $table->foreign('faskes_id')->references('id')->on('faskes');
            $table->foreign('dibuat_oleh_user_id')->references('id')->on('users');

            // Both named in the DDL, so compared by name. Do not reorder, and do
            // NOT add a unique on (dokter_id, tanggal_kunjungan, slot_mulai) -
            // see the class docblock for the lockForUpdate strategy instead.
            $table->index(['dokter_id', 'tanggal_kunjungan'], 'idx_booking_dokter');
            $table->index(['pasien_id', 'status'], 'idx_booking_pasien');
        });

        DB::statement('ALTER TABLE booking MODIFY diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking');
    }
};
