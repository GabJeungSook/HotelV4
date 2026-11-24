<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** A function hall (DATABASE_SCHEMA §4.14). Validation only; rules live in SaveHall. */
final class HallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'capacity' => ['required', 'integer', 'between:0,100000'],
            'base_rate_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
