<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengingat;

/**
 * `POST /api/v1/pengingat` - create one reminder for the caller.
 *
 * `jenis`, `judul`, `tanggal_mulai` and a non-empty `waktu` are required;
 * `status` is `prohibited` because a new reminder is always `aktif` (see
 * {@see PengingatRequest::aturan()}).
 */
class StorePengingatRequest extends PengingatRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->aturan(membuat: true);
    }
}
