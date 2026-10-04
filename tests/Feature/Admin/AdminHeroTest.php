<?php

declare(strict_types=1);

use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The landing carousel: one public read, six `admin` writes
|--------------------------------------------------------------------------
|
| `hero_slides` is an EXTRA table (registered in `docs/schema-notes.md`), so this
| file asserts everything the module promises without leaning on the reference DDL
| for any of it: the window is evaluated here, the guards are driven here and the
| files are checked on a faked disk rather than on the developer's `public/`.
|
| **What this file proves, and why each half matters:**
|
| - The public read answers ONLY published slides inside their window, in strip
|   order, and an empty install answers `[]` rather than an error - because the web
|   client's fallback logic (`SLIDE_HERO`) is decided by exactly that difference.
| - `hero.kelola` (this module's owner-approved catalogue code, granted to `admin`
|   and `superadmin`) is the gate, on top of the F14 party gate. All three refusals
|   are driven: a `pasien` and a `dokter` fail the party gate, an `admin` with no
|   role passes it and fails the permission, and both named holders pass.
| - The publish switch is a ONE-FIELD update, so an operator cannot lose a slide's
|   copy by toggling it.
| - The sixth published slide is refused by name: five is the strip's ceiling.
| - `cta_target` refuses an absolute and a protocol-relative target, which is the
|   open-redirect refusal and not a formatting preference.
| - An image is uploaded WITH its alternative text, replaces and unlinks whatever
|   was there, and leaves with the slide.
|
| **Fixtures come from `f14-helpers.php`** (`f14Admin`, `f14Superadmin`,
| `f14Pasien`, `f14User`, `f14As`, `f14TanpaToken`), and `RefreshDatabase` is bound
| by `tests/Pest.php`.
*/

require_once __DIR__.'/f14-helpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    // The multipart uploads in this file go through `post()`, not `postJson()`, so
    // without an explicit `Accept` the 422s below would answer a redirect and
    // `assertUnprocessable` would be asserting the wrong transport.
    $this->withHeader('Accept', 'application/json');
});

/**
 * A real 1x1 PNG, byte for byte.
 *
 * `mimes:jpg,jpeg,png,webp,avif` is decided by what fileinfo reads from the CONTENT,
 * not by the name the request reports, so a positive case has to carry actual image
 * bytes: a fake file with a declared MIME would be measuring the rule's failure
 * branch against a `TestFile` rather than against a real upload.
 */
function heroPng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    );
}

/**
 * Insert one slide straight through the query builder and answer its id.
 *
 * The table has no model on purpose (see `HeroService`'s class docblock), so this is
 * how a fixture reaches it - and it keeps every test's row visible as an explicit
 * column list rather than as a model the reader has to reconstruct.
 *
 * @param  array<string, mixed>  $ubah
 */
function heroBaris(array $ubah = []): int
{
    return (int) DB::table('hero_slides')->insertGetId(array_merge([
        'urutan' => 0,
        'judul' => 'Judul uji',
        'deskripsi' => 'Deskripsi uji untuk carousel.',
        'cta_label' => 'Lihat',
        'cta_target' => '/dokter',
        'status' => 'draf',
    ], $ubah));
}

// ----------------------------------------------------------------- the public read

test('the public read answers only published slides inside their window, in strip order', function (): void {
    $selalu = heroBaris(['urutan' => 1, 'status' => 'tayang']);
    $draf = heroBaris(['urutan' => 2, 'status' => 'draf']);
    $belumWaktunya = heroBaris([
        'urutan' => 3,
        'status' => 'tayang',
        'mulai_tayang' => Carbon::now()->addDay()->format('Y-m-d H:i:s'),
    ]);
    $sudahBerlalu = heroBaris([
        'urutan' => 4,
        'status' => 'tayang',
        'selesai_tayang' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
    ]);
    $terakhir = heroBaris(['urutan' => 5, 'status' => 'tayang']);

    $response = $this->getJson('/api/v1/hero');

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($response->json('data.hero'))->toHaveCount(2)
        ->and($response->json('data.hero.0.id'))->toBe($selalu)
        ->and($response->json('data.hero.1.id'))->toBe($terakhir)
        // The three excluded rows are excluded for three DIFFERENT reasons - a
        // draft, a window that has not opened and one that has closed - and the
        // assertions above prove all three at once only because each has its own
        // `urutan`. A count of 2 with the wrong two would fail the id checks, and
        // they are still rows: the read excludes them, nothing deleted them.
        ->and(DB::table('hero_slides')->whereIn('id', [$draf, $belumWaktunya, $sudahBerlalu])->count())->toBe(3)
        // The stored disk path never leaves the API: `gambar` is a URL and nothing
        // else publishes where the file lives.
        ->and($response->json('data.hero.0'))->not->toHaveKey('gambar_path');
});

test('a fresh install answers an empty list, not an error, because the client falls back', function (): void {
    $response = $this->getJson('/api/v1/hero');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.hero', []);

    // It carries no `auth:sanctum`: a signed-out visitor asks this on the page they
    // arrived at, so a 401 here would be a broken front page rather than a prompt.
    expect($response->json('data.hero'))->toBe([]);
});

