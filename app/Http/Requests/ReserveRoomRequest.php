<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * New reservation (58). Never a price: ReserveRoom prices the stay. The arrival is a local date
 * and time (Asia/Manila). Validation only; the action enforces availability and the rules.
 */
final class ReserveRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'guest_name' => ['required', 'string', 'min:3', 'max:80'],
            'guest_mobile' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'arrival_time' => ['required', 'date_format:H:i'],
            'kind' => ['required', 'string', 'in:hourly,overnight,long_stay'],
            'quantity' => ['required', 'integer', 'min:1', 'max:31'],
            'room_type_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'paid_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'method' => ['nullable', 'required_unless:paid_amount,null,0', 'string', 'in:cash,gcash,card'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'guest_mobile.regex' => 'Enter all 11 digits, or leave it blank.',
            'reference_no.required_if' => 'Type the reference number.',
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
