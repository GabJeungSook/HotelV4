<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel a reservation, or settle a no-show's money paid ahead (detail sheet on 57, then sheet
 * 14): a reason from the cancel_reservation list, an optional note, whether the money is handed
 * back or kept, and the approver's PIN.
 */
final class SettleReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
            'refund' => ['required', 'boolean'],
            'approver_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'digits:4'],
        ];
    }
}
