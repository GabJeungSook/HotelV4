<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Open shift (2). The drawer count is integer centavos; the period and date are checked by OpenShift. Validation only; the action enforces the business rules. */
final class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'integer', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'definition_id' => ['nullable', 'integer'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'digits:4'],
        ];
    }
}
