<?php

declare(strict_types=1);

use App\Enums\ReasonKind;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\FolioLine;
use App\Models\PosOrder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| PosController (19): the page, a sale (walk-in and room charge), the void with a PIN, and who may
| use them. The rules are tested on the actions (tests/Feature/Pos). The page carries no total of
| the shift's sales or cash (CLAUDE.md rule 9).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->shift = Hotel::openShift();
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, (string) Str::uuid());
    $this->sale = fn (array $overrides = []) => [
        'mode' => 'walk_in',
        'items' => [Hotel::product('Beer 330 ml')->id => 2, Hotel::product('Chips')->id => 1],
        'tendered' => 20000,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
});

/** Every key of a nested props array. */
function posPropKeys(array $props): array
{
    $keys = [];
    $walk = function (array $a) use (&$walk, &$keys): void {
        foreach ($a as $k => $v) {
            $keys[] = (string) $k;
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($props);

    return array_values(array_unique($keys));
}

it('shows the POS with products, stock states, occupied rooms and this shift’s sales, without totals', function (): void {
    Hotel::checkIn('205');
    $this->post('/pos', ($this->sale)()); // Chips 10 → 9, at its low-stock level of 9
    Hotel::product('Chips')->update(['low_stock_at' => 9]);

    $response = $this->get('/pos')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Pos/Index')
        ->where('products', fn ($products) => collect($products)->firstWhere('name', 'Coke 1.5 L')['stock'] === 0
            && collect($products)->firstWhere('name', 'Chips')['low'] === true
            && collect($products)->where('name', 'Broken glass')->isEmpty())
        ->where('categories', ['Amenities', 'Drinks', 'Snacks', 'Toiletries'])
        ->where('rooms.0.room', '205')
        ->has('sales', 1)
        ->where('sales.0.amount', 18500)
        ->where('sales.0.voided', false)
        ->has('reasons')
        ->has('approvers'));

    expect(posPropKeys($response->viewData('page')['props']))->not->toContain('expected_cash')->not->toContain('total')->not->toContain('collected');
});

it('saves a walk-in sale and toasts the change', function (): void {
    $this->post('/pos', ($this->sale)())->assertRedirect('/pos');

    expect(PosOrder::query()->count())->toBe(1)
        ->and(session('inertia.flash_data.toast.message'))->toMatch('/^Sale P-\d{6} saved\. Change ₱15\.00$/');
});

it('saves one sale when the same form is posted twice', function (): void {
    $form = ($this->sale)();
    $this->post('/pos', $form);
    $this->post('/pos', $form);

    expect(PosOrder::query()->count())->toBe(1);
});

it('charges a sale to an occupied room', function (): void {
    $stay = Hotel::checkIn('205');

    $this->post('/pos', ($this->sale)(['mode' => 'room_charge', 'stay_id' => $stay->id, 'tendered' => null]))->assertRedirect('/pos');

    expect(PosOrder::query()->sole()->stay_id)->toBe($stay->id)
        ->and(session('inertia.flash_data.toast.message'))->toBe('₱185.00 charged to Room 205.');
});

it('turns an out-of-stock item into an error on the page and saves nothing', function (): void {
    $this->from('/pos')->post('/pos', ($this->sale)(['items' => [Hotel::product('Coke 1.5 L')->id => 1], 'tendered' => 10000]))->assertRedirect('/pos');

    expect(session('inertia.flash_data.error.code'))->toBe('insufficient_stock')
        ->and(session('inertia.flash_data.error.message'))->toContain('Coke 1.5 L is out of stock')
        ->and(PosOrder::query()->count())->toBe(0);
});

it('validates the sale form', function (array $bad, string $field): void {
    $this->post('/pos', ($this->sale)($bad))->assertSessionHasErrors($field);
    expect(PosOrder::query()->count())->toBe(0);
})->with([
    'no items' => [['items' => []], 'items'],
    'bad quantity' => [['items' => [1 => 0]], 'items.1'],
    'no cash for a walk-in' => [['tendered' => null], 'tendered'],
    'no room for a room charge' => [['mode' => 'room_charge', 'tendered' => null], 'stay_id'],
    'unknown mode' => [['mode' => 'free'], 'mode'],
    'no idempotency key' => [['idempotency_key' => 'abc'], 'idempotency_key'],
]);

it('voids a sale with a supervisor PIN', function (): void {
    $this->post('/pos', ($this->sale)());
    $order = PosOrder::query()->sole();

    $this->post("/pos/{$order->id}/void", [
        'reason_id' => Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount')->id,
        'approver_id' => Hotel::supervisor()->id,
        'pin' => Hotel::PIN,
    ])->assertRedirect('/pos');

    expect(FolioLine::query()->whereNotNull('reversal_of_id')->count())->toBe(2)
        ->and(session('inertia.flash_data.toast.message'))->toBe("Sale {$order->order_no} is voided. The stock is back.");

    $this->get('/pos')->assertInertia(fn (Assert $page) => $page->where('sales.0.voided', true));
});

it('validates the void form', function (): void {
    $this->post('/pos', ($this->sale)());
    $order = PosOrder::query()->sole();

    $this->post("/pos/{$order->id}/void", ['reason_id' => null, 'approver_id' => Hotel::supervisor()->id, 'pin' => '12'])->assertSessionHasErrors(['reason_id', 'pin']);
});

it('refuses the POS to roles without front desk work and to a branch with POS switched off', function (): void {
    foreach (['ramil.ortega', 'grace.tolentino', 'dodong.lumayag'] as $username) {
        $this->actingAs(Hotel::user($username))->get('/pos')->assertForbidden();
        $this->post('/pos', ($this->sale)())->assertForbidden();
    }

    Hotel::branch()->update(['pos_enabled' => false]);
    $this->actingAs(Hotel::frontdesk())->get('/pos')->assertForbidden();
    $this->post('/pos', ($this->sale)())->assertForbidden();

    expect(PosOrder::query()->count())->toBe(0);
});

it('sends a front desk user who is not on the shift to open or join it', function (): void {
    $this->actingAs(Hotel::user('rhea.bautista'))->get('/pos')->assertRedirect('/shift/open');
});
