<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Actions\Admin\UpdateBranchSettings;
use App\Services\CurrentBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One group of branch settings (46). `group` names which settings the form sends; only that
 * group's keys are passed on. Money is centavos, times HH:MM. Validation only; the rules that
 * join settings together (at least one payment method, at least one kind of stay, VAT needs a
 * TIN) are in UpdateBranchSettings.
 */
final class BranchSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['sometimes', 'required', 'integer', 'min:0', 'max:100000000'];
        $flag = ['sometimes', 'required', 'boolean'];
        $minutes = ['sometimes', 'required', 'integer', 'min:1', 'max:1440'];

        return [
            'group' => ['required', 'string', 'in:'.implode(',', array_keys(UpdateBranchSettings::GROUPS))],
            // stays
            'hourly_enabled' => $flag,
            'overnight_enabled' => $flag,
            'overnight_checkin_time' => ['sometimes', 'required', 'date_format:H:i'],
            'overnight_checkout_time' => ['sometimes', 'required', 'date_format:H:i'],
            'long_stay_enabled' => $flag,
            'floor_room_rates_enabled' => $flag,
            'long_stay_max_days' => ['sometimes', 'required', 'integer', 'between:1,31'],
            'extension_rule' => ['sometimes', 'required', 'string', 'in:cycle,per_hour,block'],
            'extension_cycle_hours' => ['sometimes', 'required', 'integer', 'between:1,24'],
            'extension_per_hour_amount' => $money,
            'due_soon_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:240'],
            // money
            'deposit_enabled' => $flag,
            'deposit_name' => ['sometimes', 'required', 'string', 'max:40'],
            'deposit_amount' => $money,
            'lost_key_amount' => $money,
            'lost_remote_amount' => $money,
            'payment_methods' => ['sometimes', 'array', 'min:1'],
            'payment_methods.*' => ['string', 'in:cash,gcash,card'],
            // shifts
            'max_frontdesks_per_shift' => ['sometimes', 'required', 'integer', 'between:1,10'],
            'shift_overdue_warn_minutes' => $minutes,
            'shift_stale_block_minutes' => $minutes,
            // modules
            'kiosk_enabled' => $flag,
            'kiosk_hold_minutes' => ['sometimes', 'required', 'integer', 'between:1,120'],
            'reservations_enabled' => $flag,
            'reservation_release_minutes' => $minutes,
            'pos_enabled' => $flag,
            'housekeeping_enabled' => $flag,
            'cleaning_deadline_minutes' => $minutes,
            'cleaning_target_minutes' => $minutes,
            'cleaning_urgent_minutes' => $minutes,
            // VAT
            'vat_enabled' => $flag,
            'vat_rate_percent' => ['sometimes', 'required', 'integer', 'between:1,100'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:20'],
            'registered_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            // branch details, slip and house rules
            'name' => ['sometimes', 'required', 'string', 'max:80', Rule::unique('branches', 'name')->ignore(app(CurrentBranch::class)->id())],
            'slip_header' => ['sometimes', 'required', 'string', 'max:80'],
            'address' => ['sometimes', 'required', 'string', 'max:160'],
            'house_rules' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['payment_methods.min' => 'Accept at least one payment method.'];
    }

    /**
     * The validated settings of the chosen group only.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $group = UpdateBranchSettings::GROUPS[$this->string('group')->toString()] ?? [];
        $validated = $this->validated();

        return array_intersect_key($validated, array_flip($group));
    }
}
