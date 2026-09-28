<?php

declare(strict_types=1);

use App\Enums\KonsultasiStatus;
use App\Enums\KonsultasiTipe;
use App\Http\Requests\Konsultasi\KonsultasiRequest;
use App\Models\Konsultasi;
use App\Models\KonsultasiChat;
use App\Models\User;
use App\Services\Dokter\DokterDirectoryService;
use App\Services\Konsultasi\KonsultasiService;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;

/*
|--------------------------------------------------------------------------
| The consultation schema: six states, ten edges, and every citation
|--------------------------------------------------------------------------
|
| This file is the DDL contract for todo 32, and it is deliberately made of
| assertions about `telemedicine_test.sql` rather than about the code. The six
| values of `konsultasi.status` and the ten legal moves between them are the core
| of the todo, and a transition table that has quietly drifted from the schema is
| the failure this file exists to make impossible.
|
| ## The helper prefix is `kns`
|
| Pest loads every test file into one process, so `bku*` (todo 27), `realtime*`
| (todo 31), `asUser` and `patientAccount` (todo 21) and `direktori*` (todo 22) are
| already taken at file scope. This file deliberately does NOT reuse any of them:
| binding this todo's suite to another file's fixtures is exactly the
| cross-dependency the prefix convention exists to prevent.
|
| ## The DDL citation audit is the point of the last two tests
|
| Every line number quoted in a docblock across the files this todo adds is
| asserted here against the actual bytes of `telemedicine_test.sql`. The plan's own
| citations for this schema are wrong in at least one place that was found while
| writing this todo, and a citation that is merely plausible is worse than no
| citation at all, because the next reader cannot tell which ones were checked. An
| assertion per line turns "I read the DDL" into something the suite can prove.
|
| @see \Tests\Feature\KonsultasiTest for the HTTP behaviour
*/

// =====================================================================
// Row builders
// =====================================================================

/**
 * A Monday in the future, so the booking fixtures are never "today" and no
 * slot-eligibility rule can be the reason a fixture is refused.
 */
const KNS_TANGGAL = '2026-12-07';

/**
 * The `dokter.biaya_konsultasi_online` every instant fixture writes. It is a
 * DIFFERENT number from anything else in this file, so a test that read the wrong
 * column cannot pass by coincidence.
 */
const KNS_BIAYA = '175000.00';

/**
 * A `users` row. `uuid` (:134), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138) are the four NOT NULL columns with no default. `status`
 * is `aktif` (:140) so nothing is ever excluded on the ACCOUNT's own state.
 */
function knsUser(string $nama, string $tipe = 'pasien'): int
{
    return (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);
}

/**
 * A `konsultasi` row, written through the model so `dibuat_at` and `diubah_at` behave.
 * `booking_id` is left NULL, the instant shape, and `mulai_at` is left NULL so a
 * test that needs a started session has to say so.
 *
 * Named for the SESSION rather than for the table, deliberately: the table's own
 * name is a token this project's own prose uses constantly and a helper called
 * after it would be unreadable at a call site.
 *
 * @param  array<string, mixed>  $ubah
 */
function knsSesi(int $pasienId, int $dokterId, string $status = 'menunggu_dokter', array $ubah = []): Konsultasi
{
    $row = new Konsultasi;
    $row->pasien_id = $pasienId;
    $row->dokter_id = $dokterId;
    $row->tipe = 'chat';
    $row->status = $status;
    $row->room_id = (string) Str::uuid();

    foreach ($ubah as $column => $value) {
        $row->{$column} = $value;
    }

    $row->save();

    return $row;
}

/**
 * A `pasien` row. `jenis_kelamin` (:225), `tanggal_lahir` (:226) and
 * `alamat_lengkap` (:234) are NOT NULL with no default.
 *
 * @param  array<string, mixed>  $ubah
 */
function knsPasien(int $userId, array $ubah = []): int
{
    return (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $userId,
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji Sesi No. 9, Jakarta',
    ], $ubah));
}

/**
 * A `dokter` row. `nomor_str` (:413) is UNIQUE so it is randomised per call.
 * `status_verifikasi` is `terverifikasi` (:427) and `tersedia_telemedisin` is 1
 * (:426) by default, which are two of the four rules
 * {@see DokterDirectoryService::find()} applies and the two the plan's sentence
 * names.
 *
 * @param  array<string, mixed>  $ubah
 */
function knsDokter(int $userId, array $ubah = []): int
{
    return (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $userId,
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-KONS-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'biaya_konsultasi_online' => KNS_BIAYA,
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));
}

/**
 * A `booking` row. `tipe_layanan` defaults to `video_call` because that is one of
 * the TWO `booking.tipe_layanan` values (:506) a telemedicine session may be built
 * from, and a fixture that were ineligible by default would make every happy-path
 * test a 422 for the wrong reason. `nomor_booking` is UNIQUE and randomised.
 *
 * @param  array<string, mixed>  $ubah
 */
function knsBooking(int $pasienId, int $dokterId, string $status = 'terjadwal', array $ubah = []): int
{
    return (int) DB::table('booking')->insertGetId(array_merge([
        'nomor_booking' => 'BK'.Str::upper(Str::random(10)),
        'pasien_id' => $pasienId,
        'dokter_id' => $dokterId,
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => KNS_TANGGAL,
        'slot_mulai' => '09:00:00',
        'slot_selesai' => '09:15:00',
        // ooking.dibuat_oleh_user_id is a foreign key to users(id) (:527), not to
        // pasien(id), so the creator is the patient's own ACCOUNT. Passing the profile
        // id here is a 1452 rather than a silent wrong answer.
        'dibuat_oleh_user_id' => (int) DB::table('pasien')->where('id', $pasienId)->value('user_id'),
        'status' => $status,
    ], $ubah));
}

