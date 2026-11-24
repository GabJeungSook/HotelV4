<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SaveProduct;
use App\Enums\Outlet;
use App\Enums\ProductKind;
use App\Enums\ReportGroup;
use App\Http\Requests\Admin\ProductRequest;
use App\Queries\AdminSetupQuery;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Products (48) with the kind tabs; price changes are logged (DATABASE_SCHEMA §4.13). */
final class ProductController extends AdminController
{
    public function index(Request $request, AdminSetupQuery $query): Response
    {
        $kind = ProductKind::tryFrom($request->string('kind')->toString()) ?? ProductKind::FoodDrink;

        return Inertia::render('Admin/Products', $query->products($this->branch(), $kind));
    }

    public function store(ProductRequest $request, SaveProduct $save): RedirectResponse
    {
        $product = $save->handle($this->user($request), $this->branchId(), null, $this->data($request));

        return $this->saved("{$product->name} added.");
    }

    public function update(ProductRequest $request, SaveProduct $save, int $product): RedirectResponse
    {
        $saved = $save->handle($this->user($request), $this->branchId(), $product, $this->data($request));

        return $this->saved("{$saved->name} saved.");
    }

    /** @return array{name: string, kind: ProductKind, category: string, report_group: ReportGroup, outlet: Outlet, price_amount: Money, sellable: bool, tracks_stock: bool, low_stock_at: int, is_active: bool, ingredients: list<array{ingredient_id: int, quantity: int}>} */
    private function data(Request $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'kind' => ProductKind::from($request->string('kind')->toString()),
            'category' => $request->string('category')->toString(),
            'report_group' => ReportGroup::from($request->string('report_group')->toString()),
            'outlet' => Outlet::tryFrom($request->string('outlet')->toString()) ?? Outlet::Frontdesk,
            'price_amount' => Money::ofCentavos($request->integer('price_amount')),
            'sellable' => $request->has('sellable') ? $request->boolean('sellable') : true,
            'tracks_stock' => $request->boolean('tracks_stock'),
            'low_stock_at' => $request->integer('low_stock_at'),
            'is_active' => $request->boolean('is_active'),
            'ingredients' => array_values(array_map(
                static fn (array $row): array => ['ingredient_id' => (int) ($row['ingredient_id'] ?? 0), 'quantity' => (int) ($row['quantity'] ?? 0)],
                array_filter((array) $request->input('ingredients', []), 'is_array'),
            )),
        ];
    }
}
