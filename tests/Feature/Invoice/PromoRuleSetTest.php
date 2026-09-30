<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Services\Invoice\InvoiceService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/invoice-helpers.php';

/*
|--------------------------------------------------------------------------
| Todo 44 - the promo rule set, rule by rule
|--------------------------------------------------------------------------
|
| `InvoicePromoTest` covers the invoice and the polymorphic reference; this
| file covers what makes a promo VALID, which is a rule set evaluated against
| `master_promo` rather than a lookup of a code.
|
| ## Every rule is proved at its BOUNDARY
|
| "some promo is rejected" is a statement about a fixture, not about a rule.
| Each rule here is tested on both sides of its own edge: the last instant or
| the last use that is accepted, and the first one that is refused. The window
| is tested to the second, the total quota to the exact redemption count, and
| the per-user limit per patient rather than globally.
|
| ## Every rejection is INDEPENDENT
|
| The four rules are reported under four different keys, so a 422 body says
| which one failed without any prose parsing. The two fixtures that the plan
| and the brief both name - in-window but over-quota, and in-quota but
| out-of-window - are built as separate cases, and one fixture that fails four
| rules at once is asserted to report all four under their own keys in a single
| exception.
|
*/

// =====================================================================
// The five rules, one at a time
// =====================================================================

beforeEach(function (): void {
    // See `InvoicePromoTest`: `RoleAssigner` refuses a role whose `roles` row
    // is missing, and every fixture here assigns one.
    $this->seed(RbacSeeder::class);
});

test('an unknown promo code is rejected on kode, and a case-folded code is not the same code', function (): void {
    $pasienId = inv44Pasien(inv44User('Pemilik Kode Hantu')->getKey());
    $layanan = app(InvoiceService::class);

    $ditolak = inv44Tangkap(
        fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, 'DISONGSIKAN0001'),
        ValidationException::class
    );

    expect($ditolak->errors())->toHaveKey('kode')
        ->and($ditolak->errors()['kode'][0])->toBe('Kode promo tidak ditemukan.')
        ->and(DB::table('invoice')->count())->toBe(0)
        ->and(DB::table('promo_redemption')->count())->toBe(0);

    // `master_promo.kode` is `VARCHAR(30)` with a plain UNIQUE (:987) and no
    // `COLLATE` clause, so the column's own collation decides case
    // sensitivity. The service matches the code the way the column stores it
    // rather than lowercasing first, and this test states which of the two
    // outcomes that produces on THIS server rather than assuming one.
    $kodeAsli = inv44Kode(['kode' => 'KodeHurufBesar']);
    $varian = 'KODEHURFBESAR';

    $sama = DB::table('master_promo')
        ->where('kode', $varian)
        ->exists();

    if ($sama) {
        // The server folds case, so the lookup folded it too. The service did
        // not introduce the folding - the DDL did.
        $diterima = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $varian);

        expect($diterima->diskon)->toBe('15000.00');
    } else {
        $tolak = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $varian),
            ValidationException::class
        );

        expect($tolak->errors())->toHaveKey('kode');
    }

    expect(DB::table('master_promo')->where('kode', $kodeAsli)->count())->toBe(1);

    // An empty or whitespace code is a validation problem on the field itself,
    // not a failed lookup: `kode` is `VARCHAR(30) NOT NULL` (:987) and an
    // empty string would be a storable but meaningless code.
    foreach (['', '   '] as $kosong) {
        $tolak = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kosong),
            ValidationException::class
        );

        expect($tolak->errors())->toHaveKey('kode');
    }
});

test('an inactive promo is rejected on status_aktif, and flipping the flag alone unblocks it', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilik Promo Mati')->getKey());

        $promoId = inv44Promo(['status_aktif' => 0]);
        $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

        // In window, no minimum, no quota: this fixture breaks ONE rule, so the
        // refusal can only be about `status_aktif`.
        expect(DB::table('master_promo')->where('id', $promoId)->value('status_aktif'))->toBe(0);

        $ditolak = inv44Tangkap(
            fn () => app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode),
            ValidationException::class
        );

        expect($ditolak->errors())->toHaveKey('status_aktif')
            ->and($ditolak->errors()['status_aktif'][0])->toBe('Promo tidak aktif.')
            ->and(array_keys($ditolak->errors()))->toBe(['status_aktif'])
            ->and(DB::table('invoice')->count())->toBe(0);

        // The same row with `status_aktif = 1` is accepted, so the rejection is
        // the flag and not something else about the promo.
        DB::table('master_promo')->where('id', $promoId)->update(['status_aktif' => 1]);

        $diterima = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);

        expect($diterima->diskon)->toBe('15000.00')
            ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(1);
    } finally {
        inv44LepasJam();
    }
});

