<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Book a function hall (DATABASE_SCHEMA §4.14). Validation only; the rules live in ReserveHall. */
final class HallBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hall_id' => ['required', 'integer'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'pax' => ['required', 'integer', 'between:0,100000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.package_id' => ['nullable', 'integer'],
            'items.*.label' => ['required', 'string', 'max:120'],
            'items.*.pricing' => ['required', 'string', 'in:per_pax,flat'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'deposit_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'method' => ['nullable', 'string', 'in:cash,gcash,card'],
            'reference_no' => ['nullable', 'string', 'max:40'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
