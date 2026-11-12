<?php

declare(strict_types=1);

use App\Enums\StayState;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| KioskController (27–31b): welcome, room and hours, your name, hold and held — happy path,
| validation and permission. The rules are tested on the actions (tests/Feature/Stays/KioskHoldTest).
| Deluxe 6 hours ₱450 − ₱50 promo + ₱200 key deposit = ₱600.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM in Manila
    $this->kiosk = Hotel::user('kiosk-gensan-1');
    $this->deluxe = RoomType::query()->where('name', 'Deluxe')->firstOrFail();
    $this->actingAs($this->kiosk);
});

/** @return array<string, mixed> */
function holdForm(array $overrides = []): array
{
    return [
        'room_type_id' => RoomType::query()->where('name', 'Deluxe')->value('id'),
        'hours' => 6,
        'guest_name' => 'Carlo Dumandan',
        'guest_mobile' => '0917 555 0142',
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

it('signs the kiosk device in to the welcome screen (27)', function (): void {
    auth()->logout();

    $this->post('/login', ['username' => 'kiosk-gensan-1', 'password' => 'password'])->assertRedirect('/home');
    $this->get('/home')->assertRedirect('/kiosk');
});

it('shows the welcome screen with the house rules and the rooms it can offer', function (): void {
    $this->get('/kiosk')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Kiosk/Welcome')
        ->where('hotel', 'Hotel Dalisay GenSan')
        ->where('rules', ['Quiet hours 10 PM to 6 AM.', 'No smoking in rooms.', 'Return the key and the remote at check-out.'])
        ->where('free', 46)
        ->where('poll', 20));
});

it('shows no rooms (31) when no room is free in any type', function (): void {
    Room::query()->update(['status' => 'maintenance']);

    $this->get('/kiosk')->assertInertia(fn (Assert $page) => $page->component('Kiosk/Welcome')->where('free', 0));
});

it('offers one room per type with the hour prices, and Full for a type with no room (28)', function (): void {
    Room::query()->whereIn('number', ['301', '302', '3A', '3B'])->update(['is_kiosk_enabled' => false]);

    $this->get('/kiosk/start')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Kiosk/PickRoom')
        ->where('types.0.name', 'Standard')
        ->where('types.0.room.label', 'Room 101 · 1st floor')
        ->where('types.1.name', 'Deluxe')
        ->where('types.1.room.label', 'Room 201 · 2nd floor')
        ->where('types.1.options', [['hours' => 6, 'amount' => 45000], ['hours' => 12, 'amount' => 65000], ['hours' => 18, 'amount' => 85000], ['hours' => 24, 'amount' => 100000]])
        ->where('types.2.name', 'Suite')
        ->where('types.2.full', true)
        ->where('types.2.room', null)
        ->missing('types.0.free'));
});

it('shows your name (29) with the kiosk discount applied and the total to pay at the desk', function (): void {
    $this->get("/kiosk/details?room_type={$this->deluxe->id}&hours=6")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Kiosk/Details')
        ->where('offer.room.number', '201')
        ->where('offer.room_amount', 45000)
        ->where('offer.discount.amount', 5000)
        ->where('offer.discount.text', 'Promo discount for 6-hour stays.')
        ->where('offer.deposit.amount', 20000)
        ->where('offer.total', 60000));

    // No discount on 12 hours: the promo is for 6-hour rates only.
    $this->get("/kiosk/details?room_type={$this->deluxe->id}&hours=12")->assertInertia(fn (Assert $page) => $page->where('offer.discount', null)->where('offer.total', 85000));
});

it('sends the guest back to 28 for an hour option that does not exist or a full type', function (): void {
    $this->get("/kiosk/details?room_type={$this->deluxe->id}&hours=7")->assertRedirect('/kiosk/start');
    expect(session('inertia.flash_data.error.code'))->toBe('room_taken');
});