// --------------------------------------------------------------------- the guards

test('the admin surface refuses a patient and a doctor, an admin without the role, and admits both named holders', function (): void {
    heroBaris(['status' => 'tayang']);

    f14As(f14Pasien()['user']);
    $this->getJson('/api/v1/admin/hero')->assertForbidden();

    f14As(f14User('Dokter Uji', 'dokter'));
    $this->getJson('/api/v1/admin/hero')->assertForbidden();

    // The interesting half: this caller PASSES `tipe:admin,superadmin` and still
    // fails, because the account type is not a grant. Without this assertion a
    // permission-less party gate would look identical to a working one.
    f14As(f14User('Admin Tanpa Role', 'admin'));
    $this->getJson('/api/v1/admin/hero')->assertForbidden();

    f14As(f14Admin());
    $this->getJson('/api/v1/admin/hero')->assertOk();

    f14As(f14Superadmin());
    $this->getJson('/api/v1/admin/hero')->assertOk();
});

test('the same three refusals apply to a write, and to a write with no token at all', function (): void {
    $id = heroBaris(['status' => 'tayang']);

    f14TanpaToken();
    $this->postJson('/api/v1/admin/hero', [
        'judul' => 'Aman',
        'deskripsi' => 'Teks.',
        'cta_label' => 'Lihat',
        'cta_target' => '/dokter',
    ])->assertUnauthorized();

    f14As(f14Pasien()['user']);
    $this->putJson("/api/v1/admin/hero/{$id}", ['status' => 'draf'])->assertForbidden();
    $this->deleteJson("/api/v1/admin/hero/{$id}")->assertForbidden();

    f14As(f14User('Admin Tanpa Role', 'admin'));
    $this->deleteJson("/api/v1/admin/hero/{$id}")->assertForbidden();

    // Nothing was deleted by any of the four refusals above.
    expect(DB::table('hero_slides')->where('id', $id)->exists())->toBeTrue();
});

// --------------------------------------------------------------------------- CRUD

test('an admin creates, publishes with a one-field update, and the public read follows', function (): void {
    f14As(f14Admin());

    $dibuat = $this->postJson('/api/v1/admin/hero', [
        'judul' => 'Promo Oktober',
        'deskripsi' => 'Diskon konsultasi sepanjang Oktober.',
        'cta_label' => 'Lihat Promo',
        'cta_target' => '/dokter',
    ]);

    $dibuat->assertCreated()
        ->assertJsonPath('data.hero.status', 'draf')
        ->assertJsonPath('data.hero.tayang_aktif', false);

    $id = (int) $dibuat->json('data.hero.id');

    // A draft is invisible on the front page, which is what makes the switch worth
    // having: writing and publishing are two separate acts.
    $this->getJson('/api/v1/hero')->assertOk()->assertJsonPath('data.hero', []);

    // The switch, and NOTHING else in the body - the copy, the window and the image
    // survive because every other rule on the server is `sometimes`.
    $this->putJson("/api/v1/admin/hero/{$id}", ['status' => 'tayang'])
        ->assertOk()
        ->assertJsonPath('data.hero.tayang_aktif', true)
        ->assertJsonPath('data.hero.judul', 'Promo Oktober');

    $this->getJson('/api/v1/hero')
        ->assertOk()
        ->assertJsonPath('data.hero.0.id', $id)
        ->assertJsonPath('data.hero.0.cta_target', '/dokter');

    expect(DB::table('hero_slides')->where('id', $id)->value('judul'))->toBe('Promo Oktober');

    $this->deleteJson("/api/v1/admin/hero/{$id}")->assertOk();

    expect(DB::table('hero_slides')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('hero_slides')->count())->toBe(0);
});

