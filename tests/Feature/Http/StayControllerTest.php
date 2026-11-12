<?php

declare(strict_types=1);

use App\Actions\Shifts\JoinShift;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\ReasonKind;
use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\Branch;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Stay;
use App\Models\User;
use App\Queries\StayQuery;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| StayController (6, 8–18) with the problem states 52 and 53: happy path, validation and
| permission for every method. The rules themselves are tested on the actions (tests/Feature/Stays).
| Room 205 is Deluxe: 12 hours ₱650, key deposit ₱200.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00'); // 9:12 AM in Manila
    $this->shift = Hotel::openShift();
    $this->device = (string) Str::uuid();
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device);
    $this->stays = app(StayQuery::class);
});

/** @return array<string, mixed> */
function checkInForm(array $overrides = []): array
{
    return [
        'room_id' => Hotel::room('205')->id,
        'guest_name' => 'Rico Manalastas',
        'guest_mobile' => '09175550142',
        'kind' => 'hourly',
        'quantity' => 12,
        'method' => 'cash',
        'tendered' => 100000,
        'keep_change' => false,
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/** @return array{approver_id: int, pin: string} */
function approval(string $pin = Hotel::PIN): array
{
    return ['approver_id' => Hotel::supervisor()->id, 'pin' => $pin];
}

// 6 · 52 -----------------------------------------------------------------------------------

it('shows walk-in check-in (6) with every option priced by the server', function (): void {
    $this->get('/stays/create?room='.Hotel::room('205')->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Stays/Create')
            ->where('room.number', '205')
            ->where('room.type', 'Deluxe')
            ->where('options.1.key', 'hourly-12')
            ->where('options.1.price', 65000)
            ->where('options.1.choices.0.expected_checkout_at', '2026-11-12T13:12:00+00:00')
            ->where('deposit.amount', 20000)
            ->where('methods', [['value' => 'cash', 'label' => 'Cash', 'needs_reference' => false], ['value' => 'gcash', 'label' => 'GCash', 'needs_reference' => true]]));
});

it('greys out an option without a rate (52)', function (): void {
    $this->get('/stays/create?room='.Hotel::room('3A')->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('options.2.key', 'hourly-18')
            ->where('options.2.disabled', true)
            ->where('options.2.price', null)
            ->where('options.2.missing', 'There is no 18-hour rate for Suite rooms.'));
});

it('sends an occupied room back to the board', function (): void {
    Hotel::checkIn('205');

    $this->get('/stays/create?room='.Hotel::room('205')->id)->assertRedirect('/board');
});

it('checks a guest in, shows the slip (8) once, then the folio (9)', function (): void {
    $this->post('/stays', checkInForm())->assertRedirect();

    $stay = Stay::query()->sole();
    expect($stay->state)->toBe(StayState::CheckedIn)
        ->and($stay->guest_mobile)->toBe('09175550142')
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Occupied);

    $this->get('/stays/'.$stay->id)->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Slip')
        ->where('slip.total', 85000)
        ->where('slip.tendered', 100000)
        ->where('slip.change', 15000)
        ->where('slip.by', 'Jessa Mae D.'));

    $this->get('/stays/'.$stay->id)->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Show')
        ->where('summary.balance', 0)
        ->where('summary.deposit_held', 20000)
        ->where('lines.0.description', 'Deluxe 12 hrs')
        ->where('lines.0.by', 'Jessa Mae'));
});

it('returns the first stay for a repeated idempotency key', function (): void {
    $form = checkInForm();

    $this->post('/stays', $form);
    $this->post('/stays', $form)->assertRedirect('/stays/'.Stay::query()->sole()->id);

    expect(Stay::query()->count())->toBe(1);
});

it('validates the check-in form', function (array $bad, string $field): void {
    $this->post('/stays', checkInForm($bad))->assertSessionHasErrors($field);

    expect(Stay::query()->count())->toBe(0);
})->with([
    'no name' => [['guest_name' => ''], 'guest_name'],
    'short mobile' => [['guest_mobile' => '0917555'], 'guest_mobile'],
    'no key' => [['idempotency_key' => 'abc'], 'idempotency_key'],
    'cash without amount' => [['tendered' => null], 'tendered'],
    'GCash without reference' => [['method' => 'gcash', 'reference_no' => ''], 'reference_no'],
    'unknown kind' => [['kind' => 'weekly'], 'kind'],
]);

