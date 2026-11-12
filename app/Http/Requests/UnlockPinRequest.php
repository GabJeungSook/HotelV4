<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Unlock PIN entry on this computer (TRD §4.12). Validation only; the action enforces the business rules. */
final class UnlockPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }
}
