<?php

declare(strict_types=1);

use App\Actions\Admin\SaveListReason;
use App\Actions\Admin\SaveRate;
use App\Actions\Admin\SaveShiftDefinition;
use App\Actions\Admin\UpdateBranchSettings;
use App\Enums\PaymentMethod;
use App\Enums\ReasonKind;
use App\Enums\StayKind;
use App\Enums\VatMode;
use App\Exceptions\ActionRefused;
use App\Exceptions\InvalidStayOption;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\Rate;
use App\Models\Reason;
use App\Models\RoomType;
use App\Models\ShiftDefinition;
use App\Models\TransferReason;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Branch settings (46): UpdateBranchSettings, SaveShiftDefinition, SaveListReason and
| SettingController — DATABASE_SCHEMA §4.1, §4.3, §4.6, §4.16; APP_FLOW §8.2. And the Phase 9
| "Done when": switching hourly stays off removes the hourly options from check-in (6), the
| kiosk (28) and the rate table (45).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->admin = Hotel::user('ramil.ortega');
    $this->branchId = Hotel::branch()->id;
    $this->update = fn (array $values) => app(UpdateBranchSettings::class)->handle($this->admin, $this->branchId, $values);
});

it('saves a group of settings and logs only what changed', function (): void {
    ($this->update)(['deposit_enabled' => true, 'deposit_amount' => 30000, 'deposit_name' => 'Key & remote', 'payment_methods' => ['gcash', 'cash', 'card']]);

    $branch = Hotel::branch();
    $log = AuditLog::query()->where('action', 'settings.updated')->sole();

    expect($branch->deposit_amount?->centavos())->toBe(30000)
        ->and($branch->payment_methods->all())->toBe([PaymentMethod::Cash, PaymentMethod::Gcash, PaymentMethod::Card])
        ->and($log->subject_type)->toBe('branch')
        ->and($log->subject_id)->toBe($this->branchId)
        ->and($log->before_json)->toBe(['deposit_amount' => 20000, 'payment_methods' => ['cash', 'gcash']])
        ->and($log->after_json)->toBe(['deposit_amount' => 30000, 'payment_methods' => ['cash', 'gcash', 'card']]);
});

it('writes no audit row when nothing changed', function (): void {
    ($this->update)(['deposit_amount' => 20000]);

    expect(AuditLog::query()->where('action', 'settings.updated')->count())->toBe(0);
});

it('manages the hourly blocks: a new block can be priced, and dropping one clears its rates', function (): void {
    $type = RoomType::query()->where('name', 'Standard')->firstOrFail();
    $has3h = fn (): bool => Rate::query()->where('branch_id', $this->branchId)->where('stay_kind', StayKind::Hourly)->where('hours', 3)->exists();

    // 3 h is not offered by default, so a 3-hour rate is refused.
    expect(fn () => app(SaveRate::class)->handle($this->admin, $this->branchId, $type->id, null, null, StayKind::Hourly, 3, Money::ofPesos(250)))
        ->toThrow(ActionRefused::class, 'not offered by this branch');

    // Offer a 3-hour block → the 3-hour rate now saves.
    ($this->update)(['hourly_blocks' => [3, 6, 12, 18, 24]]);
    app(SaveRate::class)->handle($this->admin, $this->branchId, $type->id, null, null, StayKind::Hourly, 3, Money::ofPesos(250));
    expect($has3h())->toBeTrue()
        ->and(Hotel::branch()->hourlyBlocks())->toBe([3, 6, 12, 18, 24]);

    // Drop the 3-hour block → its rates are cleared, so nothing offers it any more.
    ($this->update)(['hourly_blocks' => [6, 12, 18, 24]]);
    expect($has3h())->toBeFalse()
        ->and(Hotel::branch()->hourlyBlocks())->toBe([6, 12, 18, 24]);
});

it('refuses dropping an hour block an open stay is still using', function (): void {
    Hotel::openShift();
    Hotel::checkIn('101', 6); // a 6-hour stay

    expect(fn () => ($this->update)(['hourly_blocks' => [12, 18, 24]]))->toThrow(ActionRefused::class, 'in use by open stays');
    expect(Rate::query()->where('branch_id', $this->branchId)->where('stay_kind', StayKind::Hourly)->where('hours', 6)->exists())->toBeTrue();
});

it('keeps the 24-hour block while long stay is on', function (): void {
    expect(fn () => ($this->update)(['hourly_blocks' => [6, 12, 18]]))->toThrow(ActionRefused::class, '24-hour rate');
});

it('refuses hourly stays on with no blocks', function (): void {
    expect(fn () => ($this->update)(['hourly_blocks' => []]))->toThrow(ActionRefused::class, 'at least one hourly block');
});

