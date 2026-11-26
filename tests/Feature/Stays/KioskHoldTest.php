<?php

declare(strict_types=1);

use App\Actions\Stays\ConfirmKioskHold;
use App\Actions\Stays\HoldRoom;
use App\Actions\Stays\ReleaseKioskHold;
use App\Actions\Stays\ReserveRoom;
use App\Actions\Stays\ResolveHeldDiscount;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\HoldExpired;
use App\Exceptions\NotAvailable;
use App\Exceptions\RoomTaken;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use App\Queries\StayQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| Kiosk holds (27–30, 5, 7): HoldRoom, ConfirmKioskHold, ReleaseKioskHold and the
| ExpireKioskHolds command (APP_FLOW §4.6, §4.18, DATABASE_SCHEMA §8.2), and the "Kiosk and
| reservations" tests of TRD §10 that are about the kiosk. Deluxe 6 hours is ₱450 with the ₱50
| kiosk promo, key deposit ₱200: ₱600 to collect. The kiosk hold time is 10 minutes.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM in Manila
    $this->kiosk = Hotel::user('kiosk-gensan-1');
    $this->deluxe = RoomType::query()->where('name', 'Deluxe')->firstOrFail();
    $this->hold = fn (int $hours = 6, string $name = 'Carlo Dumandan', ?string $key = null, ?int $typeId = null): Stay => app(HoldRoom::class)
        ->handle($this->kiosk, $typeId ?? $this->deluxe->id, StayKind::Hourly, $hours, $name, '09175550142', $key ?? (string) Str::uuid());
});

/** @return array{lines: int, payments: int} */
function kioskLedger(): array
{
    return ['lines' => FolioLine::query()->count(), 'payments' => Payment::query()->count()];
}

it('rotation order: never-used rooms first in number order, then the oldest check-out', function (): void {
    expect(($this->hold)()->room->number)->toBe('201');

    Room::query()->where('number', '202')->update(['last_checkout_at' => '2026-11-10 01:00:00']);
    Room::query()->whereIn('number', ['203', '204', '205', '206', '207', '208', '209', '210', '211', '212', '214', '215', '216', '219', '220', '221'])
        ->update(['last_checkout_at' => '2026-11-11 01:00:00']);
    Room::query()->where('number', '219')->update(['last_checkout_at' => '2026-11-09 01:00:00']);

    expect(($this->hold)()->room->number)->toBe('219')
        ->and(($this->hold)()->room->number)->toBe('202')
        ->and(($this->hold)()->room->number)->toBe('203');
});

it('holds a room: a held kiosk stay priced on the server, no ledger row and no shift needed', function (): void {
    $before = kioskLedger();
    $counter = Hotel::branch()->next_stay_number;

    $stay = ($this->hold)();

    expect($stay->state)->toBe(StayState::Held)
        ->and($stay->source)->toBe(StaySource::Kiosk)
        ->and($stay->stay_kind)->toBe(StayKind::Hourly)
        ->and($stay->hours)->toBe(6)
        ->and($stay->discount_id)->toBe(Hotel::discount('Promo')->id)
        ->and($stay->quoted_amount?->centavos())->toBe(40000)
        ->and($stay->hold_expires_at?->toDateTimeString())->toBe('2026-11-12 01:10:00')
        ->and($stay->code)->toBe(sprintf('DGS-26-%06d', $counter))
        ->and($stay->checkin_shift_id)->toBeNull()
        ->and(kioskLedger())->toBe($before)
        ->and($stay->room->status)->toBe(RoomStatus::Available)
        ->and(ShiftSession::query()->count())->toBe(0);
});

it('kiosk cannot send a price: the hold is priced from the rate table whatever is posted', function (): void {
    $this->actingAs($this->kiosk)->post('/kiosk/hold', [
        'room_type_id' => $this->deluxe->id,
        'hours' => 6,
        'guest_name' => 'Carlo Dumandan',
        'idempotency_key' => (string) Str::uuid(),
        'quoted_amount' => 100,
        'price' => 100,
        'amount' => 100,
        'room_id' => Hotel::room('3A')->id,
    ])->assertRedirect();

    $stay = Stay::query()->sole();

    expect($stay->quoted_amount?->centavos())->toBe(40000)
        ->and($stay->room->number)->toBe('201')
        ->and($stay->discount_id)->toBe(Hotel::discount('Promo')->id)
        ->and($stay->discount_unverified)->toBeFalse();
});