it('refuses cash that does not cover the total and saves nothing', function (): void {
    $this->from('/stays/create')->post('/stays', checkInForm(['tendered' => 50000]))->assertRedirect('/stays/create');

    expect(session('inertia.flash_data.error.message'))->toBe('The amount received is less than the amount to collect.')
        ->and(Stay::query()->count())->toBe(0);
});

it('turns a room taken a moment ago into dialog 53 with who took it and the next free room', function (): void {
    Hotel::checkIn('205', by: Hotel::user('rhea.bautista'));
    app(JoinShift::class)->handle(Hotel::user('rhea.bautista'), Hotel::branch()->id);
    $url = '/stays/create?room='.Hotel::room('205')->id;

    $this->from($url)->post('/stays', checkInForm())->assertRedirect($url);

    expect(session('inertia.flash_data.error'))->toMatchArray([
        'code' => 'room_taken',
        'room' => '205',
        'taken_by' => 'Rhea Bautista',
        'type' => 'Deluxe',
        'next_room' => '201',
    ])->and(Stay::query()->count())->toBe(1);

    // Back on the form, the page stays (with the guest's details) under the dialog.
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Stays/Create'));
});

it('refuses check-in to roles without front desk work', function (): void {
    $this->actingAs(Hotel::user('grace.tolentino'));

    $this->get('/stays/create?room='.Hotel::room('205')->id)->assertForbidden();
    $this->post('/stays', checkInForm())->assertForbidden();
});

// 9 · 10 · 11 ------------------------------------------------------------------------------

