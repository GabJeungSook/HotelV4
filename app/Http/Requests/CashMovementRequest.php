<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CashMovementKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An expense, remittance or shortage payment (21). Validation only; RecordCashMovement enforces the rules. */
final class CashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(CashMovementKind::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'note' => ['required', 'string', 'max:200'],
            'idempotency_key' => ['required', 'uuid'],
            'expense_category_id' => ['nullable', 'integer', 'required_if:kind,expense'],
            'for_shift_id' => ['nullable', 'integer', 'required_if:kind,shortage_payment'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'digits:4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.min' => 'Type an amount above ₱0.00.',
            'note.required' => 'Write what this is for.',
            'expense_category_id.required_if' => 'Pick a category.',
            'for_shift_id.required_if' => 'Pick the shift that was short.',
        ];
    }
}
