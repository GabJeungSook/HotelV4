<?php

declare(strict_types=1);

use App\Actions\Auth\UnlockPin;
use App\Actions\Auth\VerifyPin;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\ExtendStay;
use App\Actions\Stays\TakePayment;
use App\Actions\Stays\TransferRoom;
use App\Actions\Stays\VoidLine;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\ReasonKind;
use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Exceptions\PinInvalid;
use App\Exceptions\PinLocked;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PinLock;
use App\Queries\StayQuery;
use App\Support\Invariants;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| ROADMAP Phase 3 "Done when": open a shift, check in, add charges, pay partly, extend,
| transfer, void a line and check out; afterwards the balance is 0, deposits held are 0,
| the room is uncleaned and all invariants hold. Ten wrong PINs lock the test computer and
| a supervisor unlocks it.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00'); // 9:12 AM in Manila
});

it('runs a whole stay from check-in to check-out and leaves everything settled', function (): void {
    $jessa = Hotel::frontdesk();
    $stays = app(StayQuery::class);

    // Open the shift.
    $shift = Hotel::openShift($jessa);

    // Check in: Deluxe 205, 12 hours ₱650 + ₱200 key deposit, ₱1,000 handed over, change kept as deposit.
    $stay = Hotel::checkIn('205', 12, tendered: 100000, keepChange: true);
    expect($stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and($stays->depositHeld($stay->id, DepositType::Guest)->centavos())->toBe(15000);

    // Add charges: 2 cup noodles (₱90) and a bottle of water (₱25).
    $this->travelClock(60);
    app(AddCharges::class)->handle($jessa, $stay, [Hotel::product('Cup noodles')->id => 2, Hotel::product('Bottled water 500 ml')->id => 1]);
    expect($stays->balanceDue($stay->id)->centavos())->toBe(11500);

    // Pay partly: ₱50 cash.
    app(TakePayment::class)->handle($jessa, $stay, PaymentMethod::Cash, Money::ofPesos(50));
    expect($stays->balanceDue($stay->id)->centavos())->toBe(6500);

    // Extend by 6 hours inside the 24-hour cycle (₱250), pay later.
    $this->travelClock(120);
    app(ExtendStay::class)->handle($jessa, $stay, 6);
    expect($stay->fresh()->expected_checkout_at->toDateTimeString())->toBe('2026-11-12 19:12:00')
        ->and($stays->balanceDue($stay->id)->centavos())->toBe(31500);

    // Transfer to Suite 301 with Carol's approval: Suite 12 h ₱900 − ₱650 = ₱250 to collect.
    $this->travelClock(30);
    app(TransferRoom::class)->handle($jessa, $stay, Hotel::room('301'), Hotel::transferReason('Guest asked for an upgrade'), Hotel::supervisor(), Hotel::PIN, Hotel::device());
    expect($stays->balanceDue($stay->id)->centavos())->toBe(56500)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Uncleaned)
        ->and(Hotel::room('301')->status)->toBe(RoomStatus::Occupied);

    // Void the water (unpaid: the ₱50 paid went to the older noodles line).
    $water = FolioLine::query()->where('stay_id', $stay->id)->where('description', 'Bottled water 500 ml')->sole();
    app(VoidLine::class)->handle($jessa, $water, Hotel::reason(ReasonKind::VoidLine, 'Guest returned the item'), Hotel::supervisor(), Hotel::PIN, Hotel::device());
    expect($stays->balanceDue($stay->id)->centavos())->toBe(54000)
        ->and(Hotel::product('Bottled water 500 ml')->stock_on_hand)->toBe(36);

    // Check out: ₱150 from the guest deposit, the rest in cash; the key comes back.
    $this->travelClock(240);
    app(TakePayment::class)->handle($jessa, $stay, PaymentMethod::Deposit, Money::ofPesos(150));
    $out = app(CheckOutGuest::class)->handle($jessa, $stay, payWith: PaymentMethod::Cash, amountGiven: Money::ofPesos(400));

    expect($out->state)->toBe(StayState::CheckedOut)
        ->and($stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and($stays->depositHeld($stay->id, DepositType::Key)->isZero())->toBeTrue()
        ->and($stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue()
        ->and(Hotel::room('301')->status)->toBe(RoomStatus::Uncleaned)
        ->and(Invariants::check())->toBe([]);

    // Every money row points at the open shift and at Jessa.
    expect(FolioLine::query()->pluck('shift_session_id')->unique()->all())->toBe([$shift->id])
        ->and(Payment::query()->pluck('shift_session_id')->unique()->all())->toBe([$shift->id])
        ->and(FolioLine::query()->pluck('created_by')->unique()->all())->toBe([$jessa->id])
        ->and(Payment::query()->pluck('created_by')->unique()->all())->toBe([$jessa->id]);

    // Cash in the drawer from this stay: ₱650 + ₱200 + ₱150 kept + ₱50 + ₱390 at check-out − ₱200 key back = ₱1,240.
    expect((int) Payment::query()->where('method', PaymentMethod::Cash)->sum('amount'))->toBe(124000);
});

it('locks the test computer after ten wrong PINs and a supervisor unlocks it', function (): void {
    Hotel::openShift();
    $stay = Hotel::checkIn('205', 12);
    $order = app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 1]);
    $line = FolioLine::query()->where('pos_order_id', $order->id)->sole();
    $device = Hotel::device();
    $void = fn (string $approver) => app(VoidLine::class)->handle(
        Hotel::frontdesk(), $line, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::user($approver), '0000', $device,
    );

    // Ten wrong PINs in a row on this computer: five by Ramil (which also locks his own approvals),
    // four by Carol, and one on switch user.
    $tries = [...array_fill(0, 5, 'ramil.ortega'), ...array_fill(0, 4, 'carol.villanueva')];
    foreach ($tries as $approver) {
        try {
            $void($approver);
        } catch (PinInvalid) {
        }
    }
    expect(PinLock::query()->count())->toBe(0);

    try {
        app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), '0000', $device);
    } catch (PinInvalid) {
    }

    // Supervisor Carol's approval PIN is refused too: the computer is locked.
    expect(PinLock::query()->whereNull('unlocked_at')->sole()->device_key)->toBe($device);
    expect(fn () => app(VoidLine::class)->handle(Hotel::frontdesk(), $line, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, $device))
        ->toThrow(PinLocked::class, 'PIN entry is locked on this computer.');

    app(UnlockPin::class)->handle($device, 'carol.villanueva', 'password');

    $reversal = app(VoidLine::class)->handle(Hotel::frontdesk(), $line, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, $device);
    expect($reversal->reversal_of_id)->toBe($line->id)
        ->and(PinLock::query()->sole()->unlocked_by)->toBe(Hotel::supervisor()->id);

    expect(app(StayQuery::class)->balanceDue($stay->id)->isZero())->toBeTrue();
});
