<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Konsultasi;
use App\Models\PesananObat;
use App\Models\Resep;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\PromoService;
use App\Support\Dokumen\NomorDokumen;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/invoice-helpers.php';

/*
|--------------------------------------------------------------------------
| Todo 44 - the polymorphic invoice and the promo rule set
|--------------------------------------------------------------------------
|
| ## The two things this file is really about
|
| 1. **A promo is a RULE SET, not a lookup.** `master_promo` is not a bag of
|    flags: it can be inactive (:997), outside its window (:995, :996), under
|    its minimum (:991), over its total quota (:993) or over this patient's own
|    limit (:994), and those five failures are INDEPENDENT. A promo can be
|    in-window but over-quota and in-quota but out-of-window, so every rule is
|    evaluated and every failure is reported - one `ValidationException`
|    carrying all of them, keyed so the five are distinguishable in the 422.
|
| 2. **Quota consumption is a race the schema cannot close.**
|    `promo_redemption` declares no UNIQUE and no INDEX anywhere in its eleven
|    lines, so the count check and the insert are separated by a window in
|    which a second transaction can reach the same decision. The serialisation
|    point is `SELECT ... FROM master_promo WHERE id = ? FOR UPDATE`, taken
|    FIRST inside the transaction, and the counting reads are locking reads too
|    - a non-locking read after that lock would re-read the transaction's own
|    REPEATABLE READ snapshot and undercount. See PromoQuotaConcurrencyTest.
|
| ## The five rejection fields, and why the window and the quotas are one key each
|
| | field | rule | column |
| | --- | --- | --- |
| | `kode` | the code is in `master_promo` at all | :987 |
| | `status_aktif` | the promo is switched on | :997 |
| | `jendela_waktu` | `mulai_at <= now <= selesai_at` | :995, :996 |
| | `min_transaksi` | `subtotal >= min_transaksi` | :991 |
| | `kuota` | remaining total AND this patient's own limit | :993, :994 |
|
| The window is ONE key fed by TWO columns and the two quotas are ONE key fed
| by two columns, because a single key fed by more than one column is where a
| multi-message 422 comes from honestly rather than by contrivance: an
| INVERTED window (`mulai_at > selesai_at`, legal storage - there is no CHECK
| anywhere on the pair) makes "not started" and "already expired" both true at
| the same instant, and a patient who has spent both the total allowance and
| their own trips both halves of `kuota` at once.
|
*/

// =====================================================================
// Section 1 - the DDL, cited from the file rather than from the plan
// =====================================================================

beforeEach(function (): void {
    // `RoleAssigner` refuses to grant a role whose `roles` row is missing, and
    // the fixtures assign `pasien` and `dokter` roles to the accounts they
    // create. Seeding here rather than in each test is what the other Feature
    // directories do.
    $this->seed(RbacSeeder::class);
});

