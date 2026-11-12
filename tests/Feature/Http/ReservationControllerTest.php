<?php

declare(strict_types=1);

use App\Actions\Stays\ReserveRoom;
use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReasonKind;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\Payment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Queries\StayQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| ReservationController (57, 58, detail sheet) and check-in from a reservation (6): happy path,
| validation and permission. The rules are tested on the actions (tests/Feature/Stays/ReservationTest).
| The "Done when" chain — a reservation blocks its room for its dates and its money paid ahead
| comes off the bill at check-in — is the last test. Deluxe overnight ₱950, key deposit ₱200.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // Thu 12 Nov, 9:00 AM in Manila
    $this->shift = Hotel::openShift();
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, (string) Str::uuid());
    $this->deluxe = RoomType::query()->where('name', 'Deluxe')->firstOrFail();
    $this->reserve = fn (?string $room, string $arrival, ?int $paid = null, string $name = 'Maria Lourdes Fajardo'): Stay => app(ReserveRoom::class)->handle(
        Hotel::frontdesk(), $name, null, $this->deluxe, $room === null ? null : Hotel::room($room), StayKind::Overnight, 1,
        CarbonImmutable::parse($arrival), (string) Str::uuid(), $paid === null ? null : PaymentMethod::Cash, $paid === null ? null : Money::ofCentavos($paid),
    );
});

/** @return array<string, mixed> */
function reservationForm(array $overrides = []): array
{
    return [
        'guest_name' => 'Maria Lourdes Fajardo',
        'guest_mobile' => '0918 555 0177',
        'arrival_date' => '2026-11-13',
        'arrival_time' => '14:00',
        'kind' => 'overnight',
        'quantity' => 2,
        'room_type_id' => RoomType::query()->where('name', 'Deluxe')->value('id'),
        'room_id' => Hotel::room('214')->id,
        'paid_amount' => 100000,
        'method' => 'gcash',
        'reference_no' => '3012 884 517',
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

it('lists today’s reservations with paid ahead and status, the tab counts and the rooms free (57)', function (): void {
    ($this->reserve)('214', '2026-11-12 06:00:00', 100000);
    ($this->reserve)(null, '2026-11-12 10:00:00', name: 'Jerome Pascual');
    ($this->reserve)('215', '2026-11-13 06:00:00', name: 'Gloria Navarro');

    $this->get('/reservations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reservations/Index')
        ->where('tab', 'today')
        ->has('rows', 2)
        ->where('rows.0.guest', 'Maria Lourdes Fajardo')
        ->where('rows.0.room', '214 · Deluxe')
        ->where('rows.0.stay', '1 night')
        ->where('rows.0.paid_ahead', 100000)
        ->where('rows.0.status', 'Arriving')
        ->where('rows.0.can_check_in', true)
        ->where('rows.1.room', 'Any Deluxe')
        ->where('counts', ['today' => 2, 'upcoming' => 1])
        ->where('days.0.label', 'Thu 12')
        ->where('free.types.1', ['name' => 'Deluxe', 'free' => 16, 'of' => 18])
        ->where('detail', null)
        ->missing('rows.0.expected_cash'));

    $this->get('/reservations?tab=upcoming')->assertInertia(fn (Assert $page) => $page
        ->has('rows', 1)->where('rows.0.status', 'Confirmed')->where('rows.0.can_check_in', false));
});

it('opens the detail sheet of a reservation with what can be done', function (): void {
    $stay = ($this->reserve)('214', '2026-11-13 06:00:00', 100000);

    $this->get("/reservations/{$stay->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reservations/Index')
        ->where('detail.code', $stay->code)
        ->where('detail.booked_amount', 95000)
        ->where('detail.held', 100000)
        ->where('detail.paid_method', 'Cash')
        ->where('detail.can_cancel', true)
        ->where('detail.can_settle', false)
        ->where('detail.reasons.0.name', 'Booked by mistake')
        ->where('detail.approvers.0.name', 'Carol Villanueva'));
});

it('shows the new reservation form and checks a room for the dates as it is filled in (58)', function (): void {
    ($this->reserve)('214', '2026-11-13 06:00:00');

    $this->get('/reservations/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reservations/Create')
        ->where('types.1.label', 'Deluxe')
        ->where('options.4.label', '1 night')
        ->where('today', '2026-11-12')
        ->where('quote', null));

    $q = ['room_type_id' => $this->deluxe->id, 'kind' => 'overnight', 'quantity' => 2, 'arrival_date' => '2026-11-13', 'arrival_time' => '14:00'];
    $this->get('/reservations/create?'.http_build_query([...$q, 'room_id' => Hotel::room('215')->id]))->assertInertia(fn (Assert $page) => $page
        ->where('quote.ok', true)
        ->where('quote.message', 'Room 215 is free for all 2 nights. Check-out Sun 15 Nov, 12:00 PM.')
        ->where('quote.room_amount', 190000)
        ->where('quote.total', 210000));
    $this->get('/reservations/create?'.http_build_query([...$q, 'room_id' => Hotel::room('214')->id]))->assertInertia(fn (Assert $page) => $page
        ->where('quote.ok', false)
        ->where('quote.message', 'Room 214 is not free for those dates. Pick another room or other dates.'));
    $this->get('/reservations/create?'.http_build_query($q))->assertInertia(fn (Assert $page) => $page
        ->where('quote.ok', true)
        ->where('quote.message', '17 Deluxe rooms are free for all 2 nights. Check-out Sun 15 Nov, 12:00 PM.'));
});