it('saves the modules, the stale-shift limit, the VAT block and the slip settings', function (): void {
    ($this->update)(['kiosk_enabled' => false, 'cleaning_target_minutes' => 20, 'shift_stale_block_minutes' => 180]);
    ($this->update)(['vat_enabled' => true, 'vat_rate_percent' => 12, 'tin' => '123-456-789-000', 'registered_name' => 'Dalisay Hospitality Inc.']);
    ($this->update)(['slip_header' => 'HOTEL DALISAY', 'house_rules' => "No pets.\nNo smoking."]);

    $branch = Hotel::branch();
    expect($branch->kiosk_enabled)->toBeFalse()
        ->and($branch->shift_stale_block_minutes)->toBe(180)
        ->and($branch->vat_enabled)->toBeTrue()
        ->and($branch->tin)->toBe('123-456-789-000')
        ->and($branch->house_rules)->toBe("No pets.\nNo smoking.")
        ->and(AuditLog::query()->where('action', 'settings.updated')->count())->toBe(3);
});

it('saves VAT as a fixed peso amount per transaction', function (): void {
    ($this->update)(['vat_enabled' => true, 'vat_mode' => 'amount', 'vat_amount' => 5000, 'tin' => '123-456-789-000', 'registered_name' => 'Dalisay Hospitality Inc.']);

    $branch = Hotel::branch();
    expect($branch->vat_enabled)->toBeTrue()
        ->and($branch->vat_mode)->toBe(VatMode::Amount)
        ->and($branch->vat_amount->centavos())->toBe(5000);
});

it('refuses settings that would break the branch', function (array $values, string $message): void {
    $before = Hotel::branch()->toArray();

    expect(fn () => ($this->update)($values))->toThrow(ActionRefused::class, $message);
    expect(Hotel::branch()->toArray())->toEqual($before)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'no payment method' => [['payment_methods' => []], 'at least one payment method'],
    'no kind of stay' => [['hourly_enabled' => false, 'overnight_enabled' => false, 'long_stay_enabled' => false], 'at least one kind of stay'],
    'VAT without TIN' => [['vat_enabled' => true, 'tin' => null], 'TIN and registered name'],
    'VAT fixed amount of zero' => [['vat_enabled' => true, 'vat_mode' => 'amount', 'vat_amount' => 0, 'tin' => '123', 'registered_name' => 'Co.'], 'fixed-amount VAT needs the peso amount'],
    'a counter is not a setting' => [['next_z_number' => 1], 'not branch settings'],
    'per hour without an amount' => [['extension_rule' => 'per_hour', 'extension_per_hour_amount' => 0], 'amount per hour'],
]);

it('adds and edits a shift, unique by name, and keeps one active', function (): void {
    $save = app(SaveShiftDefinition::class);
    $night = $save->handle($this->admin, $this->branchId, null, 'Night', '22:00', '06:00');
    $save->handle($this->admin, $this->branchId, $night->id, 'Night', '23:00', '07:00');

    expect($night->fresh()?->starts_at)->toBe('23:00:00')
        ->and($night->fresh()?->sort_order)->toBeGreaterThan(1)
        ->and(AuditLog::query()->whereIn('action', ['shift_definition.created', 'shift_definition.updated'])->count())->toBe(2);

    expect(fn () => $save->handle($this->admin, $this->branchId, null, 'AM', '06:00', '14:00'))->toThrow(ActionRefused::class, 'already a shift called AM');
    expect(fn () => $save->handle($this->admin, $this->branchId, null, 'Odd', '06:00', '06:00'))->toThrow(ActionRefused::class, 'same time');

    ShiftDefinition::query()->where('branch_id', $this->branchId)->whereKeyNot($night->id)->update(['is_active' => false]);
    expect(fn () => $save->handle($this->admin, $this->branchId, $night->id, 'Night', '23:00', '07:00', false))->toThrow(ActionRefused::class, 'at least one active shift');
});

it('refuses a shift of another branch', function (): void {
    $other = ShiftDefinition::factory()->create();

    expect(fn () => app(SaveShiftDefinition::class)->handle($this->admin, $this->branchId, $other->id, 'X', '08:00', '20:00'))->toThrow(ModelNotFoundException::class);
});

it('adds and edits rows of each reason list with their note and manager switches', function (): void {
    $save = app(SaveListReason::class);
    $transfer = $save->handle($this->admin, $this->branchId, SaveListReason::TRANSFER, null, 'Guest asked for a view', true, false);
    $expense = $save->handle($this->admin, $this->branchId, SaveListReason::EXPENSE, null, 'Courier', false, true);
    $void = $save->handle($this->admin, $this->branchId, ReasonKind::VoidLine->value, null, 'Typed twice', true, true);
    $save->handle($this->admin, $this->branchId, SaveListReason::TRANSFER, $transfer->getKey(), 'Guest asked for a view', true, true);

    expect($transfer)->toBeInstanceOf(TransferReason::class)
        ->and($transfer->fresh()?->needs_manager)->toBeTrue()
        ->and($expense)->toBeInstanceOf(ExpenseCategory::class)
        ->and($void)->toBeInstanceOf(Reason::class)
        ->and($void->kind)->toBe(ReasonKind::VoidLine)
        ->and(AuditLog::query()->orderBy('id')->pluck('action')->all())
        ->toBe(['transfer_reason.created', 'expense_category.created', 'reason.created', 'transfer_reason.updated']);
});

