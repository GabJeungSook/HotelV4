<?php

declare(strict_types=1);

use App\Actions\Admin\SaveDiscount;
use App\Actions\Admin\SaveExtensionRate;
use App\Actions\Admin\SaveRate;
use App\Enums\DiscountKind;
use App\Enums\StayKind;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\Discount;
use App\Models\ExtensionRate;
use App\Models\Rate;
use App\Models\RoomType;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Rates (45): SaveRate, SaveExtensionRate, SaveDiscount and RateController — DATABASE_SCHEMA §4.5.
| A blank cell is no rates row ("not set"), never ₱0; a row quoted by an open stay stays.
| Seeded Suite has no 18-hour rate.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->admin = Hotel::user('ramil.ortega');
    $this->branchId = Hotel::branch()->id;
    $this->type = fn (string $name) => RoomType::query()->where('branch_id', $this->branchId)->where('name', $name)->firstOrFail();
    $this->rate = fn (string $type, StayKind $kind, int $hours, ?int $centavos) => app(SaveRate::class)->handle(
        $this->admin, $this->branchId, ($this->type)($type)->id, null, null, $kind, $hours, $centavos === null ? null : Money::ofCentavos($centavos),
    );
    $this->floorRate = fn (int $floorId, StayKind $kind, int $hours, ?int $centavos) => app(SaveRate::class)->handle(
        $this->admin, $this->branchId, null, $floorId, null, $kind, $hours, $centavos === null ? null : Money::ofCentavos($centavos),
    );
    $this->roomRate = fn (int $roomId, StayKind $kind, int $hours, ?int $centavos) => app(SaveRate::class)->handle(
        $this->admin, $this->branchId, null, null, $roomId, $kind, $hours, $centavos === null ? null : Money::ofCentavos($centavos),
    );
    $this->find = fn (string $type, StayKind $kind, int $hours) => Rate::query()->where('room_type_id', ($this->type)($type)->id)->where('stay_kind', $kind)->where('hours', $hours)->first();
});

it('sets a missing rate, changes it, and logs both', function (): void {
    ($this->rate)('Suite', StayKind::Hourly, 18, 110000);
    ($this->rate)('Suite', StayKind::Hourly, 18, 115000);

    expect(($this->find)('Suite', StayKind::Hourly, 18)?->amount?->centavos())->toBe(115000);

    $logs = AuditLog::query()->where('subject_type', 'rate')->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['rate.created', 'rate.updated'])
        ->and($logs[1]->before_json['amount'])->toBe(110000)
        ->and($logs[1]->after_json['amount'])->toBe(115000)
        ->and($logs[1]->actor_id)->toBe($this->admin->id);
});

it('removes a rate when the cell is blanked, so the option is not sold', function (): void {
    expect(($this->rate)('Standard', StayKind::Hourly, 6, null))->toBeNull();

    expect(($this->find)('Standard', StayKind::Hourly, 6))->toBeNull()
        ->and(AuditLog::query()->where('action', 'rate.removed')->sole()->before_json['amount'])->toBe(35000);
});

it('never stores a ₱0 rate', function (): void {
    expect(fn () => ($this->rate)('Suite', StayKind::Hourly, 18, 0))->toThrow(ActionRefused::class, 'more than ₱0');
    expect(($this->find)('Suite', StayKind::Hourly, 18))->toBeNull();
});

it('refuses to remove a rate an open stay quotes, and allows it after check-out', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205', 12); // Deluxe 12 hrs

    expect(fn () => ($this->rate)('Deluxe', StayKind::Hourly, 12, null))->toThrow(ActionRefused::class, 'quoted by 1 open stay');
    expect(($this->find)('Deluxe', StayKind::Hourly, 12))->not->toBeNull();

    // A rate no open stay quotes goes.
    expect(($this->rate)('Deluxe', StayKind::Hourly, 6, null))->toBeNull();
});

it('treats the 24-hour rate as quoted by an open long stay', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205', 2, StayKind::LongStay);

    expect(fn () => ($this->rate)('Deluxe', StayKind::Hourly, 24, null))->toThrow(ActionRefused::class, 'quoted by 1 open stay');
});

it('refuses options the branch does not sell and odd hour blocks', function (): void {
    Hotel::branch()->update(['overnight_enabled' => false]);

    expect(fn () => ($this->rate)('Suite', StayKind::Overnight, 0, 99000))->toThrow(ActionRefused::class, 'Overnight stays are switched off');
    expect(fn () => ($this->rate)('Suite', StayKind::Hourly, 3, 20000))->toThrow(ActionRefused::class, '6, 12, 18 or 24 hours');
    expect(fn () => ($this->rate)('Suite', StayKind::LongStay, 0, 20000))->toThrow(ActionRefused::class, 'long stay is priced');

    Hotel::branch()->update(['hourly_enabled' => false, 'overnight_enabled' => true]);
    expect(fn () => ($this->rate)('Suite', StayKind::Hourly, 18, 20000))->toThrow(ActionRefused::class, 'Hourly stays are switched off');
});

