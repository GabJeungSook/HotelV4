<?php

declare(strict_types=1);

use App\Actions\Halls\AddHallDeposit;
use App\Actions\Halls\CancelHallBooking;
use App\Actions\Halls\CompleteHallBooking;
use App\Actions\Halls\ReserveHall;
use App\Enums\HallBookingStatus;
use App\Enums\HallPricing;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\ActionRefused;
use App\Models\FolioLine;
use App\Models\Hall;
use App\Models\Payment;
use App\Services\HallLedger;
use App\Services\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| Event-hall bookings (DATABASE_SCHEMA §4.14, §8.14): a booking is a third ledger owner. Reserve
| takes a deposit, complete posts the quote as event_hall folio lines and settles, cancel refunds
| or forfeits the deposit. The money reconciles in the shift totals and the §8 invariants hold.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00'); // 11 AM Manila
    $this->branch = Hotel::branch();
    $this->branch->update(['event_hall_enabled' => true]);
    $this->jessa = Hotel::frontdesk();
    $this->sup = Hotel::supervisor();
    $this->mark = Hotel::user('mark.sumagaysay');
    $this->hall = Hall::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Garden Pavilion', 'capacity' => 200, 'is_active' => true]);
    $this->start = CarbonImmutable::parse('2026-11-20 06:00:00'); // 2 PM Manila, future
    $this->end = $this->start->addHours(5);

    // venue flat ₱5,000 + food ₱300/pax × 20 = ₱6,000 → ₱11,000 quoted
    $this->items = [
        ['package_id' => null, 'label' => 'Venue', 'pricing' => HallPricing::Flat, 'unit_amount' => Money::ofCentavos(500000)],
        ['package_id' => null, 'label' => 'Food package', 'pricing' => HallPricing::PerPax, 'unit_amount' => Money::ofCentavos(30000)],
    ];

    $this->reserve = fn (array $over = []) => app(ReserveHall::class)->handle(
        $this->jessa, $this->branch->id, $over['hall_id'] ?? $this->hall->id, 'Dela Cruz Wedding', '09175551234',
        $over['start'] ?? $this->start, $over['end'] ?? $this->end, $over['pax'] ?? 20, $over['items'] ?? $this->items,
        $over['key'] ?? (string) Str::uuid(), $over['method'] ?? PaymentMethod::Cash, $over['deposit'] ?? Money::ofCentavos(500000),
    );
});

it('reserves a hall: a quote of per-pax and flat items plus a deposit', function (): void {
    Hotel::openShift($this->jessa);
    $booking = ($this->reserve)();

    expect($booking->status)->toBe(HallBookingStatus::Reserved)
        ->and($booking->quoted_amount->centavos())->toBe(1100000)
        ->and($booking->code)->toStartWith('HALL-')
        ->and($booking->items()->count())->toBe(2)
        ->and($booking->items()->where('pricing', HallPricing::PerPax)->first()->amount->centavos())->toBe(600000)
        ->and(app(HallLedger::class)->depositHeld($booking->id)->centavos())->toBe(500000)
        ->and(app(HallLedger::class)->balanceDue($booking->id)->centavos())->toBe(0) // no charges posted yet
        ->and(Payment::query()->where('hall_booking_id', $booking->id)->where('kind', PaymentKind::DepositIn)->count())->toBe(1);
});

it('refuses an overlapping booking of the same hall', function (): void {
    Hotel::openShift($this->jessa);
    ($this->reserve)();

    expect(fn () => ($this->reserve)(['start' => $this->start->addHour(), 'end' => $this->end->addHour()]))
        ->toThrow(ActionRefused::class, 'already booked');

    // A non-overlapping slot on the same hall is fine.
    expect(($this->reserve)(['start' => $this->end->addHour(), 'end' => $this->end->addHours(6)])->status)->toBe(HallBookingStatus::Reserved);
});

