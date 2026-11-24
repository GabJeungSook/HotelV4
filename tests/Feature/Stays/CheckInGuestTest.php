<?php

declare(strict_types=1);

use App\Actions\Stays\CheckInGuest;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\NoOpenShift;
use App\Exceptions\RateNotFound;
use App\Exceptions\RoomNotAvailable;
use App\Exceptions\RoomTaken;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Rate;
use App\Models\RoomStatusLog;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Queries\StayQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| CheckInGuest (6): DATABASE_SCHEMA §8.1, APP_FLOW §4.5 and the check-in tests of TRD §10.
| Room 205 is a Deluxe room: 12 hours ₱650, key deposit ₱200.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00'); // 9:12 AM in Manila
    $this->shift = Hotel::openShift();
    $this->stays = app(StayQuery::class);
});

/** @return array{stays: int, lines: int, payments: int, allocations: int, counter: int} */
function ledgerCounts(): array
{
    return [
        'stays' => Stay::query()->count(),
        'lines' => FolioLine::query()->count(),
        'payments' => Payment::query()->count(),
        'allocations' => PaymentAllocation::query()->count(),
        'counter' => Hotel::branch()->next_stay_number,
    ];
}

it('charges a room override over a floor rate, onto the folio line', function (): void {
    $room = Hotel::room('205'); // Deluxe, 12 h ₱650 by type
    Hotel::branch()->update(['floor_room_rates_enabled' => true]);
    Rate::query()->create(['branch_id' => Hotel::branch()->id, 'floor_id' => $room->floor_id, 'stay_kind' => StayKind::Hourly, 'hours' => 12, 'amount' => Money::ofCentavos(60000)]);
    Rate::query()->create(['branch_id' => Hotel::branch()->id, 'room_id' => $room->id, 'stay_kind' => StayKind::Hourly, 'hours' => 12, 'amount' => Money::ofCentavos(80000)]);

    $stay = Hotel::checkIn('205', 12, tendered: 100000);

    expect($stay->quoted_amount->centavos())->toBe(80000)
        ->and(FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::Room)->sole()->amount->centavos())->toBe(80000);
});

it('uses a flat floor rate for a room on that floor', function (): void {
    $room = Hotel::room('205'); // Deluxe, 12 h ₱650 by type
    Hotel::branch()->update(['floor_room_rates_enabled' => true]);
    Rate::query()->create(['branch_id' => Hotel::branch()->id, 'floor_id' => $room->floor_id, 'stay_kind' => StayKind::Hourly, 'hours' => 12, 'amount' => Money::ofCentavos(60000)]);

    expect(Hotel::checkIn('205', 12, tendered: 100000)->quoted_amount->centavos())->toBe(60000);
});

it('ignores floor and room rates when the branch switch is off', function (): void {
    $room = Hotel::room('205');
    Rate::query()->create(['branch_id' => Hotel::branch()->id, 'floor_id' => $room->floor_id, 'stay_kind' => StayKind::Hourly, 'hours' => 12, 'amount' => Money::ofCentavos(60000)]);

    // The switch is off, so the Deluxe type rate applies, not the ₱600 floor rate.
    expect(Hotel::checkIn('205', 12, tendered: 100000)->quoted_amount->centavos())->toBe(65000);
});

