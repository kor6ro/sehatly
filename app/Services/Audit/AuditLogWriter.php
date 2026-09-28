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
 * The table reference lives in exactly one place in executable code: the
 * `TABLE` constant below. An architecture test scans every file under
 * `app/Http/Controllers`, `app/Services`, `app/Observers` and `app/Models`
 * (comments stripped, so prose citations do not count) and fails unless
 * this file is the sole mention, which is what makes "the observer is the
 * only producer" an enforced property rather than a comment.
 *
 * ## Why the query builder and not the AuditLog model
 *
 * `audit_log.user_id` (:1120) and `audit_log.record_id` (:1123) are
 * deliberately BARE - no foreign key, no Eloquent relation - so the log
 * survives user and record deletion. A row that cannot be reached by
 * association is written without association: `DB::table()`, which also
 * guarantees no model event can ever recurse back into the observer.
 * `dibuat_at` is the table's only timestamp (:1129); the model already
 * carries `UPDATED_AT = null`, and nothing here performs an update or a
 * delete of a log row, ever.
 *
 * ## What is stored on update: changed keys only
 *
 * `data_baru` holds the sanitised CHANGED attributes and `data_lama` the
 * previous values of those SAME keys - never full before/after snapshots.
 * A row holding both full snapshots of a patient record would double the
 * exposure of every redacted field in an append-only table readable under
 * a broader permission. Each update row carries its own delta, so the full
 * history is still reconstructible across rows. On delete the whole
 * sanitised row is the before-image (the row is gone; a partial snapshot
 * would lose forensic value) and the after-image is null; on create the
 * reverse. A row is written even when sanitisation empties both payloads
 * (e.g. a password-only change): the fact of the change is accountability,
 * the secret is not.
 *
 * ## Redaction rules
 *
 * DROPPED entirely (the key never appears, so neither value, prefix, hash
 * nor length can leak): `kata_sandi_hash` and any future credential-shaped
 * key matching the patterns below - `kode_hash`, `token_hash`,
 * `webhook_payload` (gateway secrets), `nik_cipher` / `nik_hash` (a
 * ciphertext in the log would be a second copy of the NIK; a hash a
 * permanent linkable identifier).
 *
 * MASKED with `NikMasker` (the same masker the API resources use - one
 * rule, not two): `nik`, `nomor_kk`, and - as a safety sweep - EVERY
 * 16-digit run inside ANY stored string value, so a NIK typed into a
 * free-text field that is otherwise stored (e.g. an address) is still
 * masked.
 *
 * EXCLUDED per table (deny-by-default allow-list: every other column of
 * the table is stored): narrative clinical text - the RekamMedis SOAP and
 * history columns, the Konsultasi SOAP copy, `booking.keluhan` plus its
 * attachment, `konsultasi_chat.isi` plus file columns, `catatan_alergi`,
 * `rekam_medis_persetujuan.isi_persetujuan` plus the signature URL, and
 * `surat_keterangan.isi` plus its file URL. Storing them would write a
 * second, less-protected copy of protected health information into an
 * append-only table.
 *
 * STORED deliberately (a decision, not an omission): `no_telepon` and
 * `email` are the login identifiers and OTP targets - redacting them would
 * destroy the log's incident-response value, and they are changeable
 * contact identifiers, not immutable national identifiers. `qr_token`,
 * `va_number` and `nomor_referensi` are verification artefacts the audit
 * must be able to name. `resep.catatan_dokter` is the ONLY column the
 * schema offers for a prescription-override acknowledgement, so dropping
 * it would delete the decision record. `rujukan.diagnosis_kerja` and
 * `alasan_rujukan` are the cross-faskes accountability record, gated by
 * the `berbagi_data_medis` consent. Allergy, diagnosis and procedure rows
 * keep their short structured fields: they are safety-critical
 * operational data, not narrative.
 */
final class AuditLogWriter
{
    /**
     * The one executable mention of the table name in the codebase.
     */
    private const TABLE = 'audit_log';

    /**
     * The `aksi` values this writer emits. Each is asserted against the
     * eight-member DDL ENUM parsed from `telemedicine_test.sql`, so a value
     * outside the schema fails the suite rather than the INSERT.
     *
     * @var list<string>
     */
    public const AKSI = ['create', 'update', 'delete', 'login', 'logout'];

    /**
     * Exact column names that are never stored, on any table.
     *
     * @var list<string>
     */
    private const DROP_KEYS = [
        'kata_sandi_hash',
        'kode_hash',
        'token_hash',
        'webhook_payload',
        'nik_cipher',
        'nik_hash',
        'remember_token',
    ];

    /**
     * Credential-shaped keys that are never stored, matched
     * case-insensitively, so a future secret column is dropped by shape
     * rather than by remembering its name.
     *
     * @var list<string>
     */
    private const DROP_PATTERNS = [
        '/kata_sandi/i',
        '/password/i',
        '/passwd/i',
        '/secret/i',
        '/_hash$/i',
    ];

    /**
     * Identifier columns masked with NikMasker rather than dropped: the
     * fact of the identifier is operational, the digits are not.
     *
     * @var list<string>
     */
    private const MASK_KEYS = ['nik', 'nomor_kk'];

