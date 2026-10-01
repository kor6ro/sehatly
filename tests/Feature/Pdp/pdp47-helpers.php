<?php

declare(strict_types=1);

use App\Models\Konsultasi;
use App\Models\PersetujuanPdp;
use App\Models\User;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| Todo 47 - shared fixtures for the consent and notification test files
|--------------------------------------------------------------------------
|
| `require_once`d by each of them, so a single file is runnable on its own - the
| same reason todo 39's `resep-helpers.php`, todo 40's `resep40-helpers.php` and
| todo 46's `pesanan46-helpers.php` exist. The prefix is `pd47` because Pest
| loads every test file into ONE process and `skt`, `po46`, `rx39`, `rx40`,
| `inv44`, `bku`, `kns`, `rmd`, `slot` and `realtime` are taken.
|
| ## Every fixture is a RAW insert, and that is deliberate
|
| `DB::table()->insert()` does not fire an Eloquent event, so no fixture in this
| file writes an `audit_log` row through `AuditObserver`. That matters because
| the acceptance criterion for consent is that ONE `POST /api/v1/pdp/persetujuan`
| produces exactly ONE audit row: a fixture that went through the model would
| make that count depend on how many fixture rows a test happened to build, and a
| test that counts audit rows has to be able to attribute every one of them.
| {@see pd47Consent()} takes a model rather than writing raw for the ONE case
| that needs a hydrated row, and even there the save is what the test wants to
| observe, so it is spelled {@see pd47ConsentRaw()} and {@see pd47ConsentModel()}.
|
| ## The DDL helpers assert a CITATION, they do not quote one
|
| The plan's own `:NNN` citations are off by one in places - `notifikasi.idx_notif`
| is cited as `:1045`, and `:1045` is `dibuat_at`; the index is on `:1047`. Every
| line number this todo depends on is therefore read back out of
| `telemedicine_test.sql` by {@see pd47AssertLine()} before it is relied on.
*/

const PD47_JAM = '2026-03-11 10:00:00';

const PD47_HARI = '2026-03-11';

const PD47_KONEKSI_LAWAN = 'po47_b';

function pd47Jam(): Carbon
{
    return Carbon::parse(PD47_JAM);
}

function pd47KunciJam(): void
{
    Carbon::setTestNow(pd47Jam());
}

function pd47LepasJam(): void
{
    Carbon::setTestNow();
}

/**
 * `telemedicine_test.sql:$line`, or null when the file is shorter than that.
 */
function pd47DdlLine(int $line): ?string
{
    static $baris = null;

    $baris ??= file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    return $baris[$line - 1] ?? null;
}

/**
 * Assert `telemedicine_test.sql:$line` contains `$token`.
 */
function pd47AssertLine(int $line, string $token): void
{
    $actual = pd47DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and does not contain ['.$token.']',
    );
}

/**
 * Assert `telemedicine_test.sql:$line` does NOT contain `$token`.
 *
 * The negative half is the load-bearing one for a claim about something MISSING:
 * `notifikasi` having no delivery-state column, `persetujuan_pdp` having no
 * `dibuat_at`, and `:1144` no longer carrying a `UNIQUE KEY` after F02 dropped
 * `uq_consent`. A positive search cannot prove an absence.
 */
function pd47AssertLineLacks(int $line, string $token): void
{
    $actual = pd47DdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringNotContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual.'] and unexpectedly contains ['.$token.']',
    );
}

/**
 * The lines of a `CREATE TABLE` block, CREATE included and ENGINE included.
 *
 * @return list<string>
 */
function pd47Blok(string $tabel): array
{
    static $baris = null;

    $baris ??= file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    $mulai = null;

    foreach ($baris as $i => $satu) {
        if (str_starts_with($satu, 'CREATE TABLE '.$tabel.' ')) {
            $mulai = $i;

            break;
        }
    }

    Assert::assertNotNull($mulai, "telemedicine_test.sql has no CREATE TABLE {$tabel}");

    $blok = [];

    for ($i = $mulai; $i < count($baris); $i++) {
        $blok[] = $baris[$i];

        if (str_ends_with($baris[$i], ') ENGINE=InnoDB;')) {
            break;
        }
    }

    return $blok;
}