test('every DDL citation this todo argues from is at the line the file says', function (): void {
    // master_metode_pembayaran: CREATE at :925, closing ENGINE at :934.
    inv44AssertLine(925, 'CREATE TABLE master_metode_pembayaran');
    inv44AssertLine(934, 'ENGINE=InnoDB');
    inv44AssertLine(931, 'biaya_admin_flat DECIMAL(12,2) NOT NULL DEFAULT 0');
    inv44AssertLine(932, 'biaya_admin_persen DECIMAL(5,2) NOT NULL DEFAULT 0');
    inv44AssertLine(933, 'status_aktif TINYINT(1) NOT NULL DEFAULT 1');

    // invoice: CREATE at :936, closing ENGINE at :956.
    inv44AssertLine(936, 'CREATE TABLE invoice');
    inv44AssertLine(956, 'ENGINE=InnoDB');
    inv44AssertLine(938, 'nomor_invoice VARCHAR(30) NOT NULL UNIQUE');
    inv44AssertLine(940, "referensi_tipe ENUM('booking','konsultasi','resep','pesanan_obat','lab_permintaan','home_care')");
    inv44AssertLine(941, "referensi_id BIGINT UNSIGNED NOT NULL COMMENT 'Polimorfik'");

    // Four money columns that DO default, and the one that does not.
    inv44AssertLine(942, 'subtotal DECIMAL(14,2) NOT NULL DEFAULT 0');
    inv44AssertLine(943, 'diskon DECIMAL(14,2) NOT NULL DEFAULT 0');
    inv44AssertLine(944, 'biaya_admin DECIMAL(14,2) NOT NULL DEFAULT 0');
    inv44AssertLine(945, 'biaya_pengiriman DECIMAL(14,2) NOT NULL DEFAULT 0');
    inv44AssertLine(946, 'total DECIMAL(14,2) NOT NULL');
    inv44AssertLineLacks(946, 'DEFAULT');
    inv44AssertLine(947, "'draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan',");
    inv44AssertLine(948, "'refund_sebagian','refund_penuh') NOT NULL DEFAULT 'menunggu_pembayaran'");

    // :953 is the ONLY foreign key on invoice and it is the patient, not the
    // polymorphic reference. :955 is idx_ref - the plan cites :954.
    inv44AssertLine(953, 'FOREIGN KEY (pasien_id) REFERENCES pasien(id)');
    inv44AssertLine(954, 'INDEX idx_invoice (pasien_id, status)');
    inv44AssertLine(955, 'INDEX idx_ref (referensi_tipe, referensi_id)');
    inv44AssertLineLacks(955, 'UNIQUE');

    // master_promo: CREATE at :985, closing ENGINE at :998.
    inv44AssertLine(985, 'CREATE TABLE master_promo');
    inv44AssertLine(998, 'ENGINE=InnoDB');
    inv44AssertLine(987, 'kode VARCHAR(30) NOT NULL UNIQUE');
    inv44AssertLine(989, "tipe_diskon ENUM('persen','nominal','gratis_ongkir')");
    inv44AssertLine(990, 'nilai DECIMAL(12,2) NOT NULL');
    inv44AssertLine(991, 'min_transaksi DECIMAL(12,2) NOT NULL DEFAULT 0');
    inv44AssertLine(992, 'maks_diskon DECIMAL(12,2) NULL');
    inv44AssertLine(993, 'kuota_total INT UNSIGNED NULL');
    inv44AssertLine(994, 'kuota_per_user TINYINT UNSIGNED NOT NULL DEFAULT 1');
    inv44AssertLine(995, 'mulai_at DATETIME NOT NULL');
    inv44AssertLine(996, 'selesai_at DATETIME NOT NULL');
    inv44AssertLine(997, 'status_aktif TINYINT(1) NOT NULL DEFAULT 1');

    // promo_redemption: CREATE at :1000, closing ENGINE at :1010.
    inv44AssertLine(1000, 'CREATE TABLE promo_redemption');
    inv44AssertLine(1010, 'ENGINE=InnoDB');
    inv44AssertLine(1002, 'promo_id BIGINT UNSIGNED NOT NULL');
    inv44AssertLine(1003, 'pasien_id BIGINT UNSIGNED NOT NULL');
    inv44AssertLine(1004, 'invoice_id BIGINT UNSIGNED NOT NULL');
    inv44AssertLine(1005, 'nilai_diskon DECIMAL(12,2) NOT NULL');
    inv44AssertLine(1007, 'FOREIGN KEY (promo_id) REFERENCES master_promo(id)');
    inv44AssertLine(1008, 'FOREIGN KEY (pasien_id) REFERENCES pasien(id)');
    inv44AssertLine(1009, 'FOREIGN KEY (invoice_id) REFERENCES invoice(id)');
});

