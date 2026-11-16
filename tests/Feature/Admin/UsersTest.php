<?php

declare(strict_types=1);

use App\Actions\Admin\ResetUserPassword;
use App\Actions\Admin\ResetUserPin;
use App\Actions\Admin\SaveUser;
use App\Actions\Admin\SetUserActive;
use App\Enums\Role;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\Floor;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Users (47): SaveUser, SetUserActive, ResetUserPin, ResetUserPassword and UserController —
| DATABASE_SCHEMA §4.2, §4.6; APP_FLOW §8.2. Never an owner, never another branch's user.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->admin = Hotel::user('ramil.ortega');
    $this->branchId = Hotel::branch()->id;
    $this->floors = Floor::query()->where('branch_id', $this->branchId)->orderBy('number')->pluck('id')->all();
    $this->save = fn (?int $id, string $name, string $username, Role $role, array $floors = []) => app(SaveUser::class)->handle($this->admin, $this->branchId, $id, $name, $username, $role, $floors);
});

it('adds a user with a temporary password shown once, no PIN, and an audit row without the password', function (): void {
    ['user' => $user, 'password' => $password] = ($this->save)(null, 'Ana Reyes', 'Ana.Reyes', Role::Frontdesk);

    expect($user->username)->toBe('ana.reyes')
        ->and($user->branch_id)->toBe($this->branchId)
        ->and($user->pin_hash)->toBeNull()
        ->and($user->fresh()?->must_change_password)->toBeTrue()
        ->and($password)->toHaveLength(10)
        ->and(Hash::check((string) $password, $user->fresh()?->password ?? ''))->toBeTrue();

    $log = AuditLog::query()->where('action', 'user.created')->sole();
    expect(json_encode([$log->before_json, $log->after_json]))->not->toContain((string) $password)
        ->and($log->after_json['role'])->toBe('frontdesk');
});

it('assigns floors to a housekeeper only, and clears them when the role changes', function (): void {
    ['user' => $hk] = ($this->save)(null, 'Bong Dela Peña', 'bong', Role::Housekeeper, [$this->floors[0], $this->floors[1]]);
    expect($hk->floors()->pluck('floors.id')->all())->toEqualCanonicalizing([$this->floors[0], $this->floors[1]]);

    ($this->save)($hk->id, 'Bong Dela Peña', 'bong', Role::Housekeeper, [$this->floors[2]]);
    expect($hk->floors()->pluck('floors.id')->all())->toBe([$this->floors[2]]);

    ($this->save)($hk->id, 'Bong Dela Peña', 'bong', Role::Backoffice, [$this->floors[0]]);
    expect($hk->floors()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'user.updated')->count())->toBe(2);
});

it('refuses an owner role, a username in use, another branch’s floor and changing your own role', function (): void {
    expect(fn () => ($this->save)(null, 'Boss', 'boss', Role::Owner))->toThrow(ActionRefused::class, 'Owner accounts');
    expect(fn () => ($this->save)(null, 'Jessa Two', 'jessa.delacruz', Role::Frontdesk))->toThrow(ActionRefused::class, 'jessa.delacruz is already used');
    expect(fn () => ($this->save)(null, 'Hk', 'hk', Role::Housekeeper, [Floor::factory()->create()->id]))->toThrow(ActionRefused::class, 'floors of this branch');
    expect(fn () => ($this->save)($this->admin->id, $this->admin->name, $this->admin->username, Role::Supervisor))->toThrow(ActionRefused::class, 'your own role');

    expect(User::query()->whereIn('username', ['boss', 'hk'])->exists())->toBeFalse();
});