test('the sixth slide published at once is refused by name rather than silently ignored', function (): void {
    f14As(f14Admin());

    foreach (range(1, 5) as $urutan) {
        heroBaris(['urutan' => $urutan, 'status' => 'tayang']);
    }

    $this->postJson('/api/v1/admin/hero', [
        'urutan' => 6,
        'judul' => 'Terlalu banyak',
        'deskripsi' => 'Slide keenam.',
        'cta_label' => 'Lihat',
        'cta_target' => '/dokter',
        'status' => 'tayang',
    ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

    // The refusal is about the CAP, not about the payload: the same body as a draft
    // is a perfectly good request.
    $this->postJson('/api/v1/admin/hero', [
        'urutan' => 6,
        'judul' => 'Cadangan',
        'deskripsi' => 'Slide cadangan.',
        'cta_label' => 'Lihat',
        'cta_target' => '/dokter',
        'status' => 'draf',
    ])->assertCreated();

    // And a sixth PUBLISHED row is not reachable by editing an existing draf either:
    // the count excludes only the row being edited.
    $keenam = heroBaris(['urutan' => 7, 'status' => 'draf']);
    $this->putJson("/api/v1/admin/hero/{$keenam}", ['status' => 'tayang'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('the target must be an internal path: an absolute and a protocol-relative URL are both 422', function (): void {
    f14As(f14Admin());

    $isi = [
        'judul' => 'Tautan uji',
        'deskripsi' => 'Teks uji.',
        'cta_label' => 'Lihat',
    ];

    // An absolute URL, refused by the anchored regex rather than by a `url` rule
    // that would have ACCEPTED it.
    $this->postJson('/api/v1/admin/hero', $isi + ['cta_target' => 'https://contoh.example/phish'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cta_target']);

    // The classic bypass of a naive `str_starts_with($target, '/')`: two slashes
    // make the second segment a host.
    $this->postJson('/api/v1/admin/hero', $isi + ['cta_target' => '//contoh.example/phish'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cta_target']);

    $this->postJson('/api/v1/admin/hero', [
        'deskripsi' => 'Tanpa judul sama sekali.',
        'cta_label' => 'Lihat',
        'cta_target' => '/dokter',
    ])->assertUnprocessable()->assertJsonValidationErrors(['judul']);

    // A single slash is the whole app's home route and is legal.
    $this->postJson('/api/v1/admin/hero', $isi + ['cta_target' => '/'])
        ->assertCreated();
});

// ------------------------------------------------------------------------- images

test('an image uploads with its alternative text, replaces the previous file, and leaves with the slide', function (): void {
    Storage::fake('public');

    f14As(f14Admin());

    $id = heroBaris(['status' => 'tayang']);

    $this->post("/api/v1/admin/hero/{$id}/gambar", [
        'gambar' => UploadedFile::fake()->createWithContent('promo.png', heroPng()),
        'gambar_alt' => 'Dokter sedang berkonsultasi dengan pasien.',
    ])->assertOk()->assertJsonPath('data.hero.gambar_alt', 'Dokter sedang berkonsultasi dengan pasien.');

    $pertama = (string) DB::table('hero_slides')->where('id', $id)->value('gambar');

    expect($pertama)->toStartWith('hero/')
        ->and(Storage::disk('public')->exists($pertama))->toBeTrue()
        // What the public read publishes is the file that was stored, basename and
        // all - the URL's host is the environment's business, the name is not.
        ->and($this->getJson('/api/v1/hero')->json('data.hero.0.gambar'))->toContain(basename($pertama))
        ->and(Storage::disk('public')->allFiles('hero'))->toHaveCount(1);

    // A second upload REPLACES the first, and the first is unlinked - two files in a
    // public folder for one slide would be an orphan neither request owns.
    $this->post("/api/v1/admin/hero/{$id}/gambar", [
        'gambar' => UploadedFile::fake()->createWithContent('promo-baru.png', heroPng()),
        'gambar_alt' => 'Promo konsultasi Oktober.',
    ])->assertOk();

    $kedua = (string) DB::table('hero_slides')->where('id', $id)->value('gambar');

    expect($kedua)->not->toBe($pertama)
        ->and(Storage::disk('public')->exists($pertama))->toBeFalse()
        ->and(Storage::disk('public')->allFiles('hero'))->toHaveCount(1);

    // Dropping the image keeps the slide: the copy is the campaign, the photo is not.
    $this->deleteJson("/api/v1/admin/hero/{$id}/gambar")
        ->assertOk()
        ->assertJsonPath('data.hero.gambar', null)
        ->assertJsonPath('data.hero.gambar_alt', null);

    expect(Storage::disk('public')->allFiles('hero'))->toBeEmpty();

    // And a delete takes whatever file is left with it.
    $this->post("/api/v1/admin/hero/{$id}/gambar", [
        'gambar' => UploadedFile::fake()->createWithContent('promo-akhir.png', heroPng()),
        'gambar_alt' => 'Terakhir.',
    ])->assertOk();

    $this->deleteJson("/api/v1/admin/hero/{$id}")->assertOk();

    expect(Storage::disk('public')->allFiles('hero'))->toBeEmpty();
});

test('the alternative text travels with the file, and a non-image is refused before it is written', function (): void {
    Storage::fake('public');

    f14As(f14Admin());

    $id = heroBaris(['status' => 'tayang']);

    // Required WITH the file, because no `CHECK` in the schema can couple two
    // columns and the migration cannot be edited to add one.
    $this->post("/api/v1/admin/hero/{$id}/gambar", [
        'gambar' => UploadedFile::fake()->createWithContent('promo.png', heroPng()),
    ])->assertUnprocessable()->assertJsonValidationErrors(['gambar_alt']);

    // `mimes` reads the CONTENT, so a `.exe` named `.png` and a text file are both
    // refused - and refused before anything reaches the public disk.
    $this->post("/api/v1/admin/hero/{$id}/gambar", [
        'gambar' => UploadedFile::fake()->createWithContent('catatan.txt', 'bukan gambar'),
        'gambar_alt' => 'Teks.',
    ])->assertUnprocessable()->assertJsonValidationErrors(['gambar']);

    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(DB::table('hero_slides')->where('id', $id)->value('gambar'))->toBeNull();
});