test('the window boundary, to the second: the last instant inside is accepted and the next one is refused', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilik Jendela')->getKey());
        $layanan = app(InvoiceService::class);

        // The comparison is against Asia/Jakarta wall-clock, because
        // `mulai_at` and `selesai_at` are operator-authored local times
        // (:995, :996) with no timezone. Seven hours of offset is the whole
        // difference between a promo that is live and one that is not.
        $sekarang = Carbon::parse(INV44_SEKARANG_UTC)->setTimezone(InvoiceService::ZONA_WALL_CLOCK);

        expect($sekarang->format('Y-m-d H:i:s'))->toBe(INV44_SEKARANG_WIB);

        // --- the closing edge -------------------------------------------------
        $promoTepi = inv44Promo([
            'mulai_at' => '2026-03-01 00:00:00',
            'selesai_at' => $sekarang->format('Y-m-d H:i:s'),
        ]);

        $kodeTepi = (string) DB::table('master_promo')->where('id', $promoTepi)->value('kode');

        // LAST VALID USE: the window is inclusive, so `now == selesai_at`
        // still works and the discount is granted, and the redemption is
        // written - the acceptance criterion is not merely "no error".
        $diTepi = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeTepi);

        expect($diTepi->diskon)->toBe('15000.00')
            ->and(DB::table('promo_redemption')->where('promo_id', $promoTepi)->count())->toBe(1);

        // FIRST INVALID USE: one second earlier the same promo is refused, and
        // the refusal names the WINDOW and not the quota - the two are
        // different keys for exactly this reason.
        $promoLewat = inv44Promo([
            'mulai_at' => '2026-03-01 00:00:00',
            'selesai_at' => $sekarang->copy()->subSecond()->format('Y-m-d H:i:s'),
        ]);

        $kodeLewat = (string) DB::table('master_promo')->where('id', $promoLewat)->value('kode');

        $setelah = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeLewat),
            ValidationException::class
        );

        expect($setelah->errors())->toHaveKey('jendela_waktu')
            ->and($setelah->errors())->not->toHaveKey('kuota')
            ->and($setelah->errors()['jendela_waktu'][0])->toBe('Promo sudah berakhir.')
            ->and(DB::table('promo_redemption')->where('promo_id', $promoLewat)->count())->toBe(0);

        // --- the opening edge -------------------------------------------------
        $promoBuka = inv44Promo([
            'mulai_at' => $sekarang->format('Y-m-d H:i:s'),
            'selesai_at' => '2026-03-31 23:59:59',
        ]);

        $kodeBuka = (string) DB::table('master_promo')->where('id', $promoBuka)->value('kode');

        $diBuka = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeBuka);

        expect($diBuka->diskon)->toBe('15000.00');

        $promoBelum = inv44Promo([
            'mulai_at' => $sekarang->copy()->addSecond()->format('Y-m-d H:i:s'),
            'selesai_at' => '2026-03-31 23:59:59',
        ]);

        $kodeBelum = (string) DB::table('master_promo')->where('id', $promoBelum)->value('kode');

        $terlaluDini = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeBelum),
            ValidationException::class
        );

        expect($terlaluDini->errors()['jendela_waktu'][0])->toBe('Promo belum dimulai.')
            ->and($terlaluDini->errors())->not->toHaveKey('kuota');

        // --- and both edges of a one-second window at once --------------------
        $sangatSempit = inv44Promo([
            'mulai_at' => $sekarang->format('Y-m-d H:i:s'),
            'selesai_at' => $sekarang->format('Y-m-d H:i:s'),
        ]);

        $kodeSempit = (string) DB::table('master_promo')->where('id', $sangatSempit)->value('kode');

        $tepatSaja = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeSempit);

        expect($tepatSaja->diskon)->toBe('15000.00');
    } finally {
        inv44LepasJam();
    }
});

test('the window is read as Asia/Jakarta, so seven hours of offset is the whole difference', function (): void {
    // The plan's todo 51 requires this regression, and it is a todo-44
    // property because the comparison happens here: a promo whose `mulai_at` is
    // an hour away in LOCAL time must report as not yet started. Compared
    // against `now()` in UTC the same row reads as already live for seven
    // hours, which is the bug the offset prevents.
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilik Zona')->getKey());
        $layanan = app(InvoiceService::class);

        // Starts 18:00 WIB, which is 11:00 UTC - one hour after the frozen
        // 17:00 WIB / 10:00 UTC instant.
        inv44AssertLine(995, 'mulai_at DATETIME NOT NULL');

        $kode = inv44Kode([
            'mulai_at' => '2026-03-11 18:00:00',
            'selesai_at' => '2026-03-11 23:00:00',
        ]);

        $ditolak = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode),
            ValidationException::class
        );

        expect($ditolak->errors()['jendela_waktu'][0])->toBe('Promo belum dimulai.');

        // The counterfactual, so the seven hours are visible rather than
        // asserted: read as UTC the same stored literal is 18:00 UTC, which IS
        // after the frozen 10:00 UTC instant, so a UTC comparison would have
        // accepted this promo.
        $sebagaiUtc = Carbon::parse('2026-03-11 18:00:00', 'UTC');

        expect($sebagaiUtc->greaterThan(Carbon::parse(INV44_SEKARANG_UTC, 'UTC')))->toBeTrue();
        expect(config('app.timezone'))->toBe('UTC');

        // The boundary, to the second, either side of the frozen 17:00:00 WIB.
        //
        // A UTC comparison would have put this edge at 11:00:00, so a promo
        // starting at 11:00:01 would have been ACCEPTED by it and is refused
        // here - which is the seven hours, made into a single assertion.
        $kodeBelum = inv44Kode([
            'mulai_at' => '2026-03-11 17:00:01',
            'selesai_at' => '2026-03-11 23:00:00',
        ]);

        $belum = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeBelum),
            ValidationException::class
        );

        expect($belum->errors()['jendela_waktu'][0])->toBe('Promo belum dimulai.');

        $kodeSudah = inv44Kode([
            'mulai_at' => '2026-03-11 16:59:59',
            'selesai_at' => '2026-03-11 23:00:00',
        ]);

        $diterima = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeSudah);

        expect($diterima->diskon)->toBe('15000.00');
    } finally {
        inv44LepasJam();
    }
});