it('writes the stay, the room line, the payment, the key deposit and the room status in one transaction', function (): void {
    $counter = Hotel::branch()->next_stay_number;

    $stay = Hotel::checkIn('205', 12, tendered: 100000);

    $room = Hotel::room('205');
    expect($stay->state)->toBe(StayState::CheckedIn)
        ->and($stay->code)->toBe(sprintf('DGS-26-%06d', $counter))
        ->and(Hotel::branch()->next_stay_number)->toBe($counter + 1)
        ->and($stay->room_id)->toBe($room->id)
        ->and($stay->checked_in_at->toDateTimeString())->toBe('2026-11-12 01:12:00')
        ->and($stay->expected_checkout_at->toDateTimeString())->toBe('2026-11-12 13:12:00')
        ->and($stay->cycle_hours_used)->toBe(12)
        ->and($stay->quoted_amount->centavos())->toBe(65000)
        ->and($stay->checkin_shift_id)->toBe($this->shift->id)
        ->and($stay->checked_in_by)->toBe(Hotel::frontdesk()->id);

    $line = FolioLine::query()->where('stay_id', $stay->id)->sole();
    expect([$line->kind, $line->description, $line->amount->centavos(), $line->room_id, $line->shift_session_id, $line->created_by])
        ->toBe([LineKind::Room, 'Deluxe 12 hrs', 65000, $room->id, $this->shift->id, Hotel::frontdesk()->id]);

    $payments = Payment::query()->where('stay_id', $stay->id)->orderBy('id')->get();
    expect($payments->map(fn (Payment $p): array => [$p->kind, $p->method, $p->deposit_type, $p->amount->centavos(), $p->tendered_amount?->centavos(), $p->change_amount?->centavos()])->all())
        ->toBe([
            [PaymentKind::Payment, PaymentMethod::Cash, null, 65000, 100000, 15000],
            [PaymentKind::DepositIn, PaymentMethod::Cash, DepositType::Key, 20000, null, null],
        ])
        ->and($payments->pluck('shift_session_id')->unique()->all())->toBe([$this->shift->id]);

    expect(PaymentAllocation::query()->where('folio_line_id', $line->id)->sole()->amount->centavos())->toBe(65000)
        ->and(StayRoom::query()->where('stay_id', $stay->id)->sole()->room_id)->toBe($room->id)
        ->and($room->status)->toBe(RoomStatus::Occupied)
        ->and(RoomStatusLog::query()->where('room_id', $room->id)->sole()->only(['from_status', 'to_status', 'reason', 'stay_id']))
        ->toBe(['from_status' => RoomStatus::Available, 'to_status' => RoomStatus::Occupied, 'reason' => RoomStatusReason::CheckIn, 'stay_id' => $stay->id])
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Key)->centavos())->toBe(20000);
});

it('checks in without a key deposit when the branch has deposits switched off', function (): void {
    Hotel::branch()->update(['deposit_enabled' => false]);

    $stay = Hotel::checkIn('205', 12, tendered: 65000);

    expect(Payment::query()->where('stay_id', $stay->id)->pluck('kind')->all())->toBe([PaymentKind::Payment])
        ->and($this->stays->depositHeld($stay->id, DepositType::Key)->isZero())->toBeTrue();
});

it('keeps the excess as a guest deposit when the guest asks', function (): void {
    $stay = Hotel::checkIn('205', 12, tendered: 100000, keepChange: true);

    $payment = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::Payment)->sole();
    expect($payment->change_amount->centavos())->toBe(0)
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->centavos())->toBe(15000)
        ->and(Payment::query()->where('stay_id', $stay->id)->where('deposit_type', DepositType::Guest)->sole()->note)->toBe('change kept as deposit');
});

it('writes a discount line and allocates the room payment to both lines', function (): void {
    $stay = Hotel::checkIn('205', 12, discount: Hotel::discount('Senior citizen'));

    $lines = FolioLine::query()->where('stay_id', $stay->id)->orderBy('id')->get();
    $payment = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::Payment)->sole();

    expect($lines->map(fn (FolioLine $l): array => [$l->kind, $l->amount->centavos()])->all())->toBe([[LineKind::Room, 65000], [LineKind::Discount, -13000]])
        ->and($payment->amount->centavos())->toBe(52000)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('id')->pluck('amount')->map->centavos()->all())->toBe([65000, -13000])
        ->and($stay->quoted_amount->centavos())->toBe(52000)
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue();
});

it('turns a second active stay for the room into RoomTaken and saves nothing', function (): void {
    // Another stay holds the room (a kiosk hold), so the status check passes and the unique index decides.
    Stay::factory()->held()->create(['branch_id' => Hotel::branch()->id, 'room_id' => Hotel::room('205')->id, 'hold_expires_at' => '2026-11-12 01:20:00']);
    $before = ledgerCounts();

    expect(fn () => Hotel::checkIn('205'))->toThrow(RoomTaken::class, 'Room 205 was just taken.');

    expect(ledgerCounts())->toBe($before)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Available)
        ->and(RoomStatusLog::query()->count())->toBe(0);
});

