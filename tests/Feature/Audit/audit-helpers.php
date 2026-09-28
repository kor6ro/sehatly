<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Konsultasi;
use App\Models\KonsultasiChat;
use App\Models\RekamMedis;
use App\Models\User;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Shared helpers for the todo 43 audit tests
|--------------------------------------------------------------------------
|
| APPENDED by todo 43. The helper prefix is `aud`.
|
| Pest loads every test file into ONE process, so a helper name declared at
| file scope in two files is a redeclaration and a fatal error. `aud` follows
| the `rmd` / `kns` / `bku` / `realtime` convention already used by the other
| Feature suites, and this file is `require_once`d from each of them.
|
| Fixtures are built with direct property assignment on a model instance
| rather than `fill()` or `create()`. NO model in `app/Models` declares
| `$fillable`, so the framework default `$guarded = ['*']` applies and mass
| assignment would drop every attribute silently. A fixture that used
| `create([...])` here would quietly produce a row of NULLs and the redaction
| assertions would pass against nothing.
|
| Rows that need no observer (the seed rows the audited rows point at) are
| written with the query builder, which is both cheaper and free of the
| `GuardsMedicalRecordRead` read guard.
*/

if (! function_exists('audSpec')) {
    /**
     * The reference SQL, parsed once per process.
     *
     * `telemedicine_test.sql` is 1349 lines and takes roughly 64 ms to parse,
     * so the dozens of tests in this directory share one parse instead of
     * paying for it each.
     */
    function audSpec(): SchemaSpec
    {
        static $spec = null;

        return $spec ??= (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
    }
}

if (! function_exists('audPhpFiles')) {
    /**
     * Every PHP file under `$sub`, recursively, sorted.
     *
     * @return list<string>
     */
    function audPhpFiles(string $sub = 'app'): array
    {
        $base = base_path($sub);
        $out = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($out);

        return $out;
    }
}

if (! function_exists('audCode')) {
    /**
     * The CODE of a PHP file, with every comment and docblock removed.
     *
     * `audit_log` and `AuditLog` are named in prose in more than a dozen
     * docblocks across `app/`, so a raw `str_contains()` over the source would
     * report a CITATION and not a write. Comments carry no runtime meaning, so
     * dropping them is what makes "this code references audit_log" true when it
     * is true.
     */
    function audCode(string $path): string
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $out = '';

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $out .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= "\n";

                continue;
            }

            $out .= $token[1];
        }

        return $out;
    }
}

if (! function_exists('audFilesMentioning')) {
    /**
     * Every file under `$sub` whose CODE mentions `$needle`.
     *
     * @return list<string> paths relative to the project root, sorted
     */
    function audFilesMentioning(string $sub, string $needle): array
    {
        $hits = [];

        // base_path() is backslashed on Windows while the matched paths are
        // normalised to forward slashes, so strip against a normalised base or
        // the "relative" path comes back absolute.
        $base = str_replace('\\', '/', base_path()).'/';

        foreach (audPhpFiles($sub) as $path) {
            if (str_contains(audCode($path), $needle)) {
                $hits[] = Str::after($path, $base);
            }
        }

        sort($hits);

        return $hits;
    }
}

if (! function_exists('audRowsFor')) {
    /**
     * The audit rows written for one row of one table, oldest first.
     *
     * @return Collection<int, object>
     */
    function audRowsFor(string $table, int|string $recordId): Collection
    {
        return DB::table('audit_log')
            ->where('tabel_target', $table)
            ->where('record_id', (string) $recordId)
            ->orderBy('id')
            ->get();
    }
}

if (! function_exists('audOne')) {
    /**
     * The single row a write was expected to produce.
     *
     * The count assertion is the point: "one write, one row" is a claim about
     * cardinality, and returning `first()` from a two-row result would hide a
     * duplicate.
     */
    function audOne(string $table, int|string $recordId, string $aksi = 'create'): object
    {
        $rows = audRowsFor($table, $recordId)->where('aksi', $aksi)->values();

        expect($rows)->toHaveCount(1, $table.'/'.$recordId.'/'.$aksi.' row count');

        return $rows->first();
    }
}

if (! function_exists('audPayload')) {
    /**
     * `data_lama` or `data_baru`, decoded.
     *
     * @return array<string, mixed>|null
     */
    function audPayload(object $row, string $which): ?array
    {
        $raw = $row->{$which};

        if ($raw === null) {
            return null;
        }

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }
}

if (! function_exists('audWholeRow')) {
    /**
     * The WHOLE row as one string: the surface every absence claim is about.
     *
     * Encoding the entire row rather than one column at a time is deliberate.
     * The brief asks for absence from the row, not from the keys the author
     * happened to think to look at.
     */
    function audWholeRow(object $row): string
    {
        return (string) json_encode($row, JSON_UNESCAPED_SLASHES);
    }
}

