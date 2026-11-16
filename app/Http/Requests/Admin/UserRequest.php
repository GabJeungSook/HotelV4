<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Add or edit a user of this branch (47). Owner is not a role offered here. Validation only; the rules of the change are in the action. */
final class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'username' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'role' => ['required', 'string', 'in:admin,supervisor,frontdesk,backoffice,housekeeper,kiosk'],
            'floor_ids' => ['array'],
            'floor_ids.*' => ['integer'],
        ];
    }
}