it('adds a charge (10) and takes it out of stock', function (): void {
    $stay = Hotel::checkIn('205');
    $noodles = Hotel::product('Cup noodles');

    $this->post("/stays/{$stay->id}/charges", ['items' => [$noodles->id => 2], 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect('/stays/'.$stay->id);

    expect($this->stays->balanceDue($stay->id)->centavos())->toBe(9000)
        ->and($noodles->fresh()->stock_on_hand)->toBe(28);
});

it('adds a charge and takes the payment now in one go', function (): void {
    $stay = Hotel::checkIn('205');

    $this->post("/stays/{$stay->id}/charges", ['items' => [Hotel::product('Cup noodles')->id => 2], 'pay_now' => true, 'method' => 'cash', 'amount' => 10000, 'idempotency_key' => (string) Str::uuid()]);

    expect($this->stays->balanceDue($stay->id)->centavos())->toBe(0);
});

it('validates the charge form and refuses an item out of stock', function (): void {
    $stay = Hotel::checkIn('205');

    $this->post("/stays/{$stay->id}/charges", ['items' => [], 'idempotency_key' => 'x'])->assertSessionHasErrors(['items', 'idempotency_key']);
    $this->post("/stays/{$stay->id}/charges", ['items' => [1 => 1], 'pay_now' => true, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['method', 'amount']);

    $this->post("/stays/{$stay->id}/charges", ['items' => [Hotel::product('Coke 1.5 L')->id => 1], 'idempotency_key' => (string) Str::uuid()]);
    expect(session('inertia.flash_data.error.code'))->toBe('insufficient_stock')
        ->and($this->stays->balanceDue($stay->id)->centavos())->toBe(0);
});

it('takes a payment (11), says the change, and returns the first payment for a repeated key', function (): void {
    $stay = Hotel::checkIn('205');
    $this->post("/stays/{$stay->id}/charges", ['items' => [Hotel::product('Cup noodles')->id => 2, Hotel::product('Bottled water 500 ml')->id => 1], 'idempotency_key' => (string) Str::uuid()]);
    $form = ['method' => 'cash', 'amount' => 20000, 'idempotency_key' => (string) Str::uuid()];

    $this->post("/stays/{$stay->id}/payments", $form)->assertRedirect('/stays/'.$stay->id);
    expect(session('inertia.flash_data.toast.message'))->toBe('Payment saved. Change ₱85.00');

    $count = Payment::query()->count();
    $this->post("/stays/{$stay->id}/payments", $form);

    expect(Payment::query()->count())->toBe($count)
        ->and($this->stays->balanceDue($stay->id)->centavos())->toBe(0);
});

it('validates the payment form and refuses a payment when nothing is due', function (): void {
    $stay = Hotel::checkIn('205');

    $this->post("/stays/{$stay->id}/payments", ['method' => 'bitcoin', 'amount' => 0])->assertSessionHasErrors(['method', 'amount', 'idempotency_key']);
    $this->post("/stays/{$stay->id}/payments", ['method' => 'gcash', 'amount' => 100, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('reference_no');

    $this->post("/stays/{$stay->id}/payments", ['method' => 'cash', 'amount' => 100, 'idempotency_key' => (string) Str::uuid()]);
    expect(session('inertia.flash_data.error.message'))->toBe('There is nothing to pay on this folio.');
});

it('sends the folio sheets what they need: products, reasons, approvers and the cancel preview', function (): void {
    $stay = Hotel::checkIn('205');

    $this->get('/stays/'.$stay->id)->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Show')
        ->where('cancel.refund', 85000)
        ->where('reasons.void_line.0.name', 'Charged to the wrong room')
        ->where('approvers.0.name', 'Carol Villanueva')
        ->has('products'));
});

it('refuses the folio to roles without front desk work, and to another branch', function (): void {
    $stay = Hotel::checkIn('205');

    $this->actingAs(Hotel::user('dodong.lumayag'))->get('/stays/'.$stay->id)->assertForbidden();

    $other = Branch::factory()->create();
    $stranger = User::factory()->create(['branch_id' => $other->id, 'role' => 'supervisor']);
    expect(Gate::forUser($stranger)->allows('view', $stay))->toBeFalse()
        ->and(Gate::forUser(Hotel::frontdesk())->allows('view', $stay))->toBeTrue();
});

// 15 · 14 ----------------------------------------------------------------------------------

it('voids a line (15) after a supervisor PIN (14)', function (): void {
    $stay = Hotel::checkIn('205');
    $this->post("/stays/{$stay->id}/charges", ['items' => [Hotel::product('Cup noodles')->id => 2], 'idempotency_key' => (string) Str::uuid()]);
    $line = FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::FoodDrink)->sole();

    $this->post("/stays/{$stay->id}/lines/{$line->id}/void", ['reason_id' => Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount')->id, ...approval()])
        ->assertRedirect('/stays/'.$stay->id);

    expect(FolioLine::query()->where('reversal_of_id', $line->id)->exists())->toBeTrue()
        ->and($this->stays->balanceDue($stay->id)->centavos())->toBe(0);
});

it('saves nothing on a wrong approval PIN and says so', function (): void {
    $stay = Hotel::checkIn('205');
    $line = FolioLine::query()->where('stay_id', $stay->id)->sole();

    $this->from('/stays/'.$stay->id)->post("/stays/{$stay->id}/lines/{$line->id}/void", ['reason_id' => Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount')->id, ...approval('9999')])
        ->assertRedirect('/stays/'.$stay->id);

    expect(session('inertia.flash_data.error.code'))->toBe('pin_invalid')
        ->and(FolioLine::query()->where('reversal_of_id', $line->id)->exists())->toBeFalse();
});

it('validates the void and cancel forms', function (): void {
    $stay = Hotel::checkIn('205');
    $line = FolioLine::query()->where('stay_id', $stay->id)->sole();

    $this->post("/stays/{$stay->id}/lines/{$line->id}/void", [])->assertSessionHasErrors(['reason_id', 'approver_id', 'pin']);
    $this->post("/stays/{$stay->id}/cancel", ['reason_id' => 1, 'approver_id' => 1, 'pin' => '12'])->assertSessionHasErrors('pin');

    // "Other" needs a note.
    $this->post("/stays/{$stay->id}/cancel", ['reason_id' => Hotel::reason(ReasonKind::CancelStay)->id, ...approval()]);
    expect($stay->fresh()->state)->toBe(StayState::CheckedIn);
});

it('cancels a stay (15) with approval and frees the room', function (): void {
    $stay = Hotel::checkIn('205');

    $this->post("/stays/{$stay->id}/cancel", ['reason_id' => Hotel::reason(ReasonKind::CancelStay, 'Wrong room checked in')->id, ...approval()])
        ->assertRedirect('/board');

    expect($stay->fresh()->state)->toBe(StayState::Cancelled)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Available);
});

it('refuses voids and cancels to roles without front desk work', function (): void {
    $stay = Hotel::checkIn('205');
    $line = FolioLine::query()->where('stay_id', $stay->id)->sole();
    $this->actingAs(Hotel::user('ramil.ortega'));

    $this->post("/stays/{$stay->id}/lines/{$line->id}/void", ['reason_id' => 1, ...approval()])->assertForbidden();
    $this->post("/stays/{$stay->id}/cancel", ['reason_id' => 1, ...approval()])->assertForbidden();
});

// 12 ---------------------------------------------------------------------------------------

it('shows extend (12) with the price worked out and extends with payment', function (): void {
    $stay = Hotel::checkIn('205');

    $this->get("/stays/{$stay->id}/extend")->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Extend')
        ->where('options.1.hours', 6)
        ->where('options.1.amount', 25000)
        ->where('options.3.amount', 105000)
        ->where('options.3.parts.0.amount', 40000)
        ->where('cycle_hours_used', 12));

    $this->post("/stays/{$stay->id}/extend", ['hours' => 6, 'pay_now' => true, 'method' => 'cash', 'amount' => 25000])->assertRedirect('/stays/'.$stay->id);

    expect($stay->fresh()->expected_checkout_at->toDateTimeString())->toBe('2026-11-12 19:12:00')
        ->and($this->stays->balanceDue($stay->id)->centavos())->toBe(0);
});

it('validates the extension and refuses it on a stay that has left', function (): void {
    $stay = Hotel::checkIn('205');

    $this->post("/stays/{$stay->id}/extend", ['hours' => 0])->assertSessionHasErrors('hours');
    $this->post("/stays/{$stay->id}/extend", ['hours' => 6, 'pay_now' => true])->assertSessionHasErrors(['method', 'amount']);

    $this->post("/stays/{$stay->id}/checkout", ['key_returned' => true, 'remote_returned' => true, 'deposit_claimed' => true]);
    $this->get("/stays/{$stay->id}/extend")->assertRedirect('/stays/'.$stay->id);
});

it('refuses extend to roles without front desk work', function (): void {
    $stay = Hotel::checkIn('205');
    $this->actingAs(Hotel::user('grace.tolentino'));

    $this->get("/stays/{$stay->id}/extend")->assertForbidden();
    $this->post("/stays/{$stay->id}/extend", ['hours' => 6])->assertForbidden();
});

// 13 ---------------------------------------------------------------------------------------

it('shows transfer (13) with free rooms and their price difference, and transfers with approval', function (): void {
    $stay = Hotel::checkIn('108', 12); // Standard 12 hrs ₱500

    $this->get("/stays/{$stay->id}/transfer")->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Transfer')
        ->where('charged', 50000)
        ->where('rooms.0.number', '101')
        ->where('rooms.0.difference', 0)
        ->where('reasons.0.name', 'Aircon not cooling'));

    $this->post("/stays/{$stay->id}/transfer", [
        'room_id' => Hotel::room('212')->id,
        'reason_id' => Hotel::transferReason()->id,
        'old_room' => 'uncleaned',
        'method' => 'cash',
        'amount' => 15000,
        ...approval(),
    ])->assertRedirect('/stays/'.$stay->id);

    expect($stay->fresh()->room_id)->toBe(Hotel::room('212')->id)
        ->and(Hotel::room('108')->status)->toBe(RoomStatus::Uncleaned)
        ->and($this->stays->balanceDue($stay->id)->centavos())->toBe(0);
});

it('validates the transfer, and a wrong PIN saves nothing', function (): void {
    $stay = Hotel::checkIn('108', 12);

    $this->post("/stays/{$stay->id}/transfer", ['old_room' => 'garden'])->assertSessionHasErrors(['room_id', 'reason_id', 'old_room', 'approver_id', 'pin']);
    $this->post("/stays/{$stay->id}/transfer", ['room_id' => Hotel::room('109')->id, 'reason_id' => Hotel::transferReason()->id, 'old_room' => 'uncleaned', ...approval('0000')]);

    expect(session('inertia.flash_data.error.code'))->toBe('pin_invalid')
        ->and($stay->fresh()->room_id)->toBe(Hotel::room('108')->id);
});

it('turns a transfer to a room taken a moment ago into dialog 53', function (): void {
    $stay = Hotel::checkIn('108', 12);
    Hotel::checkIn('109', 12);

    $this->post("/stays/{$stay->id}/transfer", ['room_id' => Hotel::room('109')->id, 'reason_id' => Hotel::transferReason()->id, 'old_room' => 'uncleaned', ...approval()]);

    expect(session('inertia.flash_data.error'))->toMatchArray(['code' => 'room_taken', 'room' => '109'])
        ->and($stay->fresh()->room_id)->toBe(Hotel::room('108')->id);
});

it('refuses transfer to roles without front desk work', function (): void {
    $stay = Hotel::checkIn('108', 12);
    $this->actingAs(Hotel::user('dodong.lumayag'));

    $this->get("/stays/{$stay->id}/transfer")->assertForbidden();
});

// 16 · 17 · 18 -----------------------------------------------------------------------------

it('works out step 2 of check-out (17) from the step 1 answers', function (): void {
    $stay = Hotel::checkIn('205');

    $this->get("/stays/{$stay->id}/checkout")->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Checkout')
        ->where('screen.lost.key', 10000)
        ->where('screen.settle.return', 20000));

    $glass = Hotel::product('Broken glass');
    $this->get("/stays/{$stay->id}/checkout?key=0&remote=1&damages[{$glass->id}]=1")->assertInertia(fn (Assert $page) => $page
        ->where('screen.settle.damages', 15000)
        ->where('screen.settle.less_damages', 15000)
        ->where('screen.settle.balance', 0)
        ->where('screen.settle.return', 5000));
});

it('checks the guest out (16–18) and shows the done view, and a second press does nothing', function (): void {
    $stay = Hotel::checkIn('205');
    $form = ['key_returned' => false, 'remote_returned' => true, 'damages' => [], 'deposit_claimed' => true];

    $this->post("/stays/{$stay->id}/checkout", $form)->assertRedirect("/stays/{$stay->id}/checkout");
    $count = Payment::query()->count();
    $this->post("/stays/{$stay->id}/checkout", $form);

    expect($stay->fresh()->state)->toBe(StayState::CheckedOut)
        ->and(Payment::query()->count())->toBe($count)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Uncleaned);

    $this->get("/stays/{$stay->id}/checkout")->assertInertia(fn (Assert $page) => $page
        ->where('screen.done.deposit_returned', 10000)
        ->where('screen.done.room_status', 'uncleaned')
        ->where('screen.done.clean_by', '2026-11-12T05:12:00+00:00'));
});

it('validates check-out and refuses it with a balance left', function (): void {
    $stay = Hotel::checkIn('205');
    $this->post("/stays/{$stay->id}/charges", ['items' => [Hotel::product('Cup noodles')->id => 2], 'idempotency_key' => (string) Str::uuid()]);

    $this->post("/stays/{$stay->id}/checkout", [])->assertSessionHasErrors(['key_returned', 'remote_returned', 'deposit_claimed']);
    $this->post("/stays/{$stay->id}/checkout", ['key_returned' => true, 'remote_returned' => true, 'deposit_claimed' => true]);

    expect(session('inertia.flash_data.error.code'))->toBe('balance_not_zero')
        ->and($stay->fresh()->state)->toBe(StayState::CheckedIn);

    $this->post("/stays/{$stay->id}/checkout", ['key_returned' => true, 'remote_returned' => true, 'deposit_claimed' => false, 'method' => 'cash', 'amount' => 9000]);
    expect($stay->fresh()->state)->toBe(StayState::CheckedOut)
        ->and(Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::DepositApplied)->exists())->toBeTrue();
});

it('refuses check-out to roles without front desk work', function (): void {
    $stay = Hotel::checkIn('205');
    $this->actingAs(Hotel::user('ramon.alvarez'));

    $this->get("/stays/{$stay->id}/checkout")->assertForbidden();
    $this->post("/stays/{$stay->id}/checkout", ['key_returned' => true, 'remote_returned' => true, 'deposit_claimed' => true])->assertForbidden();
});