it('refuses a booking when event halls are switched off', function (): void {
    Hotel::openShift($this->jessa);
    $this->branch->update(['event_hall_enabled' => false]);

    expect(fn () => ($this->reserve)())->toThrow(ActionRefused::class, 'switched off');
});

it('completes a booking: posts event_hall lines, applies the deposit and collects the balance', function (): void {
    Hotel::openShift($this->jessa);
    $booking = ($this->reserve)();

    app(CompleteHallBooking::class)->handle($this->jessa, $booking->refresh(), PaymentMethod::Cash, Money::ofCentavos(600000));
    $booking->refresh();

    $lines = FolioLine::query()->where('hall_booking_id', $booking->id)->where('kind', LineKind::EventHall)->get();

    expect($booking->status)->toBe(HallBookingStatus::Completed)
        ->and($lines->count())->toBe(2)
        ->and((int) $lines->sum(fn ($l) => $l->amount->centavos()))->toBe(1100000)
        ->and(app(HallLedger::class)->balanceDue($booking->id)->centavos())->toBe(0)
        ->and(app(HallLedger::class)->depositHeld($booking->id)->centavos())->toBe(0);
});

it('takes a second deposit instalment and refuses more than the quote', function (): void {
    Hotel::openShift($this->jessa);
    $booking = ($this->reserve)();

    app(AddHallDeposit::class)->handle($this->jessa, $booking->refresh(), PaymentMethod::Gcash, Money::ofCentavos(300000), referenceNo: 'GC-1');
    expect(app(HallLedger::class)->depositHeld($booking->id)->centavos())->toBe(800000);

    expect(fn () => app(AddHallDeposit::class)->handle($this->jessa, $booking->refresh(), PaymentMethod::Cash, Money::ofCentavos(400000)))
        ->toThrow(ActionRefused::class, 'more than the quoted total');
});

it('cancels a booking and refunds the deposit with a supervisor PIN', function (): void {
    Hotel::openShift($this->jessa);
    $booking = ($this->reserve)();

    app(CancelHallBooking::class)->handle($this->jessa, $booking->refresh(), 'Customer called off', $this->sup, Hotel::PIN, Hotel::device());
    $booking->refresh();

    expect($booking->status)->toBe(HallBookingStatus::Cancelled)
        ->and($booking->cancel_reason)->toBe('Customer called off')
        ->and(app(HallLedger::class)->depositHeld($booking->id)->centavos())->toBe(0)
        ->and(Payment::query()->where('hall_booking_id', $booking->id)->where('kind', PaymentKind::DepositRefund)->count())->toBe(1);
});

it('cancels and forfeits the deposit as income', function (): void {
    Hotel::openShift($this->jessa);
    $booking = ($this->reserve)();

    app(CancelHallBooking::class)->handle($this->jessa, $booking->refresh(), 'No-show', $this->sup, Hotel::PIN, Hotel::device(), refund: false);

    expect((int) FolioLine::query()->where('hall_booking_id', $booking->id)->where('kind', LineKind::ForfeitedDeposit)->sum('amount'))->toBe(500000)
        ->and(app(HallLedger::class)->depositHeld($booking->id)->centavos())->toBe(0);
});

it('reconciles a completed hall booking in the shift totals', function (): void {
    $shift = Hotel::openShift($this->jessa);
    $booking = ($this->reserve)();
    app(CompleteHallBooking::class)->handle($this->jessa, $booking->refresh(), PaymentMethod::Cash, Money::ofCentavos(600000));

    Hotel::closeAndHandOver($this->mark);
    $shift->refresh();

    expect($shift->totals['sales']['event_hall'])->toBe(1100000)
        ->and($shift->totals['sales']['total'])->toBe((int) FolioLine::query()->where('shift_session_id', $shift->id)->sum('amount'))
        ->and(app(ReportService::class)->recompute($shift)['sales'])->toEqual($shift->totals['sales']);
});
