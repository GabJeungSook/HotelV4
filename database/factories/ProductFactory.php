<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Outlet;
use App\Enums\ProductKind;
use App\Enums\ReportGroup;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A ₱25 drink with 50 in stock by default.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->words(2, true),
            'kind' => ProductKind::FoodDrink,
            'category' => 'Drinks',
            'report_group' => ReportGroup::Drinks,
            'outlet' => Outlet::Frontdesk,
            'price_amount' => 2500,
            'tracks_stock' => true,
            'stock_on_hand' => 50,
            'low_stock_at' => 10,
            'is_active' => true,
        ];
    }

    public function damage(int $amount = 10000): static
    {
        return $this->state([
            'kind' => ProductKind::Damage, 'category' => 'Damages', 'report_group' => ReportGroup::Misc,
            'price_amount' => $amount, 'tracks_stock' => false, 'stock_on_hand' => 0, 'low_stock_at' => 0,
        ]);
    }
}
