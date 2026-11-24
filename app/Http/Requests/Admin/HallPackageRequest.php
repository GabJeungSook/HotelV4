<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** A hall package (DATABASE_SCHEMA §4.14). Validation only; rules live in SaveHallPackage. */
final class HallPackageRequest extends FormRequest
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
            'kind' => ['required', 'string', 'in:venue,food,host,other'],
            'pricing' => ['required', 'string', 'in:per_pax,flat'],
            'unit_amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
