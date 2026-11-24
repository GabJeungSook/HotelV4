<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductKind;
use App\Enums\ReportGroup;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * 17 food & drinks, 3 amenities, 3 damages (prices in pesos). Lost key and lost
 * remote are priced from the branch settings, not products. Stock levels are the
 * opening stock; the demo shift writes the movements that follow.
 *
 * Plus a short-order kitchen to show recipes (DATABASE_SCHEMA §4.13): ingredients sold on their
 * own too (rice, egg…), supplies that are never sold (spoon, plate — sellable = 0, no price), and
 * meals (tocilog, hotsilog — no own stock) that deduct their ingredients when sold.
 */
class ProductSeeder extends Seeder
{
    /** Stock items that are also sold on their own (e.g. extra egg): name, category, group, pesos, stock, low at. */
    public const INGREDIENTS = [
        ['Rice', 'Short orders', ReportGroup::Foods, 15, 200, 40],
        ['Egg', 'Short orders', ReportGroup::Foods, 15, 200, 40],
        ['Tocino', 'Short orders', ReportGroup::Foods, 45, 100, 20],
        ['Hotdog', 'Short orders', ReportGroup::Foods, 40, 100, 20],
    ];

    /** Stock items never sold on their own (supplies): name, stock, low at. */
    public const SUPPLIES = [
        ['Plastic spoon & fork', 300, 50],
        ['Meal plate', 150, 30],
    ];

    /** Meals (no own stock), each the price in pesos and its recipe [component name => quantity]. */
    public const MEALS = [
        'Tocilog' => [120, ['Rice' => 1, 'Tocino' => 1, 'Egg' => 1, 'Plastic spoon & fork' => 1, 'Meal plate' => 1]],
        'Hotsilog' => [110, ['Rice' => 1, 'Hotdog' => 2, 'Egg' => 1, 'Plastic spoon & fork' => 1, 'Meal plate' => 1]],
    ];

    /** @var list<array{0: string, 1: ProductKind, 2: string, 3: ReportGroup, 4: int, 5: int, 6: int}> name, kind, category, group, pesos, stock, low at */
    public const PRODUCTS = [
        ['Cup noodles', ProductKind::FoodDrink, 'Snacks', ReportGroup::Foods, 45, 30, 8],
        ['Chips', ProductKind::FoodDrink, 'Snacks', ReportGroup::Foods, 35, 10, 5],
        ['Biscuits', ProductKind::FoodDrink, 'Snacks', ReportGroup::Foods, 15, 40, 10],
        ['Peanuts', ProductKind::FoodDrink, 'Snacks', ReportGroup::Foods, 10, 30, 8],
        ['Chocolate bar', ProductKind::FoodDrink, 'Snacks', ReportGroup::Foods, 40, 24, 6],
        ['Bread roll', ProductKind::FoodDrink, 'Snacks', ReportGroup::Foods, 30, 20, 5],
        ['Canned tuna', ProductKind::FoodDrink, 'Snacks', ReportGroup::Foods, 55, 20, 5],
        ['Bottled water 500 ml', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 25, 36, 10],
        ['Bottled water 1 L', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 40, 24, 6],
        ['3-in-1 coffee', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 20, 60, 15],
        ['Coke 1.5 L', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 95, 0, 4],
        ['Coke in can', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 45, 48, 12],
        ['Iced tea', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 35, 30, 8],
        ['Orange juice', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 40, 24, 6],
        ['Energy drink', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 50, 24, 6],
        ['Beer 330 ml', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 75, 36, 12],
        ['Hot chocolate', ProductKind::FoodDrink, 'Drinks', ReportGroup::Drinks, 25, 40, 10],
        ['Toothbrush kit', ProductKind::Amenity, 'Toiletries', ReportGroup::Misc, 30, 25, 8],
        ['Shampoo sachet', ProductKind::Amenity, 'Toiletries', ReportGroup::Misc, 10, 80, 20],
        ['Extra towel', ProductKind::Amenity, 'Amenities', ReportGroup::Misc, 50, 20, 5],
        ['Broken remote', ProductKind::Damage, 'Damages', ReportGroup::Misc, 100, 0, 0],
        ['Stained linen', ProductKind::Damage, 'Damages', ReportGroup::Misc, 150, 0, 0],
        ['Broken glass', ProductKind::Damage, 'Damages', ReportGroup::Misc, 50, 0, 0],
    ];

    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        foreach (self::PRODUCTS as [$name, $kind, $category, $group, $pesos, $stock, $lowAt]) {
            Product::query()->create([
                'branch_id' => $branchId,
                'name' => $name,
                'kind' => $kind,
                'category' => $category,
                'report_group' => $group,
                'price_amount' => Money::ofPesos($pesos),
                'tracks_stock' => $kind !== ProductKind::Damage,
                'stock_on_hand' => $stock,
                'low_stock_at' => $lowAt,
            ]);
        }

        $id = []; // name => product id, to wire recipes

        foreach (self::INGREDIENTS as [$name, $category, $group, $pesos, $stock, $lowAt]) {
            $id[$name] = Product::query()->create([
                'branch_id' => $branchId,
                'name' => $name,
                'kind' => ProductKind::FoodDrink,
                'category' => $category,
                'report_group' => $group,
                'price_amount' => Money::ofPesos($pesos),
                'tracks_stock' => true,
                'stock_on_hand' => $stock,
                'low_stock_at' => $lowAt,
            ])->id;
        }

        foreach (self::SUPPLIES as [$name, $stock, $lowAt]) {
            $id[$name] = Product::query()->create([
                'branch_id' => $branchId,
                'name' => $name,
                'kind' => ProductKind::Amenity,
                'category' => 'Supplies',
                'report_group' => ReportGroup::Misc,
                'price_amount' => Money::ofPesos(0),
                'sellable' => false,
                'tracks_stock' => true,
                'stock_on_hand' => $stock,
                'low_stock_at' => $lowAt,
            ])->id;
        }

        foreach (self::MEALS as $name => [$pesos, $recipe]) {
            $meal = Product::query()->create([
                'branch_id' => $branchId,
                'name' => $name,
                'kind' => ProductKind::FoodDrink,
                'category' => 'Meals',
                'report_group' => ReportGroup::Foods,
                'price_amount' => Money::ofPesos($pesos),
                'tracks_stock' => false,
                'stock_on_hand' => 0,
                'low_stock_at' => 5,
            ]);

            foreach ($recipe as $component => $quantity) {
                ProductIngredient::query()->create([
                    'branch_id' => $branchId,
                    'product_id' => $meal->id,
                    'ingredient_id' => $id[$component],
                    'quantity' => $quantity,
                ]);
            }
        }
    }
}
