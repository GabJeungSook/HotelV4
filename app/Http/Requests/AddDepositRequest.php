<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Add to the guest deposit (9). The amount is integer centavos; a deposit is never funded from a deposit. Validation only; the action enforces the business rules. */
final class AddDepositRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