test('promo_redemption declares no UNIQUE and no INDEX on any of its eleven lines', function (): void {
    // Asserted as a RANGE rather than a search: a positive search cannot prove
    // an absence, and this absence is what forces the whole quota design.
    for ($baris = 1000; $baris <= 1010; $baris++) {
        inv44AssertLineLacks($baris, 'UNIQUE');
        inv44AssertLineLacks($baris, 'INDEX ');
    }

    // The live table agrees: MySQL synthesises exactly one index per foreign
    // key and adds none of its own, so every index that exists besides the
    // primary key is a non-unique single-column index standing in for one of
    // the three FKs. `PRIMARY` is excluded because its `Non_unique` is 0 for a
    // different reason and including it would make this assertion vacuous.
    $index = DB::select('SHOW INDEX FROM promo_redemption');

    expect($index)->not->toBeEmpty();

    $dariFKey = 0;

    foreach ($index as $satu) {
        if ($satu->Key_name === 'PRIMARY') {
            expect($satu->Column_name)->toBe('id');

            continue;
        }

        $dariFKey++;

        expect($satu->Non_unique)->toBe(1);
    }

    expect($dariFKey)->toBe(3);

    // The count the quota check issues therefore rides `promo_id`, the leading
    // column of a synthesised single-column index: a range probe, not a scan.
    $promoId = inv44Promo();

    $sql = [];

    DB::listen(function ($peristiwa) use (&$sql): void {
        $sql[] = $peristiwa->sql;
    });

    DB::table('promo_redemption')->where('promo_id', $promoId)->lockForUpdate()->pluck('id');

    $terakhir = (string) end($sql);

    expect($terakhir)->toContain('from `promo_redemption`')
        ->and($terakhir)->toContain('for update')
        ->and($terakhir)->toContain('`promo_id` = ?');
});

test('the four in-scope reference tables each carry a pasien_id and the ENUM is exactly six', function (): void {
    inv44AssertLine(501, 'pasien_id BIGINT UNSIGNED NOT NULL');
    inv44AssertLine(539, 'pasien_id BIGINT UNSIGNED NOT NULL');
    inv44AssertLine(747, 'pasien_id BIGINT UNSIGNED NOT NULL');
    inv44AssertLine(801, 'pasien_id BIGINT UNSIGNED NOT NULL');

    // The two OUT of scope, which exist as tables and are in the ENUM.
    inv44AssertLine(881, 'pasien_id BIGINT UNSIGNED NOT NULL');
    inv44AssertLine(1096, 'pasien_id BIGINT UNSIGNED NOT NULL');

    expect(inv44Enum('invoice', 'referensi_tipe'))->toBe([
        'booking', 'konsultasi', 'resep', 'pesanan_obat', 'lab_permintaan', 'home_care',
    ]);
});

// =====================================================================
// Section 2 - the polymorphic reference
// =====================================================================

