<?php

declare(strict_types=1);

use App\Actions\Stays\AddCharges;
use App\Actions\Stays\TakePayment;
use App\Enums\PaymentMethod;
use App\Enums\ShiftStatus;
use App\Exceptions\NoOpenShift;
use App\Exceptions\ShiftClosing;
use App\Exceptions\ShiftStale;
use App\Models\Payment;
use App\Services\CurrentShift;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Hotel;

/*
| CurrentShift (TRD §3.5) and the guards every money action shares (TRD §4.12).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00');
    $this->branch = Hotel::branch();
});

it('throws NoOpenShift when the branch has no open shift', function (): void {
    DB::transaction(fn () => app(CurrentShift::class)->for($this->branch->id));
})->throws(NoOpenShift::class);

it('finds the open shift and locks it inside a transaction', function (): void {
    $shift = Hotel::openShift();

    $found = DB::transaction(fn () => app(CurrentShift::class)->for($this->branch->id));

    expect($found->id)->toBe($shift->id);
});

it('stamps a payment with the open shift and the person who took it, whoever opened the shift', function (): void {
    $shift = Hotel::openShift(Hotel::user('mark.sumagaysay'));
    $stay = Hotel::checkIn(by: Hotel::user('rhea.bautista'));

    $payments = Payment::query()->where('stay_id', $stay->id)->get();

    expect($payments->pluck('shift_session_id')->unique()->all())->toBe([$shift->id])
        ->and($payments->pluck('created_by')->unique()->all())->toBe([Hotel::user('rhea.bautista')->id]);
});

it('refuses money writes with ShiftClosing once the close has started, and nothing is saved', function (): void {
    $shift = Hotel::openShift();
    $stay = Hotel::checkIn();
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 1]);
    $shift->update(['status' => ShiftStatus::Closing]);
    $before = Payment::query()->count();

    expect(fn () => app(TakePayment::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, Money::ofPesos(35)))
        ->toThrow(ShiftClosing::class, 'The shift is being closed. Nothing was saved.');
    expect(Payment::query()->count())->toBe($before);
});

it('refuses payments on a shift that is shift_stale_block_minutes past its end, and allows them before', function (): void {
    Hotel::openShift();
    $stay = Hotel::checkIn();
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 2]);

    // The AM shift of 12 Nov ends at 8:00 PM Manila (12:00 UTC); it is stale from 12:00 AM (16:00 UTC).
    $this->freezeClock('2026-11-12 15:59:00');
    app(TakePayment::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, Money::ofPesos(35));

    $this->freezeClock('2026-11-12 16:00:00');
    expect(fn () => app(TakePayment::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, Money::ofPesos(35)))
        ->toThrow(ShiftStale::class);
});