it('refuses to edit an owner or a user of another branch', function (): void {
    $owner = Hotel::user('ramon.alvarez');
    $stranger = User::factory()->create();

    expect(fn () => ($this->save)($owner->id, 'X', 'x1', Role::Admin))->toThrow(ModelNotFoundException::class);
    expect(fn () => ($this->save)($stranger->id, 'X', 'x2', Role::Admin))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(ResetUserPin::class)->handle($this->admin, $this->branchId, $stranger->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(ResetUserPassword::class)->handle($this->admin, $this->branchId, $stranger->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(SetUserActive::class)->handle($this->admin, $this->branchId, $stranger->id, false))->toThrow(ModelNotFoundException::class);

    expect($stranger->fresh()?->pin_hash)->not->toBeNull();
});

it('resets a PIN to NULL with an audit row', function (): void {
    $jessa = Hotel::frontdesk();
    app(ResetUserPin::class)->handle($this->admin, $this->branchId, $jessa->id);

    expect($jessa->fresh()?->pin_hash)->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.pin_reset')->sole()->subject_id)->toBe($jessa->id);
});

it('resets a password to a new temporary one and clears the sign-in lock', function (): void {
    $jessa = Hotel::frontdesk();
    expect($jessa->must_change_password)->toBeFalse();
    $jessa->forceFill(['failed_logins' => 5, 'locked_until' => now()->addMinutes(10)])->save();

    $password = app(ResetUserPassword::class)->handle($this->admin, $this->branchId, $jessa->id);
    $fresh = $jessa->fresh();

    expect(Hash::check($password, $fresh?->password ?? ''))->toBeTrue()
        ->and(Hash::check('password', $fresh?->password ?? ''))->toBeFalse()
        ->and($fresh?->must_change_password)->toBeTrue()
        ->and($fresh?->failed_logins)->toBe(0)
        ->and($fresh?->locked_until)->toBeNull()
        ->and(json_encode(AuditLog::query()->where('action', 'user.password_reset')->sole()->after_json))->not->toContain($password);
});

it('deactivates and reactivates a user, never deletes, and not yourself', function (): void {
    $jessa = Hotel::frontdesk();
    app(SetUserActive::class)->handle($this->admin, $this->branchId, $jessa->id, false);
    expect($jessa->fresh()?->is_active)->toBeFalse();

    app(SetUserActive::class)->handle($this->admin, $this->branchId, $jessa->id, true);
    expect($jessa->fresh()?->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('subject_id', $jessa->id)->orderBy('id')->pluck('action')->all())->toBe(['user.deactivated', 'user.reactivated']);

    expect(fn () => app(SetUserActive::class)->handle($this->admin, $this->branchId, $this->admin->id, false))->toThrow(ActionRefused::class, 'your own account');
});

it('lets a deactivated user no longer through the role check', function (): void {
    $jessa = Hotel::frontdesk();
    app(SetUserActive::class)->handle($this->admin, $this->branchId, $jessa->id, false);

    $this->actingAs($jessa->fresh())->get('/shift/open')->assertForbidden();
});

// HTTP -------------------------------------------------------------------------------------

it('lists this branch’s users (47) only, with PIN state and floors, never an owner', function (): void {
    User::factory()->create(['username' => 'other.branch.user']);

    $this->actingAs($this->admin)->get('/admin/users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Users')
        ->where('users', fn ($users) => collect($users)->pluck('username')->doesntContain('other.branch.user')
            && collect($users)->pluck('username')->doesntContain('ramon.alvarez')
            && collect($users)->firstWhere('username', 'liza.gonzaga')['pin'] === false
            && collect($users)->firstWhere('username', 'dodong.lumayag')['pin'] === null
            && collect($users)->firstWhere('username', 'dodong.lumayag')['floors'] === ['1F', '2F'])
        ->where('roles', fn ($roles) => collect($roles)->pluck('value')->doesntContain('owner')));
});

it('flashes the temporary password once after adding a user or resetting a password', function (): void {
    $this->actingAs($this->admin)->post('/admin/users', ['name' => 'Ana Reyes', 'username' => 'ana.reyes', 'role' => 'frontdesk'])->assertRedirect('/admin/users');
    $secret = session('inertia.flash_data.secret');
    expect($secret['username'])->toBe('ana.reyes')
        ->and(Hash::check($secret['password'], Hotel::user('ana.reyes')->password))->toBeTrue();

    $this->post('/admin/users/'.Hotel::frontdesk()->id.'/password-reset')->assertRedirect('/admin/users');
    expect(session('inertia.flash_data.secret.username'))->toBe('jessa.delacruz');

    $this->post('/admin/users', ['name' => 'Boss', 'username' => 'boss', 'role' => 'owner'])->assertSessionHasErrors('role');
});

it('resets a PIN and deactivates over HTTP, and refuses another branch’s user', function (): void {
    $jessa = Hotel::frontdesk();
    $this->actingAs($this->admin)->from('/admin/users')->post("/admin/users/{$jessa->id}/pin-reset")->assertRedirect('/admin/users');
    $this->from('/admin/users')->put("/admin/users/{$jessa->id}/active", ['active' => false])->assertRedirect('/admin/users');

    expect($jessa->fresh()?->pin_hash)->toBeNull()
        ->and($jessa->fresh()?->is_active)->toBeFalse();

    $stranger = User::factory()->create();
    $this->post("/admin/users/{$stranger->id}/pin-reset")->assertNotFound();
});
