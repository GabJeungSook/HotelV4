<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** One cell of the rate table (45). A blank amount removes the rate; amounts are centavos. Validation only; the rules of the change are in the action. */
final class RateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Exactly one scope is required; which one is enforced in SaveRate.
            'room_type_id' => ['nullable', 'integer'],
            'floor_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'stay_kind' => ['required', 'string', 'in:hourly,overnight'],
            'hours' => ['required', 'integer', 'between:0,24'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
