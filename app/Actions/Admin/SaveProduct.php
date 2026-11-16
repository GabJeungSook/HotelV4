<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Outlet;
use App\Enums\ProductKind;
use App\Enums\ReportGroup;
use App\Exceptions\ActionRefused;
use App\Models\Product;
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
 * @phpstan-type ProductData array{name: string, kind: ProductKind, category: string, report_group: ReportGroup, outlet: Outlet, price_amount: Money, tracks_stock: bool, low_stock_at: int, is_active: bool}
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
        if ($data['price_amount']->centavos() <= 0) {
            throw ActionRefused::because('A price is more than ₱0.');
        }

        if ($data['low_stock_at'] < 0) {
            throw ActionRefused::because('The low-stock level cannot be below zero.');
        }

        return $this->transaction->run(function () use ($by, $branchId, $productId, $data): Product {
            $product = $productId === null ? null : Product::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($productId);
            $name = trim($data['name']);

            $taken = Product::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('name', $name)
                ->when($product !== null, fn ($q) => $q->whereKeyNot($product?->id))->exists();

            if ($taken) {
                throw ActionRefused::because("There is already a product called {$name}.");
            }

            $after = [
                'name' => $name,
                'kind' => $data['kind']->value,
                'category' => trim($data['category']),
                'report_group' => $data['report_group']->value,
                'outlet' => $data['outlet']->value,
                'price_amount' => $data['price_amount']->centavos(),
                'tracks_stock' => $data['tracks_stock'],
                'low_stock_at' => $data['tracks_stock'] ? $data['low_stock_at'] : 0,
                'is_active' => $data['is_active'],
            ];
            $columns = [...$after, 'price_amount' => $data['price_amount']];

            if ($product === null) {
                $product = Product::query()->create(['branch_id' => $branchId, ...$columns, 'stock_on_hand' => 0]);
                $this->audit->record($by, $branchId, 'product.created', 'product', $product->id, null, $after);

                return $product;
            }

            $before = [
                'name' => $product->name,
                'kind' => $product->kind->value,
                'category' => $product->category,
                'report_group' => $product->report_group->value,
                'outlet' => $product->outlet->value,
                'price_amount' => $product->price_amount?->centavos(),
                'tracks_stock' => $product->tracks_stock,
                'low_stock_at' => $product->low_stock_at,
                'is_active' => $product->is_active,
            ];
            [$old, $new] = AuditTrail::diff($before, $after);

            if ($new === []) {
                return $product;
            }

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

            return $product;
        });
    }
}
