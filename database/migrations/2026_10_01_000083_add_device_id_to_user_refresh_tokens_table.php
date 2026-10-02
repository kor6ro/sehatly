<?php

declare(strict_types=1);

use App\Services\Auth\TokenService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F01 — adds `user_refresh_tokens.device_id` and `idx_refresh_device`, the
 * owner-approved mapping that makes per-device revocation expressible.
 *
 * ## What was wrong
 *
 * `user_refresh_tokens` (`telemedicine_test.sql:204-212`) recorded no device, so a
 * token could only be revoked by presenting its own secret
 * ({@see TokenService::revoke()}) or by revoking every session an
 * account had ({@see TokenService::revokeAllForUser()}). The
 * device list therefore could not deliver the promise of `/profil/perangkat`:
 * `DELETE /auth/devices/{deviceId}` deactivated the `user_devices` row but left that
 * device's refresh token live, so the signed-out installation could rotate the token
 * and stay signed in, and the next `POST /auth/devices` from it reactivated the row.
 * `docs/schema-notes.md` recorded the limitation under "Batch-B schema limitations"
 * as an omission a later reader must **not** "fix" against the frozen DDL — which is
 * exactly why this migration exists only alongside the owner decision below.
 *
 * ## The owner decision (F01, 2026-10-02)
 *
 * The owner approved the column for F01's device-management step ("Token↔device
 * mapping"). It is:
 *
 * - **nullable** — legacy rows hold no value and none may be invented; `NULL` is the
 *   honest reading, and revocation by device simply never matches those rows;
 * - **`VARCHAR(255)`** — the exact width of `user_devices.device_id`
 *   (`telemedicine_test.sql:193`), because the value is the same client-supplied
 *   installation identifier, not a new vocabulary;
 * - **indexed** by `idx_refresh_device` — every revoke is
 *   `WHERE user_id = ? AND device_id = ?`, and the table previously had no index
 *   beyond the primary key and the engine's implicit FK-support index on `user_id`.
 *
 * There is deliberately **no foreign key** to `user_devices`: the unique key there is
 * `(user_id, device_id)` (`:201`), a device row is deactivated rather than deleted,
 * and a token must not become unrevivable because its device row moved. The pairing
 * is an application invariant enforced by `TokenService`, not a constraint.
 *
 * ## The reference-SQL edit, and why it does not shift a line
 *
 * `telemedicine_test.sql` is cited by line number in dozens of docblocks and tests,
 * and `TokenAuditPdpTest` pins the file at **1,364 lines**. Adding a physical line to
 * the `user_refresh_tokens` body would shift every `CREATE TABLE` after it, so the
 * two new declarations are folded onto existing lines instead:
 *
 * ```sql
 * :206  user_id BIGINT UNSIGNED NOT NULL, device_id VARCHAR(255) NULL,
 * :211  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_refresh_device (device_id)
 * ```
 *
 * `SqlSchemaParser` splits a table body on top-level commas, not on newlines, so the
 * model it builds is identical to the one a multi-line spelling would build, and
 * `sehatly:verify-schema` compares semantics rather than layout. That is why the
 * frozen file can carry the new column without a single citation moving — the same
 * discipline F02 used when it turned `:1144` into a comment instead of deleting it.
 *
 * `down()` drops the index first and then the column. A database migrated before
 * this change has neither; a fresh `migrate:fresh` never creates them in migration
 * `2026_10_01_000019` (which is deliberately left untouched), so this migration is
 * the only creator in both cases and needs no `hasColumn()` guard.
 *
 * @see TokenService for the issue / rotate / revoke write paths
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_refresh_tokens', function (Blueprint $table): void {
            // NULL means "minted before the mapping existed", never a made-up value.
            // `after('user_id')` mirrors the reference DDL's declaration order; the
            // verifier does not compare column order, but the live table then reads
            // like the file.
            $table->string('device_id', 255)->nullable()->after('user_id');

            // Named like `uq_device` and `idx_pasien_lahir`: a DDL-written name is
            // compared by name, so both sides must spell it identically.
            $table->index('device_id', 'idx_refresh_device');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_refresh_tokens', function (Blueprint $table): void {
            $table->dropIndex('idx_refresh_device');
        });

        Schema::table('user_refresh_tokens', function (Blueprint $table): void {
            $table->dropColumn('device_id');
        });
    }
};