it('saves a reservation with money paid ahead and goes back to the list', function (): void {
    $this->post('/reservations', reservationForm())->assertRedirect('/reservations?tab=upcoming');

    $stay = Stay::query()->sole();
    expect($stay->state)->toBe(StayState::Reserved)
        ->and($stay->arrival_at?->toDateTimeString())->toBe('2026-11-13 06:00:00')
        ->and($stay->nights)->toBe(2)
        ->and($stay->guest_mobile)->toBe('09185550177')
        ->and(session('inertia.flash_data.toast.message'))->toBe("Reservation saved for Maria Lourdes Fajardo · {$stay->code}.");

    $payment = Payment::query()->sole();
    expect($payment->kind)->toBe(PaymentKind::DepositIn)
        ->and($payment->amount?->centavos())->toBe(100000)
        ->and($payment->reference_no)->toBe('3012 884 517')
        ->and($payment->shift_session_id)->toBe($this->shift->id);
});

it('saves one reservation for a double click', function (): void {
    $form = reservationForm();

    $this->post('/reservations', $form);
    $this->post('/reservations', $form);

    expect(Stay::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1);
});

it('says the room is not free for those dates and saves nothing', function (): void {
    ($this->reserve)('214', '2026-11-13 06:00:00');

    $this->from('/reservations/create')->post('/reservations', reservationForm())->assertRedirect('/reservations/create');
    expect(session('inertia.flash_data.error.code'))->toBe('not_available')
        ->and(session('inertia.flash_data.error.message'))->toBe('Room 214 is not free for those dates. Pick another room or other dates.')
        ->and(Stay::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0);
});

it('validates the reservation form', function (array $bad, string $field): void {
    $this->post('/reservations', reservationForm($bad))->assertSessionHasErrors($field);
    expect(Stay::query()->count())->toBe(0);
})->with([
    'no name' => [['guest_name' => ''], 'guest_name'],
    'short mobile' => [['guest_mobile' => '0918 55'], 'guest_mobile'],
    'no arrival' => [['arrival_date' => ''], 'arrival_date'],
    'bad time' => [['arrival_time' => '2pm'], 'arrival_time'],
    'bad kind' => [['kind' => 'weekly'], 'kind'],
    'no type' => [['room_type_id' => null], 'room_type_id'],
    'paid without a method' => [['method' => null], 'method'],
    'gcash without a reference' => [['reference_no' => null], 'reference_no'],
    'no key' => [['idempotency_key' => ''], 'idempotency_key'],
]);

