<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Set your PIN (APP_FLOW §8.2). Validation only; the action enforces the business rules. */
final class SetPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'digits:4'],
            'pin_confirmation' => ['required', 'string', 'digits:4'],
        ];
    }
}
