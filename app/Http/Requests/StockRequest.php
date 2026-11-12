<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The two forms of the stock screen (20): "Receive stock" (kind receive) and "Record wastage"
 * (kind wastage, with a reason from the wastage list and a note, and the approver's PIN when the
 * reason needs a manager). Validation only.
 */
final class StockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:receive,wastage'],
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'note' => ['nullable', 'required_if:kind,wastage', 'string', 'max:200'],
            'reason_id' => ['nullable', 'required_if:kind,wastage', 'integer'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'required_with:approver_id', 'string', 'digits:4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required_if' => 'Wastage needs a note.',
            'reason_id.required_if' => 'Pick a reason.',
            'quantity.required' => 'Type how many pieces.',
            'quantity.min' => 'Type how many pieces.',
        ];
    }
}