it('cancels a reservation through the approval sheet and hands back the money', function (): void {
    $stay = ($this->reserve)('214', '2026-11-13 06:00:00', 100000);
    $reason = Hotel::reason(ReasonKind::CancelReservation, 'Guest cancelled');

    $this->post("/reservations/{$stay->id}/cancel", ['reason_id' => $reason->id, 'refund' => true, 'approver_id' => Hotel::supervisor()->id, 'pin' => Hotel::PIN])
        ->assertRedirect('/reservations');

    expect($stay->fresh()->state)->toBe(StayState::Cancelled)
        ->and(Payment::query()->where('kind', PaymentKind::DepositRefund)->value('amount')?->centavos())->toBe(-100000);
});

it('keeps the reservation on a wrong PIN, with the tries left', function (): void {
    $stay = ($this->reserve)('214', '2026-11-13 06:00:00', 100000);
    $reason = Hotel::reason(ReasonKind::CancelReservation, 'Guest cancelled');

    $this->post("/reservations/{$stay->id}/cancel", ['reason_id' => $reason->id, 'refund' => true, 'approver_id' => Hotel::supervisor()->id, 'pin' => '9999']);

    expect(session('inertia.flash_data.error.code'))->toBe('pin_invalid')->and($stay->fresh()->state)->toBe(StayState::Reserved);
});

it('settles a no-show’s money paid ahead', function (): void {
    $stay = ($this->reserve)('214', '2026-11-12 02:00:00', 100000);
    $this->travelClock(3 * 60);
    $this->artisan('homi:release-no-shows');
    $reason = Hotel::reason(ReasonKind::CancelReservation, 'Guest cancelled');

    $this->get("/reservations/{$stay->id}")->assertInertia(fn (Assert $page) => $page->where('detail.status', 'No-show')->where('detail.can_settle', true)->where('detail.can_check_in', true));
    $this->post("/reservations/{$stay->id}/refund", ['reason_id' => $reason->id, 'refund' => false, 'approver_id' => Hotel::supervisor()->id, 'pin' => Hotel::PIN])
        ->assertRedirect('/reservations?tab=today');

    expect(app(StayQuery::class)->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue();
    $this->get("/reservations/{$stay->id}")->assertInertia(fn (Assert $page) => $page->where('detail.can_settle', false)->where('detail.can_check_in', false));
});

it('validates the cancel and settle forms', function (): void {
    $stay = ($this->reserve)('214', '2026-11-13 06:00:00');

    $this->post("/reservations/{$stay->id}/cancel", [])->assertSessionHasErrors(['reason_id', 'refund', 'approver_id', 'pin']);
    $this->post("/reservations/{$stay->id}/refund", ['pin' => '12'])->assertSessionHasErrors('pin');
});

it('opens the check-in (6) filled in from the reservation, with the money paid ahead', function (): void {
    $stay = ($this->reserve)('214', '2026-11-12 06:00:00', 50000);

    $this->get("/stays/create?reservation={$stay->id}")->assertRedirect("/stays/create?reservation={$stay->id}&room=".Hotel::room('214')->id);
    $this->get("/stays/create?reservation={$stay->id}&room=".Hotel::room('214')->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Create')
        ->where('room.number', '214')
        ->where('reservation.guest_name', 'Maria Lourdes Fajardo')
        ->where('reservation.option_key', 'overnight')
        ->where('reservation.paid_ahead', 50000)
        ->where('rooms', []));

    // An "any room" reservation opens on the first free room of its type, with the others to pick from.
    $any = ($this->reserve)(null, '2026-11-12 06:00:00', name: 'Jerome Pascual');
    $this->get("/stays/create?reservation={$any->id}")->assertRedirect("/stays/create?reservation={$any->id}&room=".Hotel::room('201')->id);
    $this->get("/stays/create?reservation={$any->id}&room=".Hotel::room('201')->id)->assertInertia(fn (Assert $page) => $page
        ->where('reservation.any_room', true)->where('rooms.0.label', 'Room 201'));
});

it('refuses the reservations pages to other roles', function (string $username): void {
    $stay = ($this->reserve)('214', '2026-11-13 06:00:00');
    $this->actingAs(Hotel::user($username));

    $this->get('/reservations')->assertForbidden();
    $this->get('/reservations/create')->assertForbidden();
    $this->post('/reservations', reservationForm(['room_id' => Hotel::room('215')->id]))->assertForbidden();
    $this->post("/reservations/{$stay->id}/cancel", ['reason_id' => 1, 'refund' => true, 'approver_id' => 1, 'pin' => '1234'])->assertForbidden();
    expect(Stay::query()->count())->toBe(1);
})->with(['kiosk-gensan-1', 'dodong.lumayag', 'ramil.ortega']);

it('hides and refuses reservations when the module is off', function (): void {
    $this->get('/today')->assertInertia(fn (Assert $page) => $page->where('nav.0.items.2.key', 'reservations'));

    Hotel::branch()->update(['reservations_enabled' => false]);

    $this->get('/reservations')->assertForbidden();
    $this->post('/reservations', reservationForm())->assertForbidden();
    $this->get('/today')->assertInertia(fn (Assert $page) => $page->where('nav.0.items.2.key', 'pos'));
});

it('shows "Reserved · 2:00 PM" on the board row of a room reserved today', function (): void {
    ($this->reserve)('214', '2026-11-12 06:00:00');

    $this->get('/board')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(function (Assert $reload): void {
        $reload->where('board.floors.1.rooms.12.number', '214')
            ->where('board.floors.1.rooms.12.status', 'vacant')
            ->where('board.floors.1.rooms.12.reservedAt', '2026-11-12T06:00:00+00:00');
    }));
});

