<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPriceLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductPriceLog> */
class ProductPriceLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'old_amount' => 2000,
            'new_amount' => 2500,
            'changed_by' => fn (array $a) => User::factory()->admin()->state([
                'branch_id' => Product::query()->withoutGlobalScopes()->whereKey($a['product_id'])->value('branch_id'),
            ]),
            'created_at' => '2026-11-10 01:00:00',
        ];
    }
}
