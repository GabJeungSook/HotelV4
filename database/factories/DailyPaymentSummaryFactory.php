<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\DailyPaymentSummary;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyPaymentSummary> */
class DailyPaymentSummaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => '2026-11-11',
            'method' => PaymentMethod::Cash,
            'user_id' => fn (array $a) => User::factory()->frontdesk()->state(['branch_id' => $a['branch_id']]),
            'payment_count' => 10,
            'amount' => 650000,
            'refund_amount' => 0,
        ];
    }
}