test('the polymorphic reference has no Eloquent relation and the service does not invent one', function (): void {
    // Read the DECLARED relations off the class, not the loaded ones: a fresh
    // `Invoice` has nothing loaded, so `getRelations()` would answer an empty
    // array and prove nothing.
    $deklarasi = [];

    foreach ((new ReflectionClass(Invoice::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $metode) {
        if ($metode->getNumberOfParameters() !== 0) {
            continue;
        }

        $metode->setAccessible(true);

        try {
            $nilai = $metode->invoke(new Invoice);
        } catch (Throwable) {
            continue;
        }

        if ($nilai instanceof Relation) {
            $deklarasi[] = $metode->getName();
        }
    }

    sort($deklarasi);

    expect($deklarasi)->toBe(['pasien', 'pembayaran', 'promoRedemption']);

    // `referensi_id` is a bare column (:941) with NO foreign key, so a
    // `belongsTo` to any of the four sources would be a guess about a schema
    // fact. The service reaches the row through a whitelist instead, and the
    // whitelist is exactly the four reachable ENUM members - in a deliberate
    // order that puts the two out-of-scope members at neither end.
    $sumber = InvoiceService::SUMBER_REFERENSI;

    expect($sumber)->toHaveCount(4)
        ->and(array_keys($sumber))->toBe(['booking', 'konsultasi', 'pesanan_obat', 'resep'])
        ->and($sumber['booking'])->toBe(Booking::class)
        ->and($sumber['konsultasi'])->toBe(Konsultasi::class)
        ->and($sumber['resep'])->toBe(Resep::class)
        ->and($sumber['pesanan_obat'])->toBe(PesananObat::class);

    // And every one of the four really does expose `pasien_id`, which is the
    // entire basis of the ownership check.
    foreach ($sumber as $model) {
        expect(Schema::hasColumn((new $model)->getTable(), 'pasien_id'))->toBeTrue();
    }
});

test('the four in-scope reference types all mint an invoice', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilik Empat')->getKey());
        $layanan = app(InvoiceService::class);

        $bookingId = inv44Booking($pasienId);
        $konsultasiId = inv44Konsultasi($pasienId);
        $resepId = inv44Resep($pasienId);
        $pesananId = inv44PesananObat($pasienId);

        $booking = $layanan->buat('booking', $bookingId, $pasienId, [inv44Baris()]);
        $konsultasi = $layanan->buat('konsultasi', $konsultasiId, $pasienId, [inv44Baris('200000.00')]);
        $resep = $layanan->buat('resep', $resepId, $pasienId, [inv44Baris('50000.00', 2)]);
        $pesanan = $layanan->buat('pesanan_obat', $pesananId, $pasienId, [inv44Baris('120000.00')], null, null, '20000.00');

        expect($booking->referensi_tipe)->toBe('booking')
            ->and($booking->referensi_id)->toBe($bookingId)
            ->and($konsultasi->referensi_tipe)->toBe('konsultasi')
            ->and($konsultasi->referensi_id)->toBe($konsultasiId)
            ->and($resep->referensi_tipe)->toBe('resep')
            ->and($resep->referensi_id)->toBe($resepId)
            ->and($pesanan->referensi_tipe)->toBe('pesanan_obat')
            ->and($pesanan->referensi_id)->toBe($pesananId);

        // One total each, from its own lines and its own shipping.
        expect($booking->total)->toBe('150000.00')
            ->and($konsultasi->total)->toBe('200000.00')
            ->and($resep->total)->toBe('100000.00')
            ->and($pesanan->subtotal)->toBe('120000.00')
            ->and($pesanan->biaya_pengiriman)->toBe('20000.00')
            ->and($pesanan->total)->toBe('140000.00');

        expect(DB::table('invoice')->count())->toBe(4);
    } finally {
        inv44LepasJam();
    }
});

test('the two out-of-scope reference types are refused with a clear 422 naming the gap', function (): void {
    $pasienId = inv44Pasien(inv44User('Pemilik Luar Lingkup')->getKey());
    $layanan = app(InvoiceService::class);

    foreach (['lab_permintaan', 'home_care'] as $tipe) {
        $ditolak = inv44Tangkap(
            fn () => $layanan->buat($tipe, 1, $pasienId, [inv44Baris()]),
            ValidationException::class
        );

        // The refusal names the type and says it is a SCOPE gap rather than a
        // validation problem - the value is a perfectly legal `referensi_tipe`.
        expect($ditolak->errors())->toHaveKey('referensi_tipe')
            ->and($ditolak->errors()['referensi_tipe'][0])
            ->toBe("Jenis referensi [{$tipe}] valid belum diimplementasikan pada lingkup ini.");
    }

    // Neither refusal wrote a row, and neither reached a table this service
    // has no business touching yet.
    expect(DB::table('invoice')->count())->toBe(0);
});

test('a reference type outside the ENUM is refused as an invalid value, not as out of scope', function (): void {
    $pasienId = inv44Pasien(inv44User('Pemilik Nilai Aneh')->getKey());

    $ditolak = inv44Tangkap(
        fn () => app(InvoiceService::class)->buat('lab_request', 1, $pasienId, [inv44Baris()]),
        ValidationException::class
    );

    // The message names all six legal values so a caller can correct itself,
    // and it is NOT the out-of-scope sentence: two different problems with two
    // different fixes must not share one message.
    expect($ditolak->errors()['referensi_tipe'][0])
        ->toBe('Jenis referensi tidak dikenal. Nilai yang diizinkan: booking, konsultasi, resep, pesanan_obat, lab_permintaan, home_care.')
        ->and(DB::table('invoice')->count())->toBe(0);
});

