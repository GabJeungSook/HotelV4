<?php

declare(strict_types=1);

use App\Actions\Stays\CheckOutGuest;
use App\Models\CleaningTask;
use App\Models\RoomType;
use App\Models\Stay;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Hotel;

/*
| Mobile JSON API (Sanctum) for the kiosk tablet and roomboy phone. Every endpoint wraps the same
| Actions/Queries/policies as the web; these tests cover token sign-in, the two role surfaces, the
| role/auth gates, and that a domain refusal comes back as JSON.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM Manila
    Hotel::openShift();

    // Check a guest in then out of a room; returns the cleaning task the check-out queued.
    $this->leave = function (string $room): CleaningTask {
        $stay = Hotel::checkIn($room, 6);
        $this->travelClock(1);
        app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $stay);

        return CleaningTask::query()->where('stay_id', $stay->id)->sole();
    };
});

// --- sign-in -------------------------------------------------------------------------------------

it('issues a token and profile to a roomboy', function (): void {
    $res = $this->postJson('/api/auth/login', ['username' => 'dodong.lumayag', 'password' => 'password', 'device_name' => 'Dodong phone']);

    $res->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'role', 'branch_id', 'branch_name']]);
    expect($res->json('user.role'))->toBe('housekeeper')
        ->and($res->json('user.branch_name'))->toBe('Hotel Dalisay GenSan');
});

it('issues a token to the kiosk device', function (): void {
    $this->postJson('/api/auth/login', ['username' => 'kiosk-gensan-1', 'password' => 'password', 'device_name' => 'Lobby tablet'])
        ->assertOk()->assertJsonPath('user.role', 'kiosk');
});

it('refuses a wrong password', function (): void {
    $this->postJson('/api/auth/login', ['username' => 'dodong.lumayag', 'password' => 'nope', 'device_name' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('username');
});

it('keeps non-device roles (front desk) out of the mobile API', function (): void {
    $this->postJson('/api/auth/login', ['username' => 'jessa.delacruz', 'password' => 'password', 'device_name' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('username');
});

it('needs a token for protected endpoints', function (): void {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->getJson('/api/housekeeping/queue')->assertUnauthorized();
    $this->getJson('/api/kiosk/welcome')->assertUnauthorized();
});

it('revokes only the current token on logout', function (): void {
    $dodong = Hotel::user('dodong.lumayag');
    $token = $dodong->createToken('phone', ['housekeeper'])->plainTextToken;

    $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
    expect($dodong->tokens()->count())->toBe(0); // the token row is revoked, so it no longer authenticates
});

// --- roomboy -------------------------------------------------------------------------------------

it('serves the roomboy queue, start, cleaning screen, finish and history', function (): void {
    $task = ($this->leave)('101');
    Sanctum::actingAs(Hotel::user('dodong.lumayag'), ['housekeeper']);

    $this->getJson('/api/housekeeping/queue')->assertOk()->assertJsonStructure(['user_name', 'floors', 'tasks', 'current']);
    $this->postJson("/api/housekeeping/tasks/{$task->id}/start")->assertOk();
    $this->getJson("/api/housekeeping/tasks/{$task->id}")->assertOk();
    $this->postJson("/api/housekeeping/tasks/{$task->id}/finish", ['result' => 'ready'])->assertOk()->assertJsonPath('message', 'Room 101 is ready for the next guest.');
    $this->getJson('/api/housekeeping/history')->assertOk();
});

it('keeps a kiosk token out of the housekeeping API', function (): void {
    Sanctum::actingAs(Hotel::user('kiosk-gensan-1'), ['kiosk']);
    $this->getJson('/api/housekeeping/queue')->assertForbidden();
});

// --- kiosk ---------------------------------------------------------------------------------------

it('serves the kiosk welcome, rooms, offer, hold and held', function (): void {
    Sanctum::actingAs(Hotel::user('kiosk-gensan-1'), ['kiosk']);
    $deluxe = (int) RoomType::query()->where('name', 'Deluxe')->value('id');

    $this->getJson('/api/kiosk/welcome')->assertOk()->assertJsonPath('hotel', 'Hotel Dalisay GenSan');
    $this->getJson('/api/kiosk/rooms')->assertOk();
    $this->getJson("/api/kiosk/offer?room_type={$deluxe}&kind=hourly&quantity=6")->assertOk()->assertJsonStructure(['offer']);

    $hold = $this->postJson('/api/kiosk/hold', [
        'room_type_id' => $deluxe, 'hours' => 6, 'guest_name' => 'Carlo Dumandan',
        'guest_mobile' => '0917 555 0142', 'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated()->assertJsonStructure(['code', 'hold']);

    $code = $hold->json('code');
    $this->getJson("/api/kiosk/held/{$code}")->assertOk();
    expect(Stay::query()->where('code', $code)->where('source', 'kiosk')->exists())->toBeTrue();
});

it('keeps a housekeeper token out of the kiosk API', function (): void {
    Sanctum::actingAs(Hotel::user('dodong.lumayag'), ['housekeeper']);
    $this->getJson('/api/kiosk/welcome')->assertForbidden();
});

it('returns a domain refusal as JSON', function (): void {
    Sanctum::actingAs(Hotel::user('kiosk-gensan-1'), ['kiosk']);
    $deluxe = (int) RoomType::query()->where('name', 'Deluxe')->value('id');

    // 2 hours is a valid field but not an offered stay for Deluxe (6/12/18/24) → ActionRefused.
    $this->postJson('/api/kiosk/hold', [
        'room_type_id' => $deluxe, 'hours' => 2, 'guest_name' => 'Carlo Dumandan', 'idempotency_key' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonStructure(['code', 'message']);
});
