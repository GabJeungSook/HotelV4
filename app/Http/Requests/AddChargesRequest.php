<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Add charge (10): product id → quantity; with pay_now it continues into the payment. Validation only; the action enforces the business rules. */
final class AddChargesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer', 'min:1', 'max:999'],
            'pay_now' => ['boolean'],
            'method' => ['nullable', 'required_if_accepted:pay_now', 'string', 'in:cash,gcash,card,deposit'],
            'amount' => ['nullable', 'required_if_accepted:pay_now', 'integer', 'min:1', 'max:100000000'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
            'keep_change' => ['boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