test('a reference row on another patient is a 404, indistinguishable from a row that does not exist', function (): void {
    $pasienA = inv44Pasien(inv44User('Pasien A')->getKey());
    $pasienB = inv44Pasien(inv44User('Pasien B')->getKey());

    $bookingA = inv44Booking($pasienA);
    $layanan = app(InvoiceService::class);

    // 404, never 403: a 403 would confirm the row exists, which is a
    // cross-tenant existence oracle.
    $milikorang = inv44Tangkap(
        fn () => $layanan->buat('booking', $bookingA, $pasienB, [inv44Baris()]),
        ModelNotFoundException::class
    );
    $hilang = inv44Tangkap(
        fn () => $layanan->buat('booking', 999999999, $pasienA, [inv44Baris()]),
        ModelNotFoundException::class
    );

    expect($milikorang->getModel())->toBe(Booking::class)
        ->and($hilang->getModel())->toBe(Booking::class)
        ->and($milikorang->getIds())->toBe([$bookingA])
        ->and($hilang->getIds())->toBe([999999999])
        ->and(DB::table('invoice')->count())->toBe(0);

    // A non-positive id is a 422 rather than a query, because `referensi_id`
    // is `BIGINT UNSIGNED` (:941) and zero is not one of its values.
    $salahBentuk = inv44Tangkap(
        fn () => $layanan->buat('booking', 0, $pasienA, [inv44Baris()]),
        ValidationException::class
    );

    expect($salahBentuk->errors())->toHaveKey('referensi_id');
});

test('a second buat for the same reference pair is refused and writes no second invoice', function (): void {
    $pasienId = inv44Pasien(inv44User('Pemilik Ganda')->getKey());
    $bookingId = inv44Booking($pasienId);
    $layanan = app(InvoiceService::class);

    $pertama = $layanan->buat('booking', $bookingId, $pasienId, [inv44Baris('150000.00')]);

    // A DIFFERENT amount, so a silent "return the existing row" would hand
    // back an invoice whose total disagrees with what the caller asked for.
    $ditolak = inv44Tangkap(
        fn () => $layanan->buat('booking', $bookingId, $pasienId, [inv44Baris('999999.00')]),
        ValidationException::class
    );

    expect($ditolak->errors()['referensi_id'][0])
        ->toBe("Invoice untuk referensi ini sudah dibuat dengan nomor {$pertama->nomor_invoice}.")
        ->and(DB::table('invoice')->where('referensi_tipe', 'booking')->where('referensi_id', $bookingId)->count())->toBe(1)
        ->and($pertama->total)->toBe('150000.00');
});

// =====================================================================
// Section 3 - the money
// =====================================================================

test('the DDL gives total no default while its four siblings default to zero', function (): void {
    // Proved against the LIVE column as well as the file: the two can only
    // disagree if a migration diverged from the DDL, which is exactly the drift
    // `sehatly:verify-schema` exists to catch.
    $kolom = DB::selectOne(
        'SELECT IS_NULLABLE AS n, COLUMN_DEFAULT AS d FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['invoice', 'total']
    );

    expect($kolom->n)->toBe('NO')
        ->and($kolom->d)->toBeNull();

    foreach (['subtotal', 'diskon', 'biaya_admin', 'biaya_pengiriman'] as $nama) {
        $saudara = DB::selectOne(
            'SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['invoice', $nama]
        );

        expect($saudara->d)->not->toBeNull();
    }

    // And the INSERT a service that trusted the defaults would emit really is
    // refused, which is the concrete consequence the decision rests on.
    $ditolak = inv44Tangkap(function (): void {
        DB::table('invoice')->insert([
            'nomor_invoice' => 'INVNOTOTAL01',
            'pasien_id' => 1,
            'referensi_tipe' => 'booking',
            'referensi_id' => 1,
        ]);
    }, QueryException::class);

    expect((int) ($ditolak->errorInfo[1] ?? 0))->toBe(1364);
});

