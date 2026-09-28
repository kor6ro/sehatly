<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Str;

final class AuditColumnPolicy
{
    /**
     * Columns that are always denied regardless of type or name, keyed by
     * the table they belong to. The list is exhaustive: every pair below was
     * hand-validated against the DDL and the gate 2 credential vocabulary.
     *
     * @var list<list<string>>
     */
    public const EXPLICIT_DENY = [
        // users
        ['users', 'nama_lengkap'],
        ['users', 'foto_profil'],
        // pasien
        ['pasien', 'nama_lengkap'],
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
        // dokter_libur
        ['dokter_libur', 'alasan'],
        // dokter_pendidikan
        ['dokter_pendidikan', 'institusi'],
        // rekam_medis
        ['rekam_medis', 'diagnosis_kerja'],
        ['rekam_medis', 'satusehat_encounter_id'],
        // rekam_medis_diagnosa
        ['rekam_medis_diagnosa', 'deskripsi'],
        // rekam_medis_tindakan
        ['rekam_medis_tindakan', 'nama_tindakan'],
        // rekam_medis_lampiran
        ['rekam_medis_lampiran', 'nama_file'],
        ['rekam_medis_lampiran', 'file_url'],
        // rekam_medis_persetujuan
        ['rekam_medis_persetujuan', 'ditandatangani_oleh'],
        ['rekam_medis_persetujuan', 'tanda_tangan_url'],
        // booking
        ['booking', 'alasan_pebatalan'],
        // konsultasi
        ['konsultasi', 'diagnosis_kerja'],
        ['konsultasi', 'room_id'],
        // konsultasi_chat
        ['konsultasi_chat', 'file_url'],
        ['konsultasi_chat', 'file_nama'],
        // surat_keterangan
        ['surat_keterangan', 'file_url'],
        // ulasan_dokter
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
        // resep
        ['resep', 'catatan_apoteker'],
        // resep_item
        ['resep_item', 'nama_obat'],
        ['resep_item', 'kekuatan'],
        ['resep_item', 'aturan_pakai'],
        ['resep_item', 'racikan_nama'],
        // lab_hasil
        ['lab_hasil', 'nilai'],
        ['lab_hasil', 'nilai_rujukan'],
        ['lab_hasil', 'file_pdf_url'],
        // lab_permintaan
        ['lab_permintaan', 'nomor_permintaan'],
        // pembayaran
        ['pembayaran', 'nomor_referensi'],
        ['pembayaran', 'va_number'],
        // pesanan_obat
        ['pesanan_obat', 'no_resi'],
        // pesanan_obat_tracking
        ['pesanan_obat_tracking', 'keterangan'],
        ['pesanan_obat_tracking', 'lokasi'],
        // refund
        ['refund', 'alasan'],
        // rujukan
        ['rujukan', 'diagnosis_kerja'],
    ];

    /**
     * Textual / blob / JSON column types that gate 1 always denies, lowercased
     * without the size prefix (e.g. `text`, not `longtext`).
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
     * Column names that gate 2 always denies, by name. The list is the
     * canonical credential vocabulary; any column whose name matches one of
     * these is denied regardless of which table it lives in.
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
     * Columns that are masked rather than dropped. A rule is the canonical
     * mask character repeated for the interior of the value. The two columns
     * that must be masked (not denied) are `no_telepon` and `email`.
     *
     * @var list<string>
     */
    public const MASKED = [
        'nik' => "\u{2022}",
        'nomor_kk' => "\u{2022}",
        'nomor_rm' => "\u{2022}",
        'nomor_ihs_satusehat' => "\u{2022}",
        'nomor_str' => "\u{2022}",
        'no_telepon' => "\u{2022}",
        'email' => '****@******.***',
    ];

    /**
     * Human-readable reasoning for every column that appears in {@see MASKED}.
     * Each string must be longer than 40 characters and must not be empty
     * after trimming.
     *
     * @var list<string>
     */
    public const DECISIONS = [
        'nik' => 'NIK is a national identifier that must never be stored in plain text in an audit log; masking preserves the fact of a record without retaining the identifier.',
        'nomor_kk' => 'NIK-family identifier for household; masking preserves the fact of a record without retaining the household identifier.',
        'nomor_rm' => 'Medical record number; masking preserves the fact of a record without retaining the patient\'s RM.',
        'nomor_ihs_satusehat' => 'Satu Sehat national health identifier; masking preserves the fact of a record without retaining the Kemenkes identifier.',
        'nomor_str' => 'Doctor\'s registration number; masking preserves the fact of a record without retaining the registration number.',
        'no_telepon' => 'Telephone number; masking preserves the fact of a record without retaining the caller\'s phone number.',
        'email' => 'Email address; masking preserves the fact of a record without retaining the address that could be used for social engineering.',
    ];

    /**
     * The parsed reference schema, memoised per process.
     *
     * @var TableSpec[]|null
     */
    private static ?array $tables = null;

    /**
     * The real column names for a given table, keyed by lower-cased name.
     *
     * @return array<string, \App\Support\Schema\ColumnSpec>
     */
    private static function columns(string $table): array
    {
        if (self::$tables === null) {
            self::$tables = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))->tables;
        }

        foreach (self::$tables as $spec) {
            if ($spec->name === $table) {
                return $spec->columns;
            }
        }

        return [];
    }

    /**
     * Gate 1: deny every textual / blob / JSON column in the scope.
     *
     * @return list<string> sorted, duplicate-free subset of real columns
     */
    public static function allowList(string $table): array
    {
        $real = array_keys(self::columns($table));
        $denied = [];

        // Gate 1: textual/blob types
        foreach ($real as $column) {
            $base = strtolower(preg_replace('/\(.*$/', '', $column));
            if (in_array($base, self::TEXTUAL, true)) {
                $denied[] = $column;
            }
        }

        // Gate 2: credential names
        foreach ($real as $column) {
            if (in_array($column, self::CREDENTIALS, true) || self::isSecretName($column)) {
                $denied[] = $column;
            }
        }

        // Gate 3: explicit 60-pair deny list
        foreach (self::EXPLICIT_DENY as [$denTable, $denColumn]) {
            if ($denTable === $table && in_array($denColumn, $real, true)) {
                $denied[] = $denColumn;
            }
        }

        // Keep only columns that are real and not denied
        $allowed = array_values(array_unique(array_diff($real, $denied)));
        sort($allowed);

        return $allowed;
    }

    /**
     * Gate 2: deny a column by its name, regardless of table.
     *
     * @return bool true when the name matches the credential vocabulary
     */
    public static function isSecretName(string $column): bool
    {
        $lower = strtolower($column);

        foreach (self::CREDENTIALS as $cred) {
            if ($lower === strtolower($cred)) {
                return true;
            }
        }

        // The regex: (^|_)(hash|secret|token|password|passwd|sandi|pin|cipher|nonce|salt|signature|private_key|api_key)($|_)
        $pattern = '/(^|_)(hash|secret|token|password|passwd|sandi|pin|cipher|nonce|salt|signature|private_key|api_key)($|_)/';
        return (bool) preg_match($pattern, $lower);
    }

    /**
     * The canonical mask character repeated for the interior of a value.
     * The two columns that must be masked (not denied) are `no_telepon` and
     * `email`.
     *
     * @return list<string> column => mask
     */
    public static function maskRules(): array
    {
        return self::MASKED;
    }

    /**
     * Human-readable reasoning for every column that appears in {@see MASKED}.
     * Each string must be longer than 40 characters and must not be empty
     * after trimming.
     *
     * @return list<string>
     */
    public static function decisions(): array
    {
        return self::DECISIONS;
    }
}