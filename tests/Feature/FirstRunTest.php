<?php

declare(strict_types=1);

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Floor;
use App\Models\RoomType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| ROADMAP Phase 10 "Empty database first-run": on a database with migrations and no seed,
| `homi:create-owner` → the owner signs in → branches (55) → create the first branch (56) →
| its first admin signs in with the temporary password, changes it and sets a PIN → rooms (44)
| → rates (45) → the branch is active and the welcome page (59) lists it with its prices.
*/

beforeEach(function (): void {
    $this->freezeClock('2026-11-16 01:00:00'); // 9:00 AM Manila
});

it('goes from an empty database to a branch on the welcome page', function (): void {
    expect(User::query()->count())->toBe(0)->and(Branch::query()->count())->toBe(0);

    // Before anything exists, the public pages still render.
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    $this->get('/login')->assertOk();

    // The first owner, on the server.
    $this->artisan('homi:create-owner', ['--name' => 'Gabriel Santos', '--username' => 'gab'])
        ->expectsQuestion('Password (at least 8 characters)', 'first-owner-pass')
        ->expectsQuestion('Type the password again', 'first-owner-pass')
        ->assertSuccessful();

    // The owner signs in and lands on an owner page that copes with no branches.
    $this->post('/login', ['username' => 'gab', 'password' => 'first-owner-pass'])->assertRedirect();
    $owner = User::query()->where('username', 'gab')->sole();
    $this->assertAuthenticatedAs($owner);
    $this->get('/owner')->assertOk();
    $this->get('/owner/branches')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Owner/Branches'));
    $this->get('/owner/branches/create')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Owner/BranchForm')->where('sources', []));

    // 56: the first branch, with nothing to copy from.
    $this->post('/owner/branches', [
        'name' => 'Hotel Bagong Araw', 'address' => 'Rizal Street, Koronadal City', 'code_prefix' => 'HBA',
        'admin_name' => 'Noel Fabrigas', 'admin_username' => 'noel',
    ])->assertRedirect('/owner/branches')->assertSessionHasNoErrors();

    $branch = Branch::query()->sole();
    $temporary = (string) session('inertia.flash_data.created.password');
    expect($branch->status)->toBe(BranchStatus::SettingUp)->and($temporary)->not->toBe('');

    // The public landing renders throughout, signed out.
    $this->post('/logout');
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome'));

    // The first admin: temporary password → change it → set a PIN → home.
    $this->post('/login', ['username' => 'noel', 'password' => $temporary])->assertRedirect();
    $this->get('/admin/rooms')->assertRedirect('/password/change');
    $this->put('/password/change', ['password' => 'noel-own-pass', 'password_confirmation' => 'noel-own-pass'])->assertRedirect();
    $this->get('/admin/rooms')->assertRedirect('/pin/setup');
    $this->put('/pin/setup', ['pin' => '4826', 'pin_confirmation' => '4826'])->assertRedirect();

    // 44: a floor, a room type and two rooms.
    $this->get('/admin/rooms')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Rooms')->has('rooms', 0));
    $this->post('/admin/floors', ['number' => 1, 'label' => '1F'])->assertSessionHasNoErrors();
    $this->post('/admin/room-types', ['name' => 'Standard', 'description' => 'Double bed, fan, shared bath', 'is_active' => true])->assertSessionHasNoErrors();
    $floor = Floor::query()->withoutGlobalScopes()->sole();
    $type = RoomType::query()->withoutGlobalScopes()->sole();
    foreach (['101', '102'] as $number) {
        $this->post('/admin/rooms', ['number' => $number, 'floor_id' => $floor->id, 'room_type_id' => $type->id, 'area' => 'Main', 'is_kiosk_enabled' => false, 'is_active' => true])
            ->assertSessionHasNoErrors();
    }
    expect($branch->fresh()?->status)->toBe(BranchStatus::Active);

    // 45: the rates.
    $this->get('/admin/rates')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Rates'));
    foreach ([['hourly', 6, 25000], ['hourly', 12, 50000], ['overnight', 0, 70000]] as [$kind, $hours, $amount]) {
        $this->put('/admin/rates', ['room_type_id' => $type->id, 'stay_kind' => $kind, 'hours' => $hours, 'amount' => $amount])->assertSessionHasNoErrors();
    }
    $this->get('/admin/settings')->assertOk();
    $this->get('/admin/users')->assertOk();
    $this->get('/dashboard')->assertOk();

    // 59: the public landing and the sign-in both render once the branch is live.
    $this->post('/logout');
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    $this->get('/login')->assertOk();
});
