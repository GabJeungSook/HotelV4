<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A POS sale (19): product id → quantity, the mode, the cash received (walk-in) or the stay
 * charged (room charge), and the form's idempotency key. No price or total is trusted; the
 * action prices every item from the product rows.
 */
final class PosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:walk_in,room_charge'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer', 'min:1', 'max:999'],
            'tendered' => ['nullable', 'required_if:mode,walk_in', 'integer', 'min:1', 'max:100000000'],
            'stay_id' => ['nullable', 'required_if:mode,room_charge', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tendered.required_if' => 'Type the cash received.',
            'stay_id.required_if' => 'Pick the occupied room to charge.',
            'items.required' => 'Pick at least one item.',
        ];
    }
}
