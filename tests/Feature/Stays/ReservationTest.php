<?php

declare(strict_types=1);

use App\Actions\Stays\CancelReservation;
use App\Actions\Stays\CheckInGuest;
use App\Actions\Stays\RefundNoShow;
use App\Actions\Stays\ReserveRoom;
use App\Enums\ApprovalAction;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReasonKind;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\NotAvailable;
use App\Exceptions\PinInvalid;
use App\Models\Approval;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Queries\RoomQuery;
use App\Queries\StayQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| Reservations (57, 58): ReserveRoom, check-in from a reservation, ReleaseNoShows,
| CancelReservation and RefundNoShow (APP_FLOW §4.19, DATABASE_SCHEMA §8.7, TRD §4.9) and the
| "Kiosk and reservations" tests of TRD §10 that are about reservations. Times are UTC; Manila
| is UTC+8. Deluxe overnight is ₱950, key deposit ₱200; overnight check-out is 12:00 NN.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // Thu 12 Nov, 9:00 AM in Manila
    $this->shift = Hotel::openShift();
    $this->deluxe = RoomType::query()->where('name', 'Deluxe')->firstOrFail();
    $this->stays = app(StayQuery::class);
    // Arrives Fri 13 Nov 2:00 PM Manila, one night, leaves Sat 14 Nov 12:00 NN.
    $this->reserve = fn (?string $room = '214', string $arrival = '2026-11-13 06:00:00', int $nights = 1, ?int $paid = null, ?string $key = null, PaymentMethod $method = PaymentMethod::Gcash, StayKind $kind = StayKind::Overnight): Stay => app(ReserveRoom::class)->handle(
        Hotel::frontdesk(), 'Maria Lourdes Fajardo', '09185550177', $this->deluxe, $room === null ? null : Hotel::room($room), $kind, $nights,
        CarbonImmutable::parse($arrival), $key ?? (string) Str::uuid(), $paid === null ? null : $method, $paid === null ? null : Money::ofCentavos($paid), $paid === null ? null : '3012884517',
    );
    $this->cancelReason = Hotel::reason(ReasonKind::CancelReservation, 'Guest cancelled');
});

it('reserves a room: a reserved stay with its code, dates and price, and no ledger row without money', function (): void {
    $counter = Hotel::branch()->next_stay_number;
    $stay = ($this->reserve)();

    expect($stay->state)->toBe(StayState::Reserved)
        ->and($stay->source)->toBe(StaySource::Reservation)
        ->and($stay->room_id)->toBe(Hotel::room('214')->id)
        ->and($stay->code)->toBe(sprintf('DGS-26-%06d', $counter))
        ->and($stay->arrival_at?->toDateTimeString())->toBe('2026-11-13 06:00:00')
        ->and($stay->expected_checkout_at?->toDateTimeString())->toBe('2026-11-14 04:00:00')
        ->and($stay->quoted_amount?->centavos())->toBe(95000)
        ->and($stay->active_room_id)->toBeNull()
        ->and(Payment::query()->count())->toBe(0)
        ->and(Hotel::room('214')->status->value)->toBe('available');
});

it('records money paid ahead as a guest deposit on the open shift, with method and reference', function (): void {
    $stay = ($this->reserve)(paid: 50000);
    $payment = Payment::query()->sole();

    expect($payment->kind)->toBe(PaymentKind::DepositIn)
        ->and($payment->deposit_type)->toBe(DepositType::Guest)
        ->and($payment->method)->toBe(PaymentMethod::Gcash)
        ->and($payment->reference_no)->toBe('3012884517')
        ->and($payment->shift_session_id)->toBe($this->shift->id)
        ->and($payment->created_by)->toBe(Hotel::frontdesk()->id)
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->centavos())->toBe(50000);
});