it('Done when: a reservation blocks its room for its dates and its money paid ahead comes off the bill at check-in', function (): void {
    $this->post('/reservations', reservationForm(['arrival_date' => '2026-11-12', 'arrival_time' => '14:00', 'quantity' => 1, 'paid_amount' => 50000]))->assertRedirect('/reservations?tab=today');
    $stay = Stay::query()->sole();

    // Blocked: a second reservation and a walk-in running into it are refused.
    $this->post('/reservations', reservationForm(['arrival_date' => '2026-11-12', 'arrival_time' => '20:00', 'quantity' => 1, 'paid_amount' => null, 'method' => null, 'reference_no' => null]));
    expect(session('inertia.flash_data.error.code'))->toBe('not_available');
    $this->post('/stays', [
        'room_id' => Hotel::room('214')->id, 'guest_name' => 'Walk In', 'kind' => 'hourly', 'quantity' => 6,
        'method' => 'cash', 'tendered' => 65000, 'idempotency_key' => (string) Str::uuid(),
    ]);
    expect(session('inertia.flash_data.error.code'))->toBe('not_available')->and(Stay::query()->count())->toBe(1);

    // Check-in at 2:00 PM: ₱950 room − ₱500 paid ahead + ₱200 key deposit = ₱650 at the desk.
    $this->travelClock(5 * 60);
    $this->post('/stays', [
        'room_id' => Hotel::room('214')->id, 'reservation_id' => $stay->id, 'guest_name' => 'Maria Lourdes Fajardo', 'kind' => 'overnight', 'quantity' => 1,
        'method' => 'cash', 'tendered' => 65000, 'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect("/stays/{$stay->id}");

    $this->get("/stays/{$stay->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Stays/Slip')
        ->where('slip.total', 115000)
        ->where('slip.paid_ahead', 50000)
        ->where('slip.tendered', 65000)
        ->where('slip.change', 0)
        ->where('slip.kept_as_deposit', null));

    expect($stay->fresh()->state)->toBe(StayState::CheckedIn)
        ->and(app(StayQuery::class)->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and(Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::Payment)->value('amount')?->centavos())->toBe(45000);
});
