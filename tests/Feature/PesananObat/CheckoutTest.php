<?php

declare(strict_types=1);

use App\Enums\PesananObatStatus;
use App\Models\PesananObat;
use App\Models\Resep;
use App\Services\PesananObat\ApotekStokService;
use App\Services\PesananObat\PesananObatService;
use App\Services\PesananObat\PesananObatStateMachine;
use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/pesanan46-helpers.php';

beforeEach(function (): void {
    // Declared in THIS file, not in `pesanan46-helpers.php`: that file is
    // `require_once`d by three test files, so a hook declared in it is
    // registered for the FIRST one only and the other two run with none. The
    // symptom is 35 errors of "RbacCatalog::ROLES names pasien but `roles`
    // holds no such row" in a full-suite run that passes file by file, because
    // `po46Selesai()` has to delete the RBAC catalogue DURABLY (the
    // `RefreshDatabase` wrapper was committed) and the next file has nothing to
    // re-seed it.
    po46Bersihkan();
    po46KunciJam();
    $this->seed(RbacSeeder::class);
});

afterEach(function (): void {
    po46LepasJam();
});

/*
|--------------------------------------------------------------------------
| Todo 46 - prescription checkout
|--------------------------------------------------------------------------
|
| ## The rule this file exists to prove
|
| `pesanan_obat` has `resep_id` NULLABLE (`:800`) and **no line-item table
| anywhere among the 75**, so an over-the-counter order would have no product
| lines and its `subtotal` could not be recomputed from anything stored. The
| order flow is therefore restricted to `tipe = 'resep_dokter'`, and the
| restriction is a REFUSAL AT CREATION: an `obat_bebas` request is a 422 and
| leaves no `pesanan_obat`, no `invoice` and no `pesanan_obat_tracking` row.
|
| The three tests that matter for that are the ones named for the two refused
| types and the one that counts rows afterwards, because "rejected" and "flagged"
| are only different answers if the row count is asserted.
|
| ## A 422 can carry MULTIPLE messages on ONE field, and the envelope keeps them
|
| Every refusal on this surface that the caller broke more than one rule about
| carries two messages on one field, and each is asserted by its position in
| the array rather than by "the message contains X": a concatenation would pass
| the same assertion.
|
| ## The DDL citations are asserted, not quoted
|
| Every line number in this file is checked against `telemedicine_test.sql`
| itself by {@see po46AssertLine()} / {@see po46AssertLineLacks()}, because the
| plan's own `:NNN` citations are off by one in places and two of its ranges run
| past their closing `ENGINE` line.
*/

// =====================================================================
// The DDL, read from the file
// =====================================================================

test('the five tables this todo touches are cited from the file, ranges included', function (): void {
    // CREATE and the closing ENGINE, so a citation cannot drift past the table.
    po46AssertLine(742, 'CREATE TABLE resep');
    po46AssertLine(765, ') ENGINE=InnoDB;');
    po46AssertLine(767, 'CREATE TABLE resep_item');
    po46AssertLine(783, ') ENGINE=InnoDB;');
    po46AssertLine(786, 'CREATE TABLE resep_verifikasi');
    po46AssertLine(795, ') ENGINE=InnoDB;');
    po46AssertLine(797, 'CREATE TABLE pesanan_obat');
    po46AssertLine(817, ') ENGINE=InnoDB;');
    po46AssertLine(819, 'CREATE TABLE pesanan_obat_tracking');
    po46AssertLine(827, ') ENGINE=InnoDB;');
    po46AssertLine(829, 'CREATE TABLE apotek_stok');
    po46AssertLine(841, ') ENGINE=InnoDB;');

    // `pesanan_obat.status` is a SEVENTH multi-line ENUM. Reading `:810` alone
    // yields the six values with an EMPTY tail, which reads as a nullable
    // column with no default; the `NOT NULL DEFAULT` is on `:811`.
    po46AssertLine(810, "status ENUM('menunggu_pembayaran','diproses','siap','sedang_dikirim','selesai','dibatalkan')");
    po46AssertLine(811, "NOT NULL DEFAULT 'menunggu_pembayaran'");

    // The three columns that carry the prescription-only rule.
    po46AssertLine(800, 'resep_id BIGINT UNSIGNED NULL');
    po46AssertLine(801, 'pasien_id BIGINT UNSIGNED NOT NULL');
    po46AssertLine(802, 'apotek_id BIGINT UNSIGNED NOT NULL');
    po46AssertLine(803, "tipe ENUM('resep_dokter','obat_bebas','produk_kesehatan') NOT NULL DEFAULT 'resep_dokter'");
    po46AssertLine(814, 'FOREIGN KEY (resep_id) REFERENCES resep(id)');

    // `jumlah_stok` is a SIGNED INT with no CHECK - the whole oversell story.
    po46AssertLine(833, 'jumlah_stok INT NOT NULL DEFAULT 0');
    po46AssertLineLacks(833, 'UNSIGNED');
    po46AssertLineLacks(833, 'CHECK');

    // The existing unique key that makes the lock addressable. This is what
    // removes any need for a new index.
    po46AssertLine(840, 'UNIQUE KEY uq_stok (apotek_id, obat_id)');

    // `faskes.tipe` is what "is a pharmacy" means, and nothing constrains the
    // three pharmacy columns to it.
    po46AssertLine(365, "tipe ENUM('rumah_sakit','klinik','puskesmas','apotek','laboratorium') NOT NULL");
    po46AssertLine(749, 'apotek_id BIGINT UNSIGNED NULL');

    // `resep_item`: the racikan marker and the money pair nothing maintains.
    po46AssertLine(770, 'obat_id BIGINT UNSIGNED NULL');
    po46AssertLine(774, 'jumlah SMALLINT UNSIGNED NOT NULL');
    po46AssertLine(778, 'harga_satuan DECIMAL(12,2) NOT NULL DEFAULT 0');
    po46AssertLine(779, 'subtotal DECIMAL(12,2) NOT NULL DEFAULT 0');
    po46AssertLineLacks(779, 'GENERATED');
});