test('the null total is handled deliberately: every money column is written and an empty lines array is refused', function (): void {
    $pasienId = inv44Pasien(inv44User('Pemilik Total')->getKey());
    $bookingId = inv44Booking($pasienId);

    $invoice = app(InvoiceService::class)->buat('booking', $bookingId, $pasienId, [inv44Baris('150000.00')]);

    expect($invoice->total)->toBe('150000.00')
        ->and($invoice->subtotal)->toBe('150000.00')
        ->and($invoice->diskon)->toBe('0.00')
        ->and($invoice->biaya_admin)->toBe('0.00')
        ->and($invoice->biaya_pengiriman)->toBe('0.00');

    // `status` really does default to `menunggu_pembayaran` (:948) and the
    // service still writes it, so the DDL default can never be the thing that
    // decides an invoice's state.
    expect($invoice->status)->toBe('menunggu_pembayaran')
        ->and($invoice->getRawOriginal('status'))->toBe('menunggu_pembayaran');

    // A zero-value invoice is refused rather than minted at 0.00: the column
    // would happily accept it, and a patient handed a 0.00 invoice is a defect
    // no constraint can catch.
    $ditolak = inv44Tangkap(
        fn () => app(InvoiceService::class)->buat('booking', $bookingId, $pasienId, []),
        ValidationException::class
    );

    expect($ditolak->errors())->toHaveKey('lines')
        ->and(DB::table('invoice')->count())->toBe(1);
});

test('a line price is a decimal string, and a JSON number is refused rather than cast', function (): void {
    $pasienId = inv44Pasien(inv44User('Pemilih.Float')->getKey());
    $layanan = app(InvoiceService::class);

    // A JSON number has already lost precision by the time PHP parses it, so a
    // float line price is a contract violation rather than something to
    // coerce. `true`, `null` and an array are refused for the same reason.
    foreach ([150000.5, 150000.0, true, null, ['150000.00']] as $harus) {
        $ditolak = inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [
                ['harga_satuan' => $harus, 'jumlah' => 1],
            ]),
            ValidationException::class
        );

        expect($ditolak->errors())->toHaveKey('lines.0.harga_satuan');
    }

    // Strings that are not plain two-decimal money are refused too: a
    // negative price drives `total` negative, a zero price mints a 0.00
    // invoice, and scientific or comma notation is a different currency.
    foreach (['-1.00', '0.00', '10.005', '1e3', '100,00', ' 100.00 ', '150000.000', 'abc'] as $harus) {
        inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris($harus)]),
            ValidationException::class
        );
    }

    // `jumlah` is a count, not money: zero, negative, fractional and
    // non-numeric are all refused.
    foreach ([0, -3, 1.5, 'dua', null] as $harus) {
        inv44Tangkap(
            fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [
                ['harga_satuan' => '150000.00', 'jumlah' => $harus],
            ]),
            ValidationException::class
        );
    }

    expect(DB::table('invoice')->count())->toBe(0);

    // The shapes that ARE legal, and the arithmetic each produces.
    $harga = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('150000.00')]);
    expect($harga->subtotal)->toBe('150000.00');

    $bulat = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('150000', 3)]);
    expect($bulat->subtotal)->toBe('450000.00');

    // Two lines add, and a one-decimal price is a legal string.
    $duaBaris = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [
        inv44Baris('1000.5', 2), inv44Baris('2500.00', 1),
    ]);

    expect($duaBaris->subtotal)->toBe('4501.00');
});

