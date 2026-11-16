<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Owner accounts (APP_FLOW §8.1, §8.2) and `homi:create-owner` (TRD §4.9 "First owner account").
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00');
    $this->owner = Hotel::user('ramon.alvarez');
});

it('lists owner accounts only', function (): void {
    $this->actingAs($this->owner)->get('/owner/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Owner/Accounts')
        ->has('accounts', 1)
        ->where('accounts.0.username', 'ramon.alvarez')
        ->where('accounts.0.is_me', true));
});

it('adds an owner with no branch and shows a temporary password once', function (): void {
    $this->actingAs($this->owner)->post('/owner/accounts', ['name' => 'Lourdes  Alvarez', 'username' => 'Lourdes.Alvarez'])
        ->assertRedirect('/owner/accounts')->assertSessionHasNoErrors();

    $new = User::query()->where('username', 'lourdes.alvarez')->sole();
    $flash = session('inertia.flash_data.created');

    expect($new->role)->toBe(Role::Owner)
        ->and($new->branch_id)->toBeNull()
        ->and($new->name)->toBe('Lourdes Alvarez')
        ->and($new->is_active)->toBeTrue()
        ->and(Hash::check($flash['password'], $new->password))->toBeTrue();

    $log = AuditLog::query()->where('action', 'owner.created')->sole();
    expect($log->actor_id)->toBe($this->owner->id)->and($log->subject_id)->toBe($new->id)->and($log->branch_id)->toBeNull()
        ->and(json_encode($log->after_json))->not->toContain($flash['password']);
});

it('refuses a taken username', function (): void {
    $this->actingAs($this->owner)->post('/owner/accounts', ['name' => 'Someone', 'username' => 'ramil.ortega'])
        ->assertSessionHasErrors(['username' => 'This username is taken.']);

    expect(User::query()->where('role', Role::Owner)->count())->toBe(1);
});

it('edits, deactivates and reactivates another owner, with audit logs', function (): void {
    $other = User::factory()->owner()->create(['name' => 'Lourdes Alvarez', 'username' => 'lourdes.alvarez']);

    $this->actingAs($this->owner)->patch("/owner/accounts/{$other->id}", ['name' => 'Lourdes A. Alvarez', 'username' => 'lourdes.alvarez', 'is_active' => true])
        ->assertRedirect('/owner/accounts');
    $this->actingAs($this->owner)->patch("/owner/accounts/{$other->id}", ['name' => 'Lourdes A. Alvarez', 'username' => 'lourdes.alvarez', 'is_active' => false]);

    expect($other->refresh()->name)->toBe('Lourdes A. Alvarez')->and($other->is_active)->toBeFalse();
    $this->actingAs($other)->get('/owner/branches')->assertForbidden();

    $this->actingAs($this->owner)->patch("/owner/accounts/{$other->id}", ['name' => 'Lourdes A. Alvarez', 'username' => 'lourdes.alvarez', 'is_active' => true]);
    expect($other->refresh()->is_active)->toBeTrue();

    expect(AuditLog::query()->where('subject_type', 'user')->where('subject_id', $other->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['owner.updated', 'owner.deactivated', 'owner.reactivated']);
});

it('does not let an owner deactivate their own account', function (): void {
    $this->actingAs($this->owner)->from('/owner/accounts')
        ->patch("/owner/accounts/{$this->owner->id}", ['name' => 'Ramon Alvarez', 'username' => 'ramon.alvarez', 'is_active' => false])
        ->assertRedirect('/owner/accounts');

    expect(session('inertia.flash_data.error.message'))->toBe('You cannot deactivate your own account.')
        ->and($this->owner->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('resets an owner password and shows the new one once', function (): void {
    $other = User::factory()->owner()->create(['username' => 'lourdes.alvarez']);

    $this->actingAs($this->owner)->patch("/owner/accounts/{$other->id}", ['name' => $other->name, 'username' => 'lourdes.alvarez', 'is_active' => true, 'reset_password' => true]);

    $flash = session('inertia.flash_data.created');
    expect($flash['kind'])->toBe('reset')
        ->and(Hash::check($flash['password'], $other->refresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'owner.password_reset')->where('subject_id', $other->id)->count())->toBe(1);
});

it('only edits owner accounts from here', function (): void {
    $admin = Hotel::user('ramil.ortega');

    $this->actingAs($this->owner)->patch("/owner/accounts/{$admin->id}", ['name' => 'Ramil Ortega', 'username' => 'ramil.ortega', 'is_active' => false])->assertNotFound();
    expect($admin->refresh()->is_active)->toBeTrue();
});

it('creates the first owner account from the command line', function (): void {
    $this->artisan('homi:create-owner')
        ->expectsQuestion('Full name', 'Gabriel  Santos')
        ->expectsQuestion('Username', 'Gab.Santos')
        ->expectsQuestion('Password (at least 8 characters)', 'a-long-secret')
        ->expectsQuestion('Type the password again', 'a-long-secret')
        ->assertSuccessful();

    $user = User::query()->where('username', 'gab.santos')->sole();
    expect($user->role)->toBe(Role::Owner)
        ->and($user->branch_id)->toBeNull()
        ->and($user->name)->toBe('Gabriel Santos')
        ->and(Hash::check('a-long-secret', $user->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'owner.created')->sole()->actor_id)->toBeNull();
});

it('refuses a short or mismatched password and a taken username on the command line', function (): void {
    $this->artisan('homi:create-owner', ['--name' => 'Gab Santos', '--username' => 'gab.santos'])
        ->expectsQuestion('Password (at least 8 characters)', 'short')
        ->expectsQuestion('Type the password again', 'short')
        ->assertFailed();

    $this->artisan('homi:create-owner', ['--name' => 'Gab Santos', '--username' => 'gab.santos'])
        ->expectsQuestion('Password (at least 8 characters)', 'a-long-secret')
        ->expectsQuestion('Type the password again', 'another-secret')
        ->assertFailed();

    $this->artisan('homi:create-owner', ['--name' => 'Gab Santos', '--username' => 'ramon.alvarez'])
        ->expectsQuestion('Password (at least 8 characters)', 'a-long-secret')
        ->expectsQuestion('Type the password again', 'a-long-secret')
        ->assertFailed();

    expect(User::query()->where('role', Role::Owner)->count())->toBe(1);
});
