<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Add or edit a product (48); the price is centavos. Validation only; the rules of the change are in the action. */
final class ProductRequest extends FormRequest
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
            'kind' => ['required', 'string', 'in:food_drink,amenity,damage'],
            'category' => ['required', 'string', 'max:30'],
            'report_group' => ['required', 'string', 'in:foods,drinks,misc'],
            'outlet' => ['nullable', 'string', 'in:frontdesk,kitchen,pub'],
            'price_amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'tracks_stock' => ['required', 'boolean'],
            'low_stock_at' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
