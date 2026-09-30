<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
use App\Support\NikMasker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY producer of `audit_log` rows.
 *
 * The table name lives in exactly one place in executable code: the `TABLE`
 * constant below. An architecture test scans every file under
 * `app/Http/Controllers`, `app/Services`, `app/Observers` and `app/Models`
 * (comments stripped, so prose citations do not count) and fails unless this
 * file is the sole mention, which is what makes "the observer is the only
 * producer" an enforced property rather than a comment.
 *
 * ## Why the query builder and not the AuditLog model
 *
 * `audit_log.user_id` (:1120) and `audit_log.record_id` (:1123) are
 * deliberately BARE - no foreign key, therefore no Eloquent relation - so the
 * log survives the deletion of the user and of the record it names. A row that
 * cannot be reached by association is written without association:
 * `DB::table()`, which also guarantees no model event can recurse back into the
 * observer. `dibuat_at` (:1129) is the table's only timestamp; the `AuditLog`
 * model already carries `UPDATED_AT = null`, and nothing in this class performs
 * an update or a delete of a log row, ever.
 *
 * ## What is stored on update: CHANGED KEYS, in both directions
 *
 * `data_baru` holds the sanitised changed attributes and `data_lama` the
 * previous values of those SAME keys - never two full snapshots. A row holding
 * both full snapshots of a patient record would DOUBLE the exposure of every
 * redacted field in an append-only table, and for an update it is doubly
 * pointless: the unchanged columns are by definition identical in both copies.
 * Each update row carries its own delta, so the full history is still
 * reconstructible across rows. On delete the whole sanitised row is the
 * before-image (the row is gone; a partial snapshot would lose forensic value)
 * and the after-image is null; on create the reverse. A row is written even
 * when sanitisation empties both payloads - a password-only change writes an
 * update row with NULL payloads, because the FACT of the change is
 * accountability and the secret is not.
 *
 * ## Redaction is the policy's job, not this class's
 *
 * Every rule lives in {@see AuditColumnPolicy}, which computes the allow-list
 * from the DDL and masks with the one {@see NikMasker}. This class
 * only decides what an EVENT means.
 *
 * @see AuditColumnPolicy
 * @see AuditObserver
 * @see AuditObserverRegistrar
 */
final class AuditLogWriter
{
    /**
     * The one executable mention of the table name in the codebase.
     */
    private const TABLE = 'audit_log';

    /**
     * The `aksi` values this writer can emit, mapped from the Eloquent event
     * that produced them.
     *
     * Each is asserted against the eight-member DDL ENUM parsed from
     * `telemedicine_test.sql`, so a value outside the schema fails the suite
     * rather than the INSERT.
     *
     * @var array<string, string> event => aksi
     */
    public const EVENT_AKSI = [
        'created' => 'create',
        'updated' => 'update',
        'deleted' => 'delete',
    ];

    /**
     * Every `aksi` this class writes, for the DDL check.
     *
     * `read`, `download` and `export` are deliberately absent. `read` is the
     * job of `akses_rekam_medis_log`, which has its own writer and its own
     * read-purpose ENUM; routing reads through here would double-log every
     * record access into a table readable under the broader audit permission.
     * `download` and `export` have no producer yet, and an observer must not
     * invent one.
     *
     * @var list<string>
     */
    public const AKSI = ['create', 'update', 'delete', 'login', 'logout'];

    /**
     * Record a creation. The before-image is null by definition.
     */
    public function recordCreate(Model $model): void
    {
        $this->write(
            'create',
            $model->getTable(),
            (string) $model->getKey(),
            null,
            AuditColumnPolicy::redact($model->getTable(), $model->getAttributes()),
        );
    }

    /**
     * Record an update as a delta: the sanitised CHANGED attributes, plus the
     * previous values of those same keys.
     *
     * `getChanges()` is the set of attributes the last `save()` actually
     * altered, which is what makes the delta a delta rather than a snapshot.
     * The row is written even when sanitisation empties the delta (a
     * password-only change): the fact of the change is the accountability
     * record.
     */
    public function recordUpdate(Model $model): void
    {
        $changed = $model->getChanges();
        $previous = [];

        foreach (array_keys($changed) as $key) {
            $previous[$key] = $model->getOriginal($key);
        }

        $table = $model->getTable();

        $this->write(
            'update',
            $table,
            (string) $model->getKey(),
            $this->nullable(AuditColumnPolicy::redact($table, $previous)),
            $this->nullable(AuditColumnPolicy::redact($table, $changed)),
        );
    }

    /**
     * Record a deletion. The whole sanitised row is the before-image - the row
     * is gone, so a partial snapshot would lose forensic value - and the
     * after-image is null.
     */
    public function recordDelete(Model $model): void
    {
        $this->write(
            'delete',
            $model->getTable(),
            (string) $model->getKey(),
            AuditColumnPolicy::redact($model->getTable(), $model->getAttributes()),
            null,
        );
    }

    /**
     * Record a session start.
     *
     * Called by the auth controller after a token pair is issued: the token API
     * has no session event to hook, so the write is explicit - through this
     * service, never as a direct row write in the controller. The `user_id` is
     * passed explicitly because the OTP endpoints are UNAUTHENTICATED, so
     * `auth()->id()` is null at exactly the moment the row is most needed.
     */
    public function login(User $user): void
    {
        $this->write('login', $user->getTable(), (string) $user->getKey(), null, null, (int) $user->getKey());
    }

    /**
     * Record a session end, for the same reason as {@see login()}.
     */
    public function logout(User $user): void
    {
        $this->write('logout', $user->getTable(), (string) $user->getKey(), null, null, (int) $user->getKey());
    }

    /**
     * An empty payload is NULL, not `{}`.
     *
     * "Nothing was there" and "an empty object was there" are different claims,
     * and a caller reading the log should not have to guess which one a row is
     * making. `json_encode([])` would store `[]`; the column is a JSON object
     * slot and NULL is the honest answer.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function nullable(array $payload): ?array
    {
        return $payload === [] ? null : $payload;
    }

    /**
     * The single INSERT into the log table in the whole codebase.
     *
     * Attribution prefers the explicit user id (login/logout name their subject
     * directly) and falls back to the request's authenticated user, which is
     * null for seeders, jobs and registration - all of which legitimately write
     * unattributed rows, since `user_id` is NULLABLE by DDL design.
     *
     * Every string is cut to the width the column declares (:1126 VARCHAR(45)
     * for the address, :1127 VARCHAR(255) for the agent, :1128 VARCHAR(200) for
     * the endpoint) rather than left for the database to truncate or reject.
     *
     * @param  array<string, mixed>|null  $lama
     * @param  array<string, mixed>|null  $baru
     */
    private function write(
        string $aksi,
        ?string $table,
        ?string $recordId,
        ?array $lama,
        ?array $baru,
        ?int $userId = null,
    ): void {
        $request = request();

        $ip = $request->ip();
        $agent = $request->userAgent();

        DB::table(self::TABLE)->insert([
            'user_id' => $userId ?? auth()->id(),
            'aksi' => $aksi,
            'tabel_target' => $table === null ? null : substr($table, 0, 64),
            'record_id' => $recordId === null ? null : substr($recordId, 0, 64),
            'data_lama' => self::encode($lama),
            'data_baru' => self::encode($baru),
            'ip_address' => is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null,
            'user_agent' => is_string($agent) && $agent !== '' ? substr($agent, 0, 255) : null,
            'endpoint' => substr($request->path(), 0, 200),
            'dibuat_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private static function encode(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }
}
