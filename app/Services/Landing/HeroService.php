<?php

declare(strict_types=1);

namespace App\Services\Landing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every read and write of `hero_slides`: the landing carousel, owned by `admin`.
 *
 * ## Why this is `DB::table` and not an Eloquent model
 *
 * `app/Models` holds exactly one class per table in `telemedicine_test.sql`, and
 * `ModelFoundationTest` proves it in both directions — a model whose table is not in
 * the reference DDL lands in that test's `$extra` list, and every model is also
 * required to name the DDL line it was generated from. `hero_slides` is an extra
 * table (registered in `docs/schema-notes.md`, created by migration `000088`), so an
 * Eloquent model for it would be answering a question the contract does not ask.
 * `AdminLaporanService` already sets the precedent: services that answer content
 * arrays query the table directly and hand arrays up.
 *
 * ## The consequence, stated rather than hidden: these writes are not audited
 *
 * `audit_log` is written by `AuditObserver` on Eloquent events, and
 * `tests/Feature/Audit/ArchitectureTest` asserts that no controller reaches the
 * `AuditLog` model and that `AuditLogWriter` is its only writer. Both hold here —
 * this service never names either. The cost is that publishing a banner produces no
 * audit row, and it is accepted rather than worked around: writing one directly would
 * mean a second writer beside the observer, and an Eloquent model would break the
 * one-model-per-contract-table rule above. If the owner ever wants carousel edits in
 * the trail, the honest route is adding `hero_slides` to the reference DDL (which is
 * read-only law) — not a side door.
 *
 * ## Rows are arrays, and the URL is built here
 *
 * `gambar` is stored as a disk path (`hero/x.jpg`) because the host belongs to the
 * environment; {@see url()} turns it into an absolute URL at the moment of reading,
 * the same way `KonsultasiService::simpanBerkas()` publishes an attachment. A row
 * with no image answers `null`, which is what makes the fallback slide work: the
 * carousel renders its gradient when this is `null` and a photograph when it is not.
 */
final class HeroService
{
    /** The disk every hero image lives on, and the folder inside it. */
    public const DISK = 'public';

    public const DIR = 'hero';

    /** Upload ceiling in KB, mirrored by `UnggahGambarHeroRequest`'s `max:` rule. */
    public const GAMBAR_MAKS_KB = 2048;

    /** The publication switch. Draft first in the ENUM, so a row starts unpublished. */
    public const STATUS_DRAF = 'draf';

    public const STATUS_TAYANG = 'tayang';

    /**
     * How many slides may be on air at once.
     *
     * The strip shows a dot per slide and auto-advances through them; ten published
     * slides is not a carousel, it is a queue. The cap is enforced on the write with
     * a 422 naming the field, because "publishing silently did nothing" is worse than
     * a refusal that says which switch is already full.
     */
    public const MAKS_TAYANG = 5;