it('refuses a room that is not available', function (): void {
    Hotel::checkIn('205');

    Hotel::checkIn('205', guest: 'Second Guest');
})->throws(RoomNotAvailable::class, 'Room 205 is not available.');

it('returns the same stay for a repeated idempotency key', function (): void {
    $key = (string) Str::uuid();

    $first = Hotel::checkIn('205', key: $key);
    $again = Hotel::checkIn('206', key: $key);

    expect($again->id)->toBe($first->id)
        ->and(Stay::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(2)
        ->and(Hotel::room('206')->status)->toBe(RoomStatus::Available);
});

it('refuses a check-in when no shift is open, and saves nothing', function (): void {
    $this->shift->update(['status' => 'closed', 'closed_at' => '2026-11-12 01:00:00', 'closed_by' => Hotel::frontdesk()->id]);
    $before = ledgerCounts();

    expect(fn () => Hotel::checkIn('205'))->toThrow(NoOpenShift::class);
    expect(ledgerCounts())->toBe($before);
});

it('refuses a stay option without a rate and never charges zero', function (): void {
    $before = ledgerCounts();

    expect(fn () => Hotel::checkIn('301', 18))->toThrow(RateNotFound::class, 'Suite 18 hrs');
    expect(ledgerCounts())->toBe($before)->and(Hotel::room('301')->status)->toBe(RoomStatus::Available);
});

it('refuses less cash than the total to collect', function (): void {
    Hotel::checkIn('205', tendered: 84999);
})->throws(ActionRefused::class, 'less than the amount to collect');

it('takes GCash with a reference number and refuses it without one, and refuses a method the branch does not accept', function (): void {
    $checkIn = fn (string $room, PaymentMethod $method, ?string $ref) => app(CheckInGuest::class)->handle(
        Hotel::frontdesk(), Hotel::room($room), 'Ana Reyes', StayKind::Hourly, 6, $method, (string) Str::uuid(), referenceNo: $ref,
    );

    expect(fn () => $checkIn('205', PaymentMethod::Gcash, null))->toThrow(ActionRefused::class, 'A reference number is needed for GCash.')
        ->and(fn () => $checkIn('205', PaymentMethod::Card, 'CARD-1'))->toThrow(ActionRefused::class, 'not accepted');

    $stay = $checkIn('205', PaymentMethod::Gcash, '1029384756');
    $payment = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::Payment)->sole();

    expect([$payment->method, $payment->amount->centavos(), $payment->reference_no, $payment->tendered_amount])
        ->toBe([PaymentMethod::Gcash, 45000, '1029384756', null]);
});

it('checks in overnight to the next check-out time and long stays by the day', function (): void {
    $night = Hotel::checkIn('205', 1, StayKind::Overnight);
    $long = Hotel::checkIn('206', 3, StayKind::LongStay);

    expect($night->expected_checkout_at->toDateTimeString())->toBe('2026-11-12 04:00:00') // 12:00 PM Manila
        ->and($night->nights)->toBe(1)
        ->and($night->cycle_hours_used)->toBe(0)
        ->and(FolioLine::query()->where('stay_id', $long->id)->sole()->amount->centavos())->toBe(300000)
        ->and($long->expected_checkout_at->toDateTimeString())->toBe('2026-11-15 01:12:00');
});

it('refuses a wrong discount on its rate and saves nothing', function (): void {
    $before = ledgerCounts();

    expect(fn () => Hotel::checkIn('205', 12, discount: Hotel::discount('Promo')))->toThrow(DiscountNotAllowed::class);
    expect(ledgerCounts())->toBe($before);

    $promo = Hotel::checkIn('205', 6, discount: Hotel::discount('Promo'));
    expect(app(StayQuery::class)->balanceDue($promo->id)->isZero())->toBeTrue()
        ->and(Payment::query()->where('stay_id', $promo->id)->where('kind', PaymentKind::Payment)->sole()->amount)->toEqual(Money::ofPesos(400));
});