if (! function_exists('audFindInRow')) {
    /**
     * Every place `$needle` occurs in a serialised audit row.
     *
     * @return list<string> `value:<column>` for each hit
     */
    function audFindInRow(object $row, string $needle): array
    {
        $hits = [];

        foreach ((array) $row as $column => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (str_contains($value, $needle)) {
                $hits[] = 'value:'.$column;
            }
        }

        return $hits;
    }
}

if (! function_exists('audHashFingerprints')) {
    /**
     * Credential fingerprints that make "part of it leaked" detectable.
     *
     * `password_hash()` with bcrypt returns 60 characters, so an 8 character
     * prefix survives any truncation, prefixing, or re-encoding of the hash.
     * The length is included because leaking the LENGTH is the subtlest version
     * of the same failure: it narrows the search space for anyone holding a
     * candidate list.
     *
     * @return array<string, string>
     */
    function audHashFingerprints(string $hash): array
    {
        return [
            'full hash' => $hash,
            '8-char prefix' => substr($hash, 0, 8),
            'length' => (string) strlen($hash),
        ];
    }
}

if (! function_exists('audUserRow')) {
    /**
     * A `users` row written with the query builder, so it writes NO audit row.
     *
     * `users` declares `uuid` (:134), `nama_lengkap` (:135), `no_telepon` (:137)
     * and `kata_sandi_hash` (:138) as NOT NULL with no default, so all four are
     * written explicitly.
     *
     * @param  array<string, mixed>  $extra
     * @return int the new id
     */
    function audUserRow(array $extra = []): int
    {
        return (int) DB::table('users')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'nama_lengkap' => 'Aud '.Str::upper(Str::random(6)),
            'no_telepon' => '08'.random_int(100000000, 999999999),
            'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
            'tipe' => 'pasien',
            'status' => 'aktif',
        ], $extra));
    }
}

if (! function_exists('audUserModel')) {
    /**
     * A `users` row created through the MODEL, so the observer fires.
     *
     * The password hash is written from `$extra` when supplied so a test can
     * fingerprint the exact value the fixture stored.
     *
     * @param  array<string, mixed>  $extra
     */
    function audUserModel(array $extra = []): User
    {
        $row = new User;

        $defaults = [
            'uuid' => (string) Str::uuid(),
            'nama_lengkap' => 'Aud '.Str::upper(Str::random(6)),
            'no_telepon' => '08'.random_int(100000000, 999999999),
            'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
            'tipe' => 'pasien',
            'status' => 'aktif',
        ];

        foreach (array_merge($defaults, $extra) as $column => $value) {
            $row->{$column} = $value;
        }

        $row->save();

        return $row;
    }
}

if (! function_exists('audPasienRow')) {
    /**
     * A `pasien` row written with the query builder, so it writes NO audit row.
     *
     * `pasien` requires `user_id` (:220), `jenis_kelamin` (:225),
     * `tanggal_lahir` (:226) and `alamat_lengkap` (:234).
     *
     * @param  array<string, mixed>  $extra
     * @return int the new id
     */
    function audPasienRow(int $userId, array $extra = []): int
    {
        return (int) DB::table('pasien')->insertGetId(array_merge([
            'user_id' => $userId,
            'jenis_kelamin' => 'P',
            'tanggal_lahir' => '1990-04-17',
            'alamat_lengkap' => 'Jl. Audit 12',
        ], $extra));
    }
}

if (! function_exists('audDokterRow')) {
    /**
     * A `dokter` row written with the query builder.
     *
     * `dokter` requires `user_id` (:411), `tipe` (:412), `nomor_str` (:413,
     * UNIQUE) and `str_berlaku_sampai` (:414).
     *
     * @param  array<string, mixed>  $extra
     * @return int the new id
     */
    function audDokterRow(array $extra = []): int
    {
        return (int) DB::table('dokter')->insertGetId(array_merge([
            'user_id' => audUserRow(['tipe' => 'dokter']),
            'tipe' => 'dokter_umum',
            'nomor_str' => (string) random_int(1000000000, 9999999999),
            'str_berlaku_sampai' => '2030-12-31',
        ], $extra));
    }
}

if (! function_exists('audPasien')) {
    /**
     * A `pasien` whose owning `users` row is also built here.
     *
     * The owning user is written with the query builder, so the only audit row
     * this helper can produce is the `pasien` create under test.
     *
     * @param  array<string, mixed>  $extra
     * @return int the new `pasien` id
     */
    function audPasien(array $extra = []): int
    {
        return audPasienRow(audUserRow(), $extra);
    }
}