test('a minimum purchase is read from subtotal, inclusive at the boundary', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilik Minimum')->getKey());
        $layanan = app(InvoiceService::class);

        $kode = inv44Kode(['min_transaksi' => '100000.00']);

        // Exactly at the minimum: accepted. `min_transaksi` is DECIMAL(12,2)
        // (:991) and the comparison is `<` for the refusal, so equality passes.
        $tepat = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('100000.00')], null, $kode);

        expect($tepat->subtotal)->toBe('100000.00')
            ->and($tepat->diskon)->toBe('10000.00');

        // One cent under: refused.
        $kodeTepat = inv44Kode(['min_transaksi' => '100000.01']);

        $ditolak = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('100000.00')], null, $kodeTepat),
            ValidationException::class
        );

        expect($ditolak->errors()['min_transaksi'][0])->toBe('Minimum transaksi promo belum tercapai.')
            ->and($ditolak->errors())->not->toHaveKey('jendela_waktu');

        // One cent over: accepted, so the edge is exact rather than off by a
        // rounding step.
        $satuSen = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('100000.01')], null, $kodeTepat);

        expect($satuSen->diskon)->toBe('10000.00');

        // The base is `subtotal` and NOT `total`, and NOT
        // `subtotal - diskon`: with a 50% promo the post-discount figure is
        // 50000.01, far below the minimum, yet the promo still applies. Had the
        // minimum been read off the post-discount amount this invoice would
        // have been refused.
        //
        // The subtotal is one cent over a round number on purpose: 50% of
        // 100000.01 is 50000.005, which is a THIRD decimal digit, so the
        // assertion is also the half-up rounding rule - 50000.01, not a
        // truncated 50000.00, and the total is the exact difference.
        $kodeSetengah = inv44Kode(['min_transaksi' => '100000.01', 'tipe_diskon' => 'persen', 'nilai' => '50.00']);

        $denganDiskon = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('100000.01')], null, $kodeSetengah);

        expect($denganDiskon->diskon)->toBe('50000.01')
            ->and($denganDiskon->total)->toBe('50000.00');

        // `min_transaksi` defaults to 0 (:991), so a default promo has no
        // minimum at all and the smallest legal purchase clears it.
        $kodeDefault = inv44Kode();

        expect(DB::table('master_promo')->where('kode', $kodeDefault)->value('min_transaksi'))->toBe('0.00');

        $palingKecil = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('1.00')], null, $kodeDefault);

        expect($palingKecil->diskon)->toBe('0.10');
    } finally {
        inv44LepasJam();
    }
});

test('the total quota boundary: the last use is accepted and the next one is refused', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilik Kuota')->getKey());
        $layanan = app(InvoiceService::class);

        $promoId = inv44Promo([
            'tipe_diskon' => 'nominal',
            'nilai' => '5000.00',
            'kuota_total' => 2,
            'kuota_per_user' => 5,
        ]);

        $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

        // Uses one and two of two: both succeed and both write a redemption.
        $pertama = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);
        $kedua = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);

        expect($pertama->diskon)->toBe('5000.00')
            ->and($kedua->diskon)->toBe('5000.00')
            ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(2);

        // The THIRD is the first invalid one, and the refusal names the quota
        // WITH its counts rather than a bare "expired".
        $ketiga = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode),
            ValidationException::class
        );

        expect($ketiga->errors())->toHaveKey('kuota')
            ->and($ketiga->errors()['kuota'][0])->toBe('Kuota promo telah habis (2/2).')
            ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(2);

        // A quota of 0 on an `INT UNSIGNED` (:993) is storable and means
        // nobody may use the promo - a different meaning from NULL.
        $nol = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_total' => 0]);
        $kodeNol = (string) DB::table('master_promo')->where('id', $nol)->value('kode');

        $tolakNol = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeNol),
            ValidationException::class
        );

        expect($tolakNol->errors()['kuota'][0])->toBe('Kuota promo telah habis (0/0).');

        // A release is out of this todo's reach and the schema says so:
        // `promo_redemption` has no `dihapus_at` and no `dibatalkan` column,
        // so a redemption is permanent and only a hard delete frees a quota.
        expect(DB::select("SHOW COLUMNS FROM promo_redemption LIKE 'dihapus_at'"))->toBeEmpty()
            ->and(DB::select("SHOW COLUMNS FROM promo_redemption LIKE 'dibatalkan'"))->toBeEmpty();
    } finally {
        inv44LepasJam();
    }
});