it('applies the senior / PWD discount the guest ticked, flagged unverified for the front desk', function (): void {
    $this->actingAs($this->kiosk)->post('/kiosk/hold', [
        'room_type_id' => $this->deluxe->id,
        'hours' => 6,
        'guest_name' => 'Carlo Dumandan',
        'idempotency_key' => (string) Str::uuid(),
        'discount_id' => Hotel::discount('Senior citizen')->id,
    ])->assertRedirect();

    $stay = Stay::query()->sole();

    // Senior citizen 20% off ₱450 = ₱360 (replaces the auto promo), pending ID at the desk.
    expect($stay->discount_id)->toBe(Hotel::discount('Senior citizen')->id)
        ->and($stay->discount_unverified)->toBeTrue()
        ->and($stay->quoted_amount?->centavos())->toBe(36000);
});

it('ignores a discount the guest cannot pick at the kiosk, keeping the auto promo', function (): void {
    // A discount that is not kiosk-selectable (here, limited to a rate the Deluxe 6h stay is not).
    $other = Hotel::discount('Senior citizen');
    $other->update(['all_rates' => false]);
    $other->rates()->sync([]); // allowed on no rate, so not eligible for this stay

    $this->actingAs($this->kiosk)->post('/kiosk/hold', [
        'room_type_id' => $this->deluxe->id,
        'hours' => 6,
        'guest_name' => 'Carlo Dumandan',
        'idempotency_key' => (string) Str::uuid(),
        'discount_id' => $other->id,
    ])->assertRedirect();

    $stay = Stay::query()->sole();

    expect($stay->discount_id)->toBe(Hotel::discount('Promo')->id)
        ->and($stay->discount_unverified)->toBeFalse()
        ->and($stay->quoted_amount?->centavos())->toBe(40000);
});

it('hold blocks the room for walk-in: a check-in to a held room is refused as room just taken', function (): void {
    Hotel::openShift();
    $held = ($this->hold)();

    expect(fn () => Hotel::checkIn('201', 6))->toThrow(RoomTaken::class);
    expect(Stay::query()->count())->toBe(1)
        ->and($held->fresh()->state)->toBe(StayState::Held);
});

it('kiosk hold expires: after the hold time the room is on sale again and nothing is left to clean up', function (): void {
    $stay = ($this->hold)();
    $before = kioskLedger();

    $this->travelClock(9);
    Artisan::call('homi:expire-kiosk-holds');
    expect($stay->fresh()->state)->toBe(StayState::Held);

    $this->travelClock(1);
    Artisan::call('homi:expire-kiosk-holds');

    $stay->refresh();
    expect($stay->state)->toBe(StayState::Expired)
        ->and($stay->active_room_id)->toBeNull()
        ->and(kioskLedger())->toBe($before)
        ->and(Hotel::room('201')->status)->toBe(RoomStatus::Available);

    // The room can be sold again at once.
    Hotel::openShift();
    expect(Hotel::checkIn('201', 6)->state)->toBe(StayState::CheckedIn);
});

it('a walk-in expires a hold that ran out before the next run of the job', function (): void {
    Hotel::openShift();
    $stay = ($this->hold)();
    $this->travelClock(11);

    expect(Hotel::checkIn('201', 6)->state)->toBe(StayState::CheckedIn)
        ->and($stay->fresh()->state)->toBe(StayState::Expired);
});

it('confirms a hold: the stay is checked in from now with the room line, discount, payment and key deposit', function (): void {
    $stay = ($this->hold)();
    Hotel::openShift();
    $this->travelClock(4);

    $confirmed = app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(100000));

    expect($confirmed->state)->toBe(StayState::CheckedIn)
        ->and($confirmed->checked_in_at?->toDateTimeString())->toBe('2026-11-12 01:04:00')
        ->and($confirmed->expected_checkout_at?->toDateTimeString())->toBe('2026-11-12 07:04:00')
        ->and($confirmed->checked_in_by)->toBe(Hotel::frontdesk()->id)
        ->and($confirmed->room->status)->toBe(RoomStatus::Occupied)
        ->and(FolioLine::query()->where('stay_id', $stay->id)->orderBy('id')->pluck('amount')->map(fn ($m) => $m->centavos())->all())->toBe([45000, -5000])
        ->and(FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::Discount)->value('description'))->toBe('Promo');

    $payment = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::Payment)->sole();
    expect($payment->amount?->centavos())->toBe(40000)
        ->and($payment->tendered_amount?->centavos())->toBe(100000)
        ->and($payment->change_amount?->centavos())->toBe(40000)
        ->and(app(StayQuery::class)->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and(app(StayQuery::class)->depositHeld($stay->id, DepositType::Key)->centavos())->toBe(20000);
});