it('holds the room and shows the code, the amount and the countdown (30)', function (): void {
    $response = $this->post('/kiosk/hold', holdForm());
    $stay = Stay::query()->sole();

    $response->assertRedirect("/kiosk/held/{$stay->code}");
    expect($stay->state)->toBe(StayState::Held)->and($stay->guest_mobile)->toBe('09175550142');

    $this->get("/kiosk/held/{$stay->code}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Kiosk/Held')
        ->where('hold.code', $stay->code)
        ->where('hold.room', '201')
        ->where('hold.amount', 60000)
        ->where('hold.expires_at', '2026-11-12T01:10:00+00:00')
        ->where('hold.held', true));
});

it('saves one hold for a double tap on "Hold my room"', function (): void {
    $form = holdForm();

    $this->post('/kiosk/hold', $form);
    $this->post('/kiosk/hold', $form);

    expect(Stay::query()->count())->toBe(1);
});

it('validates the name, the mobile and the key', function (array $bad, string $field): void {
    $this->post('/kiosk/hold', holdForm($bad))->assertSessionHasErrors($field);
    expect(Stay::query()->count())->toBe(0);
})->with([
    'no name' => [['guest_name' => ''], 'guest_name'],
    'two letters' => [['guest_name' => 'Al'], 'guest_name'],
    'short mobile' => [['guest_mobile' => '0917 55'], 'guest_mobile'],
    'no key' => [['idempotency_key' => ''], 'idempotency_key'],
    'no hours' => [['hours' => 0], 'hours'],
]);

it('says the message for an incomplete mobile number', function (): void {
    $this->post('/kiosk/hold', holdForm(['guest_mobile' => '0917 55']))->assertSessionHasErrors(['guest_mobile' => 'Enter all 11 digits, or leave it blank.']);
});

it('sends the guest back when the room was taken a moment earlier', function (): void {
    foreach (Room::query()->where('room_type_id', $this->deluxe->id)->get() as $room) {
        Stay::query()->create([
            'branch_id' => $room->branch_id, 'code' => 'T-'.$room->number, 'state' => StayState::Held, 'source' => 'kiosk', 'room_id' => $room->id,
            'room_type_id' => $room->room_type_id, 'guest_name' => 'Someone Else', 'stay_kind' => 'hourly', 'hours' => 6, 'quoted_amount' => 45000,
            'hold_expires_at' => '2026-11-12 01:10:00',
        ]);
    }

    $this->from('/kiosk/details')->post('/kiosk/hold', holdForm())->assertRedirect('/kiosk/details');
    expect(session('inertia.flash_data.error.code'))->toBe('room_taken')
        ->and(session('inertia.flash_data.error.message'))->toBe('The last Deluxe room was just taken.');

    $this->get('/kiosk/start?taken=210')->assertInertia(fn (Assert $page) => $page->where('notice', 'Room 210 was just taken by another guest. Here is the next free room.'));
});

it('refuses the kiosk screens to every other role', function (string $username): void {
    $this->actingAs(Hotel::user($username));

    $this->get('/kiosk')->assertForbidden();
    $this->get('/kiosk/start')->assertForbidden();
    $this->post('/kiosk/hold', holdForm())->assertForbidden();
    expect(Stay::query()->count())->toBe(0);
})->with(['jessa.delacruz', 'carol.villanueva', 'ramil.ortega', 'dodong.lumayag']);

it('keeps the kiosk device on /kiosk: front desk, housekeeping and admin pages are refused', function (): void {
    $this->get('/board')->assertForbidden();
    $this->get('/reservations')->assertForbidden();
    $this->get('/housekeeping')->assertForbidden();
    $this->get('/today')->assertForbidden();
    $this->get('/heartbeat')->assertNoContent();
});

it('refuses the kiosk when the branch has the kiosk module off', function (): void {
    Hotel::branch()->update(['kiosk_enabled' => false]);

    $this->get('/kiosk')->assertForbidden();
    $this->post('/kiosk/hold', holdForm())->assertForbidden();
});

it('shows a hold of another branch to nobody', function (): void {
    $this->get('/kiosk/held/DGS-26-999999')->assertNotFound();
});