    /**
     * The slides a signed-out visitor sees, in strip order.
     *
     * The window is applied here rather than in the request layer or the client:
     * `status = 'tayang'` is the operator's switch and the two timestamps are the
     * campaign's own dates, and only this query can see both. Three window shapes are
     * legal (always on, open-ended, until a date) and a backwards window simply
     * matches nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function daftarTayang(): array
    {
        $kini = Carbon::now()->format('Y-m-d H:i:s');

        $baris = DB::table('hero_slides')
            ->where('status', self::STATUS_TAYANG)
            ->where(function ($query) use ($kini): void {
                $query->whereNull('mulai_tayang')->orWhere('mulai_tayang', '<=', $kini);
            })
            ->where(function ($query) use ($kini): void {
                $query->whereNull('selesai_tayang')->orWhere('selesai_tayang', '>', $kini);
            })
            ->orderBy('urutan')
            ->orderBy('id')
            ->get();

        return $baris->map(fn (object $row): array => $this->susun($row, $kini))->all();
    }

    /**
     * Every row, drafts included, for the admin screen.
     *
     * No pagination: an operator reordering a strip has to see the whole strip, and
     * the set is bounded by {@see MAKS_TAYANG} published rows plus however many
     * drafts somebody left behind.
     *
     * @return list<array<string, mixed>>
     */
    public function daftarSemua(): array
    {
        $kini = Carbon::now()->format('Y-m-d H:i:s');

        return DB::table('hero_slides')
            ->orderBy('urutan')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->susun($row, $kini))
            ->all();
    }

    /**
     * Insert one slide and answer it as {@see daftarSemua()} would.
     *
     * `urutan` is optional: absent, the row takes the next free position so a new
     * slide lands at the end of the strip instead of at the front of it.
     *
     * @param  array<string, mixed>  $data  the validated request
     *
     * @throws ValidationException when publishing would exceed {@see MAKS_TAYANG}
     */
    public function simpan(array $data): array
    {
        if (($data['status'] ?? self::STATUS_DRAF) === self::STATUS_TAYANG) {
            $this->pastikanAdaSlotTayang();
        }

        $data += ['urutan' => $this->urutanBerikutnya(), 'status' => self::STATUS_DRAF];

        $id = DB::table('hero_slides')->insertGetId([
            'urutan' => (int) $data['urutan'],
            'eyebrow' => $data['eyebrow'] ?? null,
            'judul' => (string) $data['judul'],
            'deskripsi' => (string) $data['deskripsi'],
            'cta_label' => (string) $data['cta_label'],
            'cta_target' => (string) $data['cta_target'],
            'status' => (string) $data['status'],
            'mulai_tayang' => $this->waktu($data['mulai_tayang'] ?? null),
            'selesai_tayang' => $this->waktu($data['selesai_tayang'] ?? null),
        ]);

        return $this->cari((int) $id);
    }

    /**
     * Apply a partial update: only the keys the request actually validated are written.
     *
     * That is what makes `PUT /admin/hero/{id}` with `{"status":"tayang"}` the
     * publish-and-unpublish switch — the same shape F14's jadwal update uses for the
     * same reason.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws NotFoundHttpException when the id names no row
     * @throws ValidationException when publishing would exceed {@see MAKS_TAYANG}
     */
    public function ubah(int $id, array $data): array
    {
        $row = $this->cari($id);

        if (($data['status'] ?? null) === self::STATUS_TAYANG && $row['status'] !== self::STATUS_TAYANG) {
            $this->pastikanAdaSlotTayang($id);
        }

        $turun = array_intersect_key($data, array_flip([
            'urutan', 'eyebrow', 'judul', 'deskripsi', 'cta_label', 'cta_target', 'status',
        ]));

        foreach (['urutan'] as $kolom) {
            if (array_key_exists($kolom, $turun)) {
                $turun[$kolom] = (int) $turun[$kolom];
            }
        }

        foreach (['mulai_tayang', 'selesai_tayang'] as $kolom) {
            if (array_key_exists($kolom, $data)) {
                $turun[$kolom] = $this->waktu($data[$kolom]);
            }
        }

        if ($turun !== []) {
            DB::table('hero_slides')->where('id', $id)->update($turun);
        }

        return $this->cari($id);
    }

    /**
     * Delete the row and the image file it owns.
     *
     * The file goes with the row rather than being left for a janitor: a hero image
     * has no other consumer, and `Storage` has no cascade. A failure to unlink is not
     * a reason to refuse the delete, so the row goes regardless.
     *
     * @throws NotFoundHttpException when the id names no row
     */
    public function hapus(int $id): void
    {
        $path = $this->baris($id)->gambar;

        DB::table('hero_slides')->where('id', $id)->delete();

        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Store a new image for the slide, replacing and unlinking the previous one.
     *
     * The filename is random rather than the client's own name: `getClientOriginalName()`
     * is attacker-controlled, the extension comes from the MIME the upload already
     * passed, and two uploads of `promo.jpg` must not collide in a public folder.
     *
     * @throws NotFoundHttpException when the id names no row
     */
    public function pasangGambar(int $id, UploadedFile $gambar, string $alt): array
    {
        $sebelum = $this->baris($id)->gambar;

        $ekstensi = strtolower((string) $gambar->guessExtension());
        $nama = Str::random(40).($ekstensi === '' ? '' : '.'.$ekstensi);
        $path = $gambar->storeAs(self::DIR, $nama, self::DISK);

        if ($path === false) {
            throw ValidationException::withMessages([
                'gambar' => 'Gambar gagal disimpan. Coba lagi dengan berkas yang sama.',
            ]);
        }

        DB::table('hero_slides')->where('id', $id)->update([
            'gambar' => $path,
            'gambar_alt' => $alt,
        ]);

        // `$sebelum` is the raw column read through `baris()`, not the URL the
        // response shape answers with: unlinking needs `hero/xyz.png`, and the
        // absolute URL the client sees is not a path this disk recognises.
        if ($sebelum !== null && $sebelum !== $path) {
            Storage::disk(self::DISK)->delete($sebelum);
        }

        return $this->cari($id);
    }

    /**
     * Drop the image and its alt text, leaving the slide's copy untouched.
     *
     * Distinct from deleting the slide: a promo ends and its photograph goes while
     * the headline stays for the next campaign.
     *
     * @throws NotFoundHttpException when the id names no row
     */
    public function lepasGambar(int $id): array
    {
        $path = $this->baris($id)->gambar;

        DB::table('hero_slides')->where('id', $id)->update([
            'gambar' => null,
            'gambar_alt' => null,
        ]);

        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $this->cari($id);
    }

    /**
     * One row in the response shape, or the 404 the rest of the API answers.
     *
     * @return array<string, mixed>
     *
     * @throws NotFoundHttpException when the id names no row
     */
    public function cari(int $id): array
    {
        return $this->susun($this->baris($id), Carbon::now()->format('Y-m-d H:i:s'));
    }

    /**
     * The raw database row, for the operations that must read `gambar` itself.
     *
     * Deleting a slide or its image has to know the stored path, and the response
     * shape deliberately does not carry it, so the two concerns are two methods
     * rather than a path that leaks through every payload.
     *
     * @throws NotFoundHttpException when the id names no row
     */
    private function baris(int $id): object
    {
        $row = DB::table('hero_slides')->where('id', $id)->first();

        if ($row === null) {
            throw new NotFoundHttpException;
        }

        return $row;
    }

    /**
     * A database row plus the two values computed at read time.
     *
     * The raw `gambar` path is deliberately NOT one of them: {@see url()} publishes
     * the absolute form and the column itself never leaves the API, so a visitor (or
     * a client) learns nothing about where the file lives. `hapus()` and
     * `lepasGambar()` read the column through {@see baris()} for exactly that reason.
     *
     * @return array<string, mixed>
     */
    private function susun(object $row, string $kini): array
    {
        return [
            'id' => (int) $row->id,
            'urutan' => (int) $row->urutan,
            'eyebrow' => $row->eyebrow,
            'judul' => $row->judul,
            'deskripsi' => $row->deskripsi,
            'cta_label' => $row->cta_label,
            'cta_target' => $row->cta_target,
            'gambar' => $this->url($row->gambar),
            'gambar_alt' => $row->gambar_alt,
            'status' => $row->status,
            'mulai_tayang' => $row->mulai_tayang,
            'selesai_tayang' => $row->selesai_tayang,
            'tayang_aktif' => $row->status === self::STATUS_TAYANG
                && ($row->mulai_tayang === null || $row->mulai_tayang <= $kini)
                && ($row->selesai_tayang === null || $row->selesai_tayang > $kini),
            'dibuat_at' => $row->dibuat_at,
            'diubah_at' => $row->diubah_at,
        ];
    }

    /**
     * Absolute URL for a stored path, `null` when the slide has no image.
     *
     * The disk's `url` is built from `APP_URL`, which is the same origin the SPA is
     * served from — so a relative path would work too, and an absolute one also works
     * for a client that is not (the mobile app).
     */
    private function url(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(self::DISK)->url($path);
    }

    /**
     * Refuse a publish when {@see MAKS_TAYANG} slides are already on air.
     *
     * @param  int|null  $kecuali  the row being edited, so its own publish does not
     *                             count against itself
     *
     * @throws ValidationException
     */
    private function pastikanAdaSlotTayang(?int $kecuali = null): void
    {
        $query = DB::table('hero_slides')->where('status', self::STATUS_TAYANG);

        if ($kecuali !== null) {
            $query->where('id', '!=', $kecuali);
        }

        if ($query->count() >= self::MAKS_TAYANG) {
            throw ValidationException::withMessages([
                'status' => 'Maksimal '.self::MAKS_TAYANG.' slide boleh tayang bersamaan. Matikan satu slide '
                    .'yang sudah lewat masa tayangnya dulu.',
            ]);
        }
    }

    /**
     * The next free `urutan`, so an unspecified position appends to the strip.
     */
    private function urutanBerikutnya(): int
    {
        $tertinggi = DB::table('hero_slides')->max('urutan');

        return $tertinggi === null ? 0 : (int) $tertinggi + 1;
    }

    /**
     * Normalise an optional timestamp to what the column stores.
     *
     * An empty string from a form is `null`, not midnight: a date field left blank
     * means "no bound", and storing `0000-00-00` would make a live slide vanish.
     */
    private function waktu(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return Carbon::parse((string) $nilai)->format('Y-m-d H:i:s');
    }
}
