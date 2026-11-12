<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountKind;
use App\Models\Branch;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A ₱50 fixed discount by default.
 *
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->word().' promo',
            'kind' => DiscountKind::Fixed,
            'value' => 5000,
            'all_rates' => true,
            'kiosk_enabled' => false,
            'is_vat_exempt' => false,
            'is_active' => true,
        ];
    }

    public function percent(int $percent): static
    {
        return $this->state(['kind' => DiscountKind::Percent, 'value' => $percent]);
    }
}