it('refuses a room type of another branch', function (): void {
    $other = RoomType::factory()->create();

    expect(fn () => app(SaveRate::class)->handle($this->admin, $this->branchId, $other->id, null, null, StayKind::Hourly, 6, Money::ofCentavos(10000)))
        ->toThrow(ModelNotFoundException::class);
});

it('sets a flat floor rate and a room rate once the branch switch is on', function (): void {
    Hotel::branch()->update(['floor_room_rates_enabled' => true]);
    $room = Hotel::room('205'); // a Deluxe room
    $floorId = $room->floor_id;

    ($this->floorRate)($floorId, StayKind::Hourly, 12, 60000);
    ($this->roomRate)($room->id, StayKind::Hourly, 12, 80000);

    $floorRate = Rate::query()->where('floor_id', $floorId)->where('stay_kind', StayKind::Hourly)->where('hours', 12)->first();
    $roomRate = Rate::query()->where('room_id', $room->id)->where('stay_kind', StayKind::Hourly)->where('hours', 12)->first();

    expect($floorRate?->amount?->centavos())->toBe(60000)
        ->and($floorRate?->room_type_id)->toBeNull()
        ->and($roomRate?->amount?->centavos())->toBe(80000)
        ->and($roomRate?->room_type_id)->toBeNull()
        ->and(AuditLog::query()->where('subject_type', 'rate')->pluck('action')->all())->toBe(['rate.created', 'rate.created']);
});

it('refuses a floor or room rate while the branch switch is off', function (): void {
    $room = Hotel::room('205');

    expect(fn () => ($this->floorRate)($room->floor_id, StayKind::Hourly, 12, 60000))->toThrow(ActionRefused::class, 'Floor & room rates are switched off');
    expect(fn () => ($this->roomRate)($room->id, StayKind::Hourly, 12, 80000))->toThrow(ActionRefused::class, 'Floor & room rates are switched off');
});

it('refuses more than one scope on a rate', function (): void {
    Hotel::branch()->update(['floor_room_rates_enabled' => true]);
    $room = Hotel::room('205');

    expect(fn () => app(SaveRate::class)->handle($this->admin, $this->branchId, ($this->type)('Deluxe')->id, $room->floor_id, null, StayKind::Hourly, 12, Money::ofCentavos(60000)))
        ->toThrow(ActionRefused::class, 'exactly one');
});

it('removes a room override freely, even while a stay is in that room', function (): void {
    Hotel::branch()->update(['floor_room_rates_enabled' => true]);
    $room = Hotel::room('205');
    ($this->roomRate)($room->id, StayKind::Hourly, 12, 80000);

    Hotel::openShift();
    Hotel::checkIn('205', 12, tendered: 150000);

    // Unlike a type rate, an override falls back to the type, so it can always be removed.
    expect(($this->roomRate)($room->id, StayKind::Hourly, 12, null))->toBeNull()
        ->and(Rate::query()->where('room_id', $room->id)->exists())->toBeFalse();
});

it('sets, changes and removes an extension block with audit rows', function (): void {
    $save = app(SaveExtensionRate::class);
    $save->handle($this->admin, $this->branchId, 2, Money::ofCentavos(10000));
    $save->handle($this->admin, $this->branchId, 2, Money::ofCentavos(12000));
    $save->handle($this->admin, $this->branchId, 2, null);

    expect(ExtensionRate::query()->where('hours', 2)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('subject_type', 'extension_rate')->orderBy('id')->pluck('action')->all())
        ->toBe(['extension_rate.created', 'extension_rate.updated', 'extension_rate.removed']);

    expect(fn () => $save->handle($this->admin, $this->branchId, 30, Money::ofCentavos(10000)))->toThrow(ActionRefused::class, '1 to 24 hours');
    expect(fn () => $save->handle($this->admin, $this->branchId, 3, Money::zero()))->toThrow(ActionRefused::class, 'more than ₱0');
});

