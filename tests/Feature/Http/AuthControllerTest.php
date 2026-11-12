<?php

declare(strict_types=1);

use App\Actions\Shifts\JoinShift;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\PinAttempt;
use App\Models\PinLock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| AuthController (1, 3) and PinController (set your PIN, unlock): APP_FLOW §4.1, §4.4, §8;
| TRD §4.12, §9. Happy path, validation and permission for each method.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00'); // 9:12 AM in Manila
    $this->device = (string) Str::uuid();
});

it('shows the sign-in with the greeting only on a new computer', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('part', 'morning')->where('facts', null)->where('photo.src', '/images/photos/signin-morning.jpg'));
});

it('shows four counts and the open shift for the remembered branch, and no money', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205');

    $response = $this->withUnencryptedCookie('homi_branch', (string) Hotel::branch()->id)->get('/login');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Login')
        ->where('facts.branch', Hotel::branch()->name)
        ->where('facts.in_house', 1)
        ->where('facts.available', 45)
        ->where('facts.due_soon', 0)
        ->where('facts.arriving', 0)
        ->where('facts.shift.period_name', 'AM'));

    expect(array_keys($response->viewData('page')['props']['facts']))->toBe(['branch', 'available', 'in_house', 'due_soon', 'arriving', 'shift']);
});

it('signs a front desk user in, remembers the branch, and sends them to open the shift', function (): void {
    $this->post('/login', ['username' => 'jessa.delacruz', 'password' => 'password'])
        ->assertRedirect('/home')
        ->assertCookie('homi_branch', (string) Hotel::branch()->id, false);

    $this->assertAuthenticatedAs(Hotel::frontdesk());
    $this->get('/home')->assertRedirect('/shift/open');
});

it('sends every role to its home', function (string $username, string $home): void {
    $this->post('/login', ['username' => $username, 'password' => 'password']);

    $this->get('/home')->assertRedirect($home);
})->with([
    'supervisor' => ['carol.villanueva', '/today'],
    'admin' => ['ramil.ortega', '/dashboard'],
    'back office' => ['grace.tolentino', '/dashboard'],
    'owner' => ['ramon.alvarez', '/owner'],
    'housekeeper' => ['dodong.lumayag', '/housekeeping'],
    'kiosk' => ['kiosk-gensan-1', '/kiosk'],
]);

it('sends a front desk member of the open shift to Today', function (): void {
    Hotel::openShift();
    $this->post('/login', ['username' => 'jessa.delacruz', 'password' => 'password']);

    $this->get('/home')->assertRedirect('/today');
});

it('validates the sign-in form', function (): void {
    $this->post('/login', ['username' => '', 'password' => ''])->assertSessionHasErrors(['username', 'password']);
    $this->assertGuest();
});

it('refuses a wrong password with the tries left and locks the account after five', function (): void {
    foreach (range(1, 4) as $try) {
        $this->post('/login', ['username' => 'jessa.delacruz', 'password' => 'wrong']);
    }

    expect(session('inertia.flash_data.error.message'))->toBe('Wrong username or password. 1 try left before a 15-minute lock.');

    $this->post('/login', ['username' => 'jessa.delacruz', 'password' => 'wrong']);
    expect(session('inertia.flash_data.error.message'))->toContain('locked')
        ->and(Hotel::frontdesk()->locked_until?->toDateTimeString())->toBe('2026-11-12 01:27:00');

    // The right password is refused while the lock lasts.
    $this->post('/login', ['username' => 'jessa.delacruz', 'password' => 'password']);
    $this->assertGuest();
});

it('refuses a switched-off account', function (): void {
    $this->post('/login', ['username' => 'nonoy.cabrera', 'password' => 'password']);

    $this->assertGuest();
    expect(session('inertia.flash_data.error.message'))->toBe('This account is switched off. Ask your branch admin.');
});

it('sends a signed-in user away from the sign-in page', function (): void {
    $this->actingAs(Hotel::frontdesk())->get('/login')->assertRedirect('/home');
});

it('signs out without touching the shift', function (): void {
    $shift = Hotel::openShift();

    $this->actingAs(Hotel::frontdesk())->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
    expect($shift->fresh()->status->value)->toBe('open');
});

it('switches user to another member of the open shift by PIN', function (): void {
    $shift = Hotel::openShift();
    $rhea = Hotel::user('rhea.bautista');
    app(JoinShift::class)->handle($rhea, Hotel::branch()->id);

    $this->actingAs(Hotel::frontdesk())
        ->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device)
        ->from('/today')
        ->post('/switch-user', ['user_id' => $rhea->id, 'pin' => Hotel::PIN])
        ->assertRedirect('/today');

    $this->assertAuthenticatedAs($rhea);
    expect($shift->fresh()->status->value)->toBe('open')
        ->and(PinAttempt::query()->where('device_key', $this->device)->where('succeeded', true)->count())->toBe(1);
});