it('reservation blocks overlapping dates: the same room cannot be reserved twice for one night', function (): void {
    ($this->reserve)();

    expect(fn () => ($this->reserve)(arrival: '2026-11-13 10:00:00'))->toThrow(NotAvailable::class, 'Room 214 is not free for those dates.')
        ->and(fn () => ($this->reserve)(arrival: '2026-11-12 06:00:00', nights: 2))->toThrow(NotAvailable::class);

    // The night before and the night after are free: check-out at noon, arrival after.
    expect(($this->reserve)(arrival: '2026-11-12 06:00:00')->state)->toBe(StayState::Reserved)
        ->and(($this->reserve)(arrival: '2026-11-14 06:00:00')->state)->toBe(StayState::Reserved)
        ->and(Stay::query()->where('state', StayState::Reserved)->count())->toBe(3);
});

it('refuses a reservation that runs into a guest still in the room', function (): void {
    Hotel::checkIn('214', 24); // until Fri 13 Nov 9:00 AM Manila

    expect(fn () => ($this->reserve)(arrival: '2026-11-12 10:00:00'))->toThrow(NotAvailable::class);
    expect(($this->reserve)()->state)->toBe(StayState::Reserved);
});

it('counts "any room" reservations against the rooms of the type', function (): void {
    $suite = RoomType::query()->where('name', 'Suite')->firstOrFail();
    $any = fn () => app(ReserveRoom::class)->handle(Hotel::frontdesk(), 'Edwin Ortiz', null, $suite, null, StayKind::Overnight, 1, CarbonImmutable::parse('2026-11-14 06:00:00'), (string) Str::uuid());

    foreach (range(1, 4) as $i) {
        expect($any()->room_id)->toBeNull();
    }

    expect($any)->toThrow(NotAvailable::class, 'No Suite room is free on Sat 14 Nov.');
});

it('refuses a walk-in that would still be in the room when the reservation arrives', function (): void {
    ($this->reserve)(arrival: '2026-11-12 06:00:00'); // today 2:00 PM

    expect(fn () => Hotel::checkIn('214', 6))->toThrow(NotAvailable::class, 'Room 214 is reserved from Thu 12 Nov, 2:00 PM.');
    expect(Stay::query()->where('state', StayState::CheckedIn)->count())->toBe(0);

    $now = CarbonImmutable::parse('2026-11-12 01:00:00');
    expect(app(RoomQuery::class)->free(Hotel::branch()->id, $this->deluxe->id, null, $now, $now->addHours(6))->pluck('number'))->not->toContain('214')
        ->and(app(RoomQuery::class)->free(Hotel::branch()->id, $this->deluxe->id, null, $now, $now->addHours(4))->pluck('number'))->toContain('214');
});

it('lets a walk-in that leaves before the reservation arrives take the room', function (): void {
    ($this->reserve)(arrival: '2026-11-12 08:00:00'); // today 4:00 PM

    expect(Hotel::checkIn('214', 6)->state)->toBe(StayState::CheckedIn); // 9:00 AM to 3:00 PM
});

it('money paid ahead applied at check-in: deposit_applied on the room, only the rest collected', function (): void {
    $stay = ($this->reserve)(arrival: '2026-11-12 02:00:00', paid: 50000);
    $this->travelClock(60); // 10:00 AM Manila, the arrival time

    $checkedIn = app(CheckInGuest::class)->handle(
        Hotel::frontdesk(), Hotel::room('214'), 'Maria Lourdes Fajardo', StayKind::Overnight, 1, PaymentMethod::Cash,
        (string) Str::uuid(), Money::ofCentavos(65000), reservation: $stay,
    );

    expect($checkedIn->id)->toBe($stay->id)
        ->and($checkedIn->state)->toBe(StayState::CheckedIn)
        ->and($checkedIn->checked_in_at?->toDateTimeString())->toBe('2026-11-12 02:00:00')
        ->and($checkedIn->checkin_shift_id)->toBe($this->shift->id)
        ->and($checkedIn->room->status->value)->toBe('occupied');

    $room = FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::Room)->sole();
    $applied = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::DepositApplied)->sole();
    $cash = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::Payment)->sole();

    expect($room->amount?->centavos())->toBe(95000)
        ->and($applied->amount?->centavos())->toBe(50000)
        ->and((int) PaymentAllocation::query()->where('payment_id', $applied->id)->sum('amount'))->toBe(50000)
        ->and($cash->amount?->centavos())->toBe(45000)
        ->and($cash->tendered_amount?->centavos())->toBe(65000)
        ->and($cash->change_amount?->centavos())->toBe(0)
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Key)->centavos())->toBe(20000);
});

