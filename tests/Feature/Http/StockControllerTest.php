<?php

declare(strict_types=1);

use App\Enums\ReasonKind;
use App\Enums\StockMovementKind;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\Approval;
use App\Models\StockMovement;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| StockController (20): the per-shift stock table, receive stock, record wastage (with a PIN when
| the reason asks for one), validation and who may use them. Rules: tests/Feature/Pos/StockTest.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->shift = Hotel::openShift();
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, (string) Str::uuid());
    $this->chips = Hotel::product('Chips');
});

it('shows this shift’s stock per product with the movements behind it', function (): void {
    $this->post('/stock', ['kind' => 'receive', 'product_id' => $this->chips->id, 'quantity' => 24]);

    $this->get('/stock')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Stock/Index')
        ->where('rows', fn ($rows) => collect($rows)->firstWhere('name', 'Chips') === [
            'product_id' => $this->chips->id, 'name' => 'Chips', 'opening' => 10, 'in' => 24, 'sold' => 0, 'wastage' => 0, 'closing' => 34, 'low_at' => 5,
        ] && collect($rows)->where('name', 'Broken glass')->isEmpty())
        ->has('movements', 1)
        ->where('movements.0.kind', 'receive')
        ->where('period_name', 'AM')
        ->has('reasons', 4)
        ->has('approvers'));
});

it('receives stock and toasts the new balance', function (): void {
    $this->post('/stock', ['kind' => 'receive', 'product_id' => $this->chips->id, 'quantity' => 24, 'note' => 'DR 4471'])->assertRedirect('/stock');

    expect(StockMovement::query()->sole()->note)->toBe('DR 4471')
        ->and(session('inertia.flash_data.toast.message'))->toBe('24 received. 34 on hand.');
});

it('records wastage, and asks a PIN for a reason that needs a manager', function (): void {
    $form = ['kind' => 'wastage', 'product_id' => $this->chips->id, 'quantity' => 1, 'reason_id' => Hotel::reason(ReasonKind::Wastage, 'Damaged')->id, 'note' => 'Bag torn'];
    $this->post('/stock', $form)->assertRedirect('/stock');
    expect(StockMovement::query()->sole()->kind)->toBe(StockMovementKind::Wastage);

    $other = [...$form, 'reason_id' => Hotel::reason(ReasonKind::Wastage)->id];
    $this->from('/stock')->post('/stock', $other);
    expect(session('inertia.flash_data.error.message'))->toBe('Other needs a supervisor PIN.')
        ->and(StockMovement::query()->count())->toBe(1);

    $this->post('/stock', [...$other, 'approver_id' => Hotel::supervisor()->id, 'pin' => Hotel::PIN])->assertRedirect('/stock');
    expect(StockMovement::query()->count())->toBe(2)
        ->and(Approval::query()->count())->toBe(1);
});

it('validates the stock forms', function (array $form, array $fields): void {
    $this->post('/stock', $form)->assertSessionHasErrors($fields);
    expect(StockMovement::query()->count())->toBe(0);
})->with([
    'no kind' => [['product_id' => 1, 'quantity' => 1], ['kind']],
    'no pieces' => [['kind' => 'receive', 'product_id' => 1, 'quantity' => 0], ['quantity']],
    'wastage without reason or note' => [['kind' => 'wastage', 'product_id' => 1, 'quantity' => 1], ['reason_id', 'note']],
    'a PIN that is not four digits' => [['kind' => 'wastage', 'product_id' => 1, 'quantity' => 1, 'reason_id' => 1, 'note' => 'x', 'approver_id' => 2, 'pin' => '12'], ['pin']],
]);

it('refuses stock to roles without front desk work and to a branch with POS switched off', function (): void {
    foreach (['ramil.ortega', 'joy.pacana'] as $username) {
        $this->actingAs(Hotel::user($username))->get('/stock')->assertForbidden();
        $this->post('/stock', ['kind' => 'receive', 'product_id' => $this->chips->id, 'quantity' => 5])->assertForbidden();
    }

    Hotel::branch()->update(['pos_enabled' => false]);
    $this->actingAs(Hotel::supervisor())->get('/stock')->assertForbidden();

    expect(StockMovement::query()->count())->toBe(0);
});

it('lets a supervisor work the stock without joining the shift', function (): void {
    $this->actingAs(Hotel::supervisor())->post('/stock', ['kind' => 'receive', 'product_id' => $this->chips->id, 'quantity' => 5])->assertRedirect('/stock');

    expect(StockMovement::query()->sole()->created_by)->toBe(Hotel::supervisor()->id);
});