test('there is no pesanan_obat_item table among the 75, which is WHY checkout is prescription-only', function (): void {
    $spec = po46Spec();

    expect($spec->hasTable('pesanan_obat'))->toBeTrue()
        ->and($spec->hasTable('pesanan_obat_item'))->toBeFalse()
        ->and($spec->hasTable('pesanan_obat_tracking'))->toBeTrue()
        ->and($spec->hasTable('apotek_stok'))->toBeTrue()
        // The tracking table is five columns and none of them is a timestamp.
        ->and(array_keys($spec->table('pesanan_obat_tracking')->columns))
        ->toEqual(['id', 'pesanan_obat_id', 'status', 'keterangan', 'lokasi', 'waktu'])
        ->and($spec->table('pesanan_obat_tracking')->columns['status']->type)->toBe('varchar(100)')
        ->and($spec->table('pesanan_obat_tracking')->columns['waktu']->type)->toBe('datetime');

    // No ledger table either: there is nowhere to record a movement, so stock
    // is unauditable beyond `audit_log`.
    foreach (['stok_mutasi', 'stock_movement', 'apotek_stok_mutasi', 'stok_ledger'] as $nama) {
        expect($spec->hasTable($nama))->toBeFalse("{$nama} exists, so the no-ledger claim is false");
    }
});

test('every ENUM list this todo uses is the parsed DDL list, in the DDL order', function (): void {
    // `toBe` checks ORDER as well as membership, so a reordering fails too.
    expect(po46Enum('pesanan_obat', 'status'))->toBe(PesananObatStatus::nilai())
        ->and(PesananObatStatus::nilai())->toBe([
            'menunggu_pembayaran', 'diproses', 'siap', 'sedang_dikirim', 'selesai', 'dibatalkan',
        ])
        ->and(po46Enum('pesanan_obat', 'tipe'))->toBe(PesananObatService::SEMUA_TIPE)
        ->and(po46Enum('pesanan_obat', 'kurir'))->toBe(PesananObatService::SEMUA_KURIR)
        ->and(po46Enum('faskes', 'tipe'))->toContain(ApotekStokService::TIPE_APOTEK)
        // The DDL's own default, read from the parser rather than from memory.
        ->and(po46Spec()->table('pesanan_obat')->columns['status']->default)->toBe("'menunggu_pembayaran'")
        ->and(po46Spec()->table('pesanan_obat')->columns['tipe']->default)->toBe("'resep_dokter'")
        // `jumlah_stok` SIGNED and nullable=false: the two facts the whole
        // oversell argument rests on, measured rather than quoted.
        ->and(po46Spec()->table('apotek_stok')->columns['jumlah_stok']->unsigned)->toBeFalse()
        ->and(po46Spec()->table('apotek_stok')->columns['jumlah_stok']->type)->toBe('int')
        ->and(po46Spec()->table('pesanan_obat')->columns['resep_id']->nullable)->toBeTrue()
        ->and(po46Spec()->table('pesanan_obat')->columns['apotek_id']->nullable)->toBeFalse();
});

test('the transition map is keyed by the six ENUM members and reaches every one of them', function (): void {
    $nilai = PesananObatStatus::nilai();

    expect(array_keys(PesananObatStateMachine::TRANSISI))->toBe($nilai);

    // Every value on the right of an arrow is a real member, so a typo in the
    // map is a typo that cannot be written.
    $tujuan = [];

    foreach (PesananObatStateMachine::TRANSISI as $dari => $ke) {
        expect($dari)->toBeIn($nilai);

        foreach ($ke as $satu) {
            expect($satu)->toBeIn($nilai);
            $tujuan[$satu] = true;
        }
    }

    // Every member EXCEPT the initial state is reachable, so a seventh ENUM
    // value added without a decision here would fail rather than become a
    // state nothing can ever move into.
    $tidakTerjangkau = array_values(array_diff($nilai, ['menunggu_pembayaran'], array_keys($tujuan)));

    expect($tidakTerjangkau)->toBe([]);

    // The terminals are exactly the states with no outgoing edge, and
    // `dibatalkan` is reachable from every LIVE state.
    $terminal = [];

    foreach (PesananObatStateMachine::TRANSISI as $dari => $ke) {
        if ($ke === []) {
            $terminal[] = $dari;
        }
    }

    expect($terminal)->toBe(PesananObatStateMachine::TERMINAL);

    foreach (array_diff($nilai, PesananObatStateMachine::TERMINAL) as $hidup) {
        expect(PesananObatStateMachine::TRANSISI[$hidup])->toContain('dibatalkan');
        expect(PesananObatStateMachine::TRANSISI[$hidup])->not->toContain($hidup);
    }
});

// =====================================================================
// THE HEADLINE: prescription-only, refused AT CREATION
// =====================================================================

test('an obat_bebas checkout is a 422 and writes NOTHING', function (): void {
    // This is the whole of the prescription-only rule, stated as a test rather
    // than a docblock: not "the row says resep_dokter" but "there is no row".
    $dunia = po46Dunia();

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', ['tipe' => 'obat_bebas']);

    $response->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'message', 'errors' => ['tipe']]);

    // TWO messages on ONE field, and each is asserted by its POSITION: a
    // concatenation would satisfy a single "contains" assertion.
    $pesan = $response->json('errors.tipe');

    expect($pesan)->toBeArray()->toHaveCount(2)
        ->and($pesan[0])->toBe('Hanya pesanan dengan resep dokter yang dapat dibuat lewat endpoint ini.')
        ->and($pesan[1])->toContain('pesanan_obat tidak memiliki tabel item')
        ->and($pesan[1])->toContain('telemedicine_test.sql:797-817');

    // NOT a flag on a row. Not a warning. Nothing.
    expect(DB::table('pesanan_obat')->where('pasien_id', $dunia['pasien'])->count())->toBe(0)
        ->and(DB::table('pesanan_obat')->count())->toBe(0)
        ->and(DB::table('pesanan_obat_tracking')->count())->toBe(0)
        ->and(DB::table('invoice')->where('referensi_tipe', 'pesanan_obat')->count())->toBe(0);

    // And the shelf is untouched: a refused checkout must not reserve a unit.
    expect((int) DB::table('apotek_stok')->where('id', $dunia['stok'])->value('jumlah_stok'))->toBe(100);
});

