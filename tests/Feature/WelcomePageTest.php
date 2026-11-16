<?php

declare(strict_types=1);

use App\Enums\BranchStatus;
use App\Enums\StayKind;
use App\Models\Branch;
use App\Models\Rate;
use App\Models\RoomType;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Welcome page (59, TRD §4.11): branches with welcome_enabled and status active; per room type
| the lowest hourly and overnight rate read live from `rates`; no availability, no booking.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00');
});

it('shows the room types with their lowest hourly and overnight prices from the rate table', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('branch.name', 'Hotel Dalisay GenSan')
            ->where('branch.place', 'General Santos City')
            ->where('branch.deposit.amount', 20000)
            ->has('types', 3)
            ->where('types.0.name', 'Standard')
            ->where('types.0.price', ['amount' => 35000, 'hours' => 6])
            ->where('types.0.overnight', 75000)
            ->where('types.0.photo', '/images/photos/room-standard.jpg')
            ->where('types.1.price.amount', 45000)
            ->where('types.2.overnight', 130000)
            ->where('year', 2026)
            ->has('hero', 3)
            ->missing('rooms')
            ->missing('available'));
});

it('follows a rate change at once', function (): void {
    $standard = RoomType::query()->withoutGlobalScopes()->where('name', 'Standard')->firstOrFail();
    Rate::query()->withoutGlobalScopes()->where('room_type_id', $standard->id)->where('stay_kind', StayKind::Hourly)->where('hours', 12)
        ->update(['amount' => Money::ofPesos(300)->centavos()]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('types.0.price', ['amount' => 30000, 'hours' => 12]));
});

it('leaves out the overnight price when the branch does not sell overnight, and the deposit line when it is off', function (): void {
    Branch::query()->update(['overnight_enabled' => false, 'deposit_enabled' => false]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('types.0.overnight', null)
        ->where('branch.deposit', null));
});

it('lists only active branches that are on the welcome page, with the picker data when there are several', function (): void {
    $other = Branch::factory()->create(['name' => 'Hotel Dalisay Polomolok', 'status' => BranchStatus::Active, 'address' => 'National Highway, Polomolok']);
    Branch::factory()->create(['name' => 'Hidden Branch', 'status' => BranchStatus::Active, 'welcome_enabled' => false]);
    Branch::factory()->create(['name' => 'Closed Branch', 'status' => BranchStatus::Inactive]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->has('branches', 2)
        ->where('branches.0.name', 'Hotel Dalisay GenSan')
        ->where('branches.1.name', 'Hotel Dalisay Polomolok'));

    $this->get('/?branch='.$other->id)->assertInertia(fn (Assert $page) => $page
        ->where('branch.name', 'Hotel Dalisay Polomolok')
        ->where('types', []));
});

it('shows nothing about a branch that is still being set up', function (): void {
    Branch::query()->update(['status' => BranchStatus::SettingUp]);

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('branch', null)
        ->where('branches', [])
        ->where('types', []));
});

it('credits only the photos in use', function (): void {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('credits', fn ($credits): bool => collect($credits)->pluck('file')->contains('lobby.jpg')
            && collect($credits)->pluck('file')->contains('room-suite.jpg')
            && ! collect($credits)->pluck('file')->contains('signin-morning.jpg')));
});

it('is open to signed-in staff too, whose button goes to their home', function (): void {
    $this->actingAs(Hotel::frontdesk())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome')->where('home', '/home'));
});

it('serves the first visit through the app Blade root with Ziggy routes', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('<script data-page="app" type="application/json">', false)
        ->assertSee('Ziggy.routes', false)
        ->assertSee('"welcome":{"uri":"\/"', false);
});
