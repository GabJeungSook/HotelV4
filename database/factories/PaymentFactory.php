<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Stay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A ₱500 cash payment for a checked-in stay by default.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'branch_id' => fn (array $a) => $this->stay($a)->branch_id,
            'shift_session_id' => fn (array $a) => $this->stay($a)->checkin_shift_id ?? ShiftSessionFactory::openFor($a['branch_id']),
            'kind' => PaymentKind::Payment,
            'method' => PaymentMethod::Cash,
            'amount' => 50000,
            'created_by' => fn (array $a) => $this->stay($a)->checked_in_by,
            'occurred_at' => '2026-11-11 01:00:00',
            'created_at' => '2026-11-11 01:00:00',
        ];
    }

    public function keyDeposit(int $amount = 20000): static
    {
        return $this->state(['kind' => PaymentKind::DepositIn, 'deposit_type' => DepositType::Key, 'amount' => $amount]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function stay(array $attributes): Stay
    {
        return Stay::query()->withoutGlobalScopes()->findOrFail($attributes['stay_id']);
    }
}
