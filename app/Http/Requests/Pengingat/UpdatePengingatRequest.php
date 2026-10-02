<?php

declare(strict_types=1);

namespace App\Http\Requests\Pengingat;

/**
 * `PUT /api/v1/pengingat/{id}` - update one of the caller's reminders.
 *
 * Every field is `sometimes`: the endpoint is a form PUT, so a client pausing a
 * reminder sends `{status: "nonaktif"}` alone and does not have to echo the
 * fields it is not touching. `status` accepts the three DDL values here - this
 * is the route that pauses (`nonaktif`) and closes (`selesai`) a reminder.
 */
class UpdatePengingatRequest extends PengingatRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->aturan(membuat: false);
    }
}
