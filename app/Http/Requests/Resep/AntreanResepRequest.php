<?php

declare(strict_types=1);

namespace App\Http\Requests\Resep;

use App\Services\Resep\ResepStateMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/resep` - the pharmacist verification queue's filters.
 *
 * ## `status` is the TWO verifiable states, not the ENUM's eight
 *
 * `ResepStateMachine::BISA_DIVERIFIKASI` is `['aktif', 'diproses']`, and it is
 * read here rather than typed: a prescription arrives in `aktif` the moment it
 * is written and the pharmacy takes it into `diproses` before signing it, so
 * those two are the only states this queue can contain. The set comes from the
 * state machine - the one class allowed to know which `resep.status` values are
 * live - so a ninth ENUM member or a new legal verification edge moves the
 * queue without a second list here to drift.
 *
 * **A value outside that set is a 422 naming the field, not an empty page.** A
 * `?status=diverifikasi` answered with `[]` would read as "nothing to verify",
 * which is a different and wrong claim: `diverifikasi` is never in this queue
 * because a signed prescription is not waiting for anything. The same applies
 * to `dipenuhi`/`dikirim` and to all three terminal states - the queue is a
 * worklist, and a status the worklist can never hold is a client mistake.
 * (`RiwayatResepRequest` makes the opposite choice for the patient's history,
 * accepting all eight, because there the question IS "what happened to the ones
 * that did not work". Same table, different question, different closed set.)
 *
 * ## `page` and `per_page` are the project-wide pair
 *
 * `per_page` is capped at 100 - the same ceiling `RiwayatResepRequest` and
 * `PasienRecordAccess::PER_PAGE_MAX` apply - and the cap is applied again in
 * the service, so a value arriving from anywhere else cannot bypass it. The
 * `meta` block the controller returns is `ApiResponse::pageMeta()`, so the
 * queue answers the one list envelope every other list in this project uses.
 */
class AntreanResepRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(ResepStateMachine::BISA_DIVERIFIKASI)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
