<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /marketing/artikel` - buat artikel baru di portal marketing.
 *
 * `penulis_user_id` sengaja TIDAK divalidasi di sini: barisan ditulis dengan
 * id akun yang sedang terautentikasi oleh service, sehingga klien tidak
 * menuliskan siapa penulis. Semua lebar kolom mengikuti DDL
 * (`telemedicine_test.sql:1074`-:1091).
 */
class StoreArtikelRequest extends FormRequest
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
            'kategori_id' => ['required', 'integer', 'exists:artikel_kategori,id'],
            'judul' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:artikel,slug'],
            'ringkasan' => ['nullable', 'string', 'max:500'],
            'konten' => ['required', 'string'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:draft,review,terbit,arsip'],
        ];
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
     * @return array{kategori_id: int, judul: string, slug: string, ringkasan: ?string, konten: string, cover_url: ?string, status: string}
     */
    public function isi(): array
    {
        return [
            'kategori_id' => (int) $this->input('kategori_id'),
            'judul' => (string) $this->input('judul'),
            'slug' => (string) $this->input('slug'),
            'ringkasan' => $this->input('ringkasan'),
            'konten' => (string) $this->input('konten'),
            'cover_url' => $this->input('cover_url'),
            'status' => (string) $this->input('status', 'draft'),
        ];
    }
}