test('the per-user limit is enforced per patient, and NULL total quota is unlimited', function (): void {
    inv44KunciJam();

    try {
        $pasienA = inv44Pasien(inv44User('Pasien Kuota A')->getKey());
        $pasienB = inv44Pasien(inv44User('Pasien Kuota B')->getKey());
        $pasienC = inv44Pasien(inv44User('Pasien Kuota C')->getKey());

        $layanan = app(InvoiceService::class);

        // `kuota_per_user = 1` is the DDL default (:994), and it is stated
        // here so the per-patient claim is about the column and not the
        // fixture default.
        $promoId = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_per_user' => 1]);
        $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

        $a1 = $layanan->buat('booking', inv44Booking($pasienA), $pasienA, [inv44Baris()], null, $kode);
        $a2 = inv44Tangkap(fn () => $layanan->buat('booking', inv44Booking($pasienA), $pasienA, [inv44Baris()], null, $kode), ValidationException::class);
        $b1 = $layanan->buat('booking', inv44Booking($pasienB), $pasienB, [inv44Baris()], null, $kode);
        $c1 = $layanan->buat('booking', inv44Booking($pasienC), $pasienC, [inv44Baris()], null, $kode);

        // Three different patients, one redemption each: the per-user limit
        // does not leak across patients, which is the whole point of it.
        expect($a1->diskon)->toBe('5000.00')
            ->and($b1->diskon)->toBe('5000.00')
            ->and($c1->diskon)->toBe('5000.00')
            ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(3);

        // Patient A's own allowance is spent. The other two are untouched by
        // it, and the refusal names only the quota.
        expect($a2->errors()['kuota'][0])->toBe('Kuota promo untuk pengguna ini telah habis (1/1).')
            ->and(array_keys($a2->errors()))->toBe(['kuota']);

        // A second redemption for B is still refused, so the count is per
        // patient rather than per patient-per-invoice.
        $b2 = inv44Tangkap(fn () => $layanan->buat('booking', inv44Booking($pasienB), $pasienB, [inv44Baris()], null, $kode), ValidationException::class);

        expect($b2->errors()['kuota'][0])->toBe('Kuota promo untuk pengguna ini telah habis (1/1).');

        // `kuota_per_user = 0` on a `TINYINT UNSIGNED NOT NULL` (:994) is
        // storable and means nobody at all may use the promo.
        $nol = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_per_user' => 0]);
        $kodeNol = (string) DB::table('master_promo')->where('id', $nol)->value('kode');

        $tolakNol = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienC), $pasienC, [inv44Baris()], null, $kodeNol),
            ValidationException::class
        );

        expect($tolakNol->errors()['kuota'][0])->toBe('Kuota promo untuk pengguna ini telah habis (0/0).');

        // `kuota_total IS NULL` (:993) is UNLIMITED, which is a third meaning
        // distinct from 0 and is the reason the column is nullable. Four uses
        // by one patient against `kuota_per_user = 1` would fail on the
        // per-user rule, so the fixture widens that too.
        $takTerbatas = inv44Promo([
            'tipe_diskon' => 'nominal',
            'nilai' => '5000.00',
            'kuota_total' => null,
            'kuota_per_user' => 10,
        ]);

        $kodeTak = (string) DB::table('master_promo')->where('id', $takTerbatas)->value('kode');

        for ($i = 0; $i < 4; $i++) {
            $layanan->buat('booking', inv44Booking($pasienC), $pasienC, [inv44Baris()], null, $kodeTak);
        }

        expect(DB::table('master_promo')->where('id', $takTerbatas)->value('kuota_total'))->toBeNull()
            ->and(DB::table('promo_redemption')->where('promo_id', $takTerbatas)->count())->toBe(4);
    } finally {
        inv44LepasJam();
    }
});

// =====================================================================
// The five rules together: distinguishable, and stackable
// =====================================================================

