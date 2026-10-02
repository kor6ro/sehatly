<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Dokter;
use App\Models\Invoice;
use App\Models\MasterMetodePembayaran;
use App\Models\Pasien;
use App\Models\Pembayaran;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payment\PaymentGatewayService;
use App\Support\Rbac\RoleAssigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| F12 - shared fixtures for the cancellations, reschedule and refunds tests
|--------------------------------------------------------------------------
|
| Prefix `f12` because Pest loads every test file into one process and `bku*`,
| `pay45*`, `slot*`, `resep*` are already taken. The fixtures are deliberately
| small and explicit rather than reusing another file's helpers: this suite
| measures a ledger invariant, and a fixture that shared state with the booking
| suite would make a red test ambiguous about which suite broke.
|
| Every date is fixed and a Monday (the `dokter_jadwal.hari = 1` every window
| here is written with), for the same reason `BookingTest` fixes its dates:
| the STR rule and the elapsed-slot rule both compare against dates, and a
| `now()`-relative test would assert different things on different days.
|
*/

/** Monday, under the DDL's own `0=Minggu s.d. 6=Sabtu` numbering (:475). */
const F12_TANGGAL = '2026-12-07';

/** The next Monday, for the date-change half of a reschedule. */
const F12_TANGGAL_LAIN = '2026-12-14';

/** `dokter_jadwal.hari` for a Monday. */
const F12_HARI = 1;

/** The fee every doctor fixture charges, as a DECIMAL string. */
const F12_BIAYA = '150000.00';

/**
 * A `users` row plus its role. The four NOT NULL columns with no default are
 * `uuid` (:133), `nama_lengkap` (:135), `no_telepon` (:137) and
 * `kata_sandi_hash` (:138); `status` is `aktif` so no test excludes on account
 * state.
 */
function f12User(string $nama, string $tipe, ?string $role = null): User
{
    $id = (int) DB::table('users')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'nama_lengkap' => $nama,
        'no_telepon' => '08'.random_int(100000000, 999999999),
        'kata_sandi_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'tipe' => $tipe,
        'status' => 'aktif',
    ]);

    $role ??= in_array($tipe, ['pasien', 'dokter', 'admin', 'superadmin'], true) ? $tipe : null;

    if ($role !== null) {
        app(RoleAssigner::class)->assign($id, $role);
    }

    return User::query()->findOrFail($id);
}

/**
 * A `pasien` row for `$user`. `jenis_kelamin` (:225), `tanggal_lahir` (:226)
 * and `alamat_lengkap` (:234) are NOT NULL with no default.
 */
function f12Pasien(User $user, array $ubah = []): Pasien
{
    $id = (int) DB::table('pasien')->insertGetId(array_merge([
        'user_id' => $user->getKey(),
        'jenis_kelamin' => 'P',
        'tanggal_lahir' => '1990-05-17',
        'alamat_lengkap' => 'Jl. Uji F12 No. 1, Jakarta',
    ], $ubah));

    return Pasien::query()->findOrFail($id);
}

/** A doctor account: the `users` row, its `dokter` row and the `dokter` role. */
function f12DokterAkun(array $ubah = []): array
{
    $user = f12User('Dokter F12 '.Str::upper(Str::random(4)), 'dokter');

    $id = (int) DB::table('dokter')->insertGetId(array_merge([
        'user_id' => $user->getKey(),
        'tipe' => 'dokter_umum',
        'nomor_str' => 'STR-F12-'.Str::upper(Str::random(8)),
        'str_berlaku_sampai' => '2099-12-31',
        'pengalaman_tahun' => 5,
        'biaya_konsultasi_online' => F12_BIAYA,
        'durasi_default_menit' => 20,
        'tersedia_telemedisin' => 1,
        'status_verifikasi' => 'terverifikasi',
        'status_aktif' => 1,
    ], $ubah));

    return [$user, Dokter::query()->findOrFail($id)];
}

/**
 * A `dokter_jadwal` row: Monday 09:00-10:00, four 15-minute slots, no quota
 * override (NULL is read as 1).
 */
function f12Jadwal(int $dokterId, array $ubah = []): int
{
    return (int) DB::table('dokter_jadwal')->insertGetId(array_merge([
        'dokter_id' => $dokterId,
        'faskes_id' => null,
        'tipe_layanan' => 'online',
        'hari' => F12_HARI,
        'jam_mulai' => '09:00:00',
        'jam_selesai' => '10:00:00',
        'durasi_slot_menit' => 15,
        'kuota_per_sesi' => null,
        'berlaku_mulai' => '2020-01-01',
        'berlaku_sampai' => null,
        'status_aktif' => 1,
    ], $ubah));
}

/** A `master_metode_pembayaran` row of `$tipe`; `kode` and `nama` are NOT NULL. */
function f12Metode(string $tipe): int
{
    return (int) DB::table('master_metode_pembayaran')->insertGetId([
        'kode' => 'F12-'.Str::upper(Str::random(8)),
        'nama' => 'Metode F12 '.$tipe,
        'tipe' => $tipe,
        'penyedia' => 'Penyedia F12',
    ]);
}

