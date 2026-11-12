<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Take payment (11). The amount given is integer centavos. Validation only; the action enforces the business rules. */
final class TakePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', 'in:cash,gcash,card,deposit'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
            'keep_change' => ['boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