    /**
     * Narrative clinical columns excluded per table. Keyed by TABLE name
     * (from the DDL, via `$model->getTable()`), so a model rename cannot
     * silently widen the allow-list. Every other column of the table is
     * stored after the drop/mask passes above.
     *
     * @var array<string, list<string>>
     */
    private const DENIED = [
        'rekam_medis' => [
            'keluhan_utama',
            'riwayat_penyakit_sekarang',
            'riwayat_penyakit_dahulu',
            'riwayat_keluarga',
            'riwayat_psikososial',
            'hasil_pemeriksaan_fisik',
            'subjektif',
            'objektif',
            'asesmen',
            'plan',
            'diagnosis_kerja',
            'instruksi_tindak_lanjut',
        ],
        'konsultasi' => [
            'catatan_subjektif',
            'catatan_objektif',
            'catatan_asessment',
            'catatan_plan',
            'diagnosis_kerja',
            'saran_tindak_lanjut',
        ],
        'booking' => [
            'keluhan',
            'lampiran_keluhan',
        ],
        'konsultasi_chat' => [
            'isi',
            'file_url',
            'file_nama',
        ],
        'pasien' => [
            'catatan_alergi',
        ],
        'pasien_anggota_keluarga' => [
            'catatan_alergi',
        ],
        'rekam_medis_persetujuan' => [
            'isi_persetujuan',
            'tanda_tangan_url',
        ],
        'surat_keterangan' => [
            'isi',
            'file_url',
        ],
    ];

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
            self::sanitize($model->getTable(), $model->getAttributes()),
            null,
        );
    }

    /**
     * Record an update as a delta: sanitised changed attributes, plus the
     * previous values of those same keys. The row is written even when
     * sanitisation empties the delta (a password-only change): the fact of
     * the change is the accountability record.
     */
    public function recordUpdate(Model $model): void
    {
        $changed = $model->getChanges();
        $previous = [];

        foreach (array_keys($changed) as $key) {
            $previous[$key] = $model->getOriginal($key);
        }

        $this->write(
            'update',
            $model->getTable(),
            (string) $model->getKey(),
            self::sanitize($model->getTable(), $previous),
            self::sanitize($model->getTable(), $changed),
            null,
        );
    }

    /**
     * Record a deletion. The whole sanitised row is the before-image - the
     * row is gone, so a partial snapshot would lose forensic value - and
     * the after-image is null.
     */
    public function recordDelete(Model $model): void
    {
        $this->write(
            'delete',
            $model->getTable(),
            (string) $model->getKey(),
            self::sanitize($model->getTable(), $model->getAttributes()),
            null,
            null,
        );
    }

    /**
     * Record a session start. Called by the auth controller after a token
     * pair is issued - the token API has no session event to hook, so the
     * write is explicit, through this service, never a direct row write in
     * the controller.
     */
    public function login(User $user): void
    {
        $this->write('login', $user->getTable(), (string) $user->getKey(), null, null, (int) $user->getKey());
    }

    /**
     * Record a session end. Called by the auth controller after the token
     * revocation, for the same reason as {@see login()}.
     */
    public function logout(User $user): void
    {
        $this->write('logout', $user->getTable(), (string) $user->getKey(), null, null, (int) $user->getKey());
    }

    /**
     * Strip secrets, mask identifiers and drop per-table narrative fields.
     *
     * The three passes run in a fixed order - deny, drop, mask, sweep - so
     * a column named in more than one rule gets the strictest treatment:
     * a dropped key never reaches the masker and a masked value is still
     * swept for raw digit runs.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function sanitize(string $table, array $attributes): array
    {
        $denied = self::DENIED[$table] ?? [];
        $out = [];

        foreach ($attributes as $key => $value) {
            $name = (string) $key;

            if (in_array($name, $denied, true)) {
                continue;
            }

            if (in_array($name, self::DROP_KEYS, true) || self::matchesDropPattern($name)) {
                continue;
            }

            if (in_array($name, self::MASK_KEYS, true) && is_string($value)) {
                $out[$name] = self::sweep($value);

                continue;
            }

            $out[$name] = is_string($value) ? self::sweep($value) : $value;
        }

        return $out;
    }

    /**
     * Mask every 16-digit run in a stored string: a NIK typed into an
     * otherwise-stored free-text field is still a NIK. Runs shorter than a
     * full identifier pass through untouched - masking fragments would
     * corrupt phone numbers and dates while proving nothing.
     */
    private static function sweep(string $value): string
    {
        $masked = preg_replace_callback(
            '/\d{16}/',
            static fn (array $run): string => (string) NikMasker::mask($run[0]),
            $value,
        );

        return is_string($masked) ? $masked : $value;
    }

    private static function matchesDropPattern(string $name): bool
    {
        foreach (self::DROP_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single INSERT into the log table in the whole codebase.
     *
     * Attribution prefers the explicit user id (login/logout name their
     * subject directly, because the OTP endpoints are unauthenticated) and
     * falls back to the request's authenticated user, which is null for
     * seeders, jobs and registration - all of which legitimately write
     * unattributed rows, since `user_id` is NULLABLE by DDL design.
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
        ?int $userId,
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