/** Make the next request carry a real Sanctum bearer token for `$user`. */
function f12As(User $user): void
{
    app('auth')->forgetGuards();

    test()->withToken($user->createToken('f12-test', ['*'], now()->addHour())->plainTextToken);
}

/**
 * A minimal valid `POST /booking` body.
 *
 * @return array<string, mixed>
 */
function f12Payload(int $dokterId, array $ubah = []): array
{
    return array_merge([
        'dokter_id' => $dokterId,
        'jadwal_id' => null,
        'tipe_layanan' => 'video_call',
        'tanggal_kunjungan' => F12_TANGGAL,
        'slot_mulai' => '09:00:00',
        'keluhan' => 'Kontrol rutin.',
    ], $ubah);
}

/**
 * A complete unpaid scenario: a doctor with one Monday window, a patient with
 * the `pasien` role, and a real booking + invoice created through HTTP.
 *
 * @return array{user: User, pasien: Pasien, dokter_user: User, dokter: Dokter, booking: Booking, invoice: Invoice}
 */
function f12Skenario(?User $pasienUser = null): array
{
    [$dokterUser, $dokter] = f12DokterAkun();
    $jadwal = f12Jadwal($dokter->getKey());

    $pasienUser ??= f12User('Pasien F12 '.Str::upper(Str::random(4)), 'pasien');
    $pasien = f12Pasien($pasienUser);

    f12As($pasienUser);

    test()->postJson('/api/v1/booking', f12Payload($dokter->getKey(), ['jadwal_id' => $jadwal]))
        ->assertCreated();

    $booking = Booking::query()->where('pasien_id', $pasien->getKey())->orderByDesc('id')->firstOrFail();
    $invoice = Invoice::query()
        ->where('referensi_tipe', 'booking')
        ->where('referensi_id', $booking->getKey())
        ->firstOrFail();

    return [
        'user' => $pasienUser,
        'pasien' => $pasien,
        'dokter_user' => $dokterUser,
        'dokter' => $dokter,
        'jadwal_id' => $jadwal,
        'booking' => $booking,
        'invoice' => $invoice,
    ];
}

/** Drop any bearer token and cached guard, so the next request is anonymous. */
function f12TanpaAuth(): void
{
    app('auth')->forgetGuards();

    test()->flushHeaders();
}

/**
 * Mark `$invoice` paid with a `berhasil` `pembayaran` row of `$tipe`.
 *
 * The payment is inserted directly rather than driven through
 * `POST /invoice/{id}/bayar` + the signed webhook, because the cancellation
 * ledger's input is "a settled payment exists" and the webhook path has its
 * own suite. `$invoice.lunas_at` is stamped like a real settlement, because
 * the ledger's read path does not depend on it but the fixture should not be
 * less true than the flow it stands in for.
 */
function f12Bayar(Invoice $invoice, string $tipe): Pembayaran
{
    $pembayaran = new Pembayaran;
    $pembayaran->invoice_id = $invoice->getKey();
    $pembayaran->metode_id = f12Metode($tipe);
    $pembayaran->jumlah = (string) $invoice->total;
    $pembayaran->nomor_referensi = 'MOCK-F12-'.Str::upper(Str::random(8));
    $pembayaran->gateway = 'midtrans';
    $pembayaran->status = 'berhasil';
    $pembayaran->dibayar_at = now();
    $pembayaran->save();

    $invoice->status = 'lunas';
    $invoice->lunas_at = now();
    $invoice->save();

    return $pembayaran;
}

/**
 * Every `refund` row for one payment, oldest first.
 *
 * @return list<Refund>
 */
function f12Refund(int $pembayaranId): array
{
    return Refund::query()
        ->where('pembayaran_id', $pembayaranId)
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * A gateway stand-in whose `refund()` either throws or reports failure.
 *
 * Implements the whole interface because PHP requires it; the other three
 * methods throw if called, which is itself an assertion - a cancellation that
 * reached `createTransaction` or `verifyWebhook` would be a wiring bug.
 */
function f12GatewayRusak(bool $lempar): PaymentGatewayService
{
    return new class($lempar) implements PaymentGatewayService
    {
        public int $dipanggil = 0;

        public function __construct(private readonly bool $lempar) {}

        public function nama(): string
        {
            return 'midtrans';
        }

        public function dapatkah(string $kode): bool
        {
            return in_array($kode, ['midtrans', 'xendit', 'doku', 'flip'], true);
        }

        public function createTransaction(Invoice $invoice, MasterMetodePembayaran $metode): array
        {
            throw new RuntimeException('createTransaction tidak dipakai di test ini.');
        }

        public function refund(Pembayaran $pembayaran): array
        {
            $this->dipanggil++;

            if ($this->lempar) {
                throw new RuntimeException('gateway timeout');
            }

            return ['berhasil' => false, 'referensi' => null, 'pesan' => 'saldo tujuan ditutup'];
        }

        public function verifyWebhook(Request $request): array
        {
            throw new RuntimeException('verifyWebhook tidak dipakai di test ini.');
        }
    };
}