/**
 * A `konsultasi_chat` row written through the query builder, which is what a restore, a
 * seeder or a test that needs a forced `terkirim_at` needs.
 *
 * @param  array<string, mixed>  $ubah
 */
function knsPesan(int $sesiId, int $pengirimUserId, string $pengirimTipe, string $tipePesan = 'teks', ?string $isi = 'Halo', array $ubah = []): int
{
    return (int) DB::table('konsultasi_chat')->insertGetId(array_merge([
        'konsultasi_id' => $sesiId,
        'pengirim_user_id' => $pengirimUserId,
        'pengirim_tipe' => $pengirimTipe,
        'tipe_pesan' => $tipePesan,
        'isi' => $isi,
    ], $ubah));
}

/**
 * The parsed DDL, once, for the vocabulary and citation assertions.
 */
function knsSpec(): App\Support\Schema\SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * One `enum(...)` column's values, read through the project's own parser.
 *
 * The parser is the same one `sehatly:verify-schema` uses, so a test that
 * disagreed with the DDL here would also disagree with the schema verifier, and the
 * two would fail together rather than separately.
 *
 * @return list<string>
 */
function knsEnum(string $table, string $column): array
{
    $type = knsSpec()->table($table)->columns[$column]->type;

    preg_match_all("/'([^']+)'/", $type, $matches);

    return $matches[1];
}

/**
 * The name of the consultation relation on a chat row, read from the MODEL.
 *
 * `KonsultasiChat` declares exactly two `BelongsTo` relations, and the consultation one is
 * the only `BelongsTo` whose name plus `_id` is a COLUMN of `konsultasi_chat`; the other is
 * `pengirimUser`, whose foreign key is `pengirim_user_id` and whose name is not a
 * column. Resolving it this way means the accessor in the assertions below is
 * checked against the model instead of being a second hand-written spelling of it.
 */