it('refuses to confirm a hold whose kiosk discount has not been verified at the desk', function (): void {
    $stay = app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::Hourly, 6, 'Carlo Dumandan', '09175550142', (string) Str::uuid(), Hotel::discount('Senior citizen')->id);
    Hotel::openShift();
    $before = kioskLedger();

    expect($stay->discount_unverified)->toBeTrue();
    expect(fn () => app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(100000)))
        ->toThrow(ActionRefused::class);
    expect(kioskLedger())->toBe($before);
});

it('verifies the ID: the claimed discount stays and the guest checks in with it', function (): void {
    $stay = app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::Hourly, 6, 'Carlo Dumandan', '09175550142', (string) Str::uuid(), Hotel::discount('Senior citizen')->id);
    Hotel::openShift();

    app(ResolveHeldDiscount::class)->handle(Hotel::frontdesk(), $stay, true);

    expect($stay->fresh()?->discount_unverified)->toBeFalse()
        ->and($stay->fresh()?->discount_id)->toBe(Hotel::discount('Senior citizen')->id);

    app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(100000));

    // Senior citizen 20% off ₱450 = ₱90 off; the discount line is recorded.
    expect(FolioLine::query()->where('stay_id', $stay->id)->orderBy('id')->pluck('amount')->map(fn ($m) => $m->centavos())->all())->toBe([45000, -9000])
        ->and(FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::Discount)->value('description'))->toBe('Senior citizen');
});

it('revokes the discount: the hold is re-priced at the plain rate and no discount is charged', function (): void {
    $stay = app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::Hourly, 6, 'Carlo Dumandan', '09175550142', (string) Str::uuid(), Hotel::discount('Senior citizen')->id);
    Hotel::openShift();

    app(ResolveHeldDiscount::class)->handle(Hotel::frontdesk(), $stay, false);

    expect($stay->fresh()?->discount_id)->toBeNull()
        ->and($stay->fresh()?->discount_unverified)->toBeFalse()
        ->and($stay->fresh()?->quoted_amount?->centavos())->toBe(45000);

    app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(100000));

    // The plain ₱450 room line, with no discount line.
    expect(FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::Discount)->exists())->toBeFalse()
        ->and(FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::Room)->value('amount')?->centavos())->toBe(45000);
});

it('confirm after expiry refused: HoldExpired, the stay is expired and nothing is written', function (): void {
    $stay = ($this->hold)();
    Hotel::openShift();
    $before = kioskLedger();
    $this->travelClock(10);

    expect(fn () => app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(60000)))
        ->toThrow(HoldExpired::class, 'This hold has expired.');

    expect($stay->fresh()->state)->toBe(StayState::Expired)
        ->and(kioskLedger())->toBe($before)
        ->and(Hotel::room('201')->status)->toBe(RoomStatus::Available);
});

it('refuses to confirm a released hold', function (): void {
    $stay = ($this->hold)();
    Hotel::openShift();
    app(ReleaseKioskHold::class)->handle(Hotel::frontdesk(), $stay);

    expect(fn () => app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(60000)))
        ->toThrow(HoldExpired::class);
});

it('refuses cash that does not cover the total, and writes nothing', function (): void {
    $stay = ($this->hold)();
    Hotel::openShift();

    expect(fn () => app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(59999)))
        ->toThrow(ActionRefused::class);
    expect($stay->fresh()->state)->toBe(StayState::Held)->and(FolioLine::query()->count())->toBe(0);
});

it('returns the first check-in for a repeated confirm key', function (): void {
    $stay = ($this->hold)();
    Hotel::openShift();
    $key = (string) Str::uuid();

    $first = app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, $key, Money::ofCentavos(60000));
    $second = app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, $key, Money::ofCentavos(60000));

    expect($second->id)->toBe($first->id)
        ->and(Payment::query()->where('stay_id', $stay->id)->count())->toBe(2);
});

it('returns the first hold for a repeated kiosk key', function (): void {
    $key = (string) Str::uuid();

    expect(($this->hold)(key: $key)->id)->toBe(($this->hold)(key: $key)->id)
        ->and(Stay::query()->count())->toBe(1);
});

it('releases a hold: cancelled with a reason, the room back on sale, no money involved', function (): void {
    $stay = ($this->hold)();
    $released = app(ReleaseKioskHold::class)->handle(Hotel::frontdesk(), $stay);

    expect($released->state)->toBe(StayState::Cancelled)
        ->and($released->closed_reason)->toContain('Released at the front desk')
        ->and($released->fresh()->active_room_id)->toBeNull()
        ->and(Payment::query()->count())->toBe(0);

    // A second release of a closed hold changes nothing.
    expect(app(ReleaseKioskHold::class)->handle(Hotel::frontdesk(), $stay)->state)->toBe(StayState::Cancelled);
});

