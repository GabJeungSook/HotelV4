<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Branch;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Add branch (56, APP_FLOW §4.20 step 3): name unique, a three-character stay-code prefix that
 * no branch uses ("DGS is already used by Dalisay GenSan."), the first admin's full name and a
 * unique username, and an optional branch to copy from. Validation only; CreateBranch writes.
 */
final class CreateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::squish((string) $this->input('name')),
            'address' => Str::squish((string) $this->input('address')),
            'code_prefix' => Str::upper(trim((string) $this->input('code_prefix'))),
            'admin_name' => Str::squish((string) $this->input('admin_name')),
            'admin_username' => Str::lower(trim((string) $this->input('admin_username'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('branches', 'name')],
            'address' => ['required', 'string', 'max:160'],
            'code_prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{3}$/', function (string $attribute, mixed $value, Closure $fail): void {
                $owner = Branch::query()->withoutGlobalScopes()->where('code_prefix', $value)->value('name');
                if ($owner !== null) {
                    $fail("{$value} is already used by {$owner}.");
                }
            }],
            'admin_name' => ['required', 'string', 'min:3', 'max:80'],
            'admin_username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')],
            'copy_from_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a branch with this name.',
            'code_prefix.regex' => 'The prefix is three letters or digits, like DGS.',
            'admin_username.unique' => 'This username is taken.',
            'admin_username.regex' => 'Use lower-case letters, digits, dots, dashes or underscores.',
        ];
    }

    /** @return array{name: string, address: string, code_prefix: string, admin_name: string, admin_username: string, copy_from_id: int|null} */
    public function branchData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'address' => $this->string('address')->toString(),
            'code_prefix' => $this->string('code_prefix')->toString(),
            'admin_name' => $this->string('admin_name')->toString(),
            'admin_username' => $this->string('admin_username')->toString(),
            'copy_from_id' => $this->filled('copy_from_id') ? $this->integer('copy_from_id') : null,
        ];
    }
}
