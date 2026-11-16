<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Add or edit a floor (44, floors tab). Validation only; the rules of the change are in the action. */
final class FloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'number' => ['required', 'integer', 'between:-5,200'],
            'label' => ['required', 'string', 'max:20'],
        ];
    }
}
