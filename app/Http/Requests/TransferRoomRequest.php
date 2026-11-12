<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Transfer (13) with the approval of sheet 14. Validation only; the action enforces the business rules. */
final class TransferRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer'],
            'reason_id' => ['required', 'integer'],
            'old_room' => ['required', 'string', 'in:uncleaned,maintenance'],
            'note' => ['nullable', 'string', 'max:200'],
            'approver_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'digits:4'],
            'method' => ['nullable', 'string', 'in:cash,gcash,card'],
            'amount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
        ];
    }
}