/**
 * A `users` row. `uuid` (:134), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the four NOT NULL columns with no default, and
 * `tipe` is the seven-value ENUM at :139.
 */
function pd47User(string $nama, string $tipe = 'pasien', ?string $role = null): User
{
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default.
 *
 * @param  array<string, mixed>  $ubah
 */
function pd47Pasien(int $userId, array $ubah = []): int
{
    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji PDP No. 7, Jakarta',
    ], $ubah));
}

/**
 * A patient account plus its `pasien` row and the `pasien` role, which is what
 * `permission:notifikasi.lihat` needs.
 *
 * @return array{user: User, pasien: int}
 */
function pd47AkunPasien(string $nama = 'Pasien PDP'): array
{
    $user = pd47User($nama, 'pasien', 'pasien');

    return ['user' => $user, 'pasien' => pd47Pasien((int) $user->getKey())];
}

/**
 * A `dokter` row. `nomor_str` (:413) is UNIQUE, so it is randomised per call.
 *
 * @param  array<string, mixed>  $ubah
 */
function pd47Dokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-PD47-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `faskes` row, which is what `rujukan.faskes_tujuan_id` (:613) points at.
 * `kode_faskes` (:362) is UNIQUE so it is randomised.
 */
function pd47Faskes(string $tipe = 'rumah_sakit'): int
{
    return (int) DB::table('faskes')->insertGetId([
        'kode_faskes' => 'F'.Str::upper(Str::random(8)),
        'nama' => 'RS Uji PDP',
        'tipe' => $tipe,
        'alamat' => 'Jl. Uji PDP No. 8, Jakarta',
        'status_aktif' => 1,
    ]);
}

/**
 * A `konsultasi` row, so `POST /konsultasi/{id}/surat-keterangan` has a session
 * to hang a referral off.
 */
function pd47Konsultasi(int $pasienId, int $dokterId, string $status = 'selesai'): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = $status;
    $row->room_id = (string) Str::uuid();
    $row->mulai_at = pd47Jam();
    $row->save();

    return $row;
}

/**
 * A doctor account, the patient they have seen, and a facility to refer to.
 *
 * @return array{user: User, dokter: int, pasien: int, pasienUser: User, faskes: int}
 */
function pd47DoctorAccount(): array
{
    $dokterUser = pd47User('Dokter PDP', 'dokter', 'dokter');
    $pasienUser = pd47User('Pasien Rujukan PDP', 'pasien', 'pasien');

    return [
        'user' => $dokterUser,
        'dokter' => pd47Dokter((int) $dokterUser->getKey()),
        'pasien' => pd47Pasien((int) $pasienUser->getKey()),
        'pasienUser' => $pasienUser,
        'faskes' => pd47Faskes(),
    ];
}

/**
 * A `persetujuan_pdp` row written RAW, so it writes no `audit_log` row.
 *
 * `user_id` (:1136), `jenis` (:1137-1138), `versi_dokumen` (:1139), `disetujui`
 * (:1140) and `disetujui_at` (:1141) are the NOT NULL columns; `ip_address`
 * (:1142) is the one nullable one and is left NULL unless `$ubah` says otherwise.
 *
 * @param  array<string, mixed>  $ubah
 */
function pd47ConsentRaw(int $userId, string $jenis, string $versi, bool $disetujui, array $ubah = []): int
{
    return (int) DB::table('persetujuan_pdp')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis' => $jenis,
        'versi_dokumen' => $versi,
        'disetujui' => $disetujui ? 1 : 0,
        'disetujui_at' => pd47Jam(),
        'ip_address' => null,
    ], $ubah));
}

/**
 * A `persetujuan_pdp` row written through the MODEL, so the global audit
 * observer fires. Only for a test whose subject IS the audit row.
 */
