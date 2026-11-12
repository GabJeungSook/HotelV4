<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Void a POS sale (19 → 14): a reason from the void list, an optional note, the approver and PIN. */
final class VoidSaleRequest extends FormRequest
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
