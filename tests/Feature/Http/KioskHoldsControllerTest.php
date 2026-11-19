<?php

declare(strict_types=1);

use App\Actions\Stays\HoldRoom;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\FolioLine;
use App\Models\RoomType;
use App\Models\Stay;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| The front desk side of a kiosk hold: the holds sheet on the board (5), a scanned hold code,
| the confirm screen (7) with ConfirmKioskHold, and "Release room" (StayController@release).
| The "Done when" chain — a guest holds a room on the kiosk and the front desk confirms it
| before it expires — is the last test.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM in Manila
    $this->shift = Hotel::openShift();
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, (string) Str::uuid());
    $this->hold = fn (string $name = 'Carlo Dumandan'): Stay => app(HoldRoom::class)->handle(
        Hotel::user('kiosk-gensan-1'), RoomType::query()->where('name', 'Deluxe')->value('id'), StayKind::Hourly, 6, $name, null, (string) Str::uuid(),
    );
});

it('lists the kiosk holds on the board with the time left and what to collect (5)', function (): void {
    $hold = ($this->hold)();
    $this->travelClock(3);

    $this->get('/board')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Board/Index')
        ->where('holdsPoll', 5)
        ->has('holds', 1)
        ->where('holds.0.room', '201')
        ->where('holds.0.guest', 'Carlo Dumandan')
        ->where('holds.0.stay', '6 hrs')
        ->where('holds.0.to_collect', 60000)
        ->where('holds.0.seconds_left', 420)
        ->where('holds.0.expired', false));

    $this->get('/board')->assertInertia(fn (Assert $page) => $page->loadDeferredProps(function (Assert $reload) use ($hold): void {
        $reload->where('board.floors.1.rooms.0.status', 'held')->where('board.floors.1.rooms.0.stayId', $hold->id);
    }));
});

it('shows a hold that expired in the last hour as expired', function (): void {
    ($this->hold)();
    $this->travelClock(11);
    $this->artisan('homi:expire-kiosk-holds');

    $this->get('/board')->assertInertia(fn (Assert $page) => $page->where('holds.0.expired', true)->where('holds.0.room', '201'));

    $this->travelClock(61);
    $this->get('/board')->assertInertia(fn (Assert $page) => $page->has('holds', 0));
});

it('opens the confirm screen (7) for a scanned hold code', function (): void {
    $hold = ($this->hold)();

    $this->get('/board?q='.$hold->code)->assertRedirect("/stays/{$hold->id}/confirm");
    $this->get('/board?q=DGS-26-999999')->assertOk();
});

it('shows the confirm screen with what the guest picked and the amount to collect', function (): void {
    $hold = ($this->hold)();

    $this->get("/stays/{$hold->id}/confirm")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Stays/ConfirmHold')
        ->where('hold.code', $hold->code)
        ->where('hold.room', '201')
        ->where('hold.stay', '6 hrs')
        ->where('hold.discount', '₱50.00 applied')
        ->where('hold.expired', false)
        ->where('lines', [
            ['label' => 'Room · Deluxe 6 hrs', 'amount' => 45000],
            ['label' => 'Promo', 'amount' => -5000],
            ['label' => 'Key & remote deposit', 'amount' => 20000],
        ])
        ->where('total', 60000));
});