test('each rule fires alone, and all four reportable keys are distinguishable in one 422', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pasien Gabungan')->getKey());
        $layanan = app(InvoiceService::class);

        // One case at a time. Each fixture breaks exactly ONE rule, so each
        // refusal can only be about that rule and a client can tell the five
        // apart from the body alone.
        $hanyaKode = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, 'TIDAKADA9999'),
            ValidationException::class
        );
        $hanyaAktif = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, inv44Kode(['status_aktif' => 0])),
            ValidationException::class
        );
        $hanyaJendela = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, inv44Kode(['selesai_at' => '2026-03-10 00:00:00'])),
            ValidationException::class
        );
        $hanyaMinimum = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('1000.00')], null, inv44Kode(['min_transaksi' => '50000.00'])),
            ValidationException::class
        );
        $hanyaKuota = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, inv44Kode(['kuota_total' => 0])),
            ValidationException::class
        );

        expect(array_keys($hanyaKode->errors()))->toBe(['kode'])
            ->and(array_keys($hanyaAktif->errors()))->toBe(['status_aktif'])
            ->and(array_keys($hanyaJendela->errors()))->toBe(['jendela_waktu'])
            ->and(array_keys($hanyaMinimum->errors()))->toBe(['min_transaksi'])
            ->and(array_keys($hanyaKuota->errors()))->toBe(['kuota']);

        // None of the five wrote anything, so the next case starts from the
        // same state and a 422 can never be masking a partial write.
        expect(DB::table('invoice')->count())->toBe(0)
            ->and(DB::table('promo_redemption')->count())->toBe(0);

        // --- the pair the brief names -----------------------------------------
        // In-window but over-quota: the WINDOW passes and the quota does not, so
        // only the quota is reported. A rule set that stopped at the first
        // failure would report the same thing, so the discriminating evidence
        // is the other direction below.
        $jendelaTapiKuota = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, inv44Kode([
                'mulai_at' => '2026-03-01 00:00:00',
                'selesai_at' => '2026-03-31 23:59:59',
                'kuota_total' => 0,
            ])),
            ValidationException::class
        );

        expect(array_keys($jendelaTapiKuota->errors()))->toBe(['kuota']);

        // In-quota but out-of-window: quota is generous and satisfied, and the
        // window is the only thing wrong, so ONLY the window is reported. Had
        // the quota been evaluated first and short-circuited, this would have
        // been empty or would have named the quota.
        $kuotaTapiJendela = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, inv44Kode([
                'mulai_at' => '2026-03-01 00:00:00',
                'selesai_at' => '2026-03-10 00:00:00',
                'kuota_total' => 10,
                'kuota_per_user' => 10,
            ])),
            ValidationException::class
        );

        expect(array_keys($kuotaTapiJendela->errors()))->toBe(['jendela_waktu']);

        // --- one promo failing four rules at once -----------------------------
        $semua = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('1000.00')], null, inv44Kode([
                'status_aktif' => 0,
                'mulai_at' => '2026-03-01 00:00:00',
                'selesai_at' => '2026-03-10 00:00:00',
                'min_transaksi' => '50000.00',
                'kuota_total' => 0,
            ])),
            ValidationException::class
        );

        expect(array_keys($semua->errors()))
            ->toBe(['status_aktif', 'jendela_waktu', 'min_transaksi', 'kuota'])
            ->and($semua->errors()['status_aktif'][0])->toBe('Promo tidak aktif.')
            ->and($semua->errors()['jendela_waktu'][0])->toBe('Promo sudah berakhir.')
            ->and($semua->errors()['min_transaksi'][0])->toBe('Minimum transaksi promo belum tercapai.')
            ->and($semua->errors()['kuota'][0])->toBe('Kuota promo telah habis (0/0).');

        // The envelope's `errors` is a map of field to a LIST, and this is
        // where one field carries more than one message. An INVERTED window is
        // legal storage - there is no CHECK on the pair anywhere in the DDL -
        // and it makes "not started" and "already expired" both true at the
        // same instant, so both belong on the same key.
        $terbalik = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, inv44Kode([
                'mulai_at' => '2026-03-20 00:00:00',
                'selesai_at' => '2026-03-10 00:00:00',
            ])),
            ValidationException::class
        );

        expect($terbalik->errors()['jendela_waktu'])->toHaveCount(2)
            ->and($terbalik->errors()['jendela_waktu'][0])->toBe('Promo belum dimulai.')
            ->and($terbalik->errors()['jendela_waktu'][1])->toBe('Promo sudah berakhir.');

        // The same shape on the quota key: a patient who has spent both the
        // total allowance and their own trips both halves of `kuota` at once,
        // and BOTH messages must survive rather than one replacing the other.
        $promoGanda = inv44Promo(['tipe_diskon' => 'nominal', 'nilai' => '5000.00', 'kuota_total' => 2, 'kuota_per_user' => 1]);
        $kodeGanda = (string) DB::table('master_promo')->where('id', $promoGanda)->value('kode');

        $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeGanda);

        // A second patient spends the last of the total allowance, so both
        // limits are at their ceiling for the patient under test.
        $pasienLain = inv44Pasien(inv44User('Pasien Pemakan')->getKey());

        $layanan->buat('booking', inv44Booking($pasienLain), $pasienLain, [inv44Baris()], null, $kodeGanda);

        $keduaGanda = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kodeGanda),
            ValidationException::class
        );

        expect($keduaGanda->errors()['kuota'])->toHaveCount(2)
            ->and($keduaGanda->errors()['kuota'][0])->toBe('Kuota promo telah habis (2/2).')
            ->and($keduaGanda->errors()['kuota'][1])->toBe('Kuota promo untuk pengguna ini telah habis (1/1).');

        // Every one of the refusals above wrote nothing.
        expect(DB::table('promo_redemption')->where('promo_id', $promoGanda)->count())->toBe(2);
    } finally {
        inv44LepasJam();
    }
});

