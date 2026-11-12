<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** "Room is ready" (result ready) or "Report a problem in this room" (result problem with a note), screen 33. */
final class FinishCleaningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result' => ['required', 'string', 'in:ready,problem'],
            'problem_note' => ['nullable', 'required_if:result,problem', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['problem_note.required_if' => 'Say what the problem is.'];
    }
}
