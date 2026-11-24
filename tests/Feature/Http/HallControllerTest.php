<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceKey;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\HallPackage;
use App\Models\Payment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| The event-hall HTTP endpoints: admin manages halls/packages, front desk books; both gated by the
| branch's event_hall_enabled switch. The booking rules themselves are tested on the actions.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->branch = Hotel::branch();
    $this->branch->update(['event_hall_enabled' => true]);
    $this->admin = Hotel::user('ramil.ortega');
});

it('lets an admin add halls and packages', function (): void {
    $this->actingAs($this->admin)->from('/admin/halls')
        ->post('/admin/halls', ['name' => 'Garden Pavilion', 'capacity' => 200, 'base_rate_amount' => 500000, 'is_active' => true])
        ->assertRedirect();
    $this->actingAs($this->admin)
        ->post('/admin/hall-packages', ['name' => 'Buffet A', 'kind' => 'food', 'pricing' => 'per_pax', 'unit_amount' => 35000, 'is_active' => true])
        ->assertRedirect();

    expect(Hall::query()->where('name', 'Garden Pavilion')->exists())->toBeTrue()
        ->and(HallPackage::query()->where('name', 'Buffet A')->exists())->toBeTrue();

    $this->actingAs($this->admin)->get('/admin/halls')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Admin/Halls')
        ->where('halls', fn ($h) => collect($h)->contains(fn ($x) => $x['name'] === 'Garden Pavilion'))
        ->has('packages'));
});

it('gates the hall module behind the branch switch', function (): void {
    $this->branch->update(['event_hall_enabled' => false]);

    $this->actingAs($this->admin)->get('/admin/halls')->assertForbidden();

    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, (string) Str::uuid());
    Hotel::openShift();
    $this->get('/halls')->assertForbidden();
});

it('lets front desk book a hall with a deposit over HTTP', function (): void {
    $hall = Hall::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, (string) Str::uuid());
    Hotel::openShift();

    $this->get('/halls')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Halls/Index')->has('halls')->has('bookings'));

    $this->from('/halls')->post('/halls', [
        'hall_id' => $hall->id,
        'customer_name' => 'Reyes Party',
        'event_date' => '2026-11-20',
        'start_time' => '14:00',
        'end_time' => '18:00',
        'pax' => 30,
        'items' => [
            ['label' => 'Venue', 'pricing' => 'flat', 'unit_amount' => 500000, 'quantity' => 1],
            ['label' => 'Food', 'pricing' => 'per_pax', 'unit_amount' => 30000],
        ],
        'deposit_amount' => 400000,
        'method' => 'cash',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect('/halls');

    $booking = HallBooking::query()->where('hall_id', $hall->id)->sole();
    expect($booking->quoted_amount->centavos())->toBe(1400000) // 5,000 + 30×300
        ->and($booking->items()->count())->toBe(2)
        ->and((int) Payment::query()->where('hall_booking_id', $booking->id)->where('kind', 'deposit_in')->sum('amount'))->toBe(400000);
});