test('a produk_kesehatan checkout is refused the same way, and a fourth value is a different 422', function (): void {
    $dunia = po46Dunia();

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', ['tipe' => 'produk_kesehatan']);

    $response->assertUnprocessable();

    expect($response->json('errors.tipe.0'))
        ->toBe('Hanya pesanan dengan resep dokter yang dapat dibuat lewat endpoint ini.')
        ->and($response->json('errors.tipe.1'))->toContain('produk_kesehatan')
        ->and(DB::table('pesanan_obat')->count())->toBe(0);

    // A value that is NOT an ENUM member is the REQUEST's answer, not the
    // service's, and the two are different failures: one is a broken caller and
    // the other is a structurally unimplementable request.
    $bogus = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', ['tipe' => 'obat']);

    $bogus->assertUnprocessable()->assertJsonValidationErrors('tipe');

    expect($bogus->json('errors.tipe.0'))->toBe('Tipe pesanan tidak dikenal.')
        ->and(DB::table('pesanan_obat')->count())->toBe(0);
});

test('a drug with requires_resep = 0 does NOT open an over-the-counter path', function (): void {
    // The prescription requirement on an ORDER is `tipe` plus a non-null
    // `resep_id`, NOT `master_obat.requires_resep`. `requires_resep` is the
    // CATALOGUE rule todo 39 enforces at prescribing time (`:720`), and reading
    // it as the order rule would be reading a different column for a different
    // question.
    po46AssertLine(720, 'requires_resep TINYINT(1) NOT NULL DEFAULT 1');

    $pasienUser = po46User('Pasien OTC', 'pasien', 'pasien');
    $pasien = po46Pasien((int) $pasienUser->getKey());
    $dokter = po46Dokter(po46User('Dokter OTC', 'dokter')->getKey());
    $apotek = po46Faskes();
    $obat = po46Obat('Paracetamol', ['requires_resep' => 0, 'kelas_obat' => 'bebas']);
    po46Stok($apotek, $obat, 50);

    $resep = po46Resep($pasien, $dokter, [$obat], 'diverifikasi', ['apotek_id' => $apotek]);

    $response = test()->withToken(po46As($pasienUser)['Authorization'])
        ->postJson('/api/v1/resep/'.$resep->getKey().'/checkout', ['tipe' => 'obat_bebas']);

    $response->assertUnprocessable();

    expect($response->json('errors.tipe.0'))
        ->toBe('Hanya pesanan dengan resep dokter yang dapat dibuat lewat endpoint ini.')
        ->and(DB::table('pesanan_obat')->count())->toBe(0);

    // The same prescription WITH a prescription type does check out, so the
    // refusal is about the TYPE and not about the drug.
    $berhasil = test()->withToken(po46As($pasienUser)['Authorization'])
        ->postJson('/api/v1/resep/'.$resep->getKey().'/checkout', ['tipe' => 'resep_dokter']);

    $berhasil->assertCreated();

    expect($berhasil->json('data.pesanan.tipe'))->toBe('resep_dokter')
        ->and(DB::table('pesanan_obat')->count())->toBe(1);
});

test('a PARTIAL basket is not expressible: items is prohibited, not ignored', function (): void {
    // There is no column and no table in which a selection would be recorded,
    // so naming `items` is answered rather than dropped: a caller who sent it
    // believes the order takes a basket, and silently dropping it would hand
    // back an order for the WHOLE prescription and look like success.
    $dunia = po46Dunia();

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [
            'items' => [['obat_id' => $dunia['obat'], 'jumlah' => 1]],
        ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('items');

    expect($response->json('errors.items.0'))
        ->toBe('Pesanan obat diturunkan dari item resep dan tidak menerima item.')
        ->and(DB::table('pesanan_obat')->count())->toBe(0);
});

// =====================================================================
// The happy path, and what it writes
// =====================================================================

test('a verified prescription checks out, with an invoice and the first tracking row', function (): void {
    $dunia = po46Dunia(100, 10);

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', []);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pesanan.tipe', 'resep_dokter')
        ->assertJsonPath('data.pesanan.status', 'menunggu_pembayaran')
        // MONEY IS A JSON STRING at this boundary, and the assertion is on the
        // TYPE: a float here would render `75000` and pass a loose comparison.
        ->assertJsonPath('data.pesanan.subtotal', '75000.00')
        ->assertJsonPath('data.pesanan.biaya_kirim', '0.00')
        ->assertJsonPath('data.pesanan.total', '75000.00');

    $id = (int) $response->json('data.pesanan.id');

    $pesanan = PesananObat::query()->findOrFail($id);

    expect((string) $pesanan->nomor_pesanan)->toStartWith('PO')
        ->and((string) $pesanan->nomor_pesanan)->toHaveLength(16)
        ->and((int) $pesanan->resep_id)->toBe((int) $dunia['resep']->getKey())
        ->and((int) $pesanan->pasien_id)->toBe($dunia['pasien'])
        ->and((int) $pesanan->apotek_id)->toBe($dunia['apotek'])
        // The address SNAPSHOT: the profile's, because none was named.
        ->and((string) $pesanan->alamat_kirim)->toBe('Jl. Uji Checkout No. 1, Jakarta')
        ->and($pesanan->kurir)->toBeNull()
        ->and($pesanan->no_resi)->toBeNull();

    // The invoice, through the ONE entry point, with the polymorphic
    // reference resolved off `InvoiceService::SUMBER_REFERENSI`.
    $invoice = DB::table('invoice')->where('referensi_tipe', 'pesanan_obat')->where('referensi_id', $id)->first();

    expect($invoice)->not->toBeNull()
        ->and((string) $invoice->nomor_invoice)->toStartWith('INV')
        ->and((int) $invoice->pasien_id)->toBe($dunia['pasien'])
        // No promo and no method, so the order's total and the invoice's total
        // are the SAME figure. `pesanan_obat` has no discount and no admin-fee
        // column, so anything else would be money this API cannot represent.
        ->and((string) $invoice->subtotal)->toBe('75000.00')
        ->and((string) $invoice->diskon)->toBe('0.00')
        ->and((string) $invoice->biaya_admin)->toBe('0.00')
        ->and((string) $invoice->biaya_pengiriman)->toBe('0.00')
        ->and((string) $invoice->total)->toBe('75000.00')
        ->and((string) $invoice->status)->toBe('menunggu_pembayaran');

    // The first tracking row, carrying the order's own status and a `waktu`
    // that is NOT `created_at` - this table has no timestamps at all.
    $tracking = DB::table('pesanan_obat_tracking')->where('pesanan_obat_id', $id)->get();

    expect($tracking)->toHaveCount(1)
        ->and((string) $tracking[0]->status)->toBe('menunggu_pembayaran')
        ->and((string) $tracking[0]->waktu)->not->toBe('')
        ->and($tracking[0]->lokasi)->toBeNull();

    $kolom = DB::select('SHOW COLUMNS FROM pesanan_obat_tracking');

    expect(array_map(static fn ($row): string => (string) $row->Field, $kolom))
        ->not->toContain('dibuat_at')
        ->not->toContain('diubah_at')
        ->not->toContain('created_at')
        ->not->toContain('updated_at');
});

