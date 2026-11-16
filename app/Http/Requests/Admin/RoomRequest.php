<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Add or edit a room (44). Validation only; the rules of the change are in the action. */
final class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:8', 'regex:/^[A-Za-z0-9-]+$/'],
            'floor_id' => ['required', 'integer'],
            'room_type_id' => ['required', 'integer'],
            'area' => ['nullable', 'string', 'max:20'],
            'is_kiosk_enabled' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'status' => ['nullable', 'string', 'in:available,occupied,uncleaned,cleaning,maintenance'],
        ];
    }
}
