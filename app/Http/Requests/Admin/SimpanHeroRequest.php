<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Landing\HeroService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /admin/hero` - create one landing-carousel slide.
 *
 * ## The target is a path, and the rule is what keeps it one
 *
 * `cta_target` is validated with `regex:/^\/(?!\/)/` rather than `url`, because the
 * destinations this app has are internal (`/dokter`, `/artikel`) and a slide is a
 * link every visitor clicks. Anchoring on a single leading `/` rejects an absolute
 * URL, a protocol-relative `//evil.example` (the classic bypass of a naive
 * "starts with /" check) and a `javascript:` target in one rule, so a content
 * editor cannot turn the front page into an open redirect. `url` would have accepted
 * exactly the cases that must be refused and refused the only case that is wanted.
 *
 * ## The image is NOT in this payload
 *
 * `gambar` and `gambar_alt` are `prohibited` here: an image arrives through
 * `POST /admin/hero/{id}/gambar` as multipart, one file at a time, after the row
 * exists. Two reasons to separate them: `create` would otherwise have to create a
 * slide before its image could be attached, and a multipart `POST` and a JSON `PUT`
 * have different failure shapes (422 on a bad file vs 422 on bad copy) that are
 * clearer when they never share a request.
 *
 * Format and vocabulary only. The one cross-row rule - at most
 * {@see HeroService::MAKS_TAYANG} slides on air - lives in the service, where the
 * merge of "what this request wants" and "what is already published" can be seen
 * together.
 */
class SimpanHeroRequest extends FormRequest
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
            'urutan' => ['sometimes', 'nullable', 'integer', 'between:0,255'],
            'eyebrow' => ['nullable', 'string', 'max:60'],
            'judul' => ['required', 'string', 'max:160'],
            'deskripsi' => ['required', 'string', 'max:400'],
            'cta_label' => ['required', 'string', 'max:60'],
            'cta_target' => ['required', 'string', 'max:120', 'regex:/^\/(?!\/)/'],
            'status' => ['sometimes', Rule::in([HeroService::STATUS_DRAF, HeroService::STATUS_TAYANG])],
            'mulai_tayang' => ['nullable', 'date'],
            'selesai_tayang' => ['nullable', 'date'],
            'gambar' => ['prohibited'],
            'gambar_alt' => ['prohibited'],
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
            'urutan' => 'urutan tampil',
            'eyebrow' => 'label kecil',
            'judul' => 'judul',
            'deskripsi' => 'deskripsi',
            'cta_label' => 'tombol',
            'cta_target' => 'tautan tombol',
            'status' => 'status',
            'mulai_tayang' => 'mulai tayang',
            'selesai_tayang' => 'selesai tayang',
            'gambar' => 'gambar',
            'gambar_alt' => 'teks alternatif gambar',
        ];
    }
}
