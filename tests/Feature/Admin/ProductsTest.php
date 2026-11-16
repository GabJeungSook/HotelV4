<?php

declare(strict_types=1);

use App\Actions\Admin\SaveProduct;
use App\Enums\Outlet;
use App\Enums\ProductKind;
use App\Enums\ReportGroup;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductPriceLog;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Products (48): SaveProduct and ProductController — DATABASE_SCHEMA §4.13. A price change writes
| product_price_logs and audit_logs; stock on hand is not edited here.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->admin = Hotel::user('ramil.ortega');
    $this->branchId = Hotel::branch()->id;
    $this->data = fn (array $over = []): array => [
        'name' => 'Calamansi juice', 'kind' => ProductKind::FoodDrink, 'category' => 'Drinks', 'report_group' => ReportGroup::Drinks,
        'outlet' => Outlet::Frontdesk, 'price_amount' => Money::ofCentavos(4000), 'tracks_stock' => true, 'low_stock_at' => 6, 'is_active' => true, ...$over,
    ];
    $this->save = fn (?int $id, array $data) => app(SaveProduct::class)->handle($this->admin, $this->branchId, $id, $data);
});

it('adds a product with no stock and an audit row, and no price log', function (): void {
    $product = ($this->save)(null, ($this->data)());

    expect($product->stock_on_hand)->toBe(0)
        ->and($product->branch_id)->toBe($this->branchId)
        ->and(ProductPriceLog::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'product.created')->sole()->after_json['price_amount'])->toBe(4000);
});

it('writes product_price_logs and audit_logs when the price changes', function (): void {
    $chips = Hotel::product('Chips');
    $old = $chips->price_amount?->centavos();

    ($this->save)($chips->id, ($this->data)([
        'name' => 'Chips', 'category' => $chips->category, 'report_group' => $chips->report_group, 'kind' => $chips->kind,
        'tracks_stock' => $chips->tracks_stock, 'low_stock_at' => $chips->low_stock_at, 'price_amount' => Money::ofCentavos(5500),
    ]));

    $log = ProductPriceLog::query()->sole();
    expect($log->product_id)->toBe($chips->id)
        ->and($log->old_amount?->centavos())->toBe($old)
        ->and($log->new_amount?->centavos())->toBe(5500)
        ->and($log->changed_by)->toBe($this->admin->id)
        ->and($chips->fresh()?->stock_on_hand)->toBe($chips->stock_on_hand)
        ->and(AuditLog::query()->where('action', 'product.updated')->sole()->after_json)->toBe(['price_amount' => 5500]);
});

it('writes no price log when only other fields change', function (): void {
    $product = ($this->save)(null, ($this->data)());
    ($this->save)($product->id, ($this->data)(['low_stock_at' => 3]));

    expect(ProductPriceLog::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'product.updated')->sole()->after_json)->toBe(['low_stock_at' => 3]);
});

it('refuses a ₱0 price, a duplicate name and another branch’s product', function (): void {
    expect(fn () => ($this->save)(null, ($this->data)(['price_amount' => Money::zero()])))->toThrow(ActionRefused::class, 'more than ₱0');
    expect(fn () => ($this->save)(null, ($this->data)(['name' => 'Chips'])))->toThrow(ActionRefused::class, 'already a product called Chips');

    $other = Product::factory()->create();
    expect(fn () => ($this->save)($other->id, ($this->data)()))->toThrow(ModelNotFoundException::class);
    expect($other->fresh()?->name)->not->toBe('Calamansi juice');
});

// HTTP -------------------------------------------------------------------------------------

it('shows the products of one kind (48) with the counts of every kind', function (): void {
    Product::factory()->create(['name' => 'Elsewhere water']);

    $this->actingAs($this->admin)->get('/admin/products?kind=damage')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Products')
        ->where('kind', 'damage')
        ->where('products', fn ($rows) => collect($rows)->every(fn ($r) => $r['kind'] === 'damage') && collect($rows)->isNotEmpty())
        ->where('counts.food_drink', Product::query()->where('branch_id', $this->branchId)->where('kind', ProductKind::FoodDrink)->count()));
});

it('adds and edits a product over HTTP', function (): void {
    $form = ['name' => 'Calamansi juice', 'kind' => 'food_drink', 'category' => 'Drinks', 'report_group' => 'drinks', 'price_amount' => 4000, 'tracks_stock' => true, 'low_stock_at' => 6, 'is_active' => true];
    $this->actingAs($this->admin)->from('/admin/products')->post('/admin/products', $form)->assertRedirect('/admin/products');

    $tea = Hotel::product('Calamansi juice');
    $this->from('/admin/products')->put("/admin/products/{$tea->id}", [...$form, 'price_amount' => 4500])->assertRedirect('/admin/products');

    expect($tea->fresh()?->price_amount?->centavos())->toBe(4500)
        ->and(ProductPriceLog::query()->count())->toBe(1);

    $this->post('/admin/products', [...$form, 'price_amount' => 0])->assertSessionHasErrors('price_amount');
});
