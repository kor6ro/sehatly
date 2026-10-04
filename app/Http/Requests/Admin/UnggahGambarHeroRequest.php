<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Landing\HeroService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /admin/hero/{id}/gambar` - attach (or replace) a slide's photograph.
 *
 * ## Why the alternative text is required WITH the file
 *
 * `gambar_alt` is `required` next to `gambar`, and the pairing is the whole rule.
 * The migration cannot express it (there is no `CHECK` tying two columns together
 * and `telemedicine_test.sql` is read-only), so it is enforced at the only moment
 * where both values are present: the upload. A hero is the first thing a screen
 * reader announces on the landing page, and "upload now, caption later" never
 * happens - the caption would be missing for as long as the campaign ran. Upload
 * the file and its words together, or do not upload.
 *
 * A slide with no image needs no alt text and is uploaded without this endpoint:
 * `DELETE /admin/hero/{id}/gambar` clears both columns together.
 *
 * ## The size ceiling and the format list
 *
 * `max:` is in kilobytes and mirrors
 * {@see HeroService::GAMBAR_MAKS_KB}, so the message the client reads comes from
 * this rule rather than from a write that failed halfway. `mimes` checks the type
 * fileinfo derives from the CONTENT, not the filename a browser reports, which is
 * why it is `mimes` and not `extensions` - and why a renamed `.exe` is refused
 * before anything is written to the public disk.
 */
class UnggahGambarHeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'gambar' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,avif',
                'max:'.HeroService::GAMBAR_MAKS_KB,
            ],
            'gambar_alt' => ['required', 'string', 'max:160'],
        ];
    }

    /**
     * Indonesian labels, per the project convention.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'gambar' => 'gambar',
            'gambar_alt' => 'teks alternatif gambar',
        ];
    }
}