it('applies money paid ahead above the room amount and keeps the rest as the guest deposit', function (): void {
    $stay = ($this->reserve)(arrival: '2026-11-12 02:00:00', paid: 120000, method: PaymentMethod::Cash);
    $this->travelClock(60);

    app(CheckInGuest::class)->handle(Hotel::frontdesk(), Hotel::room('214'), 'Maria Lourdes Fajardo', StayKind::Overnight, 1, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(20000), reservation: $stay);

    expect(Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::Payment)->count())->toBe(0)
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->centavos())->toBe(25000);
});

it('checks in an "any room" reservation to the room the desk picks, of its type only', function (): void {
    $stay = ($this->reserve)(room: null, arrival: '2026-11-12 02:00:00');
    $this->travelClock(60);
    $check = fn (string $room) => app(CheckInGuest::class)->handle(Hotel::frontdesk(), Hotel::room($room), 'Maria Lourdes Fajardo', StayKind::Overnight, 1, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(115000), reservation: $stay);

    expect(fn () => $check('101'))->toThrow(ActionRefused::class, 'another room type');
    expect($check('207')->room_id)->toBe(Hotel::room('207')->id);
});

it('no-show release: past the release time a reservation becomes no_show and its money stays as guest deposit', function (): void {
    $stay = ($this->reserve)(arrival: '2026-11-12 02:00:00', paid: 50000);

    $this->travelClock(60 + 119); // 10:00 AM arrival + 1 h 59 m
    Artisan::call('homi:release-no-shows');
    expect($stay->fresh()->state)->toBe(StayState::Reserved);

    $this->travelClock(1);
    Artisan::call('homi:release-no-shows');

    expect($stay->fresh()->state)->toBe(StayState::NoShow)
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->centavos())->toBe(50000)
        ->and(FolioLine::query()->count())->toBe(0);

    // The room is released for sale: a walk-in may take it.
    expect(Hotel::checkIn('214', 6)->state)->toBe(StayState::CheckedIn);
});

it('a no-show whose money was not settled can still be checked in if the room is free', function (): void {
    $stay = ($this->reserve)(arrival: '2026-11-12 02:00:00', paid: 50000);
    $this->travelClock(3 * 60);
    Artisan::call('homi:release-no-shows');

    $checkedIn = app(CheckInGuest::class)->handle(Hotel::frontdesk(), Hotel::room('214'), 'Maria Lourdes Fajardo', StayKind::Overnight, 1, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(65000), reservation: $stay->fresh());

    expect($checkedIn->state)->toBe(StayState::CheckedIn)
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue();
});

it('refunds a no-show with approval: deposit_refund on the open shift, and it can no longer be checked in', function (): void {
    $stay = ($this->reserve)(arrival: '2026-11-12 02:00:00', paid: 50000, method: PaymentMethod::Cash);
    $this->travelClock(3 * 60);
    Artisan::call('homi:release-no-shows');

    app(RefundNoShow::class)->handle(Hotel::frontdesk(), $stay->fresh(), $this->cancelReason, Hotel::supervisor(), Hotel::PIN, Hotel::device());

    $refund = Payment::query()->where('kind', PaymentKind::DepositRefund)->sole();
    expect($refund->amount?->centavos())->toBe(-50000)
        ->and($refund->shift_session_id)->toBe($this->shift->id)
        ->and(Approval::query()->where('action', ApprovalAction::RefundNoShow)->where('subject_id', $stay->id)->exists())->toBeTrue()
        ->and($stay->fresh()->state)->toBe(StayState::NoShow)
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue();

    expect(fn () => app(CheckInGuest::class)->handle(Hotel::frontdesk(), Hotel::room('214'), 'Maria', StayKind::Overnight, 1, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(115000), reservation: $stay->fresh()))
        ->toThrow(ActionRefused::class, 'already settled');
    expect(fn () => app(RefundNoShow::class)->handle(Hotel::frontdesk(), $stay->fresh(), $this->cancelReason, Hotel::supervisor(), Hotel::PIN, Hotel::device()))
        ->toThrow(ActionRefused::class);
});