function pd47ConsentModel(int $userId, string $jenis, string $versi, bool $disetujui): PersetujuanPdp
{
    $row = new PersetujuanPdp;
    $row->user_id = $userId;
    $row->jenis = $jenis;
    $row->versi_dokumen = $versi;
    $row->disetujui = $disetujui;
    $row->disetujui_at = pd47Jam();
    $row->ip_address = '203.0.113.9';
    $row->save();

    return $row;
}

/**
 * A `notifikasi` row written RAW, so it writes no `audit_log` row.
 *
 * `user_id` (:1038), `judul` (:1039), `isi` (:1040), `tipe` (:1041) and
 * `dibuat_at` (:1045) are the NOT NULL columns; `tautan` (:1042) and `payload`
 * (:1043) are nullable and `dibaca_at` (:1044) is the read stamp.
 *
 * @param  array<string, mixed>  $ubah
 */
function pd47Notifikasi(int $userId, string $tipe = 'sistem', bool $dibaca = false, array $ubah = []): int
{
    return (int) DB::table('notifikasi')->insertGetId(array_merge([
        'user_id' => $userId,
        'judul' => 'Uji notifikasi PDP',
        'isi' => 'Isi notifikasi uji.',
        'tipe' => $tipe,
        'tautan' => '/api/v1/notifikasi',
        'payload' => null,
        'dibaca_at' => $dibaca ? pd47Jam() : null,
        'dibuat_at' => pd47Jam(),
    ], $ubah));
}

/**
 * A `user_devices` row, which is where the FCM push token lives.
 *
 * `user_id` (:192), `device_id` (:193), `platform` (:194) and `aktif` (:197) are
 * the NOT NULL columns; `fcm_token` (:195) is nullable, which is the case the
 * push dispatch has to skip rather than send an empty token.
 *
 * @param  array<string, mixed>  $ubah
 */
function pd47Perangkat(int $userId, array $ubah = []): int
{
    return (int) DB::table('user_devices')->insertGetId(array_merge([
        'user_id' => $userId,
        'device_id' => 'dev-'.Str::lower(Str::random(10)),
        'platform' => 'android',
        'fcm_token' => 'fcm-token-'.Str::lower(Str::random(16)),
        'app_versi' => '1.0.0',
        'aktif' => 1,
    ], $ubah));
}

/**
 * Act as `$user` for one request, with a real Sanctum bearer token.
 *
 * `forgetGuards()` first, for the reason `sktAs()` gives: `Sanctum` caches its
 * principal, so the first authenticated request inside a test would otherwise
 * decide the caller for every later one.
 */
function pd47As(User $user): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken(
        $user->createToken('pdp-47-test', ['*'], now()->addHour())->plainTextToken
    );
}

/**
 * Every registered route as `METHOD uri => [middleware]`, keyed by the pair.
 *
 * Read off the router rather than from a `--path=` filter, because
 * `notifikasi/{id}/baca` and `pdp/persetujuan` share no common prefix a single
 * filter could see.
 *
 * @return array<string, list<string>>
 */
function pd47Routes(): array
{
    $hasil = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $hasil[$method.' '.$route->uri()] = array_values($route->gatherMiddleware());
        }
    }

    return $hasil;
}

/**
 * The middleware stack on one `METHOD uri`, naming both halves on a miss.
 *
 * @param  array<string, list<string>>  $routes
 * @return list<string>
 */
function pd47Guards(array $routes, string $methodUri): array
{
    if (! array_key_exists($methodUri, $routes)) {
        Assert::fail("No route {$methodUri}. Registered: ".implode(', ', array_keys($routes)));
    }

    return $routes[$methodUri];
}

/**
 * A `log` binding that RECORDS instead of writing, so a delivery attempt can be
 * asserted by COUNTING rather than by Mockery expectation arithmetic.
 *
 * ## Why not `Log::spy()` plus `shouldHaveReceived()`
 *
 * The first draft of the delivery test used `Log::spy()` with three
 * `->times(N)->withArgs($closure)` expectations, and it failed with
 * `Method info(<Any Arguments>) should be called exactly 5 times but called 20
 * times` - Mockery reported the expectation with NO argument matcher, and
 * counted all twenty `info` calls (5 pushes, 5 confirmations, 10 skips) against
 * the first expectation. Whether `withArgs()` composes with `times()` the way the
 * reading suggests is a Mockery detail, and a test whose pass/fail depends on it
 * is a test about Mockery.
 *
 * Binding a recorder for the `log` service instead makes the assertion a plain
 * `count(array_filter(...))` over a list the test can also print, so a failure
 * names the actual lines rather than a count with no context.
 *
 * `__call` swallows every other level, because Laravel logs through this
 * instance during a request - a sanitised 500 and a `Kernel::handleException`
 * line would otherwise fatal on an undefined method.
 */
