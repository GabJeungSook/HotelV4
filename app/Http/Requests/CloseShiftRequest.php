<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One step of the close guide (22–25), named by `step`. Validation only; CloseShift and
 * HandOverShift enforce the rules (a total sent with the count is not even read).
 */
final class CloseShiftRequest extends FormRequest
{
    public const STEPS = ['begin', 'not_yet', 'count', 'reopen_count', 'result', 'handover', 'without_handover'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->input('step')) {
            'count' => [
                'step' => ['required'],
                // Denomination mode sends pieces; total mode sends one counted total. CloseShift
                // picks the one the branch uses and ignores the other.
                'pieces' => ['sometimes', 'array'],
                'counted' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
                'witness_id' => ['nullable', 'integer'],
            ],
            'reopen_count' => [
                'step' => ['required'],
                'approver_id' => ['required', 'integer'],
                'pin' => ['required', 'string', 'digits:4'],
            ],
            'result' => [
                'step' => ['required'],
                'reason_id' => ['nullable', 'integer'],
                'note' => ['nullable', 'string', 'max:200'],
            ],
            'handover' => [
                'step' => ['required'],
                'receiver_id' => ['required', 'integer'],
                'received_cash' => ['required', 'integer', 'min:0', 'max:100000000'],
                'pin' => ['required', 'string', 'digits:4'],
                'handover_note' => ['nullable', 'string', 'max:500'],
                'difference_note' => ['nullable', 'string', 'max:200'],
            ],
            'without_handover' => [
                'step' => ['required'],
                'approver_id' => ['required', 'integer'],
                'pin' => ['required', 'string', 'digits:4'],
                'handover_note' => ['nullable', 'string', 'max:500'],
            ],
            default => ['step' => ['required', 'in:'.implode(',', self::STEPS)]],
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'receiver_id.required' => 'Pick the incoming front desk.',
            'pin.digits' => 'Type the four-digit PIN.',
            'pin.required' => 'Type the four-digit PIN.',
        ];
    }
}
