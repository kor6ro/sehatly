<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\NikMasker;
use App\Support\Schema\SqlSchemaParser;
use App\Support\Schema\TableSpec;

/**
 * The redaction policy, COMPUTED from `telemedicine_test.sql`.
 *
 * An audit row is a COPY. Everything written into one is a second copy of
 * something that already exists in a table somebody chose to protect, and a
 * second copy is a second thing to breach, subpoena or leak. So the allow-list
 * is not declared, it is derived: three gates SUBTRACT from the real column
 * list of the table, and a column must survive all three to be written at all.
 *
 *   Gate 1  the DDL TYPE. `text`, `json` and the blob family are narrative or
 *           structured payloads - that is where SOAP notes, complaint text,
 *           chat bodies and notification payloads live. The database has
 *           already decided these are unbounded free text, and an unbounded
 *           value has no business in a bounded log.
 *
 *   Gate 2  the column NAME against a credential vocabulary. `user_otp` and
 *           `user_refresh_tokens` are out of the scope entirely, but a hash can
 *           also appear on an in-scope table, and a NAME rule catches a column
 *           nobody thought to add to a hand-written list.
 *
 *   Gate 3  the columns the DDL types as safe and that are still not safe: a
 *           person's name, a diagnosis, a signature, an attachment filename.
 *           These are VARCHAR, so gates 1 and 2 pass them straight through, and
 *           each has a demonstrated habit of carrying the thing the whole
 *           feature exists to keep out.
 *
 * Of the survivors, a short list is MASKED rather than dropped, and every stored
 * string is swept for NIK-shaped digit runs. The sweep is the actual guarantee;
 * the MASKED map is a convenience that also answers a question the sweep cannot
 * ("was this the same phone number as last time?").
 *
 * ## Masking is done by the ONE masker
 *
 * Every identifier is masked with {@see NikMasker}, the class the API resources
 * already publish `nik` and `nomor_kk` through. Two maskers would mean two
 * places for the next edit to get wrong, and a wrong one is a raw NIK in a
 * response body or in an append-only log. `maskEmail()` below is not a second
 * masker: it is a column-specific presentation rule for a value that is not
 * NIK-shaped, and it delegates its masking characters to the same constant.
 *
 * @see \App\Services\Audit\AuditLogWriter
 * @see \App\Support\NikMasker
 */