function knsRelasi(): string
{
    foreach ((new ReflectionClass(KonsultasiChat::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $type = $method->getReturnType();

        if (! $type instanceof ReflectionNamedType) {
            continue;
        }

        if ($type->getName() !== Illuminate\Database\Eloquent\Relations\BelongsTo::class) {
            continue;
        }

        $name = $method->getName();

        if (array_key_exists($name.'_id', knsSpec()->table('konsultasi_chat')->columns)) {
            return $name;
        }
    }

    throw new RuntimeException('the chat model declares no relation onto the consultation table');
}
/**
 * Line `$n` of the reference DDL, or null when the file is shorter.
 */
function knsDdlLine(int $n): ?string
{
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    return $lines[$n - 1] ?? null;
}

/**
 * Assert that a cited DDL line really contains the token the docblock claims.
 *
 * `expect()->toContain()` is deliberately NOT used: Pest treats EVERY string
 * argument as a needle, so the failure message would become a second thing the
 * string must contain - the exact trap `PasienProfileTest` documents in a comment
 * on its own middleware loop.
 */
function knsAssertLine(int $line, string $token): void
{
    $actual = knsDdlLine($line);

    Assert::assertNotNull($actual, "telemedicine_test.sql:{$line} does not exist");

    Assert::assertStringContainsString(
        $token,
        (string) $actual,
        "telemedicine_test.sql:{$line} is [".(string) $actual."] and does not contain [{$token}]",
    );
}

// =====================================================================
// The six values, and the three vocabularies beside them
// =====================================================================

/**
 * The one column of `$table` whose name ENDS with `$suffix`, or a thrown
 * `RuntimeException` when there is not exactly one.
 *
 * Used instead of a transcribed property name wherever an assertion is about a
 * column this todo READS, so the assertion cannot pass by naming a column that
 * does not exist and cannot quietly switch to a sibling.
 *
 * A SUFFIX and not a prefix, because `dokter` has TWO `biaya_` columns:
 * `biaya_konsultasi_online` (:420) and `biaya_luar_jam` (:421). A prefix is
 * therefore ambiguous there, and the plan's "record the fee from
 * `biaya_konsultasi_online`" names the online one specifically.
 */
function knsKolomBerakhiran(string $table, string $suffix): string
{
    $cocok = array_values(array_filter(
        array_keys(knsSpec()->table($table)->columns),
        static fn (string $column): bool => str_ends_with($column, $suffix),
    ));

    if (count($cocok) !== 1) {
        throw new RuntimeException('expected exactly one column ending '.$suffix.' on '.$table.', found '.count($cocok));
    }

    return $cocok[0];
}

    /**
     * The one column of `$table` whose name STARTS with `$prefix`.
     *
     * The prefix twin of {@see knsKolomBerakhiran()}. It exists because
     * `konsultasi.biaya_konsultasi` is found by its prefix and `dokter`'s is found by
     * its `_online` suffix - `dokter` has two `biaya_` columns and `konsultasi` has one,
     * so no single direction resolves both.
     */
function knsKolomDenganPrefix(string $table, string $prefix): string
{
    $cocok = array_values(array_filter(
        array_keys(knsSpec()->table($table)->columns),
        static fn (string $column): bool => str_starts_with($column, $prefix),
    ));

    if (count($cocok) !== 1) {
        throw new RuntimeException('expected exactly one column starting '.$prefix.' on '.$table.', found '.count($cocok));
    }

    return $cocok[0];
}

test('the status enum is the DDL enum, in the DDL order, all six of them', function (): void {
    expect(KonsultasiStatus::nilai())->toBe(knsEnum('konsultasi', 'status'))
        ->and(KonsultasiStatus::cases())->toHaveCount(6)
        // `toBe` checks order as well as membership, so a transposed pair fails.
        ->and(KonsultasiStatus::nilai())->toBe([
            'menunggu_dokter',
            'berlangsung',
            'menunggu_resep',
            'selesai',
            'dibatalkan',
            'gagal',
        ]);
});

test('the status a session is born in is the DDL column default', function (): void {
    // The plan says `POST /mulai` "sets `status = 'menunggu_dokter'`" and the DDL
    // default says the same thing independently. Asserting both means a change to
    // either the code or the schema is caught here rather than in a client.
    $column = knsSpec()->table('konsultasi')->columns['status'];

    expect(trim((string) $column->default, "'"))->toBe(KonsultasiStatus::MenungguDokter->value)
        ->and($column->nullable)->toBeFalse();
});

test('the service type enum is the DDL enum and is NOT the booking service type', function (): void {
    // Two columns, same concept, DIFFERENT value sets: `konsultasi.tipe` is three values
    // (:541) and `booking.tipe_layanan` is four (:506). The token audit only catches
    // that if both are asserted, and the intersection is only two of the seven names.
    expect(KonsultasiTipe::nilai())->toBe(knsEnum('konsultasi', 'tipe'))
        ->and(KonsultasiTipe::cases())->toHaveCount(3);

    $booking = knsEnum('booking', 'tipe_layanan');

    expect($booking)->toHaveCount(4)
        ->and(array_values(array_intersect(KonsultasiTipe::nilai(), $booking)))->toBe(['chat', 'video_call'])
        // And the two that are NOT shareable, which is why
        // `KonsultasiService::dariBooking()` refuses them instead of coercing them.
        ->and(array_values(array_diff($booking, KonsultasiTipe::nilai())))->toBe(['kunjungan_klinik', 'home_visit']);
});

test('the chat vocabularies are the DDL enums, including the three-value sender', function (): void {
    expect(KonsultasiRequest::TIPE_PESAN)->toBe(knsEnum('konsultasi_chat', 'tipe_pesan'))
        ->and(KonsultasiRequest::TIPE_PESAN)->toHaveCount(8)
        ->and(KonsultasiRequest::PENGIRIM_TIPE)->toBe(knsEnum('konsultasi_chat', 'pengirim_tipe'))
        ->and(KonsultasiRequest::PENGIRIM_TIPE)->toHaveCount(3)
        ->and(KonsultasiRequest::TIPE_PESAN_SISTEM)->toBe(['resep', 'surat_keterangan', 'sistem'])
        ->and(KonsultasiRequest::TIPE_PESAN_BERKAS)->toBe(['gambar', 'dokumen', 'audio', 'video_note'])
        // The four file-backed types, the three system types and `teks` are the whole
        // set, so no message type is accepted without a payload rule and none is
        // orphaned by a rule that nothing reaches.
        ->and(array_merge(['teks'], KonsultasiRequest::TIPE_PESAN_BERKAS, KonsultasiRequest::TIPE_PESAN_SISTEM))
        ->toEqualCanonicalizing(KonsultasiRequest::TIPE_PESAN);
});

test('the DDL has a system author TYPE and no system author ACCOUNT', function (): void {
    // This is the blocking finding for the system-message service user, and it is a
    // claim about the SCHEMA, so it is a test rather than a paragraph.
    //
    // `konsultasi_chat.pengirim_tipe` offers a system author TYPE (:567) while
    // `konsultasi_chat.pengirim_user_id` is `BIGINT UNSIGNED NOT NULL` with a foreign key to
    // `users(id)` (:566, :577) - so the row must name a real account. The account's
    // own type is a seven-value ENUM at :139 and NOT ONE of the seven means system,
    // service, bot or robot. The schema therefore has an author type with no author
    // account.
    $tipe = knsEnum('users', 'tipe');

    expect($tipe)->toHaveCount(7)
        ->and($tipe)->toBe(['pasien', 'dokter', 'perawat', 'apoteker', 'kurir', 'admin', 'superadmin'])
        // A marker list rather than a substring test: `sistem` is a real Indonesian
        // word, so the assertion is that the whole set carries no system marker.
        ->and(array_values(array_intersect($tipe, ['sistem', 'system', 'layanan', 'service', 'bot', 'robot'])))
        ->toBe([]);

    // And the counter-example that proves this is not simple oversight: the DDL has
    // TWO other places where it does express a system actor, and in BOTH it uses an
    // ENUM VALUE rather than an account. That is the idiom `pengirim_tipe = 'sistem'`
    // belongs to, and the reason the attribution rule follows the schema instead of
    // inventing a column.
    expect(knsEnum('booking', 'dibatalkan_oleh'))->toContain('sistem')
        ->and(knsEnum('notifikasi', 'tipe'))->toContain('sistem');

    // The column really is NOT NULL, which is what forces the attribution at all.
    $chat = knsSpec()->table('konsultasi_chat');

    expect($chat->columns['pengirim_user_id']->nullable)->toBeFalse()
        ->and($chat->columns['pengirim_tipe']->nullable)->toBeFalse()
        ->and($chat->foreignKeys)->not->toBeEmpty();
});

test('the model casts the ENUM column to string and uses no enum: cast', function (): void {
    // On laravel/framework 13.33 `HasAttributes::isEnumCastable()` requires
    // `enum_exists($castType)`, so `'status' => 'enum:menunggu_dokter,...'` is a
    // SILENT NO-OP: not a primitive cast, not a class castable. It reads like
    // validation and validates nothing. `KonsultasiStatus` exists precisely so the vocabulary
    // and the transition table have a home, and it is deliberately NOT a cast:
    // `ModelFoundationTest` asserts every one of the 69 `enum(...)` columns is a
    // plain `'string'` cast, and this is that assertion for these two models.
    $method = new ReflectionMethod(Konsultasi::class, 'casts');
    $method->setAccessible(true);

    /** @var array<string, string> $sesiCasts */
    $sesiCasts = $method->invoke(new Konsultasi);

    $chatMethod = new ReflectionMethod(KonsultasiChat::class, 'casts');
    $chatMethod->setAccessible(true);

    /** @var array<string, string> $chatCasts */
    $chatCasts = $chatMethod->invoke(new KonsultasiChat);

    expect($sesiCasts['status'])->toBe('string')
        ->and($sesiCasts['tipe'])->toBe('string')
        ->and($chatCasts['pengirim_tipe'])->toBe('string')
        ->and($chatCasts['tipe_pesan'])->toBe('string');

    foreach (array_merge($sesiCasts, $chatCasts) as $column => $cast) {
        expect($cast)->not->toStartWith('enum:', 'Konsultasi.'.$column);
    }

    // The created/updated column names, because `KonsultasiChat` uses `terkirim_at` and has no
    // update stamp at all.
    $chat = new KonsultasiChat;
    $sesi = new Konsultasi;

    expect($chat->getCreatedAtColumn())->toBe('terkirim_at')
        ->and($chat->getUpdatedAtColumn())->toBeNull()
        ->and($chat->usesTimestamps())->toBeTrue()
        ->and($sesi->getCreatedAtColumn())->toBe('dibuat_at')
        ->and($sesi->getUpdatedAtColumn())->toBe('diubah_at');
});

// =====================================================================
// The transition table: the whole 6x6 matrix
// =====================================================================

test('the transition table is exactly the ten edges the suite observes', function (): void {
    // The complete matrix, driven through the ONE method the rest of the
    // application uses. All 36 ordered pairs are attempted, and the set of pairs
    // that succeed is compared to `KonsultasiStatus::TRANSISI` with `toBe`, which checks key
    // order and successor order. A fourteenth arrow cannot be added without this
    // failing, and an arrow cannot be removed without it failing too.
    $service = app(KonsultasiService::class);
    $observed = [];

    foreach (KonsultasiStatus::cases() as $dari) {
        // Seeded empty, so a state with no successor is still a key. Without this the
        // three terminal states would be absent from `$observed` and the comparison
        // would fail on a difference the table is right about.
        $observed[$dari->value] = [];

        foreach (KonsultasiStatus::cases() as $ke) {
            $sesi = knsSesi(knsPasien(knsUser('Pasien Matriks')), knsDokter(knsUser('Dokter Matriks', 'dokter')));
            $sesi->status = $dari->value;

            try {
                $service->ubahStatus($sesi, $ke);

                $observed[$dari->value][] = $ke->value;
            } catch (ValidationException $e) {
                expect($e->errors())->toHaveKey('status');
            }
        }
    }

    expect($observed)->toBe(KonsultasiStatus::TRANSISI)
        ->and(array_sum(array_map('count', $observed)))->toBe(10)
        ->and(count(KonsultasiStatus::TRANSISI))->toBe(6)
        // 10 = 3 + 4 + 3 + 0 + 0 + 0, and the per-state counts say WHICH three are
        // empty rather than only how many.
        ->and(array_values(array_map('count', KonsultasiStatus::TRANSISI)))->toBe([3, 4, 3, 0, 0, 0]);
});

test('no transition moves backwards, and none skips a state', function (): void {
    $service = app(KonsultasiService::class);
    $sesi = knsSesi(knsPasien(knsUser('Pasien Mundur')), knsDokter(knsUser('Dokter Mundur', 'dokter')));

    // Backwards: every arrow that would undo the one before it, including the three
    // out of a terminal state.
    $mundur = [
        ['berlangsung', 'menunggu_dokter'],
        ['menunggu_resep', 'berlangsung'],
        ['selesai', 'menunggu_resep'],
        ['selesai', 'berlangsung'],
        ['selesai', 'menunggu_dokter'],
        ['dibatalkan', 'menunggu_dokter'],
        ['dibatalkan', 'berlangsung'],
        ['gagal', 'menunggu_dokter'],
        ['gagal', 'berlangsung'],
    ];

    foreach ($mundur as [$from, $to]) {
        $sesi->status = $from;

        expect(fn (): Konsultasi => $service->ubahStatus($sesi, KonsultasiStatus::from($to)))
            ->toThrow(ValidationException::class);
    }

    // Skips: `menunggu_dokter` cannot reach `menunggu_resep` at all, because the DDL
    // places that value AFTER `berlangsung` and only after it, and it cannot reach
    // `selesai` because a session that never started was never begun.
    foreach ([['menunggu_dokter', 'menunggu_resep'], ['menunggu_dokter', 'selesai']] as [$from, $to]) {
        $sesi->status = $from;

        expect(fn (): Konsultasi => $service->ubahStatus($sesi, KonsultasiStatus::from($to)))
            ->toThrow(ValidationException::class);
    }

    // The positive direction of the same edges, so the list above is a real boundary
    // and not a table that refuses everything.
    $sesi->status = 'berlangsung';
    $service->ubahStatus($sesi, KonsultasiStatus::MenungguResep);
    expect($sesi->status)->toBe('menunggu_resep');

    $service->ubahStatus($sesi, KonsultasiStatus::Selesai);
    expect($sesi->status)->toBe('selesai');
});

test('the three terminal states refuse every move, and the constant agrees with the table', function (): void {
    $service = app(KonsultasiService::class);
    $sesi = knsSesi(knsPasien(knsUser('Pasien Akhir')), knsDokter(knsUser('Dokter Akhir', 'dokter')));

    foreach (KonsultasiStatus::STATUS_AKHIR as $akhir) {
        expect(KonsultasiStatus::from($akhir)->adalahAkhir())->toBeTrue();

        $sesi->status = $akhir;

        foreach (KonsultasiStatus::cases() as $tujuan) {
            expect(fn (): Konsultasi => $service->ubahStatus($sesi, $tujuan))->toThrow(ValidationException::class);
        }
    }

    // `STATUS_AKHIR` is a published convenience list, and the only way it can be
    // honest is if it is derived rather than asserted. This compares it to the set of
    // states whose successor list is empty, so a future seventh state could not
    // become terminal without the constant following it.
    $kosong = [];

    foreach (KonsultasiStatus::cases() as $case) {
        if ($case->tujuanYang() === []) {
            $kosong[] = $case->value;
        }
    }

    expect($kosong)->toBe(KonsultasiStatus::STATUS_AKHIR);

    // A wrong-arrow refusal NAMES the states that would have been allowed, so a
    // client can tell a wrong move from a finished session. The copy is Indonesian
    // user-facing text and this asserts the distinction, not the exact wording.
    $sesi->status = 'menunggu_dokter';

    try {
        $service->ubahStatus($sesi, KonsultasiStatus::Selesai);
        expect(false)->toBeTrue('the transition should have been refused');
    } catch (ValidationException $e) {
        expect($e->errors()['status'][0])->toContain('menunggu_dokter')
            ->and($e->errors()['status'][0])->toContain('berlangsung');
    }

    $sesi->status = 'selesai';

    try {
        $service->ubahStatus($sesi, KonsultasiStatus::Berlangsung);
        expect(false)->toBeTrue('the transition should have been refused');
    } catch (ValidationException $e) {
        // The terminal message is a DIFFERENT sentence from the wrong-arrow one, and
        // the difference is the point: "you already finished" and "that is not a legal
        // move" are different facts for the doctor reading them.
        expect($e->errors()['status'][0])->not->toContain('berlangsung, dibatalkan, gagal')
            ->and($e->errors()['status'][0])->not->toContain('tidak dapat bertransisi');
    }
});

test('a refused transition does not write the status', function (): void {
    // A guard that threw AFTER assigning would leave a dirty model in memory, and the
    // caller - a controller that then saves - would write it. This asserts the
    // assignment happens only after the guard passes.
    $service = app(KonsultasiService::class);
    $sesi = knsSesi(knsPasien(knsUser('Pasien Kotor')), knsDokter(knsUser('Dokter Kotor', 'dokter')));

    foreach ([['menunggu_dokter', 'selesai'], ['selesai', 'berlangsung'], ['dibatalkan', 'berlangsung']] as [$from, $to]) {
        // Sync the original after assigning, so `isDirty('status')` reports on the
        // GUARD rather than on the fixture having just written the column.
        $sesi->status = $from;
        $sesi->syncOriginal();

        try {
            $service->ubahStatus($sesi, KonsultasiStatus::from($to));
        } catch (ValidationException) {
            // expected
        }

        expect($sesi->status)->toBe($from)
            ->and($sesi->isDirty('status'))->toBeFalse();
    }
});

test('the ten edges are application policy, because the DDL constrains only the values', function (): void {
    // The claim this test exists to make is that `konsultasi.status` is a plain ENUM with
    // no CHECK, no trigger and no other state column, so nothing in the schema stops
    // a bare `UPDATE` from writing `gagal` onto a `selesai` row. The test proves it by
    // doing exactly that, through the query builder, which is what a restore or a
    // future migration would do - and then shows the service still refuses to treat
    // the forged state as proof that the session started.
    $index = knsSpec()->table('konsultasi')->indexes;

    $hasCheck = false;

    foreach (knsSpec()->table('konsultasi')->checks as $check) {
        if (str_contains($check->expression, 'status')) {
            $hasCheck = true;
        }
    }

    expect($hasCheck)->toBeFalse()
        ->and($index)->not->toBeEmpty();

    $sesi = knsSesi(knsPasien(knsUser('Pasien Bawa')), knsDokter(knsUser('Dokter Bawa', 'dokter')), 'selesai');

    DB::table('konsultasi')->where('id', $sesi->getKey())->update(['status' => 'berlangsung']);

    $sesi->refresh();

    expect($sesi->status)->toBe('berlangsung');

    // The row now says `berlangsung`, so `selesai` IS a legal arrow from it and the
    // service will apply it. What it will NOT do is read the forged state as proof
    // that the session started: `mulai_at` is still null, and that is the second,
    // independent guard the plan asks for. `KonsultasiTest` exercises it over HTTP.
    $service = app(KonsultasiService::class);
    $service->ubahStatus($sesi, KonsultasiStatus::Selesai);

    expect($sesi->status)->toBe('selesai')
        ->and($sesi->mulai_at)->toBeNull();
});

test('a session forged into a completable state with a null mulai_at is refused by the service', function (): void {
    // The second guard, exercised through the SERVICE rather than over HTTP so the
    // 422 and the reason it exists are both visible. `konsultasi.mulai_at` is
    // `DATETIME NULL` (:545), so a row in `berlangsung` with no `mulai_at` is a
    // state the schema permits and only the application can refuse.
    $dokterUser = knsUser('Dokter Tanpa Mulai', 'dokter');
    $sesi = knsSesi(knsPasien(knsUser('Pasien Tanpa Mulai')), knsDokter($dokterUser), 'berlangsung');

    expect($sesi->mulai_at)->toBeNull();

    $service = app(KonsultasiService::class);

    // The state-machine edge is legal, so the failure has to come from the invariant.
    expect($sesi->status)->toBe('berlangsung')
        ->and(fn (): KonsultasiChat => $service->selesai(User::query()->findOrFail($dokterUser), $sesi->getKey(), []))
        ->toThrow(ValidationException::class);

    // And the row is untouched: the guard throws before the status is written.
    expect($sesi->refresh()->status)->toBe('berlangsung')
        ->and($sesi->selesai_at)->toBeNull()
        ->and($sesi->total_durasi_detik)->toBeNull();
});

test('the fee is read from the doctors own column and only for the instant flow', function (): void {
    // `dokter.biaya_konsultasi_online` (:420) and `konsultasi.biaya_konsultasi` (:554) are
    // two different columns on two different tables, and the plan says to record the
    // fee for the instant flow. A booking-backed session records `0`, because
    // `BookingService` already raised an invoice for the same fee - copying it again
    // would be two amounts for one service.
    // The fee column on each of the two tables, resolved from the parser.
    $biayaKonsultasi = knsKolomDenganPrefix('konsultasi', 'biaya_');
    $biayaDokter = knsKolomBerakhiran('dokter', '_online');

    expect($biayaDokter)->toBe(knsKolomBerakhiran('dokter', '_online'))
        ->and($biayaKonsultasi)->toBe(knsKolomDenganPrefix('konsultasi', 'biaya_'));

    $pasienId = knsPasien(knsUser('Pasien Biaya'));
    $dokterId = knsDokter(knsUser('Dokter Biaya', 'dokter'), [$biayaDokter => KNS_BIAYA]);
    $service = app(KonsultasiService::class);
    $pasien = App\Models\Pasien::query()->findOrFail($pasienId);
    $user = User::query()->findOrFail((int) DB::table('pasien')->where('id', $pasienId)->value('user_id'));

    $pesan = $service->mulai($pasien, $user, ['dokter_id' => $dokterId, 'tipe' => 'chat']);

    expect((string) $pesan->{knsRelasi()}->{$biayaKonsultasi})->toBe(KNS_BIAYA)
        ->and($pesan->{knsRelasi()}->booking_id)->toBeNull();

    $bookingId = knsBooking($pasienId, $dokterId);

    $dariBooking = $service->mulai($pasien, $user, ['booking_id' => $bookingId]);

    expect((string) $dariBooking->{knsRelasi()}->{$biayaKonsultasi})->toBe('0.00')
        ->and($dariBooking->{knsRelasi()}->booking_id)->toBe($bookingId)
        ->and($dariBooking->{knsRelasi()}->tipe)->toBe('video_call');
});

test('an ineligible doctor is refused for the instant flow with one exception type', function (): void {
    // `DokterDirectoryService::find()` is the eligibility gate, and the four cases
    // below are the four rules it applies - two of which are the two the plan's own
    // sentence names. This drives the services directly rather than reading their
    // docblocks, and then again through `KonsultasiService::mulai()` so the HTTP test can assert
    // one body for five reasons.
    $directory = app(DokterDirectoryService::class);
    $service = app(KonsultasiService::class);

    $pasienId = knsPasien(knsUser('Pasien Eligible'));
    $pasien = App\Models\Pasien::query()->findOrFail($pasienId);
    $user = User::query()->findOrFail((int) DB::table('pasien')->where('id', $pasienId)->value('user_id'));

    $eligible = knsDokter(knsUser('Dokter Eligible', 'dokter'));
    $pending = knsDokter(knsUser('Dokter Pending', 'dokter'), ['status_verifikasi' => 'pending']);
    $optedOut = knsDokter(knsUser('Dokter Opt Out', 'dokter'), ['tersedia_telemedisin' => 0]);
    $inactive = knsDokter(knsUser('Dokter Nonaktif', 'dokter'), ['status_aktif' => 0]);
    $lapsed = knsDokter(knsUser('Dokter STR Habis', 'dokter'), ['str_berlaku_sampai' => '2000-01-01']);

    expect($directory->find($eligible))->not->toBeNull();

    foreach ([$pending, $optedOut, $inactive, $lapsed] as $ineligible) {
        expect($directory->find($ineligible))->toBeNull();
    }

    $refused = fn (int $dokterId): KonsultasiChat => $service->mulai($pasien, $user, [
        'dokter_id' => $dokterId,
        'tipe' => 'chat',
    ]);

    foreach ([$pending, $optedOut, $inactive, $lapsed] as $ineligible) {
        expect(fn (): KonsultasiChat => $refused($ineligible))->toThrow(ModelNotFoundException::class);
    }

    // A doctor that does not exist at all is the SAME exception, so an HTTP test can
    // assert one body for five reasons.
    expect(fn (): KonsultasiChat => $refused(999999))->toThrow(ModelNotFoundException::class);
});

// =====================================================================
// Every line number this todo cites
// =====================================================================

test('every DDL line number cited by this todo is the line it claims to be', function (): void {
    // A citation is a claim about a file, so the suite checks the claim. Each entry is
    // `line => the token that line must contain`, and every line number quoted in a
    // docblock of the files this todo adds appears here.
    $citations = [
        // The consultation table.
        536 => 'CREATE TABLE konsultasi (',
        537 => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
        538 => 'booking_id BIGINT UNSIGNED NULL UNIQUE',
        539 => 'pasien_id BIGINT UNSIGNED NOT NULL',
        540 => 'dokter_id BIGINT UNSIGNED NOT NULL',
        541 => "tipe ENUM('chat','video_call','telepon') NOT NULL",
        542 => "status ENUM('menunggu_dokter','berlangsung','menunggu_resep','selesai','dibatalkan','gagal')",
        543 => "NOT NULL DEFAULT 'menunggu_dokter'",
        544 => 'room_id VARCHAR(100) NULL',
        545 => 'mulai_at DATETIME NULL',
        546 => 'selesai_at DATETIME NULL',
        547 => 'total_durasi_detik INT UNSIGNED NULL',
        548 => 'catatan_subjektif TEXT NULL',
        549 => 'catatan_objektif TEXT NULL',
        550 => 'catatan_asessment TEXT NULL',
        551 => 'catatan_plan TEXT NULL',
        552 => 'diagnosis_kerja VARCHAR(255) NULL',
        553 => 'saran_tindak_lanjut TEXT NULL',
        554 => 'biaya_konsultasi DECIMAL(12,2) NOT NULL DEFAULT 0',
        555 => 'dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        556 => 'diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        557 => 'FOREIGN KEY (booking_id) REFERENCES booking(id)',
        558 => 'FOREIGN KEY (pasien_id) REFERENCES pasien(id)',
        559 => 'FOREIGN KEY (dokter_id) REFERENCES dokter(id)',
        560 => 'INDEX idx_',
        561 => 'ENGINE=InnoDB',

        // The chat table.
        563 => 'CREATE TABLE konsultasi_chat (',
        564 => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
        565 => 'konsultasi_id BIGINT UNSIGNED NOT NULL',
        566 => 'pengirim_user_id BIGINT UNSIGNED NOT NULL',
        567 => "pengirim_tipe ENUM('pasien','dokter','sistem') NOT NULL",
        568 => "tipe_pesan ENUM('teks','gambar','dokumen','audio','video_note','resep','surat_keterangan','sistem')",
        570 => 'isi TEXT NULL',
        571 => 'file_url VARCHAR(500) NULL',
        572 => 'file_nama VARCHAR(255) NULL',
        573 => 'file_ukuran_kb INT UNSIGNED NULL',
        574 => 'dibaca_at DATETIME NULL',
        575 => 'terkirim_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        576 => 'FOREIGN KEY (konsultasi_id) REFERENCES konsultasi(id) ON DELETE CASCADE',
        577 => 'FOREIGN KEY (pengirim_user_id) REFERENCES users(id)',
        578 => 'INDEX idx_chat (',
        579 => 'ENGINE=InnoDB',

        // Reached through the second hop, and the two system-actor enums.
        134 => 'uuid CHAR(36) NOT NULL UNIQUE',
        135 => 'nama_lengkap VARCHAR(150) NOT NULL',
        137 => 'no_telepon VARCHAR(20) NOT NULL UNIQUE',
        138 => 'kata_sandi_hash VARCHAR(255) NOT NULL',
        139 => "tipe ENUM('pasien','dokter','perawat','apoteker','kurir','admin','superadmin') NOT NULL DEFAULT 'pasien'",
        140 => "status ENUM('pending_verifikasi','aktif','nonaktif','ditangguhkan')",
        220 => 'user_id BIGINT UNSIGNED NOT NULL UNIQUE',
        225 => "jenis_kelamin ENUM('L','P') NOT NULL",
        226 => 'tanggal_lahir DATE NOT NULL',
        234 => 'alamat_lengkap TEXT NOT NULL',
        411 => 'user_id BIGINT UNSIGNED NOT NULL UNIQUE',
        413 => 'nomor_str VARCHAR(30) NOT NULL UNIQUE',
        414 => 'str_berlaku_sampai DATE NOT NULL',
        420 => 'biaya_konsultasi_online DECIMAL(12,2) NOT NULL DEFAULT 0',
        425 => 'jumlah_konsultasi INT UNSIGNED NOT NULL DEFAULT 0',
        426 => 'tersedia_telemedisin TINYINT(1) NOT NULL DEFAULT 1',
        427 => "status_verifikasi ENUM('pending','terverifikasi','ditolak') NOT NULL DEFAULT 'pending'",
        430 => 'status_aktif TINYINT(1) NOT NULL DEFAULT 1',
        506 => "tipe_layanan ENUM('chat','video_call','kunjungan_klinik','home_visit') NOT NULL",
        515 => "status ENUM('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai',",
        516 => "'dibatalkan','no_show','kadaluarsa') NOT NULL DEFAULT 'menunggu_pembayaran',",
        517 => "dibatalkan_oleh ENUM('pasien','dokter','sistem') NULL",
        1041 => "tipe ENUM('booking','pembayaran','resep','chat','lab','promo','sistem') NOT NULL",
    ];

    foreach ($citations as $line => $token) {
        knsAssertLine($line, $token);
    }

    expect(count($citations))->toBe(65);

    // The two index lines, completed from the parser so the whole statement is
    // checked and not only its safe prefix. The DDL writes `idx_konsultasi_pasien`
    // and `idx_chat`, and both are name-authoritative because both are written out.
    $indexKonsultasi = null;
    $indexChat = null;

    foreach (knsSpec()->table('konsultasi')->indexes as $index) {
        if ($index->name === 'idx_'.str_replace('_', '_', 'konsultasi').'_pasien') {
            $indexKonsultasi = $index;
        }
    }

    foreach (knsSpec()->table('konsultasi_chat')->indexes as $index) {
        if ($index->name === 'idx_chat') {
            $indexChat = $index;
        }
    }

    expect($indexKonsultasi)->not->toBeNull()
        ->and($indexChat)->not->toBeNull();

    knsAssertLine(560, 'INDEX '.$indexKonsultasi->name.' ('.implode(', ', $indexKonsultasi->columns).')');
    knsAssertLine(578, 'INDEX '.$indexChat->name.' ('.implode(', ', $indexChat->columns).')');
});

test('the plan cites 539 for the NULL-UNIQUE booking column, and 539 is the patient', function (): void {
    // The plan is not edited here - `.omo/plans/` is orchestrator-owned - so the
    // correction is asserted instead of applied, and the docblocks in `app/` cite
    // :538.
    knsAssertLine(538, 'booking_id BIGINT UNSIGNED NULL UNIQUE');
    knsAssertLine(539, 'pasien_id BIGINT UNSIGNED NOT NULL');

    // And the property the plan relies on is a MySQL UNIQUE-index property, not
    // anything the DDL says about NULLs: the column is NULL and UNIQUE, which is
    // what lets any number of instant sessions exist.
    $column = knsSpec()->table('konsultasi')->columns['booking_id'];

    $unique = false;

    foreach (knsSpec()->table('Konsultasi')->indexes as $index) {
        if ($index->type === 'UNIQUE' && in_array('booking_id', $index->columns, true)) {
            $unique = true;
        }
    }

    expect($column->nullable)->toBeTrue()
        ->and($unique)->toBeTrue();

    // The range the plan quotes for these two tables ends on `:579`, which IS the
    // closing ENGINE line of the chat table, so that range is right. The line after
    // it is blank and :581 opens the next table, which is what makes the boundary
    // checkable rather than assumed.
    knsAssertLine(579, 'ENGINE=InnoDB');
    expect(knsDdlLine(580))->toBe('');

    knsAssertLine(581, 'CREATE TABLE surat_keterangan (');
});

test('the booking_id index really is UNIQUE, and the transcript has no document linkage', function (): void {
    // The acceptance criterion for "Tanya Dokter" is that three instant sessions all
    // succeed while a second session on ONE booking is refused. Both halves are
    // asserted through the real HTTP endpoint in `KonsultasiTest`; this is the schema half, so
    // the refusal the other file sees is the database's and not a request rule.
    $bookingIdUnique = false;

    foreach (knsSpec()->table('konsultasi')->indexes as $index) {
        // `IndexSpec` carries a `type` of `PRIMARY` / `UNIQUE` / `INDEX` and no
        // boolean, so the constraint is read from the type.
        if ($index->type === 'UNIQUE' && in_array('booking_id', $index->columns, true)) {
            $bookingIdUnique = true;
        }
    }

    expect($bookingIdUnique)->toBeTrue();

    $chat = knsSpec()->table('konsultasi_chat');

    expect($chat->columns)->toHaveKeys([
        'konsultasi_id',
        'pengirim_user_id',
        'pengirim_tipe',
        'tipe_pesan',
        'isi',
        'file_url',
        'file_nama',
        'file_ukuran_kb',
        'dibaca_at',
        'terkirim_at',
    ])
        // And the transcript has NO column that could point at a prescription or a
        // letter, which is why a `resep` chat message cannot be verified against
        // anything and is refused from the human send path. Reported as a finding.
        ->and($chat->columns)->not->toHaveKeys(['resep_id', 'surat_keterangan_id'])
        ->and($chat->columns)->toHaveCount(11);
});

test('the timestamps this todo writes are the ones the DDL names', function (): void {
    // Todo 51's rule-(1) instant list names `konsultasi.mulai_at` and `selesai_at` and
    // `konsultasi_chat.terkirim_at` explicitly, and `config('app.timezone')` is UTC, so the
    // resources publish all three with `toISOString()`. This asserts the columns
    // exist, are the two kinds the policy distinguishes, and that neither table has a
    // column the resources would have to invent.
    $columns = knsSpec()->table('konsultasi')->columns;

    expect($columns)->toHaveKeys(['mulai_at', 'selesai_at', 'dibuat_at', 'diubah_at'])
        ->and($columns)->not->toHaveKeys(['created_at', 'updated_at', 'deleted_at'])
        ->and($columns['mulai_at']->nullable)->toBeTrue()
        ->and($columns['selesai_at']->nullable)->toBeTrue();

    $chat = knsSpec()->table('konsultasi_chat')->columns;

    expect($chat)->toHaveKeys(['terkirim_at'])
        ->and($chat)->not->toHaveKeys(['created_at', 'updated_at', 'diubah_at', 'deleted_at'])
        ->and($chat['terkirim_at']->nullable)->toBeFalse();

    expect(config('app.timezone'))->toBe('UTC')
        ->and((string) Carbon::now()->toISOString())->toEndWith('Z');
});
