<?php

declare(strict_types=1);

namespace App\Http\Requests\Referensi;

use App\Services\Pasien\PasienRecordAccess;
use App\Support\Reference\ReferensiEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The query-string contract for every `/api/v1/referencia/*` list endpoint.
 *
 * ## The rules are DERIVED from the endpoint, not restated
 *
 * {@see ReferensiEndpoint::queryParameters()} is the single list of what an endpoint
 * accepts, and both the validation rules and the controller's filters are built from
 * it. That is the whole reason the definition object exists: a hand-written rule table
 * beside a hand-written filter list is two lists to keep in step, and the day they
 * disagree the symptom is a parameter that validates and then does nothing - a client
 * that filtered on `?q=` and got the unfiltered list, with a 200 and no warning.
 *
 * So an unknown or misplaced parameter is a 422 naming the field, and that is enforced
 * from below rather than trusted from above.
 *
 * ## Why the endpoint is read from the ROUTE NAME
 *
 * The 14 routes are registered one per endpoint, all pointing at the same controller
 * method, so something has to tell the method which endpoint it is serving. The obvious
 * choice is `Route::defaults()`, and it is the wrong one here:
 * `Illuminate\Routing\Route::defaults()` writes into `$this->defaults` and Laravel 13
 * has no `replaceDefaults()` to fold that bag into the bound parameters - so a default
 * is not reliably readable back off the request. A route NAME, by contrast, is set by
 * the registration itself and is always present, and every reference route is named
 * `referensi.<slug>` with `<slug>` exactly the definition's slug. Reading the name is
 * therefore reading the registration, not inferring the endpoint from the URL.
 *
 * ## The 100 cap is applied twice, for the reason `IndexPasienRequest` states
 *
 * `max:100` here means `?per_page=5000` is a 422 the client can act on, and
 * {@see perPage()} clamps again before the paginator sees the number, so a path that
 * skipped this rule still cannot ask for an unbounded page. A rule alone is a
 * convention; a rule plus a clamp is a property.
 */
class IndexReferensiRequest extends FormRequest
{
    /**
     * Reference data is public, so there is nothing to authorise here.
     *
     * Stated explicitly because every other FormRequest in this project either
     * extends an authenticated base or checks an ability. Returning `true` is the
     * decision, and a reader should see it rather than infer it from its absence.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The definition of the endpoint this request is for.
     *
     * Throws rather than returning null: {@see rules()} is called by the framework
     * during validation, and a null here would be a fatal in the middle of building
     * the rules instead of a 404. A route that reaches this class without a matching
     * definition is a programming error in `routes/api.php`, not a client error.
     */
    public function endpoint(): ReferensiEndpoint
    {
        $name = $this->route()?->getName();
        $slug = is_string($name) ? substr($name, strlen('referensi.')) : '';
        $endpoint = $slug === '' ? null : ReferensiEndpoint::find($slug);

        if ($endpoint === null) {
            throw new \RuntimeException('No reference endpoint is defined for route ['.$name.'].');
        }

        return $endpoint;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $endpoint = $this->endpoint();
        $rules = [];

        if ($endpoint->parent !== null) {
            // The parent id is a `BIGINT UNSIGNED` primary key, so `integer` plus a
            // floor of 1 is the whole domain. `exists:` is deliberately NOT added: a
            // filter naming a region that has no children should answer an empty
            // list, not a 422, and a client walking the hierarchy one level at a time
            // would otherwise have to prove the parent exists before it can ask for
            // its children.
            $rules[$endpoint->parent] = ['sometimes', 'integer', 'min:1'];
        }

        if ($endpoint->searchable !== []) {
            $rules['q'] = ['sometimes', 'string', 'max:100'];
        }

        if ($endpoint->filterStatusAktif) {
            $rules['status_aktif'] = ['sometimes', 'boolean'];
        }

        if ($endpoint->paginates) {
            $rules['page'] = ['sometimes', 'integer', 'min:1'];
            $rules['per_page'] = ['sometimes', 'integer', 'min:1', 'max:'.PasienRecordAccess::PER_PAGE_MAX];
        }

        return $rules;
    }

    /**
     * Reject any parameter the endpoint does not accept.
     *
     * Without this a typo is silent: `GET /referensi/provinsi?kode=31` would return all
     * 38 provinces and the client would render a dropdown it believes is filtered to
     * one. The alternative - ignoring unknown keys - is the one Laravel does by
     * default, and it is the wrong default for an API whose whole job is to be
     * predictable.
     *
     * `page` and `per_page` are exempt on a single-page endpoint for a specific
     * reason: a client with a generic list component will send them to every list it
     * renders, and refusing them on the 8 unpaginated endpoints would break that
     * component for no benefit. They are ignored there, and the single-page `meta`
     * block makes the ignoring visible. Every OTHER unknown key is a 422.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $accepted = $this->endpoint()->queryParameters();

            foreach (array_keys($this->query->all()) as $key) {
                if (in_array($key, $accepted, true) || ($key === 'page' || $key === 'per_page')) {
                    continue;
                }

                $validator->errors()->add(
                    (string) $key,
                    'Parameter "'.$key.'" is not accepted by this endpoint. Accepted: '
                        .($accepted === [] ? '(none)' : implode(', ', $accepted)).'.',
                );
            }
        });
    }

    /**
     * The page size to paginate with, already clamped to the plan's cap.
     *
     * Reads the *validated* value, so the clamp cannot be skipped by a caller that
     * bypassed the rule. A single-page endpoint never asks for this.
     */
    public function perPage(): int
    {
        return app(PasienRecordAccess::class)->perPage(
            (int) $this->validated('per_page', PasienRecordAccess::PER_PAGE_DEFAULT),
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'page' => 'halaman',
            'per_page' => 'jumlah per halaman',
            'q' => 'pencarian',
            'status_aktif' => 'status aktif',
        ];

        $parent = $this->endpoint()->parent;

        if ($parent !== null) {
            $attributes[$parent] = str_replace('_', ' ', $parent);
        }

        return $attributes;
    }
}
