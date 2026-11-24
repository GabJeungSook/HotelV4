<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Outlet;
use App\Enums\ProductKind;
use App\Enums\ReportGroup;
use App\Exceptions\ActionRefused;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\ProductPriceLog;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Add or edit a product (48; DATABASE_SCHEMA §4.13): food and drinks, amenities and damages in
 * one list. The name is unique within the branch and the price is above ₱0. Stock on hand is not
 * edited here: it moves only through stock_movements (20). A price change writes a
 * product_price_logs row with the old and new amount and who changed it; sales already made keep
 * their price on their folio line. A product is switched off, never deleted. One transaction
 * with its audit_logs row ('product.created', 'product.updated').
 *
 * A meal (a product with ingredients) keeps no own stock and deducts its ingredients when sold; its
 * recipe is replaced on save. A supply may be `sellable = false` and then carry no price.
 *
 * @phpstan-type ProductData array{name: string, kind: ProductKind, category: string, report_group: ReportGroup, outlet: Outlet, price_amount: Money, sellable: bool, tracks_stock: bool, low_stock_at: int, is_active: bool, ingredients: list<array{ingredient_id: int, quantity: int}>}
 */
final class SaveProduct
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    /** @param  ProductData  $data */
    public function handle(User $by, int $branchId, ?int $productId, array $data): Product
    {
        $ingredients = $data['ingredients'];
        $isMeal = $ingredients !== [];
        $tracksStock = $isMeal ? false : $data['tracks_stock']; // a meal keeps no own stock

        if ($data['sellable'] && $data['price_amount']->centavos() <= 0) {
            throw ActionRefused::because('A price is more than ₱0.');
        }

        if ($data['low_stock_at'] < 0) {
            throw ActionRefused::because('The low-stock level cannot be below zero.');
        }

        if ($isMeal && ! $data['sellable']) {
            throw ActionRefused::because('A meal is sold at the counter — turn on "Sold at the counter".');
        }

        return $this->transaction->run(function () use ($by, $branchId, $productId, $data, $ingredients, $isMeal, $tracksStock): Product {
            $product = $productId === null ? null : Product::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($productId);
            $name = trim($data['name']);

            $taken = Product::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('name', $name)
                ->when($product !== null, fn ($q) => $q->whereKeyNot($product?->id))->exists();

            if ($taken) {
                throw ActionRefused::because("There is already a product called {$name}.");
            }

            $this->validateRecipe($branchId, $product?->id, $ingredients, $isMeal);

            $after = [
                'name' => $name,
                'kind' => $data['kind']->value,
                'category' => trim($data['category']),
                'report_group' => $data['report_group']->value,
                'outlet' => $data['outlet']->value,
                'price_amount' => $data['price_amount']->centavos(),
                'sellable' => $data['sellable'],
                'tracks_stock' => $tracksStock,
                // Kept for a meal too: it is the "warn when fewer than N can be made" threshold.
                'low_stock_at' => ($tracksStock || $isMeal) ? $data['low_stock_at'] : 0,
                'is_active' => $data['is_active'],
            ];
            $columns = [...$after, 'price_amount' => $data['price_amount']];

            if ($product === null) {
                $product = Product::query()->create(['branch_id' => $branchId, ...$columns, 'stock_on_hand' => 0]);
                $this->audit->record($by, $branchId, 'product.created', 'product', $product->id, null, $after);
                $this->writeRecipe($branchId, $product, $ingredients);

                return $product;
            }

            $before = [
                'name' => $product->name,
                'kind' => $product->kind->value,
                'category' => $product->category,
                'report_group' => $product->report_group->value,
                'outlet' => $product->outlet->value,
                'price_amount' => $product->price_amount?->centavos(),
                'sellable' => $product->sellable,
                'tracks_stock' => $product->tracks_stock,
                'low_stock_at' => $product->low_stock_at,
                'is_active' => $product->is_active,
            ];
            [$old, $new] = AuditTrail::diff($before, $after);

            if ($new !== []) {
                $product->update($columns);

                if (array_key_exists('price_amount', $new)) {
                    ProductPriceLog::query()->create([
                        'product_id' => $product->id,
                        'old_amount' => Money::ofCentavos((int) $old['price_amount']),
                        'new_amount' => $data['price_amount'],
                        'changed_by' => $by->id,
                        'created_at' => $this->clock->now(),
                    ]);
                }

                $this->audit->record($by, $branchId, 'product.updated', 'product', $product->id, $old, $new);
            }

            $this->writeRecipe($branchId, $product, $ingredients);

            return $product;
        });
    }

    /**
     * A recipe may only list stock-keeping products of this branch, once each, with a quantity of at
     * least one, never the meal itself, and never another meal (recipes are flat).
     *
     * @param  list<array{ingredient_id: int, quantity: int}>  $ingredients
     */
    private function validateRecipe(int $branchId, ?int $productId, array $ingredients, bool $isMeal): void
    {
        if (! $isMeal) {
            return;
        }

        $ids = array_map(static fn (array $row): int => $row['ingredient_id'], $ingredients);

        if (count($ids) !== count(array_unique($ids))) {
            throw ActionRefused::because('An ingredient is listed more than once.');
        }

        $components = Product::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereIn('id', $ids)->with('ingredients')->get()->keyBy('id');

        foreach ($ingredients as $row) {
            if ($row['quantity'] < 1) {
                throw ActionRefused::because('Each ingredient needs a quantity of at least one.');
            }

            $component = $components->get($row['ingredient_id']);

            if ($component === null) {
                throw ActionRefused::because('Pick ingredients from this branch.');
            }

            if ($component->id === $productId) {
                throw ActionRefused::because('A meal cannot contain itself.');
            }

            if ($component->ingredients->isNotEmpty()) {
                throw ActionRefused::because("{$component->name} is itself a meal, so it cannot be an ingredient.");
            }

            if (! $component->tracks_stock) {
                throw ActionRefused::because("{$component->name} does not keep stock, so it cannot be an ingredient.");
            }
        }
    }

    /**
     * Replace the product's recipe (config, not a ledger): clear it, then insert the given rows.
     *
     * @param  list<array{ingredient_id: int, quantity: int}>  $ingredients
     */
    private function writeRecipe(int $branchId, Product $product, array $ingredients): void
    {
        ProductIngredient::query()->withoutGlobalScopes()->where('product_id', $product->id)->delete();

        foreach ($ingredients as $row) {
            ProductIngredient::query()->create([
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'ingredient_id' => $row['ingredient_id'],
                'quantity' => $row['quantity'],
            ]);
        }
    }
}