if (! function_exists('audPasienModel')) {
    /**
     * A `pasien` created through the MODEL, so it DOES write a `pasien` create row.
     *
     * Use this when the test is about what the observer recorded. `audPasien()`
     * writes through the query builder, which fires no model event and therefore
     * leaves no audit row to inspect - a test that seeded that way and then
     * asserted on the create row would be asserting on nothing.
     *
     * @param  array<string, mixed>  $extra
     */
    function audPasienModel(array $extra = []): App\Models\Pasien
    {
        $pasien = new App\Models\Pasien;
        $pasien->setAttribute('user_id', audUserRow());
        $pasien->setAttribute('jenis_kelamin', 'P');
        $pasien->setAttribute('tanggal_lahir', '1990-04-17');
        $pasien->setAttribute('alamat_lengkap', 'Jl. Audit 12');

        foreach ($extra as $column => $value) {
            $pasien->setAttribute($column, $value);
        }

        $pasien->save();

        return $pasien;
    }
}

if (! function_exists('audDokter')) {
    /**
     * A `dokter` whose owning `users` row is also built here.
     *
     * @param  array<string, mixed>  $extra
     * @return int the new `dokter` id
     */
    function audDokter(array $extra = []): int
    {
        return audDokterRow($extra);
    }
}

if (! function_exists('audRecord')) {
    /**
     * A `rekam_medis` row created through the MODEL, so the observer fires.
     *
     * `uuid` (:622) is NOT written: the `HasUuid` trait fills it on `creating`.
     *
     * @param  array<string, mixed>  $extra
     */
    function audRecord(int $pasienId, int $dokterId, array $extra = []): RekamMedis
    {
        $row = new RekamMedis;
        $row->pasien_id = $pasienId;
        $row->dokter_id = $dokterId;
        $row->tipe_kunjungan = 'telemedisin';
        $row->tanggal_periksa = '2026-04-01 08:00:00';
        $row->status_dokumen = 'draft';
        $row->versi = 1;

        foreach ($extra as $column => $value) {
            $row->{$column} = $value;
        }

        $row->save();

        return $row;
    }
}

if (! function_exists('audBooking')) {
    /**
     * A `booking` row through the model.
     *
     * `booking` requires `nomor_booking` (:500), `pasien_id` (:501),
     * `dokter_id` (:503), `tipe_layanan` (:506), `tanggal_kunjungan` (:507),
     * both slot columns (:508-509) and `dibuat_oleh_user_id` (:519).
     *
     * @param  array<string, mixed>  $extra
     */
    function audBooking(int $pasienId, int $dokterId, int $userId, array $extra = []): Booking
    {
        $row = new Booking;
        $row->nomor_booking = 'BK-'.Str::upper(Str::random(10));
        $row->pasien_id = $pasienId;
        $row->dokter_id = $dokterId;
        $row->tipe_layanan = 'chat';
        $row->tanggal_kunjungan = '2026-04-02';
        $row->slot_mulai = '09:00:00';
        $row->slot_selesai = '09:15:00';
        $row->dibuat_oleh_user_id = $userId;

        foreach ($extra as $column => $value) {
            $row->{$column} = $value;
        }

        $row->save();

        return $row;
    }
}

if (! function_exists('audKonsultasi')) {
    /**
     * A `konsultasi` row through the model.
     *
     * `konsultasi` requires `pasien_id` (:539), `dokter_id` (:540) and `tipe`
     * (:541).
     *
     * @param  array<string, mixed>  $extra
     */
    function audKonsultasi(int $pasienId, int $dokterId, array $extra = []): Konsultasi
    {
        $row = new Konsultasi;
        $row->pasien_id = $pasienId;
        $row->dokter_id = $dokterId;
        $row->tipe = 'chat';

        foreach ($extra as $column => $value) {
            $row->{$column} = $value;
        }

        $row->save();

        return $row;
    }
}

if (! function_exists('audChat')) {
    /**
     * A `konsultasi_chat` row through the model.
     *
     * `konsultasi_chat` requires `konsultasi_id` (:565), `pengirim_user_id`
     * (:566) and `pengirim_tipe` (:567).
     *
     * @param  array<string, mixed>  $extra
     */
    function audChat(int $konsultasiId, int $userId, array $extra = []): KonsultasiChat
    {
        $row = new KonsultasiChat;
        $row->konsultasi_id = $konsultasiId;
        $row->pengirim_user_id = $userId;
        $row->pengirim_tipe = 'pasien';

        foreach ($extra as $column => $value) {
            $row->{$column} = $value;
        }

        $row->save();

        return $row;
    }
}