test('the stock is reserved at checkout and the reserved unit is the prescribed quantity', function (): void {
    $dunia = po46Dunia(100, 10);

    test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    expect((int) DB::table('apotek_stok')->where('id', $dunia['stok'])->value('jumlah_stok'))->toBe(90);
});

test('subtotal is RECOMPUTED, never read off a column nothing maintains', function (): void {
    // `resep_item.subtotal` (`:779`) has no generated column, no trigger and no
    // CHECK tying it to `harga_satuan * jumlah` (`:778`), so it is a figure no
    // rule maintains. A wrong one is storable and the fixture below stores one.
    $dunia = po46Dunia(100, 10, 'apotek');

    DB::table('resep_item')->where('resep_id', $dunia['resep']->getKey())->update(['subtotal' => '1.00']);

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', []);

    $response->assertCreated()
        ->assertJsonPath('data.pesanan.subtotal', '75000.00')
        ->assertJsonPath('data.pesanan.total', '75000.00');

    $id = (int) $response->json('data.pesanan.id');

    expect((string) DB::table('invoice')->where('referensi_tipe', 'pesanan_obat')->where('referensi_id', $id)->value('subtotal'))
        ->toBe('75000.00');
});

test('biaya_kirim is a money string, and a JSON number is a 422 naming the field', function (): void {
    $dunia = po46Dunia();

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', ['biaya_kirim' => '20000.00']);

    $response->assertCreated()
        ->assertJsonPath('data.pesanan.biaya_kirim', '20000.00')
        ->assertJsonPath('data.pesanan.total', '95000.00');

    // A second, independent patient and prescription, so the second attempt in
    // this test is not a repeat of the first.
    $dunia2 = po46Dunia();
    $dokter2 = po46Dokter(po46User('Dokter Float', 'dokter')->getKey());
    $resep2 = po46Resep($dunia2['pasien'], $dokter2, [$dunia2['obat']], 'diverifikasi', ['apotek_id' => $dunia2['apotek']]);

    // A float, not a string. `Uang::parse` refuses it by design and the message
    // is filed on the field it arrived on rather than becoming a 500 from PHP's
    // own type check. The request-level rule catches it first, which is the
    // better place: the caller sent a JSON number where a money string belongs.
    $float = test()->withToken(po46As($dunia2['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$resep2->getKey().'/checkout', ['biaya_kirim' => 20000.0]);

    $float->assertUnprocessable()->assertJsonValidationErrors('biaya_kirim');

    expect($float->json('errors.biaya_kirim.0'))->toBe('Biaya kirim harus berupa string desimal, bukan angka.')
        ->and(DB::table('pesanan_obat')->where('pasien_id', $dunia2['pasien'])->count())->toBe(0);

    // A negative amount is refused rather than stored: `pesanan_obat` has no
    // CHECK and `DECIMAL(12,2)` is signed.
    $negatif = test()->withToken(po46As($dunia2['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$resep2->getKey().'/checkout', ['biaya_kirim' => '-1.00']);

    $negatif->assertUnprocessable()->assertJsonValidationErrors('biaya_kirim');
});

test('the shipping address is a SNAPSHOT of the patient profile, and prohibited on the request', function (): void {
    // `pesanan_obat.alamat_kirim` is stored ONCE on the order (`:804`) with no
    // address table and no foreign key, so the profile changing afterwards must
    // not move a parcel already placed. That is the whole reason the column is a
    // snapshot rather than a live read.
    $dunia = po46Dunia();

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', []);

    $response->assertCreated()
        ->assertJsonPath('data.pesanan.alamat_kirim', 'Jl. Uji Checkout No. 1, Jakarta');

    $id = (int) $response->json('data.pesanan.id');

    DB::table('pasien')->where('id', $dunia['pasien'])->update(['alamat_lengkap' => 'Jl. Baru No. 3, Surabaya']);

    expect((string) DB::table('pesanan_obat')->where('id', $id)->value('alamat_kirim'))
        ->toBe('Jl. Uji Checkout No. 1, Jakarta');

    // And the field is not caller-writable, which is the same fact seen from the
    // other side. `prohibited`, so the answer names the field rather than
    // dropping it and handing back the profile's address as if it were asked for.
    $ditolak = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [
            'alamat_kirim' => 'Jl. Kantor No. 9, Bandung',
        ]);

    $ditolak->assertUnprocessable()->assertJsonValidationErrors('alamat_kirim');
});

// =====================================================================
// Every other refusal, each with its rows-counted answer
// =====================================================================

test('an unverified prescription is a 422 with TWO messages and writes nothing', function (): void {
    // `aktif` is the DDL's own default (`:752`) and `BISA_DIPENUHUI` is
    // `diverifikasi, dipenuhi, dikirim`, so an `aktif` prescription is refused
    // by the pre-existing method rather than by an `if` re-derived here.
    $dunia = po46Dunia();
    DB::table('resep')->where('id', $dunia['resep']->getKey())->update(['status' => 'aktif']);

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', []);

    $response->assertUnprocessable();

    $pesan = $response->json('errors.status');

    expect($pesan)->toBeArray()->toHaveCount(2)
        ->and($pesan[0])->toBe('Resep belum dapat dipenuhi.')
        ->and($pesan[1])->toContain('wajib diverifikasi apoteker sebelum dipenuhi')
        ->and($pesan[1])->toContain('aktif');

    expect(DB::table('pesanan_obat')->count())->toBe(0)
        ->and(DB::table('pesanan_obat_tracking')->count())->toBe(0)
        ->and(DB::table('invoice')->where('referensi_tipe', 'pesanan_obat')->count())->toBe(0)
        ->and((int) DB::table('apotek_stok')->where('id', $dunia['stok'])->value('jumlah_stok'))->toBe(100);
});

test('a prescription past berlaku_sampai is a 422, and the CLOCK is what makes it so', function (): void {
    // `berlaku_sampai` is a `DATE` (`:755`) and NOTHING in the schema reacts to
    // it, so the row can still read `diverifikasi` weeks later. The test moves
    // the clock FORWARD rather than writing a past date, because otherwise the
    // fixture would be asserting its own setup.
    $dunia = po46Dunia();

    // The day before the boundary it still checks out.
    Carbon::setTestNow(Carbon::parse(PO46_BERLAKU.' 08:00:00'));

    $duniain = po46Dunia();
    $doktera = po46Dokter(po46User('Dokter Inclusive', 'dokter')->getKey());
    $resepa = po46Resep($duniain['pasien'], $doktera, [$duniain['obat']], 'diverifikasi', ['apotek_id' => $duniain['apotek']]);

    test()->withToken(po46As($duniain['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$resepa->getKey().'/checkout', [])
        ->assertCreated();

    // One day past it, refused.
    Carbon::setTestNow(Carbon::parse('2026-03-19 08:00:00'));

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', []);

    $response->assertUnprocessable();

    expect($response->json('errors.resep_id.0'))->toBe('Resep sudah kedaluwarsa dan tidak dapat dipesan.')
        ->and($response->json('errors.resep_id.1'))->toContain(PO46_BERLAKU)
        ->and(DB::table('pesanan_obat')->where('pasien_id', $dunia['pasien'])->count())->toBe(0)
        ->and((int) DB::table('apotek_stok')->where('id', $dunia['stok'])->value('jumlah_stok'))->toBe(100);
});

test('a facility that is not an apotek is refused, with the type it actually is', function (): void {
    // `pesanan_obat.apotek_id` is `NOT NULL` with a foreign key to `faskes(id)`
    // (`:802`, `:816`) and NOTHING constrains `faskes.tipe` to `'apotek'`
    // (`:365`), so a hospital is representable as the dispensing pharmacy.
    $dunia = po46Dunia(100, 10, 'rumah_sakit');

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', []);

    $response->assertUnprocessable();

    $pesan = $response->json('errors.apotek_id');

    expect($pesan)->toBeArray()->toHaveCount(2)
        ->and($pesan[0])->toBe('Faskes yang dipilih bukan apotek.')
        ->and($pesan[1])->toContain('rumah_sakit')
        ->and(DB::table('pesanan_obat')->count())->toBe(0)
        ->and((int) DB::table('apotek_stok')->where('id', $dunia['stok'])->value('jumlah_stok'))->toBe(100);
});

test('an inactive pharmacy and an unknown id are two different 422s', function (): void {
    $nonaktif = po46Faskes('apotek', ['status_aktif' => 0]);
    $dunia = po46Dunia(100, 10, 'apotek');

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', ['apotek_id' => $nonaktif]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.apotek_id.0', 'Apotek yang dipilih tidak aktif.');

    $hilang = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', ['apotek_id' => 99999999]);

    $hilang->assertUnprocessable()->assertJsonPath('errors.apotek_id.0', 'Apotek dengan id 99999999 tidak ditemukan.');

    expect(DB::table('pesanan_obat')->count())->toBe(0);
});

test('no pharmacy named anywhere is a 422, because pesanan_obat.apotek_id is NOT NULL', function (): void {
    // `resep.apotek_id` is NULLABLE (`:749`) and `pesanan_obat.apotek_id` is
    // `NOT NULL` (`:802`), so the case where neither names one is real. Guessing
    // a pharmacy would ship a prescription somewhere nobody agreed to.
    $pasienUser = po46User('Pasien Tanpa Apotek', 'pasien', 'pasien');
    $pasien = po46Pasien((int) $pasienUser->getKey());
    $dokter = po46Dokter(po46User('Dokter Tanpa Apotek', 'dokter')->getKey());
    $obat = po46Obat();
    $apotek = po46Faskes();
    po46Stok($apotek, $obat, 50);

    $resep = po46Resep($pasien, $dokter, [$obat], 'diverifikasi');

    expect($resep->apotek_id)->toBeNull();

    $response = test()->withToken(po46As($pasienUser)['Authorization'])
        ->postJson('/api/v1/resep/'.$resep->getKey().'/checkout', []);

    $response->assertUnprocessable();

    $pesan = $response->json('errors.apotek_id');

    expect($pesan)->toHaveCount(2)
        ->and($pesan[0])->toBe('Apotek tujuan wajib diisi.')
        ->and($pesan[1])->toContain('Resep ini tidak memiliki apotek_id')
        ->and(DB::table('pesanan_obat')->count())->toBe(0);
});

test('a pharmacy without the drug is refused, and the message says where the alternatives are', function (): void {
    $dunia = po46Dunia(0, 10, 'apotek');

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', []);

    $response->assertUnprocessable()->assertJsonPath('success', false);

    $pesan = $response->json('errors.apotek_id');

    expect($pesan)->toHaveCount(2)
        ->and($pesan[0])->toContain('tidak mencukupi')
        ->and($pesan[0])->toContain('tersedia 0, diminta 10')
        // The alternatives are DATA, not a message: a 422's `errors` map is
        // field-keyed strings, so the list lives on the stock endpoint and the
        // message points at it.
        ->and($pesan[1])->toContain('GET /api/v1/obat/{id}/stok');

    expect(DB::table('pesanan_obat')->count())->toBe(0)
        ->and(DB::table('invoice')->where('referensi_tipe', 'pesanan_obat')->count())->toBe(0)
        ->and((int) DB::table('apotek_stok')->where('id', $dunia['stok'])->value('jumlah_stok'))->toBe(0);
});

test('a prescription with a racikan is refused, and the message NAMES the racikan', function (): void {
    // `resep_item.obat_id` NULL means racikan (`:770`) and `ResepService` writes
    // `harga_satuan` 0.00 for it, because a mixture has no catalogue price.
    // `InvoiceService` refuses a zero-priced line by design, so an order
    // containing one cannot be invoiced.
    $pasienUser = po46User('Pasien Racikan', 'pasien', 'pasien');
    $pasien = po46Pasien((int) $pasienUser->getKey());
    $dokter = po46Dokter(po46User('Dokter Racikan', 'dokter')->getKey());
    $apotek = po46Faskes();

    $resep = po46Resep($pasien, $dokter, [], 'diverifikasi', ['apotek_id' => $apotek]);

    $response = test()->withToken(po46As($pasienUser)['Authorization'])
        ->postJson('/api/v1/resep/'.$resep->getKey().'/checkout', []);

    $response->assertUnprocessable();

    $pesan = $response->json('errors.resep_id');

    expect($pesan)->toHaveCount(2)
        ->and($pesan[0])->toContain('tidak memiliki harga katalog')
        ->and($pesan[1])->toContain('Racikan Uji PO46')
        ->and(DB::table('pesanan_obat')->count())->toBe(0)
        ->and(DB::table('invoice')->where('referensi_tipe', 'pesanan_obat')->count())->toBe(0);
});

test('a prescription with no items at all is refused rather than producing a zero order', function (): void {
    $pasienUser = po46User('Pasien Kosong', 'pasien', 'pasien');
    $pasien = po46Pasien((int) $pasienUser->getKey());
    $dokter = po46Dokter(po46User('Dokter Kosong', 'dokter')->getKey());
    $apotek = po46Faskes();

    $resep = po46Resep($pasien, $dokter, [po46Obat()], 'diverifikasi', ['apotek_id' => $apotek]);

    DB::table('resep_item')->where('resep_id', $resep->getKey())->delete();

    $response = test()->withToken(po46As($pasienUser)['Authorization'])
        ->postJson('/api/v1/resep/'.$resep->getKey().'/checkout', []);

    $response->assertUnprocessable();

    expect($response->json('errors.resep_id.0'))->toBe('Resep tidak memiliki item.')
        ->and(DB::table('pesanan_obat')->count())->toBe(0);
});

test('a multi-item prescription takes its stock locks in ascending obat_id order', function (): void {
    // Two checkouts of the same two drugs whose `resep_item` rows are stored in
    // opposite order would take the same two `apotek_stok` rows in opposite
    // orders, and InnoDB would detect the cycle and roll one of them back with
    // error 1213 - a failure caused entirely by row order. Sorting by
    // `obat_id` is what makes every checkout of a basket serialise instead.
    $sql = [];
    DB::listen(function ($peristiwa) use (&$sql): void {
        if ($peristiwa->connectionName === PO46_KONEKSI_UTAMA) {
            $sql[] = mb_strtolower($peristiwa->sql);
        }
    });

    $pasienUser = po46User('Pasien Dua Obat', 'pasien', 'pasien');
    $pasien = po46Pasien((int) $pasienUser->getKey());
    $dokter = po46Dokter(po46User('Dokter Dua Obat', 'dokter')->getKey());
    $apotek = po46Faskes();
    $obatA = po46Obat('Amoxicillin');
    $obatB = po46Obat('Metformin');
    po46Stok($apotek, $obatA, 50);
    po46Stok($apotek, $obatB, 50);

    $resep = po46Resep($pasien, $dokter, [$obatB, $obatA], 'diverifikasi', ['apotek_id' => $apotek]);

    // Confirm the fixture really did store them in DESCENDING id order, so the
    // ascending assertion below is measuring the service and not the fixture.
    $terimpan = DB::table('resep_item')->where('resep_id', $resep->getKey())->orderBy('id')->pluck('obat_id')->all();
    expect($terimpan)->toBe([$obatB, $obatA]);

    $sql = [];
    $terkunci = [];

    // The BINDINGS, not the SQL text: `where('obat_id', $id)` compiles to a `?`
    // placeholder, so the drug is only identifiable from
    // `QueryExecuted::$bindings` - and reading it from the SQL would find
    // nothing and make this assertion vacuously pass.
    DB::listen(function ($peristiwa) use (&$terkunci): void {
        if ($peristiwa->connectionName !== PO46_KONEKSI_UTAMA) {
            return;
        }

        $satu = mb_strtolower($peristiwa->sql);

        if (! str_contains($satu, 'from `apotek_stok`') || ! str_contains($satu, 'for update')) {
            return;
        }

        // Bindings are [apotek_id, obat_id] in the order the wheres were added.
        $terkunci[] = (int) ($peristiwa->bindings[1] ?? 0);
    });

    test()->withToken(po46As($pasienUser)['Authorization'])
        ->postJson('/api/v1/resep/'.$resep->getKey().'/checkout', [])
        ->assertCreated();

    $naik = $terkunci;
    sort($naik);

    expect($terkunci)->toHaveCount(2)
        ->and($terkunci)->toBe($naik)
        ->and($terkunci)->toBe([min($obatA, $obatB), max($obatA, $obatB)]);
});

// =====================================================================
// Ownership: 404 for the row, 403 for the caller
// =====================================================================

test('another patient prescription is a 404 and an account with no patient row is a 403', function (): void {
    $dunia = po46Dunia();

    // Another patient, with the `pasien` role, asking for someone else's
    // prescription. A 403 here would confirm the row EXISTS, which over a
    // sequential BIGINT key is a cross-tenant existence oracle.
    $orangLain = po46AkunPasien('Pasien Orang Lain');

    $response = test()->withToken(po46As($orangLain['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', []);

    $response->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.')
        ->assertJsonPath('errors', []);

    // An account that owns NO `pasien` row at all. It passes
    // `permission:pesanan.buat` only if it holds the role, so the role is
    // granted and the 403 comes from `ownPasien()` - a fact about the CALLER.
    $tanpaProfil = po46User('Admin Tanpa Profil', 'admin', 'admin');

    test()->withToken(po46As($tanpaProfil)['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertForbidden();

    expect(DB::table('pesanan_obat')->count())->toBe(0);
});

test('the checkout guard refuses every account type that does not hold pesanan.buat', function (): void {
    // `RbacCatalog::ROLE_PERMISSIONS` grants `pesanan.buat` to `pasien` and
    // `superadmin` only. `superadmin` holds it and is STILL refused, by
    // `ownPasien()` - an oversight account does not check out on a patient's
    // behalf, because `pesanan_obat.pasien_id` is the CALLER's own patient row
    // and the endpoint takes no other.
    $dunia = po46Dunia();
    $resepId = (int) $dunia['resep']->getKey();

    $ditolak = [];

    foreach ([
        'dokter' => 'dokter',
        'apoteker' => 'apoteker',
        'admin' => 'admin',
        'superadmin' => 'superadmin',
        // `perawat` and `kurir` are real `users.tipe` values (`:139`) that hold
        // NO role in `RbacCatalog::ROLES` at all, so there is no role to assign
        // and `RoleAssigner` refuses the name. That is the point: they are
        // refused by the `permission:` gate because they hold no grant, not
        // because a rule was written to exclude them.
        'perawat' => null,
        'kurir' => null,
    ] as $tipe => $role) {
        $akun = po46User('Akun '.$tipe, $tipe, $role);

        test()->withToken(po46As($akun)['Authorization'])
            ->postJson('/api/v1/resep/'.$resepId.'/checkout', [])
            ->assertForbidden();

        $ditolak[] = $tipe;
    }

    expect($ditolak)->toHaveCount(6)
        ->and(DB::table('pesanan_obat')->count())->toBe(0);
});

test('an unauthenticated checkout is the guard 401, not the service 403', function (): void {
    $dunia = po46Dunia();

    test()->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

// =====================================================================
// The stock endpoint
// =====================================================================

test('the stock read answers the chosen shelf plus the alternatives, and a zero shelf is 200 not 422', function (): void {
    $apotekKosong = po46Faskes();
    $apotekLain = po46Faskes();
    $obat = po46Obat();

    po46Stok($apotekKosong, $obat, 0);
    po46Stok($apotekLain, $obat, 25);

    $user = po46User('Pasien Cek Stok', 'pasien', 'pasien');

    // A chosen pharmacy with NONE of the drug is a 200 with `cukup: false` and
    // an `alternatif` list. A 422 would refuse a question, and the caller asked
    // one; the spec's rule is that the answer must name the other pharmacies.
    $response = test()->withToken(po46As($user)['Authorization'])
        ->getJson('/api/v1/obat/'.$obat.'/stok?apotek_id='.$apotekKosong.'&jumlah=10');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.stok.apotek.recorded', true)
        ->assertJsonPath('data.stok.apotek.cukup', false)
        ->assertJsonPath('data.stok.apotek.jumlah_stok', 0)
        ->assertJsonPath('data.stok.alternatif.0.apotek_id', $apotekLain)
        ->assertJsonPath('data.stok.alternatif.0.jumlah_stok', 25)
        // Money is a STRING here too.
        ->assertJsonPath('data.stok.alternatif.0.harga_jual', '7500.00');

    // The chosen pharmacy is never repeated in its own alternatives list.
    expect($response->json('data.stok.alternatif'))->toHaveCount(1);

    // A quantity the alternative cannot satisfy filters it OUT, which is what
    // makes "sufficient" mean sufficient FOR THIS DEMAND.
    $janji = test()->withToken(po46As($user)['Authorization'])
        ->getJson('/api/v1/obat/'.$obat.'/stok?apotek_id='.$apotekKosong.'&jumlah=30');

    $janji->assertOk()->assertJsonPath('data.stok.alternatif', []);

    // Without `apotek_id` the answer is "where can this drug be had at all".
    $semua = test()->withToken(po46As($user)['Authorization'])
        ->getJson('/api/v1/obat/'.$obat.'/stok');

    $semua->assertOk()->assertJsonPath('data.stok.apotek', null);

    $daftar = collect($semua->json('data.stok.alternatif'))->pluck('apotek_id')->all();

    // The stocked pharmacy IS offered; the one reading zero is NOT, because the
    // default demand is one unit and `jumlah_stok >= jumlah` filters it out. A
    // zero-stock pharmacy is an answer to a different question - "which
    // pharmacies can supply me" - and answering it with a row that cannot is
    // the failure a patient experiences as being sent in circles.
    expect($daftar)->toContain($apotekLain)
        ->and($daftar)->not->toContain($apotekKosong);
});

test('a pharmacy with NO stock row is distinguished from one with a row of zero', function (): void {
    $tanpaBaris = po46Faskes();
    $denganNol = po46Faskes();
    $obat = po46Obat();
    po46Stok($denganNol, $obat, 0);

    $user = po46User('Pasien Tanpa Baris', 'pasien', 'pasien');

    $response = test()->withToken(po46As($user)['Authorization'])
        ->getJson('/api/v1/obat/'.$obat.'/stok?apotek_id='.$tanpaBaris);

    $response->assertOk()
        ->assertJsonPath('data.stok.apotek.recorded', false)
        ->assertJsonPath('data.stok.apotek.jumlah_stok', 0)
        ->assertJsonPath('data.stok.apotek.cukup', false);

    $nol = test()->withToken(po46As($user)['Authorization'])
        ->getJson('/api/v1/obat/'.$obat.'/stok?apotek_id='.$denganNol);

    $nol->assertOk()
        ->assertJsonPath('data.stok.apotek.recorded', true)
        ->assertJsonPath('data.stok.apotek.jumlah_stok', 0);
});

test('the stock read is ungated: a nurse, a courier and an admin all read a shelf', function (): void {
    // A shelf is a catalogue-adjacent fact about a drug at a facility, and no
    // code in `RbacCatalog::PERMISSIONS` names reading one. `obat.cari` IS real
    // but is granted to `dokter` alone, so gating this on it would 403 the
    // PATIENT - the one account type that has to ask this question. `perawat`
    // and `kurir` hold no role at all, so ANY gate would lock them out of a
    // read that concerns neither of them.
    $apotek = po46Faskes();
    $obat = po46Obat();
    po46Stok($apotek, $obat, 7);

    foreach (['perawat' => null, 'kurir' => null, 'admin' => 'admin', 'apoteker' => 'apoteker'] as $tipe => $role) {
        $akun = po46User('Pembaca '.$tipe, $tipe, $role);

        test()->withToken(po46As($akun)['Authorization'])
            ->getJson('/api/v1/obat/'.$obat.'/stok?apotek_id='.$apotek)
            ->assertOk()
            ->assertJsonPath('data.stok.apotek.jumlah_stok', 7);
    }
});

test('but the stock read is NOT anonymous, which is a separate assertion on purpose', function (): void {
    // `withToken()` sets a default header for the REST of the test, so an
    // "anonymous" request made later in a test that already authenticated is
    // not anonymous. Asserted in its own test so the claim is real.
    $apotek = po46Faskes();
    $obat = po46Obat();
    po46Stok($apotek, $obat, 7);

    test()->getJson('/api/v1/obat/'.$obat.'/stok?apotek_id='.$apotek)
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');

    expect(test()->getJson('/api/v1/obat/'.$obat.'/stok?apotek_id='.$apotek)->status())->toBe(401);
});

test('a drug that does not exist is a 404 and a facility that is not a pharmacy is a 422', function (): void {
    $obat = po46Obat();
    $rumahSakit = po46Faskes('rumah_sakit');
    $user = po46User('Pasien 404', 'pasien', 'pasien');

    test()->withToken(po46As($user)['Authorization'])
        ->getJson('/api/v1/obat/99999999/stok')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');

    $response = test()->withToken(po46As($user)['Authorization'])
        ->getJson('/api/v1/obat/'.$obat.'/stok?apotek_id='.$rumahSakit);

    $response->assertUnprocessable();

    expect($response->json('errors.apotek_id'))->toHaveCount(2)
        ->and($response->json('errors.apotek_id.1'))->toContain('rumah_sakit');
});

// =====================================================================
// The order read
// =====================================================================

test('the order read publishes the tracking trail, and another patient gets 404', function (): void {
    $dunia = po46Dunia();

    $created = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $id = (int) $created->json('data.pesanan.id');

    $response = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->getJson('/api/v1/pesanan-obat/'.$id);

    $response->assertOk()
        ->assertJsonPath('data.pesanan.id', $id)
        ->assertJsonPath('data.pesanan.tracking.0.status', 'menunggu_pembayaran')
        // NO `created_at` and NO `updated_at`: this table has neither, and
        // publishing a key the column does not exist would make a client read
        // null and believe the parcel has no time on it.
        ->assertJsonMissingPath('data.pesanan.tracking.0.created_at')
        ->assertJsonMissingPath('data.pesanan.tracking.0.updated_at')
        ->assertJsonStructure(['data' => ['pesanan' => ['tracking' => [['waktu']]]]]);

    expect($response->json('data.pesanan.tracking.0.waktu'))->not->toBeNull();

    // Another patient: 404, not 403.
    $orangLain = po46AkunPasien('Pasien Pengintip');

    test()->withToken(po46As($orangLain['user'])['Authorization'])
        ->getJson('/api/v1/pesanan-obat/'.$id)
        ->assertNotFound();
});

test('the order read is a DISJUNCTION: a pharmacist or an oversight account reads any order', function (): void {
    // A route gate can only express a conjunction, so the per-row half of the
    // rule lives in the service. `apoteker` and `admin` both hold
    // `pesanan.lihat` and neither owns a `pasien` row.
    $dunia = po46Dunia();

    $created = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $id = (int) $created->json('data.pesanan.id');

    foreach (['apoteker' => 'apoteker', 'admin' => 'admin', 'superadmin' => 'superadmin'] as $tipe => $role) {
        $akun = po46User('Pembaca Order '.$tipe, $tipe, $role);

        test()->withToken(po46As($akun)['Authorization'])
            ->getJson('/api/v1/pesanan-obat/'.$id)
            ->assertOk()
            ->assertJsonPath('data.pesanan.id', $id);
    }
});

test('the order read refuses dokter, perawat and kurir', function (): void {
    // `dokter` holds NO `pesanan.lihat` in `RbacCatalog::ROLE_PERMISSIONS`, so a
    // prescriber is refused the parcel they prescribed. `perawat` and `kurir`
    // hold no role at all.
    $dunia = po46Dunia();

    $created = test()->withToken(po46As($dunia['user'])['Authorization'])
        ->postJson('/api/v1/resep/'.$dunia['resep']->getKey().'/checkout', [])
        ->assertCreated();

    $id = (int) $created->json('data.pesanan.id');

    foreach (['dokter' => 'dokter', 'perawat' => null, 'kurir' => null] as $tipe => $role) {
        $akun = po46User('Pembaca Ditolak '.$tipe, $tipe, $role);

        test()->withToken(po46As($akun)['Authorization'])
            ->getJson('/api/v1/pesanan-obat/'.$id)
            ->assertForbidden();
    }
});

// =====================================================================
// The routes, and the guards they carry
// =====================================================================

test('the three routes exist with the guards each one justifies', function (): void {
    $routes = po46Routes();

    expect(array_keys($routes))->toEqualCanonicalizing([
        'POST api/v1/resep/{id}/checkout',
        'GET api/v1/obat/{id}/stok',
        'GET api/v1/pesanan-obat/{id}',
    ]);

    // `pesanan.buat` and `pesanan.lihat` are BOTH real codes, and the stock
    // read carries NEITHER - which is the decision, not an omission.
    expect(RbacCatalog::isPermission('pesanan.buat'))->toBeTrue()
        ->and(RbacCatalog::isPermission('pesanan.lihat'))->toBeTrue();

    $guard = fn (string $key): array => array_values(array_filter(
        po46Guards($routes, $key),
        static fn (string $m): bool => str_starts_with($m, 'permission:') || str_starts_with($m, 'tipe:'),
    ));

    expect($guard('POST api/v1/resep/{id}/checkout'))->toBe(['permission:pesanan.buat'])
        ->and($guard('GET api/v1/pesanan-obat/{id}'))->toBe(['permission:pesanan.lihat'])
        ->and($guard('GET api/v1/obat/{id}/stok'))->toBe([]);

    // Every one of the three is behind `auth:sanctum`, so an anonymous caller
    // is the guard's 401 rather than the service's 403.
    foreach (array_keys($routes) as $key) {
        expect(po46Guards($routes, $key))->toContain('auth:sanctum');
    }

    // `whereNumber` on every `{id}`, so a non-numeric segment is a router 404.
    foreach (array_keys($routes) as $key) {
        expect($routes[$key]->uri())->toContain('{id}');
    }

    expect(test()->getJson('/api/v1/pesanan-obat/abc')->assertNotFound()->status())->toBe(404)
        ->and(test()->getJson('/api/v1/obat/abc/stok')->assertNotFound()->status())->toBe(404);
});

test('every permission and tipe string this todo adds resolves against the RbacCatalog', function (): void {
    // `EnsurePermission` and `EnsureUserType` throw a `LogicException` - a 500 -
    // for an unknown code, so a typo is a build-time mistake rather than a 403
    // for everyone. The tripwire is installed; this proves the three new routes
    // do not trip it.
    $routes = po46Routes();

    foreach ($routes as $key => $route) {
        foreach (po46Guards($routes, $key) as $middleware) {
            // Only the two RBAC aliases. `auth:sanctum` is the FRAMEWORK's guard
            // and is not a `RbacCatalog` code, so splitting every middleware on
            // `:` would "fail" the framework's own alias.
            if (! str_starts_with($middleware, 'permission:') && ! str_starts_with($middleware, 'tipe:')) {
                continue;
            }

            [$jenis, $nilai] = explode(':', $middleware, 2);

            $known = $jenis === 'permission'
                ? RbacCatalog::isPermission($nilai)
                : RbacCatalog::isUserType($nilai);

            expect($known)->toBeTrue("{$key} carries an unknown {$jenis} code [{$nilai}]");
        }
    }
});
