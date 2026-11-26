<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kiosk checkout lookup/confirm: the guest's room number and stay code. No price or room id is
 * sent; the server finds the checked-in stay and only flags it (RequestKioskCheckout).
 */
final class KioskCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'room_number' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:40'],
        ];
    }
}