it('says the last room was taken when every room of the type is held', function (): void {
    $suite = RoomType::query()->where('name', 'Suite')->firstOrFail();

    foreach (range(1, 4) as $i) {
        ($this->hold)(6, "Guest Number {$i}", typeId: $suite->id);
    }

    expect(fn () => ($this->hold)(6, typeId: $suite->id))->toThrow(RoomTaken::class, 'The last Suite room was just taken.');
});

it('keeps a room with a reservation in the period off the kiosk', function (): void {
    Hotel::openShift();
    app(ReserveRoom::class)->handle(
        Hotel::frontdesk(), 'Maria Fajardo', null, $this->deluxe, Hotel::room('201'), StayKind::Hourly, 12,
        CarbonImmutable::parse('2026-11-12 03:00:00'), (string) Str::uuid(),
    );

    expect(($this->hold)()->room->number)->toBe('202');
});

it('holds a long stay priced per day from the 24-hour rate', function (): void {
    $stay = app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::LongStay, 2, 'Carlo Dumandan', '09175550142', (string) Str::uuid());

    expect($stay->stay_kind)->toBe(StayKind::LongStay)
        ->and($stay->days)->toBe(2)
        ->and($stay->hours)->toBeNull()
        // Deluxe 24-hour rate is ₱1,000, so two days is ₱2,000 (no kiosk discount on long stay).
        ->and($stay->quoted_amount->centavos())->toBe(200000);
});

it('will not hold a long stay the branch does not offer', function (): void {
    Hotel::branch()->update(['long_stay_enabled' => false]);

    expect(fn () => app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::LongStay, 2, 'Carlo Dumandan', '09175550142', (string) Str::uuid()))
        ->toThrow(ActionRefused::class);
});

it('confirms a long-stay hold: checked in for the days booked, priced per day', function (): void {
    $stay = app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::LongStay, 2, 'Carlo Dumandan', '09175550142', (string) Str::uuid());
    Hotel::openShift();
    $this->travelClock(4);

    $confirmed = app(ConfirmKioskHold::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, (string) Str::uuid(), Money::ofCentavos(250000));

    expect($confirmed->state)->toBe(StayState::CheckedIn)
        ->and($confirmed->stay_kind)->toBe(StayKind::LongStay)
        ->and($confirmed->days)->toBe(2)
        // Deluxe 24-hour rate ₱1,000 × 2 days = ₱2,000 (no kiosk discount on long stay).
        ->and($confirmed->quoted_amount?->centavos())->toBe(200000)
        // The days start at the desk: checkout is two days after check-in, not after the hold.
        ->and($confirmed->expected_checkout_at?->toDateTimeString())->toBe($confirmed->checked_in_at?->addDays(2)->toDateTimeString())
        ->and(app(StayQuery::class)->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and(app(StayQuery::class)->depositHeld($stay->id, DepositType::Key)->centavos())->toBe(20000);
});

it('refuses a name shorter than 3 letters, an incomplete mobile and an hour option that does not exist', function (): void {
    expect(fn () => app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::Hourly, 6, 'Al', null, (string) Str::uuid()))->toThrow(ActionRefused::class)
        ->and(fn () => app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::Hourly, 6, 'Carlo', '091755', (string) Str::uuid()))->toThrow(ActionRefused::class, 'Enter all 11 digits')
        ->and(fn () => app(HoldRoom::class)->handle($this->kiosk, $this->deluxe->id, StayKind::Hourly, 7, 'Carlo', null, (string) Str::uuid()))->toThrow(ActionRefused::class);

    expect(Stay::query()->count())->toBe(0);
});

it('takes no hold when the kiosk module is off', function (): void {
    Hotel::branch()->update(['kiosk_enabled' => false]);

    expect(fn () => ($this->hold)())->toThrow(ActionRefused::class);
});

it('hold blocks the room for a reservation of the same period', function (): void {
    Hotel::openShift();
    ($this->hold)();

    expect(fn () => app(ReserveRoom::class)->handle(
        Hotel::frontdesk(), 'Maria Fajardo', null, $this->deluxe, Hotel::room('201'), StayKind::Hourly, 6,
        CarbonImmutable::parse('2026-11-12 02:00:00'), (string) Str::uuid(),
    ))->toThrow(NotAvailable::class);
});

it('is used by the kiosk device only', function (): void {
    expect(Gate::forUser($this->kiosk)->allows('hold', Stay::class))->toBeTrue()
        ->and(Gate::forUser(Hotel::frontdesk())->allows('hold', Stay::class))->toBeFalse()
        ->and(Gate::forUser(User::query()->where('username', 'ramil.ortega')->sole())->allows('hold', Stay::class))->toBeFalse();
});
