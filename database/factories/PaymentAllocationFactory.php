<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A payment fully allocated to a line of the same stay by default.
 *
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'folio_line_id' => fn (array $a) => FolioLine::factory()->state([
                'stay_id' => Payment::query()->withoutGlobalScopes()->whereKey($a['payment_id'])->value('stay_id'),
            ]),
            'amount' => 50000,
            'created_at' => '2026-11-11 01:00:00',
        ];
    }
}
