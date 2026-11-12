<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Extend (12). Validation only; the action enforces the business rules. */
final class ExtendStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'integer', 'min:1', 'max:24'],
            'pay_now' => ['boolean'],
            'method' => ['nullable', 'required_if_accepted:pay_now', 'string', 'in:cash,gcash,card'],
            'amount' => ['nullable', 'required_if_accepted:pay_now', 'integer', 'min:1', 'max:100000000'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
            'keep_change' => ['boolean'],
        ];
    }
}
