<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /marketing/artikel/{id}` - perbarui artikel yang sudah ada.
 *
 * Semua field opsional (`sometimes`); hanya field yang dikirim yang diubah.
 * `slug` mengecualikan barisan itu sendiri dari pemeriksaan unik.
 */
class UpdateArtikelRequest extends FormRequest
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
        $id = $this->idYangDiubah();

        $slug = ['sometimes', 'string', 'max:255'];
        if ($id !== null) {
            $slug[] = 'unique:artikel,slug,'.$id;
        }

        return [
            'kategori_id' => ['sometimes', 'integer', 'exists:artikel_kategori,id'],
            'judul' => ['sometimes', 'string', 'max:255'],
            'slug' => $slug,
            'ringkasan' => ['nullable', 'string', 'max:500'],
            'konten' => ['sometimes', 'string'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'in:draft,review,terbit,arsip'],
        ];
    }

    /**
     * Route parameter id saat request nyata; null saat generator OpenAPI
     * membaca rules() di luar konteks route yang ter-bind (sehatly:openapi).
     */
    private function idYangDiubah(): ?int
    {
        try {
            $id = $this->route('id');
        } catch (\LogicException) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kategori_id' => 'kategori',
            'judul' => 'judul artikel',
            'slug' => 'slug',
            'ringkasan' => 'ringkasan',
            'konten' => 'konten',
            'cover_url' => 'sampul',
            'status' => 'status',
        ];
    }

    /**
     * @return array{kategori_id?: int, judul?: string, slug?: string, ringkasan?: ?string, konten?: string, cover_url?: ?string, status?: string}
     */
    public function isi(): array
    {
        return [
            'kategori_id' => $this->input('kategori_id'),
            'judul' => $this->input('judul'),
            'slug' => $this->input('slug'),
            'ringkasan' => $this->input('ringkasan'),
            'konten' => $this->input('konten'),
            'cover_url' => $this->input('cover_url'),
            'status' => $this->input('status'),
        ];
    }
}
