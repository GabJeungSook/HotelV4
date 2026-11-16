<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Floor;
use App\Models\Product;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\CurrentBranch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Who reaches branch setup (44–48), TRD §4.1: the branch's admin, and an owner who opened that
| branch. Every other role is refused (51). An owner on "All branches" is sent to open one. An
| admin never reads or writes another branch's rows.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->admin = Hotel::user('ramil.ortega');
    $this->pages = ['/admin/rooms' => 'Admin/Rooms', '/admin/rates' => 'Admin/Rates', '/admin/settings' => 'Admin/Settings', '/admin/users' => 'Admin/Users', '/admin/products' => 'Admin/Products'];
});

it('opens every setup page for the branch admin', function (): void {
    $this->actingAs($this->admin);

    foreach ($this->pages as $url => $component) {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }
});

it('puts the five setup pages in the admin menu', function (): void {
    $this->actingAs($this->admin)->get('/admin/rooms')->assertInertia(fn (Assert $page) => $page
        ->where('nav', fn ($nav) => collect($nav)->flatMap(fn ($g) => collect($g['items'])->pluck('key'))->intersect(['rooms', 'rates', 'products', 'users', 'settings'])->count() === 5));
});

it('refuses every other branch role', function (string $username): void {
    $this->actingAs(Hotel::user($username));

    foreach (array_keys($this->pages) as $url) {
        $this->get($url)->assertForbidden();
    }

    $this->put('/admin/settings', ['group' => 'stays', 'hourly_enabled' => false])->assertForbidden();
    $this->post('/admin/rooms', [])->assertForbidden();
    expect(Hotel::branch()->hourly_enabled)->toBeTrue();
})->with(['carol.villanueva', 'jessa.delacruz', 'grace.tolentino', 'dodong.lumayag', 'kiosk-gensan-1']);

it('sends an owner who has not opened a branch to the branch list (or 51 before it exists)', function (): void {
    $this->actingAs(Hotel::user('ramon.alvarez'));
    $response = $this->get('/admin/rooms');

    if (Route::has('owner.branches.index')) {
        $response->assertRedirect(route('owner.branches.index'));
    } else {
        $response->assertForbidden();
    }

    $this->put('/admin/settings', ['group' => 'stays', 'hourly_enabled' => false]);
    expect(Hotel::branch()->hourly_enabled)->toBeTrue();
});

it('lets an owner who opened a branch work on that branch', function (): void {
    $branchId = Hotel::branch()->id;
    // CurrentBranch is scoped: drop the one the seeders resolved, so the request's session is read.
    $this->app->forgetScopedInstances();
    $this->actingAs(Hotel::user('ramon.alvarez'))->withSession([CurrentBranch::SESSION_KEY => $branchId]);

    $this->get('/admin/rates')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Rates'));
    $this->from('/admin/settings')->put('/admin/settings', ['group' => 'stays', 'due_soon_minutes' => 45])->assertRedirect('/admin/settings');

    expect(Hotel::branch()->due_soon_minutes)->toBe(45);
});

it('never shows or changes another branch’s rows to an admin', function (): void {
    $other = Branch::factory()->create();
    $floor = Floor::factory()->create(['branch_id' => $other->id]);
    $type = RoomType::factory()->create(['branch_id' => $other->id, 'name' => 'Elsewhere type']);
    $room = Room::factory()->create(['branch_id' => $other->id, 'floor_id' => $floor->id, 'room_type_id' => $type->id, 'number' => 'Z9']);
    $product = Product::factory()->create(['branch_id' => $other->id, 'name' => 'Elsewhere soda']);
    $user = User::factory()->create(['branch_id' => $other->id, 'username' => 'elsewhere.staff']);

    $this->actingAs($this->admin);

    $this->get('/admin/rooms')->assertInertia(fn (Assert $page) => $page
        ->where('rooms', fn ($rows) => collect($rows)->pluck('number')->doesntContain('Z9'))
        ->where('types', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Elsewhere type')));
    $this->get('/admin/products')->assertInertia(fn (Assert $page) => $page->where('products', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Elsewhere soda')));
    $this->get('/admin/rates')->assertInertia(fn (Assert $page) => $page->where('rows', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Elsewhere type')));

    $form = ['number' => 'Z9', 'floor_id' => $floor->id, 'room_type_id' => $type->id, 'area' => 'Hacked', 'is_kiosk_enabled' => false, 'is_active' => true];
    $this->put("/admin/rooms/{$room->id}", $form)->assertNotFound();
    $this->put("/admin/room-types/{$type->id}", ['name' => 'Hacked', 'is_active' => true])->assertNotFound();
    $this->put("/admin/floors/{$floor->id}", ['number' => 77, 'label' => 'Hacked'])->assertNotFound();
    $this->put("/admin/products/{$product->id}", ['name' => 'Hacked', 'kind' => 'food_drink', 'category' => 'Drinks', 'report_group' => 'drinks', 'price_amount' => 100, 'tracks_stock' => false, 'is_active' => true])->assertNotFound();
    $this->put("/admin/users/{$user->id}", ['name' => 'Hacked', 'username' => 'hacked', 'role' => 'admin'])->assertNotFound();
    $this->put('/admin/rates', ['room_type_id' => $type->id, 'stay_kind' => 'hourly', 'hours' => 6, 'amount' => 100])->assertNotFound();

    expect($room->fresh()?->area)->toBe('Main')
        ->and($type->fresh()?->name)->toBe('Elsewhere type')
        ->and($product->fresh()?->name)->toBe('Elsewhere soda')
        ->and($user->fresh()?->role->value)->toBe('frontdesk');
});
