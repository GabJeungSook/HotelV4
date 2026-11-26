<?php

declare(strict_types=1);

use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\RequestKioskCheckout;
use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Queries\BoardQuery;
use App\Queries\KioskScreenQuery;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| Kiosk checkout (reference: HotelV2). A guest requests checkout at the lobby kiosk with their room
| and stay code; it only flags the stay (checkout_requested_at). The front desk still takes payment,
| returns the deposit and does the real checkout.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    Hotel::branch()->update(['kiosk_checkout_enabled' => true]);
    $this->kiosk = Hotel::user('kiosk-gensan-1');
    Hotel::openShift();
    $this->stay = Hotel::checkIn('101', 6);
});

it('flags the stay on request, idempotently, and writes no money or room change', function (): void {
    $before = ['lines' => FolioLine::query()->count(), 'payments' => Payment::query()->count()];

    $out = app(RequestKioskCheckout::class)->handle($this->kiosk, '101', $this->stay->code);

    expect($out->checkout_requested_at)->not->toBeNull()
        ->and($out->state)->toBe(StayState::CheckedIn)
        ->and(['lines' => FolioLine::query()->count(), 'payments' => Payment::query()->count()])->toBe($before)
        ->and(Hotel::room('101')->status)->toBe(RoomStatus::Occupied);

    // A second request keeps the first time (idempotent).
    $first = $out->checkout_requested_at;
    $this->travelClock(2);
    $again = app(RequestKioskCheckout::class)->handle($this->kiosk, '101', $this->stay->code);
    expect($again->checkout_requested_at?->equalTo($first))->toBeTrue();
});

it('refuses a wrong room number or stay code', function (): void {
    expect(fn () => app(RequestKioskCheckout::class)->handle($this->kiosk, '999', $this->stay->code))->toThrow(ActionRefused::class);
    expect(fn () => app(RequestKioskCheckout::class)->handle($this->kiosk, '101', 'DGS-26-999999'))->toThrow(ActionRefused::class);
    expect($this->stay->fresh()?->checkout_requested_at)->toBeNull();
});

it('refuses kiosk checkout when the branch setting is off', function (): void {
    Hotel::branch()->update(['kiosk_checkout_enabled' => false]);

    expect(fn () => app(RequestKioskCheckout::class)->handle($this->kiosk, '101', $this->stay->code))->toThrow(ActionRefused::class, 'not available');
});

it('shows the bill and then flags the stay over HTTP', function (): void {
    $this->actingAs($this->kiosk)->post('/kiosk/checkout', ['room_number' => '101', 'code' => $this->stay->code])
        ->assertInertia(fn ($page) => $page->component('Kiosk/Checkout')->where('bill.room', '101')->where('bill.already_requested', false));

    $this->actingAs($this->kiosk)->post('/kiosk/checkout/confirm', ['room_number' => '101', 'code' => $this->stay->code])
        ->assertRedirect(route('kiosk.checkout.done', absolute: false));

    expect($this->stay->fresh()?->checkout_requested_at)->not->toBeNull();
});

it('keeps the checkout routes closed when the branch setting is off', function (): void {
    Hotel::branch()->update(['kiosk_checkout_enabled' => false]);

    $this->actingAs($this->kiosk)->get('/kiosk/checkout')->assertForbidden();
});

it('shows the checkout request on the room board', function (): void {
    app(RequestKioskCheckout::class)->handle($this->kiosk, '101', $this->stay->code);

    $board = app(BoardQuery::class)->forBranch(Hotel::branch()->id);
    $rows = collect($board['floors'])->flatMap(fn (array $f): array => $f['rooms']);
    $row = $rows->firstWhere('number', '101');

    expect($row['checkoutRequested'])->toBeTrue();
});

it('lists the request on the front-desk checkout-requests sheet with its bill', function (): void {
    app(RequestKioskCheckout::class)->handle($this->kiosk, '101', $this->stay->code);

    $rows = app(KioskScreenQuery::class)->checkoutRequests(Hotel::branch()->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['room'])->toBe('101')
        ->and($rows[0]['requested_at'])->not->toBeNull()
        ->and($rows[0])->toHaveKeys(['balance', 'deposit']);
});

it('itemises the stay on the kiosk checkout bill', function (): void {
    $bill = app(KioskScreenQuery::class)->checkoutBill($this->stay->fresh());

    expect($bill['lines'])->not->toBeEmpty()
        ->and($bill)->toHaveKeys(['lines', 'balance', 'deposit', 'already_requested']);
});

it('blocks a direct front-desk checkout until the guest has requested, and the desk can start it', function (): void {
    $fd = Hotel::frontdesk();

    // Kiosk checkout is on and nothing was requested — the desk cannot check out directly.
    expect(fn () => app(CheckOutGuest::class)->handle($fd, $this->stay))->toThrow(ActionRefused::class, 'Kiosk checkout is on');
    expect($this->stay->fresh()?->state)->toBe(StayState::CheckedIn);

    // The desk starts the request for a walk-up, then checkout goes through.
    $flagged = app(RequestKioskCheckout::class)->byDesk($fd, $this->stay);
    expect($flagged->checkout_requested_at)->not->toBeNull();

    $out = app(CheckOutGuest::class)->handle($fd, $this->stay->fresh());
    expect($out->state)->toBe(StayState::CheckedOut);
});
