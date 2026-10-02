<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Services\Pasien\PasienRecordAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the query string of `GET /api/v1/pasien/refund`.
 *
 * A list endpoint validates its page size and page like every other list in
 * the project (`IndexBookingRequest`, `RiwayatResepRequest`): an out-of-range
 * `per_page` is a 422 naming the field rather than a silently clamped page,
 * and the ceiling is the shared `PasienRecordAccess::PER_PAGE_MAX` so no list
 * can drift above the project cap.
 *
 * `authorize()` is `true`: the route carries `permission:pembayaran.bayar`
 * (the catalogue's only payment code - there is no `pembayaran.lihat`, and
 * `GET /api/v1/invoice/{id}` set the reuse precedent), and the tenant question
 * is answered by `PasienRecordAccess::ownPasien()` plus the scoped query in
 * `RefundService::daftarPasien()`.
 */
class IndexRefundRequest extends FormRequest
{
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.PasienRecordAccess::PER_PAGE_MAX],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * The page size, clamped to the project's ceiling. The default is the
     * project's default, not Laravel's.
     */
    public function perPage(): int
    {
        return max(1, min(
            (int) $this->input('per_page', PasienRecordAccess::PER_PAGE_DEFAULT),
            PasienRecordAccess::PER_PAGE_MAX,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'per_page' => 'jumlah per halaman',
            'page' => 'halaman',
        ];
    }
}
