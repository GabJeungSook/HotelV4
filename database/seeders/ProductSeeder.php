<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductKind;
use App\Enums\ReportGroup;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * 17 food & drinks, 3 amenities, 3 damages (prices in pesos). Lost key and lost
 * remote are priced from the branch settings, not products. Stock levels are the
 * opening stock; the demo shift writes the movements that follow.
 */
class ProductSeeder extends Seeder
{
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
    }
}
