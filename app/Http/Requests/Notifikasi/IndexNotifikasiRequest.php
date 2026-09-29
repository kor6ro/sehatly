<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifikasi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/notifikasi` - the caller's own notifications, newest first.
 *
 * ## `unread` is a BOOLEAN FILTER, and a third state would be a fourth answer
 *
 * `?unread=true` and `?unread=false` are the whole contract. A client that wants
 * "everything" omits the parameter, and a client that sends `unread=1` or
 * `unread=maybe` gets a 422 naming the field rather than a silent
 * interpretation: the three plausible readings of a truthy `unread` (`1` is
 * true, `0` is false, `""` is absent) would make the badge count and the list
 * disagree, and this API would have no way to tell a client which one it got.
 *
 * ## `meta.unread` is the ACCOUNT's badge, not the PAGE's
 *
 * The plan asks for "an unread count in the response meta", and the honest reading
 * of a badge is a property of the account rather than of the current query. So the
 * count is `whereNull('dibaca_at')` over ALL of the caller's rows, computed
 * independently of `?unread` and of the page, and a client rendering
 * `?unread=true&per_page=15&page=3` still gets the number its bell icon needs. A
 * per-page count would be a smaller number that changed as the user scrolled, and
 * the test asserts the value is the same on all three of those requests.
 *
 * ## The `per_page` cap is the project's, not this request's
 *
 * 100, the same cap `ApiResponse::pageMeta()` documents for every list endpoint
 * in this application. Stating it here rather than in the controller keeps the
 * validation and the pagination in one file.
 */
class IndexNotifikasiRequest extends FormRequest
{
    /**
     * The cap every list endpoint in this application applies.
     */
    public const PER_PAGE_MAKS = 100;

    /**
     * The four spellings of a boolean a query string may carry.
     *
     * ## Why not the `boolean` RULE
     *
     * Laravel's `boolean` rule accepts `true`, `false`, `1`, `0`, `"1"` and
     * `"0"` - and NOT the strings `"true"` and `"false"`. A query parameter is
     * ALWAYS a string, so `?unread=true` would be refused by `boolean` while
     * `?unread=1` passed. That is the exact opposite of what a client expects
     * from a flag named `unread`, and it is why this field is an explicit
     * `Rule::in()` over a declared list rather than a shorthand: the accepted
     * spellings are part of the contract and belong in a place a reader can
     * look at them.
     *
     * A caller sending `?unread=2` or `?unread=yes` gets a 422 naming the field
     * rather than a coerced boolean - a client that meant "unread" and sent
     * something else should be told, not guessed at.
     *
     * @var list<string>
     */
    public const UNREAD_BENAR = ['true', '1'];

    /**
     * @var list<string>
     */
    public const UNREAD_SALAH = ['false', '0'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unread' => ['nullable', 'string', Rule::in(array_merge(self::UNREAD_BENAR, self::UNREAD_SALAH))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::PER_PAGE_MAKS],
        ];
    }

    /**
     * The requested unread filter as a BOOLEAN, or null when absent.
     *
     * `null` is kept distinct from `false` because the two mean different
     * things: "no filter" returns every row, and `false` returns the read ones.
     */
    public function unread(): ?bool
    {
        if (! array_key_exists('unread', $this->validated())) {
            return null;
        }

        return in_array((string) $this->validated()['unread'], self::UNREAD_BENAR, true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unread.in' => 'Nilai unread harus true atau false.',
            'unread.string' => 'Nilai unread harus true atau false.',
            'page.integer' => 'Nilai page tidak valid.',
            'page.min' => 'Nilai page tidak valid.',
            'per_page.integer' => 'Nilai per_page tidak valid.',
            'per_page.min' => 'Nilai per_page tidak valid.',
            'per_page.max' => 'Nilai per_page maksimal '.self::PER_PAGE_MAKS.'.',
        ];
    }
}
