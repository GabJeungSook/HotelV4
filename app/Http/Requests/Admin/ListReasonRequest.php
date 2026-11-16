<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Add or edit a row of a reason list (46). Validation only; the rules of the change are in the action. */
final class ListReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'needs_note' => ['required', 'boolean'],
            'needs_manager' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
