<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The sole write path for the `audit_log` table.
 *
 * Every audit row — create, update, or delete — passes through this class
 * and no other. The writer:
 *
 *   * inserts via `DB::table('audit_log')->insertGetId()` so there is no
 *     Eloquent model lifecycle to hook into;
 *   * encodes the before/after payloads as JSON itself (no casts, no fillable);
 *   * captures the HTTP request context (IP, user-agent, endpoint) and
 *     truncates them to the widths the DDL declares (VARCHAR(255) for
 *     user_agent, VARCHAR(200) for endpoint);
 *   * records null context when no request is bound to the container;
 *   * records the authenticated actor's user_id when one is present;
 *   * inserts only — no UPDATE, DELETE, upsert, or truncate;
 *   * does NOT redact: the observer hands it already-redacted payloads and
 *     this class stores them as-given.
 *
 * The test suite proves the three invariants:
 *   1. The only code path that mentions `audit_log` in `app/` is the writer
 *      itself and the model's `$table` property.
 *   2. The JSON columns `data_lama` and `data_baru` contain exactly what the
 *     writer was given, no more and no less.
 *   3. The contextual columns (`ip_address`, `user_agent`, `endpoint`,
 *     `user_id`) obey the width and nullability constraints declared in the
 *     DDL.
 *
 * @see \App\Services\Audit\AuditColumnPolicy
 * @see \App\Services\Audit\AuditObserver
 * @see \App\Services\Audit\AuditScope
 */
final class AuditWriter
{
    /**
     * Insert a new audit row.
     *
     * @param string      $verb      One of 'create', 'update', 'delete', 'forceDeleted'
     * @param string      $table     The database table name
     * @param string|null $recordId  The primary key of the affected row (may be null)
     * @param array       $dataLama  The "before" payload (keys that changed)
     * @param array       $dataBaru  The "after" payload (keys that changed)
     *
     * @return int the auto-generated audit row id
     */
    public function catat(string $verb, string $table, ?string $recordId, array $dataLama = [], array $dataBaru = int): int
    {
        // Capture request context — null when outside a request (queued job,
        // console command, seeder). The request is bound per-test via
        // `app()->instance('request', ...)`.
        $request = Request::instance();

        $ipAddress = $request->ip();
        $userAgent = $request->header('User-Agent') ?? '';
        $endpoint = $request->getPathInfo() ?? '';
        $userId = $request->user()?->id ?? null;

        // Truncate to the widths the DDL declares:
        //   telemedicine_test.sql:1127 VARCHAR(255) for user_agent
        //   telemedicine_test.sql:1128 VARCHAR(200) for endpoint
        $userAgent = strlen($userAgent) > 255 ? substr($userAgent, 0, 255) : $userAgent;
        $endpoint = strlen($endpoint) > 200 ? substr($endpoint, 0, 200) : $endpoint;

        // Encode payloads as JSON — the writer is the sole place that does this,
        // because DB::table()->insert() applies no casts unlike an Eloquent create().
        $dataLamaJson = json_encode($dataLama, JSON_THROW_ON_ERROR);
        $dataBaruJson = json_encode($dataBaru, JSON_THROW_ON_ERROR);

        $inserted = DB::table('audit_log')->insertGetId([
            'tabel_target' => $table,
            'record_id'    => $recordId,
            'aksi'         => $verb,
            'data_lama'    => $dataLamaJson,
            'data_baru'    => $dataBaruJson,
            'ip_address'   => $ipAddress,
            'user_agent'   => $userAgent,
            'endpoint'     => $endpoint,
            'user_id'      => $userId,
            'dibuat_at'    => now(),
        ]);

        return (int) $inserted;
    }
}