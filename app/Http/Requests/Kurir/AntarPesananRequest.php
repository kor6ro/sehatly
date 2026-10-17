<?php

declare(strict_types=1);

namespace App\Http\Requests\Kurir;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /kurir/pesanan/{id}/antar` - confirm the parcel reached its destination.
 *
 * Same optional free-text pair as {@see AmbilPesananRequest} (`keterangan`,
 * `lokasi`, against the same two `VARCHAR(255)` columns), and the same empty
 * body being a real request - the confirmation is the transition.
 *
 * `no_resi` is `prohibited` here rather than merely absent. A parcel that has
 * already shipped cannot be re-numbered, and `PesananObatService::resiUntuk()`
 * would refuse the field with a workflow 422 ("nomor resi hanya dapat diisi
 * saat status pesanan menjadi sedang_dikirim"). `prohibited` answers sooner and
 * with the right KIND of message - this request does not accept that field at
 * all - so a client that sends it is told about the contract rather than about
 * the state of a row it cannot see.
 */
class AntarPesananRequest extends FormRequest
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
            'no_resi' => ['prohibited'],
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
            'keterangan' => 'keterangan pengantaran',
            'lokasi' => 'lokasi',
            'no_resi' => 'nomor resi',
        ];
    }

    /**
     * The service's view of the body, with the keys ALWAYS present.
     *
     * Named `isi()` rather than `data()` - see {@see AmbilPesananRequest::isi()}
     * for the signature collision that name caused.
     *
     * @return array{keterangan: ?string, lokasi: ?string}
     */
    public function isi(): array
    {
        return [
            'keterangan' => $this->input('keterangan'),
            'lokasi' => $this->input('lokasi'),
        ];
    }
}