final class AuditColumnPolicy
{
    /**
     * Columns that are denied regardless of type or name, keyed by the table
     * they belong to. Every pair below names a REAL column - a totality test
     * pins that against the DDL, because a pair naming a column that does not
     * exist denies nothing while looking like coverage (a misspelt pair once
     * left a live cancellation-reason column allow-listed).
     *
     * @var list<array{0: string, 1: string}>
     */
    public const EXPLICIT_DENY = [
        // users
        ['users', 'nama_lengkap'],
        ['users', 'foto_profil'],
        // pasien: the name lives on `users`, not here - there is deliberately
        // no `pasien.nama_lengkap` pair, and the totality test would fail one.
        ['pasien', 'tempat_lahir'],
        ['pasien', 'pekerjaan'],
        ['pasien', 'alamat_lengkap'],
        ['pasien', 'rt'],
        ['pasien', 'rw'],
        ['pasien', 'kode_pos'],
        ['pasien_anggota_keluarga', 'nama_lengkap'],
        ['pasien_alergi', 'nama_alergen'],
        ['pasien_alergi', 'reaksi'],
        ['pasien_riwayat_penyakit', 'nama_penyakit'],
        ['pasien_imunisasi', 'nama_vaksin'],
        ['pasien_imunisasi', 'pemberi'],
        ['pasien_imunisasi', 'no_batch'],
        ['pasien_penjamin', 'nomor_peserta'],
        ['pasien_penjamin', 'file_kartu'],
        // dokter
        ['dokter', 'file_str_url'],
        ['dokter', 'file_sip_url'],
        // `dokter.nomor_str` is deliberately ABSENT here: it is in MASKED, and
        // a column cannot be both denied and masked. Gate 3 would otherwise
        // silently win and the mask rule would stop being checked.
        ['dokter_libur', 'alasan'],
        ['dokter_pendidikan', 'institusi'],
        // rekam_medis
        ['rekam_medis', 'diagnosis_kerja'],
        ['rekam_medis', 'satusehat_encounter_id'],
        ['rekam_medis_diagnosa', 'deskripsi'],
        ['rekam_medis_tindakan', 'nama_tindakan'],
        ['rekam_medis_lampiran', 'nama_file'],
        ['rekam_medis_lampiran', 'file_url'],
        ['rekam_medis_persetujuan', 'ditandatangani_oleh'],
        ['rekam_medis_persetujuan', 'tanda_tangan_url'],
        // booking: the DDL spelling is `alasan_pembatalan` (with the second
        // `m`). A cancellation reason is free-text narrative about why care
        // was refused or withdrawn, so it is denied here rather than stored.
        ['booking', 'alasan_pembatalan'],
        // konsultasi
        ['konsultasi', 'diagnosis_kerja'],
        ['konsultasi', 'room_id'],
        // konsultasi_chat
        ['konsultasi_chat', 'file_url'],
        ['konsultasi_chat', 'file_nama'],
        // surat_keterangan
        ['surat_keterangan', 'file_url'],
        // ulasan
        ['ulasan_dokter', 'isi'],
        // notifikasi
        ['notifikasi', 'isi'],
        ['notifikasi', 'judul'],
        ['notifikasi', 'tautan'],
        // artikel
        ['artikel', 'judul'],
        ['artikel', 'slug'],
        ['artikel', 'ringkasan'],
        ['artikel', 'cover_url'],
        // resep: the pharmacist note lives at `resep_verifikasi.catatan`,
        // which is TEXT and already denied by gate 1 - there is deliberately
        // no `resep.catatan_apoteker` pair, because no such column exists.
        // resep_item
        ['resep_item', 'nama_obat'],
        ['resep_item', 'kekuatan'],
        ['resep_item', 'aturan_pakai'],
        ['resep_item', 'racikan_nama'],
        // lab
        ['lab_hasil', 'nilai'],
        ['lab_hasil', 'nilai_rujukan'],
        ['lab_hasil', 'file_pdf_url'],
        ['lab_permintaan', 'nomor_permintaan'],
        // pembayaran
        ['pembayaran', 'nomor_referensi'],
        ['pembayaran', 'va_number'],
        // pesanan
        ['pesanan_obat', 'no_resi'],
        ['pesanan_obat_tracking', 'keterangan'],
        ['pesanan_obat_tracking', 'lokasi'],
        ['refund', 'alasan'],
        // rujukan
        ['rujukan', 'diagnosis_kerja'],
    ];

    /**
     * Column TYPES that gate 1 denies, lowercased and with the size prefix
     * removed, so `varchar(255)` is compared as `varchar` and `longtext` as
     * `longtext`.
     *
     * @var list<string>
     */
    public const TEXTUAL = [
        'text',
        'longtext',
        'mediumtext',
        'tinytext',
        'json',
        'blob',
        'mediumblob',
        'longblob',
        'tinyblob',
        'binary',
        'varbinary',
    ];

    /**
     * Column names that gate 2 denies, on any table. The vocabulary is a
     * closed set, but {@see isSecretName()} is the rule that generalises it, so
     * a name nobody has seen is still judged.
     *
     * @var list<string>
     */
    public const CREDENTIALS = [
        'kata_sandi_hash',
        'token_hash',
        'kode_hash',
        'qr_token',
        'fcm_token',
    ];

    /**
     * Columns MASKED rather than dropped, mapped to how they are masked.
     *
     * `nik_mask` and `email` name a presentation rule; the other three reuse
     * {@see NikMasker} unchanged, so the audit log and the API responses publish
     * the SAME masked form of the same identifier.
     *
     * @var array<string, string>
     */
    public const MASKED = [
        'nik' => 'nik_mask',
        'nomor_kk' => 'nik_mask',
        'nomor_ihs_satusehat' => 'nik_mask',
        'nomor_rm' => 'nik_mask',
        'nomor_str' => 'nik_mask',
        'no_telepon' => 'nik_mask',
        'email' => 'email',
    ];

