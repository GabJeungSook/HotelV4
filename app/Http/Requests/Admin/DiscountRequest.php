<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Add or edit a discount (45): value in centavos when fixed, whole percent when percent. Validation only; the rules of the change are in the action. */
final class DiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'kind' => ['required', 'string', 'in:fixed,percent'],
            'value' => ['required', 'integer', 'min:1', 'max:100000000'],
            'all_rates' => ['required', 'boolean'],
            'kiosk_enabled' => ['required', 'boolean'],
            'is_vat_exempt' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'rate_ids' => ['array'],
            'rate_ids.*' => ['integer'],
        ];
    }
}
