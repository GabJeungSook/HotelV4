<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Void a cash entry (21) with the approval of sheet 14. Validation only. */
final class VoidCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
            'approver_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'digits:4'],
        ];
    }
}
