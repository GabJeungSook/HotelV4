<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirm a kiosk hold (7). Room, hours and discount come from the hold; only the money is
 * typed. Validation only; ConfirmKioskHold enforces the rules.
 */
final class ConfirmHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', 'in:cash,gcash,card'],
            'tendered' => ['nullable', 'required_if:method,cash', 'integer', 'min:0', 'max:100000000'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
            'keep_change' => ['boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
