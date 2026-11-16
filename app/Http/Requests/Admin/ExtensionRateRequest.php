<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** One extension block (45). A blank amount removes it; amounts are centavos. Validation only; the rules of the change are in the action. */
final class ExtensionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'integer', 'between:1,24'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
