<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BranchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Deactivate or reactivate a branch (55). Validation only; SetBranchStatus checks the move. */
final class BranchStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([BranchStatus::Active->value, BranchStatus::Inactive->value])],
        ];
    }

    public function status(): BranchStatus
    {
        return BranchStatus::from($this->string('status')->toString());
    }
}