test('biaya_admin_persen is charged on subtotal MINUS diskon', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilik Admin')->getKey());

        // flat 5000 + 2.50%. `master_metode_pembayaran.id` is SMALLINT, so a
        // real method id is small and 999999999 is safely out of range.
        $metodeId = inv44Metode([
            'biaya_admin_flat' => '5000.00',
            'biaya_admin_persen' => '2.50',
        ]);

        $kode = inv44Kode(['tipe_diskon' => 'persen', 'nilai' => '20.00']);
        $layanan = app(InvoiceService::class);

        // 200000 subtotal, 20% diskon = 40000, so the percentage base is
        // 160000: 2.50% of it is 4000, plus the 5000 flat, so total 169000.
        $diskon = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('200000.00')], $metodeId, $kode);

        expect($diskon->subtotal)->toBe('200000.00')
            ->and($diskon->diskon)->toBe('40000.00')
            ->and($diskon->biaya_admin)->toBe('9000.00')
            ->and($diskon->total)->toBe('169000.00');

        // The counterfactual, asserted rather than described: the same method
        // on an UNDISCOUNTED invoice charges 2.50% of 200000 = 5000, so the
        // percentage demonstrably moved with the discount. Applied to
        // `subtotal` it would have been 10000 and the total 215000.
        $tanpaDiskon = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('200000.00')], $metodeId);

        expect($tanpaDiskon->diskon)->toBe('0.00')
            ->and($tanpaDiskon->biaya_admin)->toBe('10000.00')
            ->and($tanpaDiskon->total)->toBe('210000.00');

        // The two fees differ by exactly 2.50% of the discount.
        expect(bcsub($tanpaDiskon->biaya_admin, $diskon->biaya_admin, 2))->toBe('1000.00');

        // A method with no admin fee: both columns default to 0 and the row
        // is unchanged by them.
        $polos = $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris('100000.00')], inv44Metode());

        expect($polos->biaya_admin)->toBe('0.00')
            ->and($polos->total)->toBe('100000.00');

        // An unknown method id and an inactive one are both refused on
        // `metode_id` - `status_aktif` is NOT NULL with default 1 (:933), so
        // an inactive row is a real storable state rather than a fiction.
        foreach ([999999999, inv44Metode(['status_aktif' => 0])] as $tidakBisa) {
            $ditolak = inv44Tangkap(
                fn () => $layanan->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()], $tidakBisa),
                ValidationException::class
            );

            expect($ditolak->errors())->toHaveKey('metode_id');
        }
    } finally {
        inv44LepasJam();
    }
});

test('the number is INV plus the date plus six, and a genuine unique-key collision is retried', function (): void {
    inv44KunciJam();

    try {
        $pasienId = inv44Pasien(inv44User('Pemilik Nomor')->getKey());

        $tanggal = Carbon::parse(INV44_SEKARANG_UTC)
            ->setTimezone(InvoiceService::ZONA_WALL_CLOCK)
            ->format('Ymd');

        $nomor = (string) app(InvoiceService::class)
            ->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()])
            ->nomor_invoice;

        // INV (3) + Ymd (8) + six alphanumerics = 17 of the VARCHAR(30) at
        // :938, and the date inside the number is the frozen day rather than
        // the real one. The sequence is alphanumeric, not numeric:
        // `NomorDokumen` draws `Str::upper(Str::random(6))`.
        expect(strlen($nomor))->toBe(INV44_PANJANG_NOMOR)
            ->and($nomor)->toStartWith('INV'.$tanggal)
            ->and($nomor)->toMatch('/^INV[0-9]{8}[A-Z0-9]{6}$/')
            ->and(DB::table('invoice')->where('nomor_invoice', $nomor)->count())->toBe(1);

        // The collision path. `nomor_invoice` is UNIQUE (:938), so two
        // candidates drawing the same six characters is a real MySQL 1062, and
        // an unhandled one would be a 500. A `NomorDokumen` with a fixed
        // sequence makes the collision deterministic, so the first candidate
        // is spent twice and the second must still land.
        $urutan = ['AAAAAA', 'BBBBBB'];
        $terjual = new NomorDokumen(fn (int $n): string => $urutan[$n - 1]);

        $botak = new InvoiceService($terjual, app(PromoService::class));

        $pertama = $botak->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()]);
        $kedua = $botak->buat('booking', inv44Booking($pasienId), $pasienId, [inv44Baris()]);

        expect($pertama->nomor_invoice)->toBe('INV'.$tanggal.'AAAAAA')
            ->and($kedua->nomor_invoice)->toBe('INV'.$tanggal.'BBBBBB')
            ->and($kedua->id)->not->toBe($pertama->id);

        // The failed attempt left nothing behind: the retry runs inside the
        // transaction, so a collision does not orphan a row.
        expect(DB::table('invoice')->count())->toBe(3);
    } finally {
        inv44LepasJam();
    }
});
