<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Hold my room" (29, APP_FLOW §4.18 step 5): a room type, the hours, the guest's name and
 * mobile, and a key. There is no field for a room or a price; anything else sent is ignored, and
 * the controller reads only these validated fields (TRD §10 "kiosk cannot send a price").
 */
final class KioskHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'integer'],
            'kind' => ['nullable', 'string', 'in:hourly,long_stay'],
            'hours' => ['nullable', 'required_unless:kind,long_stay', 'integer', 'min:1', 'max:24'],
            'days' => ['nullable', 'required_if:kind,long_stay', 'integer', 'min:1', 'max:31'],
            'guest_name' => ['required', 'string', 'max:80', 'regex:/(?:\p{L}.*){3}/u'],
            'guest_mobile' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'guest_name.required' => 'Type your name, at least 3 letters.',
            'guest_name.regex' => 'Type your name, at least 3 letters.',
            'guest_mobile.regex' => 'Enter all 11 digits, or leave it blank.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $mobile = $this->input('guest_mobile');

        if (is_string($mobile)) {
            $digits = (string) preg_replace('/\D/', '', $mobile);
            $this->merge(['guest_mobile' => $digits === '' ? null : $digits]);
        }
    }
}
