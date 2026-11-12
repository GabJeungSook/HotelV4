<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Void a line (15) with the approval of sheet 14. Validation only; the action enforces the business rules. */
final class VoidLineRequest extends FormRequest
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