it('counts down the tries on a wrong switch PIN, and the third ends the session', function (): void {
    Hotel::openShift();
    $rhea = Hotel::user('rhea.bautista');
    app(JoinShift::class)->handle($rhea, Hotel::branch()->id);
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device);

    $this->from('/today')->post('/switch-user', ['user_id' => $rhea->id, 'pin' => '9999'])->assertRedirect('/today');
    expect(session('inertia.flash_data.error'))->toMatchArray(['code' => 'pin_invalid', 'tries_left' => 2]);

    $this->from('/today')->post('/switch-user', ['user_id' => $rhea->id, 'pin' => '9999']);
    expect(session('inertia.flash_data.error.tries_left'))->toBe(1);

    $this->from('/today')->post('/switch-user', ['user_id' => $rhea->id, 'pin' => '9999'])->assertRedirect('/login');
    $this->assertGuest();
});

it('refuses to switch to someone who is not on the shift', function (): void {
    Hotel::openShift();

    $this->actingAs(Hotel::frontdesk())
        ->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device)
        ->from('/today')
        ->post('/switch-user', ['user_id' => Hotel::user('rhea.bautista')->id, 'pin' => Hotel::PIN]);

    $this->assertAuthenticatedAs(Hotel::frontdesk());
    expect(session('inertia.flash_data.error.code'))->toBe('action_refused');
});

it('validates the switch form and refuses roles without front desk work', function (): void {
    Hotel::openShift();
    $this->actingAs(Hotel::frontdesk())->post('/switch-user', ['user_id' => '', 'pin' => '12'])->assertSessionHasErrors(['user_id', 'pin']);
    $this->actingAs(Hotel::user('grace.tolentino'))->post('/switch-user', ['user_id' => 1, 'pin' => '1234'])->assertForbidden();
});

it('makes a user without a PIN set one before anything else', function (): void {
    $liza = Hotel::user('liza.gonzaga');

    $this->actingAs($liza)->get('/home')->assertRedirect('/pin/setup');
    $this->get('/pin/setup')->assertInertia(fn (Assert $page) => $page->component('Auth/SetPin')->where('length', 4));

    $this->put('/pin/setup', ['pin' => '4826', 'pin_confirmation' => '4826'])->assertRedirect('/home');

    expect(Hash::check('4826', (string) $liza->fresh()->pin_hash))->toBeTrue();
});

it('validates the new PIN and refuses two different ones', function (): void {
    $liza = Hotel::user('liza.gonzaga');

    $this->actingAs($liza)->put('/pin/setup', ['pin' => '12', 'pin_confirmation' => ''])->assertSessionHasErrors(['pin', 'pin_confirmation']);
    $this->put('/pin/setup', ['pin' => '4826', 'pin_confirmation' => '4827']);

    expect($liza->fresh()->pin_hash)->toBeNull()
        ->and(session('inertia.flash_data.error.message'))->toBe('The two PINs are not the same. Type it again.');
});

it('lets a supervisor unlock PIN entry on a locked computer with their password', function (): void {
    PinLock::query()->create(['device_key' => $this->device, 'locked_at' => now()]);

    $this->actingAs(Hotel::frontdesk())
        ->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device)
        ->get('/pin/setup')
        ->assertRedirect('/home');

    $this->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device)
        ->from('/today')
        ->post('/pin/unlock', ['username' => 'carol.villanueva', 'password' => 'password'])
        ->assertRedirect('/today');

    expect(PinLock::query()->where('device_key', $this->device)->sole()->unlocked_by)->toBe(Hotel::supervisor()->id);
});

it('validates the unlock form and refuses a front desk password', function (): void {
    PinLock::query()->create(['device_key' => $this->device, 'locked_at' => now()]);
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device);

    $this->post('/pin/unlock', [])->assertSessionHasErrors(['username', 'password']);
    $this->post('/pin/unlock', ['username' => 'jessa.delacruz', 'password' => 'password']);

    expect(PinLock::query()->where('device_key', $this->device)->sole()->unlocked_at)->toBeNull();
});

it('shares pinLocked for this computer only', function (): void {
    PinLock::query()->create(['device_key' => $this->device, 'locked_at' => now()]);
    Hotel::openShift();

    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device)->get('/today')
        ->assertInertia(fn (Assert $page) => $page->where('pinLocked', true));
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, (string) Str::uuid())->get('/today')
        ->assertInertia(fn (Assert $page) => $page->where('pinLocked', false));
});

it('gives a new computer a device key cookie', function (): void {
    $this->get('/login')->assertCookie(EnsureDeviceKey::COOKIE, null, false);
});
