<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One recipe line: a meal consumes 1 of some other stock item. The meal and the ingredient are
 * distinct products (product_ingredients_self_chk).
 *
 * @extends Factory<ProductIngredient>
 */
class ProductIngredientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'ingredient_id' => Product::factory(),
            'quantity' => 1,
        ];
    }
}
