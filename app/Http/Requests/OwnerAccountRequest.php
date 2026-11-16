<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Owner accounts form (APP_FLOW §8.2): full name and a unique username; on edit also active and
 * "reset password". Validation only; CreateOwnerAccount and UpdateOwnerAccount write.
 */
final class OwnerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::squish((string) $this->input('name')),
            'username' => Str::lower(trim((string) $this->input('username'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');
        $ignore = is_object($user) && isset($user->id) ? $user->id : (is_numeric($user) ? (int) $user : null);

        return [
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')->ignore($ignore)],
            'is_active' => ['sometimes', 'boolean'],
            'reset_password' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.unique' => 'This username is taken.',
            'username.regex' => 'Use lower-case letters, digits, dots, dashes or underscores.',
        ];
    }
}
