<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductIngredient;

/**
 * Turns a sold product into the stock it consumes (TRD §4, DATABASE_SCHEMA §8.3). A meal (a product
 * with a recipe) consumes its ingredients; a plain stock-tracked product consumes itself; anything
 * else consumes nothing. Recipes are read once per action and passed in, so the sale can lock every
 * product it will touch — the meal and all its ingredients — in one ascending-id query.
 */
final class Recipe
{
    /**
     * The recipes of the given product ids: mealId => [ingredientId => quantity per one meal].
     *
     * @param  list<int>  $productIds
     * @return array<int, array<int, int>>
     */
    public function load(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $map = [];

        foreach (ProductIngredient::query()->withoutGlobalScopes()->whereIn('product_id', $productIds)->get() as $row) {
            $map[(int) $row->product_id][(int) $row->ingredient_id] = (int) $row->quantity;
        }

        return $map;
    }

    /**
     * Every product id a sale of these items will lock: the items themselves and their ingredients,
     * unique and sorted ascending (the deadlock-free lock order of DATABASE_SCHEMA §8).
     *
     * @param  list<int>  $itemIds
     * @param  array<int, array<int, int>>  $recipes
     * @return list<int>
     */
    public function lockIds(array $itemIds, array $recipes): array
    {
        $ids = $itemIds;

        foreach ($itemIds as $id) {
            foreach (array_keys($recipes[$id] ?? []) as $ingredientId) {
                $ids[] = $ingredientId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * What selling `$qty` of `$sold` takes out of stock: [[productId, totalQty], ...]. A meal fans
     * out to its ingredients (× qty); a stock-tracked product deducts itself; anything else nothing.
     *
     * @param  array<int, array<int, int>>  $recipes
     * @return list<array{0: int, 1: int}>
     */
    public function deductions(Product $sold, int $qty, array $recipes): array
    {
        if (isset($recipes[$sold->id])) {
            $out = [];

            foreach ($recipes[$sold->id] as $ingredientId => $perMeal) {
                $out[] = [$ingredientId, $perMeal * $qty];
            }

            return $out;
        }

        return $sold->tracks_stock ? [[(int) $sold->id, $qty]] : [];
    }

    /**
     * How many of a product can be sold now, as the POS tile's `stock`/`low`: a plain product shows
     * its own on-hand; a meal shows how many it can still be made from its ingredients (the tightest
     * ingredient wins; a component that keeps no stock is unlimited). Needs `ingredients.ingredient`
     * loaded on the product.
     *
     * @return array{stock: int|null, low: bool}
     */
    public function availability(Product $product): array
    {
        if ($product->ingredients->isNotEmpty()) {
            $left = null;

            foreach ($product->ingredients as $line) {
                $component = $line->ingredient;

                if ($component === null || ! $component->tracks_stock) {
                    continue;
                }

                $can = $line->quantity > 0 ? intdiv($component->stock_on_hand, $line->quantity) : 0;
                $left = $left === null ? $can : min($left, $can);
            }

            return ['stock' => $left, 'low' => $left !== null && $left > 0 && $left <= $product->low_stock_at];
        }

        return [
            'stock' => $product->tracks_stock ? $product->stock_on_hand : null,
            'low' => $product->tracks_stock && $product->stock_on_hand > 0 && $product->stock_on_hand <= $product->low_stock_at,
        ];
    }
}