it('keeps a no-show’s money: a forfeited_deposit line paid by a deposit_applied', function (): void {
    $stay = ($this->reserve)(arrival: '2026-11-12 02:00:00', paid: 50000);
    $this->travelClock(3 * 60);
    Artisan::call('homi:release-no-shows');

    app(RefundNoShow::class)->handle(Hotel::frontdesk(), $stay->fresh(), $this->cancelReason, Hotel::supervisor(), Hotel::PIN, Hotel::device(), refund: false);

    $line = FolioLine::query()->where('kind', LineKind::ForfeitedDeposit)->sole();
    expect($line->amount?->centavos())->toBe(50000)
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue()
        ->and(Payment::query()->where('kind', PaymentKind::DepositRefund)->count())->toBe(0);
});

it('cancels a reservation with a reason and approval and hands back the money paid ahead', function (): void {
    $stay = ($this->reserve)(paid: 50000, method: PaymentMethod::Cash);

    $cancelled = app(CancelReservation::class)->handle(Hotel::frontdesk(), $stay, $this->cancelReason, Hotel::supervisor(), Hotel::PIN, Hotel::device());

    $approval = Approval::query()->where('action', ApprovalAction::CancelReservation)->sole();
    expect($cancelled->state)->toBe(StayState::Cancelled)
        ->and($cancelled->closed_reason)->toBe('Guest cancelled')
        ->and($approval->reason_id)->toBe($this->cancelReason->id)
        ->and($approval->approved_by)->toBe(Hotel::supervisor()->id)
        ->and(Payment::query()->where('kind', PaymentKind::DepositRefund)->value('amount')?->centavos())->toBe(-50000)
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue();

    // The room is free again for those dates.
    expect(($this->reserve)()->state)->toBe(StayState::Reserved);
});

it('cancels a reservation and keeps the money paid ahead when told to', function (): void {
    $stay = ($this->reserve)(paid: 50000);

    app(CancelReservation::class)->handle(Hotel::frontdesk(), $stay, $this->cancelReason, Hotel::supervisor(), Hotel::PIN, Hotel::device(), refund: false);

    expect(FolioLine::query()->where('kind', LineKind::ForfeitedDeposit)->value('amount')?->centavos())->toBe(50000)
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue();
});

it('saves nothing on a wrong approver PIN, and needs a note for a reason that asks for one', function (): void {
    $stay = ($this->reserve)(paid: 50000);

    expect(fn () => app(CancelReservation::class)->handle(Hotel::frontdesk(), $stay, $this->cancelReason, Hotel::supervisor(), '9999', Hotel::device()))->toThrow(PinInvalid::class);
    expect($stay->fresh()->state)->toBe(StayState::Reserved)->and(Payment::query()->count())->toBe(1);

    $this->cancelReason->update(['needs_note' => true]);
    expect(fn () => app(CancelReservation::class)->handle(Hotel::frontdesk(), $stay, $this->cancelReason->fresh(), Hotel::supervisor(), Hotel::PIN, Hotel::device()))->toThrow(ActionRefused::class);
});

it('returns the first reservation for a repeated key, and refuses an arrival in the past', function (): void {
    $key = (string) Str::uuid();

    expect(($this->reserve)(key: $key, paid: 50000)->id)->toBe(($this->reserve)(key: $key, paid: 50000)->id)
        ->and(Stay::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1);

    expect(fn () => ($this->reserve)(arrival: '2026-11-12 00:00:00'))->toThrow(ActionRefused::class, 'already passed');
});
