<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Walk-in check-in (6). Never a price: the server prices the stay (SYSTEM_DESIGN §4.1). Validation only; the action enforces the business rules. */
final class CheckInGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer'],
            'guest_name' => ['required', 'string', 'min:2', 'max:80'],
            'guest_mobile' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
            'plate_number' => ['nullable', 'string', 'max:12'],
            'kind' => ['required', 'string', 'in:hourly,overnight,long_stay'],
            'quantity' => ['required', 'integer', 'min:1', 'max:31'],
            'discount_id' => ['nullable', 'integer'],
            'method' => ['required', 'string', 'in:cash,gcash,card'],
            'tendered' => ['nullable', 'required_if:method,cash', 'integer', 'min:0', 'max:100000000'],
            'reference_no' => ['nullable', 'required_if:method,gcash,card', 'string', 'max:40'],
            'keep_change' => ['boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