it('adds a percent discount limited to some rates, and edits it', function (): void {
    $six = ($this->find)('Standard', StayKind::Hourly, 6);
    $twelve = ($this->find)('Standard', StayKind::Hourly, 12);
    $data = ['name' => 'Birthday', 'kind' => DiscountKind::Percent, 'value' => 10, 'all_rates' => false, 'kiosk_enabled' => true, 'is_vat_exempt' => false, 'is_active' => true];

    $discount = app(SaveDiscount::class)->handle($this->admin, $this->branchId, null, $data, [$six->id]);
    expect($discount->rates()->pluck('rates.id')->all())->toBe([$six->id]);

    app(SaveDiscount::class)->handle($this->admin, $this->branchId, $discount->id, [...$data, 'value' => 15], [$six->id, $twelve->id]);
    $log = AuditLog::query()->where('action', 'discount.updated')->sole();

    expect($discount->fresh()?->value)->toBe(15)
        ->and($log->before_json)->toBe(['value' => 10, 'rate_ids' => [$six->id]])
        ->and($log->after_json)->toBe(['value' => 15, 'rate_ids' => [$six->id, $twelve->id]]);
});

it('refuses a bad discount', function (array $over, array $rates, string $message): void {
    $data = ['name' => 'Bad', 'kind' => DiscountKind::Fixed, 'value' => 5000, 'all_rates' => true, 'kiosk_enabled' => false, 'is_vat_exempt' => false, 'is_active' => true, ...$over];

    expect(fn () => app(SaveDiscount::class)->handle($this->admin, $this->branchId, null, $data, $rates))->toThrow(ActionRefused::class, $message);
    expect(Discount::query()->where('name', 'Bad')->exists())->toBeFalse();
})->with([
    'percent above 100' => [['kind' => DiscountKind::Percent, 'value' => 120], [], '1 to 100 %'],
    'limited with no rates' => [['all_rates' => false], [], 'Pick the rates'],
    'duplicate name' => [['name' => 'Promo'], [], 'already a discount called Promo'],
]);

it('refuses to limit a discount to another branch’s rate', function (): void {
    $other = Rate::factory()->create();
    $data = ['name' => 'Sneaky', 'kind' => DiscountKind::Fixed, 'value' => 5000, 'all_rates' => false, 'kiosk_enabled' => false, 'is_vat_exempt' => false, 'is_active' => true];

    expect(fn () => app(SaveDiscount::class)->handle($this->admin, $this->branchId, null, $data, [$other->id]))->toThrow(ActionRefused::class, 'Pick the rates');
});

// HTTP -------------------------------------------------------------------------------------

it('shows the rate table (45) with "not set" as null, the extension rates and the discounts', function (): void {
    $suite = ($this->type)('Suite');

    $this->actingAs($this->admin)->get('/admin/rates')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Rates')
        ->where('columns', fn ($c) => collect($c)->pluck('key')->all() === ['hourly-6', 'hourly-12', 'hourly-18', 'hourly-24', 'overnight'])
        ->where('rows', fn ($rows) => collect($rows)->firstWhere('room_type_id', $suite->id)['cells']['hourly-18'] === null)
        ->where('floor_room_rates_enabled', false)
        ->where('floors', [])
        ->has('extension')
        ->has('discounts'));
});

it('shows the floors and rooms for overrides once the switch is on', function (): void {
    Hotel::branch()->update(['floor_room_rates_enabled' => true]);
    $room = Hotel::room('205');
    ($this->roomRate)($room->id, StayKind::Hourly, 12, 80000);

    $this->actingAs($this->admin)->get('/admin/rates')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('floor_room_rates_enabled', true)
        ->where('floors', fn ($f) => collect($f)->isNotEmpty())
        ->where('rooms', fn ($r) => collect($r)->contains(fn ($o) => $o['value'] === $room->id))
        ->where('room_cells', fn ($cells) => collect($cells)->get((string) $room->id)['hourly-12']['amount'] === 80000));
});

it('saves a floor rate over HTTP', function (): void {
    Hotel::branch()->update(['floor_room_rates_enabled' => true]);
    $floorId = Hotel::room('205')->floor_id;

    $this->actingAs($this->admin)->from('/admin/rates')->put('/admin/rates', ['floor_id' => $floorId, 'stay_kind' => 'hourly', 'hours' => 12, 'amount' => 60000])->assertRedirect('/admin/rates');

    expect(Rate::query()->where('floor_id', $floorId)->where('hours', 12)->first()?->amount?->centavos())->toBe(60000);
});

it('saves and blanks a rate cell over HTTP', function (): void {
    $suite = ($this->type)('Suite');
    $this->actingAs($this->admin)->from('/admin/rates')->put('/admin/rates', ['room_type_id' => $suite->id, 'stay_kind' => 'hourly', 'hours' => 18, 'amount' => 110000])->assertRedirect('/admin/rates');
    expect(($this->find)('Suite', StayKind::Hourly, 18)?->amount?->centavos())->toBe(110000);

    $this->from('/admin/rates')->put('/admin/rates', ['room_type_id' => $suite->id, 'stay_kind' => 'hourly', 'hours' => 18, 'amount' => null])->assertRedirect('/admin/rates');
    expect(($this->find)('Suite', StayKind::Hourly, 18))->toBeNull();
});