    /**
     * The written reason for every MASKED rule.
     *
     * This exists because the phone and email decision is a JUDGEMENT and has
     * to be arguable. Both are personal data under UU PDP, so neither is stored
     * raw; both are kept in a redacted form because the row still has to answer
     * the only question an incident responder actually asks of them, which is
     * "DID this change?" - a masked form answers that, a hash would too but
     * would also be a permanent linkable identifier in an append-only table,
     * and a null answers nothing. See the report for the full argument.
     *
     * @var array<string, string>
     */
    public const DECISIONS = [
        'nik' => 'NIK is the immutable national identifier. Masked, never hashed: a hash in an append-only log is a permanent linkable identifier and defeats the purpose of redacting it. The masked form still correlates the same NIK across rows without ever holding it.',
        'nomor_kk' => 'The family-card number is a second national identifier over the same person, so it takes the same rule as nik rather than a rule of its own.',
        'nomor_ihs_satusehat' => 'The Satu Sehat identifier is a credential into another health system. Masked so the log can still show which external record was touched, without becoming a copy of it.',
        'nomor_rm' => 'The medical-record number is the patient-facing identifier for the very records this log is protecting. Masked for the same reason as nik.',
        'nomor_str' => 'The doctor registration number is a professional credential of a third party. Masked so a change is visible without the log holding a copy of somebody else\'s credential.',
        'no_telepon' => 'DECISION, masked not stored. A phone number is personal data under UU PDP, so it is not written raw. It is not dropped either: the incident question is "did the number on file change?", which a masked form answers and a null does not, and the actor is already named by the bare user_id. A hash was rejected for the same reason as nik - permanent linkability in an append-only table.',
        'email' => 'DECISION, masked not stored. Same reasoning as no_telepon: the address is personal data, the masked form still answers "was this the verified address on the day of the event?", and the domain is preserved so the row stays recognisable as an email field. Neither the local part nor the domain label is recoverable.',
    ];

    /**
     * The parsed reference schema, memoised per process.
     *
     * @var array<string, TableSpec>|null
     */
    private static ?array $tables = null;

    /**
     * The parsed reference schema, keyed by table name.
     *
     * @return array<string, TableSpec>
     */
    private static function tables(): array
    {
        if (self::$tables === null) {
            $byName = [];

            foreach ((new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))->tables as $spec) {
                $byName[$spec->name] = $spec;
            }

            self::$tables = $byName;
        }

