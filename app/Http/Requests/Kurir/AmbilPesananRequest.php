<?php

declare(strict_types=1);

namespace App\Http\Requests\Kurir;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /kurir/pesanan/{id}/ambil` - claim a packed order for delivery.
 *
 * ## Everything is OPTIONAL, and the empty body is a real request
 *
 * The claim itself is the route parameter and the current status; a courier who
 * just picked up a parcel needs no prose to do it. So the body may be `{}` and
 * the service fills the tracking words with its own default. Each field that IS
 * sent is free text a human typed about THIS handover, and it lands in
 * `pesanan_obat_tracking` (`keterangan VARCHAR(255) NULL`, `lokasi VARCHAR(255)
 * NULL`, `:85`-`:86`) - the widths here are the column widths, not a guess.
 *
 * ## `no_resi` is honoured on THIS edge only, and that is the schema's rule
 *
 * `pesanan_obat.no_resi` is free text, nullable and unindexed (`:806`), and the
 * number a parcel travels under is minted when the parcel SHIPS - which is
 * exactly this transition (`siap -> sedang_dikirim`).
 * `PesananObatService::resiUntuk()` already refuses the number on any other
 * edge with a 422 of its own; this class validates the SHAPE and leaves the
 * timing to the service, so there is one answer to "may this edge carry a
 * number" and not two.
 */
class AmbilPesananRequest extends FormRequest
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
            'keterangan' => ['nullable', 'string', 'max:255'],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'no_resi' => ['nullable', 'string', 'max:50'],
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
            'keterangan' => 'keterangan pengambilan',
            'lokasi' => 'lokasi',
            'no_resi' => 'nomor resi',
        ];
    }

    /**
     * The service's view of the body, with the keys ALWAYS present.
     *
     * An absent field and a sent `null` are the same request here, so the array
     * is normalised once rather than sprinkling `?? null` through the service.
     * Named `isi()` rather than `data()`: `Illuminate\Http\Request` already
     * declares `data($key, $default)` and a subclass signature that does not
     * match is a fatal error, not an override.
     *
     * @return array{keterangan: ?string, lokasi: ?string, no_resi: ?string}
     */
    public function isi(): array
    {
        return [
            'keterangan' => $this->input('keterangan'),
            'lokasi' => $this->input('lokasi'),
            'no_resi' => $this->input('no_resi'),
        ];
    }
}