// =====================================================================
// The three discount types
// =====================================================================

test('persen respects maks_diskon, and a NULL cap means uncapped', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilih Persen')->getKey());
        $layanan = app(InvoiceService::class);

        // 10% of 200000 is 20000, but the cap is 5000.
        $kode = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '10.00', 'maks_diskon' => '5000.00']);

        $terbatas = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('200000.00')], null, $kode);

        expect($terbatas->diskon)->toBe('5000.00')
            ->and($terbatas->total)->toBe('195000.00');

        // A cap ABOVE the computed discount changes nothing: the cap is a
        // ceiling, not a target.
        $kodeBesar = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '10.00', 'maks_diskon' => '50000.00']);

        $tidakTercap = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('200000.00')], null, $kodeBesar);

        expect($tidakTercap->diskon)->toBe('20000.00');

        // `maks_diskon IS NULL` (:992) is uncapped, which is a different
        // meaning from 0 - a cap of 0.00 would be a usable "no discount" promo
        // and a NULL cap is an uncapped one.
        $kodeTanpaCap = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '10.00', 'maks_diskon' => null]);

        expect(DB::table('master_promo')->where('kode', $kodeTanpaCap)->value('maks_diskon'))->toBeNull();

        $tanpaCap = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('200000.00')], null, $kodeTanpaCap);

        expect($tanpaCap->diskon)->toBe('20000.00');

        // The exact cap edge: 10% of 100000 is exactly 10000, so a cap of
        // 10000 leaves it untouched.
        $tepat = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '10.00', 'maks_diskon' => '10000.00']);

        $diTepi = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('100000.00')], null, $tepat);

        expect($diTepi->diskon)->toBe('10000.00');

        // The rounding rule, stated: 3.33% of 33333.00 is 1109.9889, which
        // rounds HALF UP at the cent to 1109.99 rather than truncating to
        // 1109.98.
        $pecahan = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '3.33', 'maks_diskon' => null]);

        $dibulatkan = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('33333.00')], null, $pecahan);

        expect($dibulatkan->diskon)->toBe('1109.99');

        // 33.33% of 99999.00 is 33329.6667, which rounds DOWN to 33329.67 -
        // the same function, the other side of the boundary, so neither figure
        // is a coincidence of one fixture.
        $pecahan2 = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '33.33', 'maks_diskon' => null]);

        $dibulatkan2 = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('99999.00')], null, $pecahan2);

        expect($dibulatkan2->diskon)->toBe('33329.67');

        // A discount can never exceed the subtotal, so a 200% percentage on
        // any purchase is bounded by the purchase rather than driving
        // `total` negative. `nilai` is DECIMAL(12,2) (:990) with no CHECK, so
        // 200.00 is storable.
        $lebih = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '200.00', 'maks_diskon' => null]);

        $terkunci = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('1000.00')], null, $lebih);

        expect($terkunci->diskon)->toBe('1000.00')
            ->and($terkunci->total)->toBe('0.00');
    } finally {
        inv44LepasJam();
    }
});

test('nominal is taken whole, capped by the purchase and by maks_diskon', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilih Nominal')->getKey());
        $layanan = app(InvoiceService::class);

        // kuota_per_user is raised so these three invoices test the MONEY and
        // not the quota, which 	he per-user limit test covers on its own.
        $kode = inv44Kode(['tipe_diskon' => 'nominal', 'nilai' => '50000.00', 'kuota_per_user' => 10]);

        $penuh = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('200000.00')], null, $kode);

        expect($penuh->diskon)->toBe('50000.00')
            ->and($penuh->total)->toBe('150000.00');

        // 50000 off a 10000 purchase would drive `total` to -40000. The
        // discount is bounded by the subtotal instead, so the invoice is 0.00
        // rather than a patient being credited 40000.
        $melebihi = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('10000.00')], null, $kode);

        expect($melebihi->diskon)->toBe('10000.00')
            ->and($melebihi->total)->toBe('0.00');

        // `maks_diskon` is not scoped to one discount TYPE by the DDL, so a
        // cap is a cap: a nominal 50000 with a 20000 cap pays 20000.
        $kodeCap = inv44Kode(['tipe_diskon' => 'nominal', 'nilai' => '50000.00', 'maks_diskon' => '20000.00', 'kuota_per_user' => 10]);

        $terbatas = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('200000.00')], null, $kodeCap);

        expect($terbatas->diskon)->toBe('20000.00')
            ->and($terbatas->total)->toBe('180000.00');

        // The minimum purchase is still read from `subtotal`, so a nominal
        // discount cannot be used to slip under it. The purchase is one cent
        // BELOW the minimum while the discount is 50000, so reading the minimum
        // off the post-discount amount would have accepted it.
        $kodeMinimum = inv44Kode([
            'tipe_diskon' => 'nominal',
            'nilai' => '50000.00',
            'min_transaksi' => '100000.00',
            'kuota_per_user' => 10,
        ]);

        $ditolak = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('99999.99')], null, $kodeMinimum),
            ValidationException::class
        );

        expect($ditolak->errors())->toHaveKey('min_transaksi');
    } finally {
        inv44LepasJam();
    }
});