final class Pd47LogRekam
{
    /**
     * @var list<array{level: string, pesan: string, konteks: array<string, mixed>}>
     */
    public array $baris = [];

    public function info(string $pesan, array $konteks = []): void
    {
        $this->baris[] = ['level' => 'info', 'pesan' => $pesan, 'konteks' => $konteks];
    }

    public function __call(string $level, array $argumen): void
    {
        $this->baris[] = [
            'level' => $level,
            'pesan' => (string) ($argumen[0] ?? ''),
            'konteks' => (array) ($argumen[1] ?? []),
        ];
    }

    /**
     * Every recorded call at `$level` whose message is EXACTLY `$pesan`.
     *
     * Equality and not `str_contains`, because the three push messages share a
     * prefix: `str_contains('notifikasi.push.terkirim', 'notifikasi.push')` is
     * true, so a substring filter counts the confirmation line as a push.
     *
     * @return list<array{pesan: string, konteks: array<string, mixed>}>
     */
    public function dengan(string $pesan, string $level = 'info'): array
    {
        return array_values(array_filter(
            $this->baris,
            static fn (array $satu): bool => $satu['level'] === $level && $satu['pesan'] === $pesan,
        ));
    }
}

/**
 * Install {@see Pd47LogRekam} as the `log` service and return it.
 */
function pd47RekamLog(): Pd47LogRekam
{
    $rekam = new Pd47LogRekam;

    app()->instance('log', $rekam);

    return $rekam;
}

/**
 * Run `$aksi` and return the throwable it raised, or fail the test naming both.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $tipe
 * @return T
 */
function pd47Tangkap(callable $aksi, string $tipe): Throwable
{
    try {
        $hasil = $aksi();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($tipe, 'Expected ['.$tipe.'] but got ['.get_class($e).']: '.$e->getMessage());

        /** @var T $e */
        return $e;
    }

    Assert::fail('Expected ['.$tipe.'] but nothing was thrown. Returned: '.var_export($hasil, true));
}

/**
 * The PHP source of `$path` with every comment removed, comments JOINED by a
 * space so two adjacent tokens cannot fuse into a new one.
 *
 * ## Why a text match cannot answer this
 *
 * A substring search over a file cannot tell code from prose, and the files under
 * test are full of comments that NAME the construct they avoid: the
 * `NotifikasiController` docblock quotes `->update([...])` in full while explaining
 * that it fires no Eloquent event. Asserting the file does not contain `->update(`
 * therefore fails on the very comment that documents the decision, and "fixing" it
 * by deleting the explanation would be exactly backwards.
 *
 * ## Why a line filter is not enough either
 *
 * Stripping whole comment LINES is wrong for `//` trailing a statement, and for
 * `/*` blocks that start mid-line. The tokenizer is the only reader that knows
 * where each comment actually ends.
 *
 * ## What this drops, and what it deliberately does not
 *
 * Comments only: `T_COMMENT` and `T_DOC_COMMENT`. String literals are KEPT, since
 * a `->update(` inside a string is still a `->update(` this audit should see. PHP
 * 8.0 removed the ability to nest unterminated block comments, so a single regex
 * would be equivalent - but the tokenizer is the definition rather than an
 * approximation of it.
 *
 * @return string Same source, comments replaced by single spaces
 */
function pd47TanpaKomentar(string $path): string
{
    $tokens = token_get_all((string) file_get_contents($path));
    $hasil = '';

    foreach ($tokens as $token) {
        if (is_array($token)) {
            $hasil .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

            continue;
        }

        $hasil .= $token;
    }

    return $hasil;
}