        return self::$tables;
    }

    /**
     * The DDL base type of a column: `varchar(255)` to `varchar`.
     */
    public static function baseType(string $declaredType): string
    {
        return strtolower((string) preg_replace('/\(.*$/', '', trim($declaredType)));
    }

    /**
     * The columns of `$table` that may be written to `audit_log` at all.
     *
     * Sorted and duplicate-free, so two runs of the same write produce the same
     * JSON and a diff of two audit rows shows a real change rather than a
     * reshuffle.
     *
     * @return list<string>
     */
    public static function allowList(string $table): array
    {
        $spec = self::tables()[$table] ?? null;

        if ($spec === null) {
            return [];
        }

        $denied = [];

        // Gate 1: the DDL TYPE.
        foreach ($spec->columns as $column) {
            if (in_array(self::baseType($column->type), self::TEXTUAL, true)) {
                $denied[] = $column->name;
            }
        }

        // Gate 2: the column NAME.
        foreach ($spec->columns as $column) {
            if (in_array($column->name, self::CREDENTIALS, true) || self::isSecretName($column->name)) {
                $denied[] = $column->name;
            }
        }

        // Gate 3: the names the DDL types as safe and that are still not safe.
        foreach (self::EXPLICIT_DENY as [$deniedTable, $deniedColumn]) {
            if ($deniedTable === $table) {
                $denied[] = $deniedColumn;
            }
        }

        $allowed = array_values(array_unique(array_diff(array_keys($spec->columns), $denied)));
        sort($allowed);

        return $allowed;
    }

    /**
     * Gate 2 as a rule rather than a list, so a secret column nobody has seen
     * is judged anyway.
     *
     * The pattern matches the word on either side of an underscore, or as the
     * whole name. It has to discriminate: `nama_lengkap`, `status` and
     * `pasien_id` are not secrets and must not be denied by a rule this blunt.
     */
    public static function isSecretName(string $column): bool
    {
        if (in_array(strtolower($column), array_map(strtolower(...), self::CREDENTIALS), true)) {
            return true;
        }

        return preg_match(
            '/(^|_)(hash|secret|token|password|passwd|sandi|pin|cipher|nonce|salt|signature|private_key|api_key)($|_)/',
            strtolower($column),
        ) === 1;
    }

    /**
     * Sanitise one attribute map for storage in `data_lama` / `data_baru`.
     *
     * The gates run in a fixed order - allow-list, then mask, then sweep - so a
     * column named in more than one rule gets the strictest treatment: a denied
     * key never reaches the masker, and a masked value is still swept for digit
     * runs before it is stored.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function redact(string $table, array $attributes): array
    {
        $allowed = array_flip(self::allowList($table));
        $out = [];

        foreach ($attributes as $key => $value) {
            $name = (string) $key;

            if (! isset($allowed[$name])) {
                continue;
            }

            $out[$name] = self::redactValue($value, $name);
        }

        return $out;
    }

    /**
     * Redact one value, given its column name.
     *
     * The column name is only ever a hint. The guarantee is the SWEEP at the
     * end, which masks any 16-digit run in ANY stored string, so a NIK typed
     * into a free-text field that happens to be allow-listed is still masked.
     */
    public static function redactValue(mixed $value, string $column): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $masked = match (self::MASKED[$column] ?? null) {
            'email' => self::maskEmail($value),
            'nik_mask' => (string) NikMasker::mask($value),
            default => $value,
        };

        return self::sweep($masked);
    }

    /**
     * Mask every 16-digit run in a stored string.
     *
     * Runs shorter than a full identifier pass through untouched: masking
     * fragments would corrupt phone numbers, dates and money amounts while
     * proving nothing. A NIK is `CHAR(16)` (telemedicine_test.sql:222), so 16
     * consecutive digits is exactly the shape, and the boundary of the column
     * keeps a longer number that merely CONTAINS a NIK from being a real risk.
     */
    public static function sweep(string $value): string
    {
        $masked = preg_replace_callback(
            '/\d{16}/',
            static fn (array $run): string => (string) NikMasker::mask($run[0]),
            $value,
        );

        return is_string($masked) ? $masked : $value;
    }

    /**
     * Mask an email address: keep the first character of the local part, the
     * `@`, the first character of the domain label and the whole TLD.
     *
     * `a*******@e******.test` still reads as an email field, still shows that
     * an address existed, and still differs from a different address - which is
     * the whole of what the log is asked for. It does not reveal the local
     * part, the domain label, or either in combination.
     */
    private static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return self::sweep($email);
        }

        [$local, $domain] = $parts;

        $maskedLocal = $local === ''
            ? ''
            : $local[0].str_repeat(NikMasker::PENGGANTI, max(0, strlen($local) - 1));

        $dot = strrpos($domain, '.');

        if ($dot === false || $dot === 0) {
            $maskedDomain = $domain === ''
                ? ''
                : $domain[0].str_repeat(NikMasker::PENGGANTI, max(0, strlen($domain) - 1));

            return $maskedLocal.'@'.$maskedDomain;
        }

        $label = substr($domain, 0, $dot);
        $tld = substr($domain, $dot + 1);

        $maskedLabel = $label === ''
            ? ''
            : $label[0].str_repeat(NikMasker::PENGGANTI, max(0, strlen($label) - 1));

        return $maskedLocal.'@'.$maskedLabel.'.'.$tld;
    }

    /**
     * The mask rules, as data.
     *
     * @return array<string, string>
     */
    public static function maskRules(): array
    {
        return self::MASKED;
    }

    /**
     * The written decision behind every mask rule.
     *
     * @return array<string, string>
     */
    public static function decisions(): array
    {
        return self::DECISIONS;
    }
}