it('confirms the hold, takes the money and shows the slip (8)', function (): void {
    $hold = ($this->hold)();

    $this->post("/stays/{$hold->id}/confirm", ['method' => 'cash', 'tendered' => 100000, 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect("/stays/{$hold->id}");
    $this->get("/stays/{$hold->id}")->assertInertia(fn (Assert $page) => $page->component('Stays/Slip')->where('slip.change', 40000));

    expect($hold->fresh()->state)->toBe(StayState::CheckedIn);
});

it('refuses the confirm of an expired hold with "This hold has expired" and writes nothing', function (): void {
    $hold = ($this->hold)();
    $this->travelClock(10);

    $this->from("/stays/{$hold->id}/confirm")
        ->post("/stays/{$hold->id}/confirm", ['method' => 'cash', 'tendered' => 60000, 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect("/stays/{$hold->id}/confirm");

    expect(session('inertia.flash_data.error.code'))->toBe('hold_expired')
        ->and(session('inertia.flash_data.error.message'))->toBe('This hold has expired.')
        ->and($hold->fresh()->state)->toBe(StayState::Expired)
        ->and(FolioLine::query()->count())->toBe(0);

    $this->get("/stays/{$hold->id}/confirm")->assertInertia(fn (Assert $page) => $page->where('hold.expired', true));
});

it('validates the confirm form', function (): void {
    $hold = ($this->hold)();

    $this->post("/stays/{$hold->id}/confirm", ['method' => 'cash'])->assertSessionHasErrors(['tendered', 'idempotency_key']);
    $this->post("/stays/{$hold->id}/confirm", ['method' => 'gcash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('reference_no');
    $this->post("/stays/{$hold->id}/confirm", ['method' => 'bitcoin'])->assertSessionHasErrors('method');
    expect($hold->fresh()->state)->toBe(StayState::Held);
});

it('releases a hold from the sheet and goes back to the board', function (): void {
    $hold = ($this->hold)();

    $this->post("/stays/{$hold->id}/release")->assertRedirect('/board');
    expect($hold->fresh()->state)->toBe(StayState::Cancelled)
        ->and(session('inertia.flash_data.toast.message'))->toBe('Room 201 released. It is back on sale.');
});

it('refuses the holds, confirm and release to other roles', function (string $username): void {
    $hold = ($this->hold)();
    $this->actingAs(Hotel::user($username));

    $this->get("/stays/{$hold->id}/confirm")->assertForbidden();
    $this->post("/stays/{$hold->id}/confirm", ['method' => 'cash', 'tendered' => 60000, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    $this->post("/stays/{$hold->id}/release")->assertForbidden();
    expect($hold->fresh()->state)->toBe(StayState::Held);
})->with(['kiosk-gensan-1', 'dodong.lumayag', 'ramil.ortega']);

it('opens the confirm screen only for kiosk stays, and sends a confirmed one to its folio', function (): void {
    $walkIn = Hotel::checkIn('205', 6);
    $this->get("/stays/{$walkIn->id}/confirm")->assertNotFound();

    $hold = ($this->hold)();
    $this->post("/stays/{$hold->id}/confirm", ['method' => 'cash', 'tendered' => 60000, 'idempotency_key' => (string) Str::uuid()]);
    $this->get("/stays/{$hold->id}/confirm")->assertRedirect("/stays/{$hold->id}");
});

it('Done when: a guest holds a room on the kiosk and the front desk confirms it before it expires', function (): void {
    $kiosk = Hotel::user('kiosk-gensan-1');
    $this->actingAs($kiosk)->post('/kiosk/hold', [
        'room_type_id' => RoomType::query()->where('name', 'Deluxe')->value('id'),
        'hours' => 6, 'guest_name' => 'Carlo Dumandan', 'idempotency_key' => (string) Str::uuid(),
    ]);
    $hold = Stay::query()->sole();

    $this->travelClock(9);
    $this->actingAs(Hotel::frontdesk())->get('/board')->assertInertia(fn (Assert $page) => $page->where('holds.0.code', $hold->code)->where('holds.0.seconds_left', 60));
    $this->post("/stays/{$hold->id}/confirm", ['method' => 'cash', 'tendered' => 60000, 'idempotency_key' => (string) Str::uuid()])->assertRedirect("/stays/{$hold->id}");

    $this->travelClock(5);
    $this->artisan('homi:expire-kiosk-holds');

    expect($hold->fresh()->state)->toBe(StayState::CheckedIn)
        ->and($hold->fresh()->room->status->value)->toBe('occupied');
});