it('refuses a duplicate in the same list, but not in another list', function (): void {
    $save = app(SaveListReason::class);

    expect(fn () => $save->handle($this->admin, $this->branchId, ReasonKind::Wastage->value, null, 'Other', false, false))->toThrow(ActionRefused::class, 'already in this list');
    expect($save->handle($this->admin, $this->branchId, SaveListReason::TRANSFER, null, 'Damaged', false, false))->toBeInstanceOf(TransferReason::class);
});

it('keeps at least one active reason in a list', function (): void {
    $rows = Reason::query()->where('branch_id', $this->branchId)->where('kind', ReasonKind::Variance)->get();
    Reason::query()->whereIn('id', $rows->skip(1)->pluck('id'))->update(['is_active' => false]);
    $last = $rows->first();

    expect(fn () => app(SaveListReason::class)->handle($this->admin, $this->branchId, ReasonKind::Variance->value, $last->id, $last->name, false, false, false))
        ->toThrow(ActionRefused::class, 'at least one active reason');
});

it('refuses a reason of another branch and an unknown list', function (): void {
    $other = Reason::factory()->create();

    expect(fn () => app(SaveListReason::class)->handle($this->admin, $this->branchId, $other->kind->value, $other->id, 'X', false, false))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(SaveListReason::class)->handle($this->admin, $this->branchId, 'payments', null, 'X', false, false))->toThrow(ActionRefused::class, 'no such reason list');
});

// HTTP -------------------------------------------------------------------------------------

it('shows the settings page (46) with every group, the shifts and the reason lists', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Settings')
        ->where('branch.hourly_enabled', true)
        ->where('branch.deposit_amount', 20000)
        ->where('branch.shift_stale_block_minutes', 240)
        ->where('branch.vat_enabled', false)
        ->has('shifts', 2)
        ->has('lists', 2 + count(ReasonKind::cases()))
        ->where('lists.0.key', 'transfer_reasons'));
});

it('saves only the keys of the group sent', function (): void {
    $this->actingAs($this->admin)->from('/admin/settings')
        ->put('/admin/settings', ['group' => 'shifts', 'max_frontdesks_per_shift' => 3, 'shift_stale_block_minutes' => 200, 'deposit_amount' => 1])
        ->assertRedirect('/admin/settings');

    $branch = Hotel::branch();
    expect($branch->max_frontdesks_per_shift)->toBe(3)
        ->and($branch->deposit_amount?->centavos())->toBe(20000);

    $this->put('/admin/settings', ['group' => 'money', 'payment_methods' => []])->assertSessionHasErrors('payment_methods');
});

it('adds a reason over HTTP', function (): void {
    $this->actingAs($this->admin)->from('/admin/settings')
        ->post('/admin/lists/cancel_stay', ['name' => 'Wrong guest name typed', 'needs_note' => true, 'needs_manager' => true, 'is_active' => true])
        ->assertRedirect('/admin/settings');

    expect(Reason::query()->where('kind', ReasonKind::CancelStay)->where('name', 'Wrong guest name typed')->sole()->needs_manager)->toBeTrue();
    $this->post('/admin/lists/payments', ['name' => 'X', 'needs_note' => false, 'needs_manager' => false, 'is_active' => true])->assertNotFound();
});

// Hourly stays off -------------------------------------------------------------------------

it('removes the hourly options from check-in (6), the kiosk (28) and the rate table (45) when hourly stays are switched off', function (): void {
    $this->actingAs($this->admin)->put('/admin/settings', ['group' => 'stays', 'hourly_enabled' => false])->assertRedirect();
    expect(Hotel::branch()->hourly_enabled)->toBeFalse();

    // 45: only the Overnight column is left.
    $this->get('/admin/rates')->assertInertia(fn (Assert $page) => $page
        ->where('columns', fn ($c) => collect($c)->pluck('key')->all() === ['overnight']));

    // 6: no hourly option offered at check-in.
    Hotel::openShift();
    $this->actingAs(Hotel::frontdesk())->get('/stays/create?room='.Hotel::room('205')->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Create')
        ->where('options', fn ($options) => collect($options)->where('kind', 'hourly')->isEmpty()
            && collect($options)->pluck('key')->doesntContain(fn ($k) => str_starts_with((string) $k, 'hourly'))
            && collect($options)->isNotEmpty()));

    expect(fn () => Hotel::checkIn('205', 12))->toThrow(InvalidStayOption::class, 'Hourly stays are not sold');

    // 28: the kiosk offers no hourly option (it may still offer long stay).
    $this->actingAs(Hotel::user('kiosk-gensan-1'));
    $this->get('/kiosk/start')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Kiosk/PickRoom')
        ->where('types', fn ($types) => collect($types)->every(fn ($t) => $t['options'] === [])));
});

it('brings the hourly options back when hourly stays are switched on again', function (): void {
    ($this->update)(['hourly_enabled' => false]);
    ($this->update)(['hourly_enabled' => true]);

    $this->actingAs($this->admin)->get('/admin/rates')->assertInertia(fn (Assert $page) => $page
        ->where('columns', fn ($c) => collect($c)->pluck('key')->contains('hourly-6')));
    expect(Branch::query()->find($this->branchId)?->hourly_enabled)->toBeTrue();
});