test('gratis_ongkir zeroes the shipping cost rather than inflating diskon', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilih Ongkir')->getKey());
        $layanan = app(InvoiceService::class);

        $kode = inv44Kode(['tipe_diskon' => 'gratis_ongkir', 'nilai' => '0.00']);

        // A nominal promo of zero on a purchase with shipping: the shipping
        // column is what moves, and `diskon` stays at its 0.00 default.
        $promo = $layanan->buat(
            'pesanan_obat',
            inv44PesananObat($pasienId),
            $pasienId,
            [inv44Baris('120000.00')],
            null,
            $kode,
            '20000.00'
        );

        expect($promo->subtotal)->toBe('120000.00')
            ->and($promo->diskon)->toBe('0.00')
            ->and($promo->biaya_pengiriman)->toBe('0.00')
            ->and($promo->total)->toBe('120000.00');

        // The same invoice without the promo is 140000, so the 20000 really did
        // come off the shipping column. If it had been credited to `diskon`
        // instead, `diskon` would read 20000.00 here.
        $tanpaPromo = $layanan->buat(
            'pesanan_obat',
            inv44PesananObat($pasienId),
            $pasienId,
            [inv44Baris('120000.00')],
            null,
            null,
            '20000.00'
        );

        expect($tanpaPromo->diskon)->toBe('0.00')
            ->and($tanpaPromo->biaya_pengiriman)->toBe('20000.00')
            ->and($tanpaPromo->total)->toBe('140000.00');

        // The shipping input is money, so it obeys the same decimal-string
        // rule as a line price: a negative or malformed value is refused
        // rather than subtracted.
        foreach (['-20000.00', '20 000.00', '20000.001', 20000.0] as $harus) {
            $ditolak = inv44Tangkap(
                fn () => $layanan->buat('pesanan_obat', inv44PesananObat($pasienId), $pasienId, [inv44Baris()], null, null, $harus),
                ValidationException::class
            );

            expect($ditolak->errors())->toHaveKey('biaya_pengiriman');
        }

        // `0.00` shipping is LEGAL and means the order has nothing to ship
        // for, which is a different value from a malformed one: a free-shipping
        // promo on a digital order is exactly this case.
        $tanpaBiaya = $layanan->buat('pesanan_obat', inv44PesananObat($pasienId), $pasienId, [inv44Baris()], null, null, '0.00');

        expect($tanpaBiaya->biaya_pengiriman)->toBe('0.00')
            ->and($tanpaBiaya->total)->toBe('150000.00');

        // A free-shipping promo on an order that has no shipping to give away
        // is a no-op rather than an error, and it still consumes its quota.
        $promoId = inv44Promo(['tipe_diskon' => 'gratis_ongkir', 'nilai' => '0.00', 'kuota_total' => 1]);
        $kodeOngkir = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

        $tanpaOngkir = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('50000.00')], null, $kodeOngkir);

        expect($tanpaOngkir->diskon)->toBe('0.00')
            ->and($tanpaOngkir->biaya_pengiriman)->toBe('0.00')
            ->and($tanpaOngkir->total)->toBe('50000.00')
            ->and(DB::table('promo_redemption')->where('promo_id', $promoId)->count())->toBe(1);
    } finally {
        inv44LepasJam();
    }
});

test('a promo writes exactly one redemption row, naming the promo, the patient and the invoice', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pasien Redemption')->getKey());

        // `nilai` is 100000, `maks_diskon` caps it at 5000 and the purchase is
        // 150000, so the GRANTED discount, the promo's `nilai` and the invoice
        // total are three DIFFERENT numbers and the assertion below is not
        // comparing a value with itself.
        $promoId = inv44Promo([
            'tipe_diskon' => 'nominal',
            'nilai' => '100000.00',
            'maks_diskon' => '5000.00',
            'kuota_per_user' => 10,
        ]);

        $kode = (string) DB::table('master_promo')->where('id', $promoId)->value('kode');

        $invoice = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], null, $kode);

        $baris = DB::table('promo_redemption')->where('promo_id', $promoId)->get();

        expect($baris)->toHaveCount(1)
            ->and((int) $baris[0]->promo_id)->toBe($promoId)
            ->and((int) $baris[0]->pasien_id)->toBe($pasienId)
            ->and((int) $baris[0]->invoice_id)->toBe((int) $invoice->getKey())
            ->and($baris[0]->nilai_diskon)->toBe('5000.00')
            ->and($invoice->total)->toBe('145000.00');

        // `nilai_diskon` is the discount GRANTED - not the promo's `nilai`, and
        // not the invoice's `total`. A redemption row that recorded `nilai`
        // would be an audit trail claiming the patient was given 100000 off.
        expect((string) $baris[0]->nilai_diskon)->toBe((string) $invoice->diskon)
            ->and((string) $baris[0]->nilai_diskon)->not->toBe((string) DB::table('master_promo')->where('id', $promoId)->value('nilai'))
            ->and((string) $baris[0]->nilai_diskon)->not->toBe((string) $invoice->total)
            ->and((string) $invoice->total)->not->toBe((string) DB::table('master_promo')->where('id', $promoId)->value('nilai'));

        // No promo means no redemption, even on a real invoice.
        $tanpa = app(InvoiceService::class)->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()]);

        expect(DB::table('promo_redemption')->count())->toBe(1)
            ->and($tanpa->diskon)->toBe('0.00')
            ->and($tanpa->total)->toBe((string) $tanpa->subtotal);
    } finally {
        inv44LepasJam();
    }
});

