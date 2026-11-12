<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Check out (16–18): the step 1 answers, an optional payment and the deposit answer. Validation only; the action enforces the business rules. */
final class CheckOutGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key_returned' => ['required', 'boolean'],
            'remote_returned' => ['required', 'boolean'],
            'damages' => ['array'],
            'damages.*' => ['integer', 'min:0', 'max:99'],
            'method' => ['nullable', 'string', 'in:cash,gcash,card'],
            'amount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
            'deposit_claimed' => ['required', 'boolean'],
        ];
    }
}
