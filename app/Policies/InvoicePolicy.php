<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Pasien\PasienRecordAccess;

/**
 * The FIRST Policy in this application, and the rule it states is one sentence:
 * an invoice is visible to the account that owns the `pasien` row it bills.
 *
 * ## Why a Policy exists when the scoped query already answers the question
 *
 * No read of a patient's child row on this API is unscoped, and
 * `PembayaranController::show()` is no exception: it fetches the invoice as
 * `Invoice::whereBelongsTo($pasien)->whereKey($id)->first()` and turns a miss
 * into a 404, so this policy never changes the answer on today's code path.
 * That is precisely why it is written out rather than inferred. The tenant-scoped
 * query is the PRIMARY rule - it is what makes another patient's row a 404 rather
 * than a 403 - and this class is the same rule stated in one place where a test
 * can assert it directly, so a future edit that drops the scope still fails
 * closed (the authorize refuses) instead of publishing the row.
 *
 * ## 403 is about the caller, 404 is about the row
 *
 * {@see PasienRecordAccess} states the project-wide rule:
 * 403 means "this account owns no `pasien` row", and 404 means "this row is not
 * yours, or does not exist". Answering 403 for another patient's invoice would
 * confirm that the invoice exists - a cross-tenant existence oracle - so the
 * controller FETCHES FIRST (which is where the 404 is produced) and authorizes
 * after it. `view()` returning false is therefore a second line of defence that
 * is unreachable through today's controller, and that ordering is deliberate
 * rather than accidental.
 *
 * ## Registered explicitly, because this project has no `AuthServiceProvider`
 *
 * There is no `app/Policies/` convention in use and no provider listing policies.
 * `AppServiceProvider::boot()` therefore calls
 * `Gate::policy(\App\Models\Invoice::class, \App\Policies\InvoicePolicy::class)`
 * so discovery is not relied upon: the binding is one line a reader finds, and a
 * second policy added later has an obvious place to be registered.
 *
 * ## It reads exactly one relation and nothing else
 *
 * `$invoice->pasien` is the model's own belongs-to, so the check is one row of
 * the table the rule is about. No other attribute of the invoice, the patient or
 * any payment is loaded, and no NIK, no money and no payload is touched.
 */
final class InvoicePolicy
{
    /**
     * May `$user` read `$invoice`?
     *
     * True iff the `pasien` row this invoice bills is owned by the caller. A
     * missing patient row, or one naming no account, answers false - the invoice
     * is then reachable by nobody rather than by everybody.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        $pasien = $invoice->pasien;

        if ($pasien === null || $pasien->user_id === null) {
            return false;
        }

        return (int) $pasien->user_id === (int) $user->getKey();
    }
}