test('a refused promo leaves the invoice unwritten, so a 422 never leaves a half-priced row', function (): void {
    // The clock is frozen because the point of this test is the ROLLBACK, and
    // an unfrozen clock would let the default March window lapse and turn the
    // refusal into a window refusal - the test would still be green while
    // testing a different rule than the one it names.
    inv44KunciJam();

    try {
        inv44PerhatikanRollback(['status_aktif' => 0]);
    } finally {
        inv44LepasJam();
    }
});

test('a window refusal rolls back identically, so the guard is not a status_aktif special case', function (): void {
    inv44KunciJam();

    try {
        inv44PerhatikanRollback(['selesai_at' => '2026-03-10 00:00:00']);
    } finally {
        inv44LepasJam();
    }
});

/**
 * One refusal on `$ganti`, and the assertion that NOTHING was written.
 *
 * Shared by the two tests above so the two rules are proved to travel the SAME
 * rollback path rather than each getting its own copy of the assertions - which
 * is the property a special case would break while both copies stayed green.
 *
 * @param  array<string, mixed>  $ganti  the one promo column to break
 */
function inv44PerhatikanRollback(array $ganti): void
{
    $pasienId = inv44Pasien(inv44User('Pasien Setengah')->getKey());

    $ditolak = inv44Tangkap(
        fn () => app(InvoiceService::class)->buat(
            'booking',
            inv44Booking($pasienId),
            $pasienId,
            [inv44Baris()],
            null,
            inv44Kode($ganti)
        ),
        ValidationException::class
    );

    expect($ditolak->errors())->not->toBeEmpty();

    // Not merely "no redemption" - no INVOICE either. The duplicate guard
    // reads `invoice`, so a row written before the promo was refused would make
    // the retry fail with the wrong error.
    expect(DB::table('invoice')->count())->toBe(0)
        ->and(DB::table('promo_redemption')->count())->toBe(0);

    // The same reference pair is still creatable, which proves the guard was
    // not tripped by a row the rollback left behind.
    $bookingId = inv44Booking($pasienId);

    $berhasil = app(InvoiceService::class)->buat('booking', $bookingId, $pasienId, [inv44Baris()], null, inv44Kode());

    expect($berhasil->id)->toBeGreaterThan(0)
        ->and(DB::table('invoice')->count())->toBe(1);
}

test('an invoice is never written with a null total, whatever the caller passes', function (): void {
    $pasienId = inv44Pasien(inv44User('Pasien Total Kosong')->getKey());
    $layanan = app(InvoiceService::class);

    $bookingId = inv44Booking($pasienId);
    $invoice = $layanan->buat('booking', $bookingId, $pasienId, [inv44Baris('150000.00')]);

    // Read back through the raw builder rather than the model, so the DECIMAL
    // comes off the wire as MySQL sends it and not through a `decimal:2` cast.
    $mentah = DB::selectOne('SELECT total, subtotal, diskon, biaya_admin, biaya_pengiriman, status FROM invoice WHERE id = ?', [$invoice->getKey()]);

    expect($mentah->total)->toBe('150000.00')
        ->and($mentah->subtotal)->toBe('150000.00')
        ->and($mentah->diskon)->toBe('0.00')
        ->and($mentah->biaya_admin)->toBe('0.00')
        ->and($mentah->biaya_pengiriman)->toBe('0.00')
        ->and($mentah->status)->toBe('menunggu_pembayaran');

    // `DECIMAL(14,2)` is 999999999999.99 at the top, so a line that overflows
    // the column is refused rather than truncated into a wrong total.
    foreach (['9999999999999.99', '1000000000000.00'] as $luap) {
        inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris($luap)]),
            ValidationException::class
        );
    }

    // And `PromoRedemption::UPDATED_AT` is null (:46) because the table has no
    // `diubah_at`, which is a fact this service relies on: it never calls
    // `update()` on a redemption, so there is nothing for Eloquent to null.
    expect(Invoice::query()->whereKey($invoice->getKey())->exists())->toBeTrue();
});
