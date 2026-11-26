<?php

declare(strict_types=1);

use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\ExtendStay;
use App\Enums\PaymentMethod;
use App\Services\ReportService;
use App\Support\Clock;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Guest movements report (new): a per-guest log of arrivals, departures, extensions and transfers
| over a day/week/month, with arrivals by room type. Read-only through ReportService.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 10:00:00');
    Hotel::openShift();
    $this->date = app(Clock::class)->localDate();

    $this->a = Hotel::checkIn('101', 6);   // arrival
    $this->b = Hotel::checkIn('102', 12);  // arrival + will depart
    Hotel::checkIn('103', 6);              // arrival

    app(ExtendStay::class)->handle(Hotel::frontdesk(), $this->a->fresh(), 6, PaymentMethod::Cash, Money::ofPesos(5000), null, false, (string) Str::uuid());
    app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $this->b->fresh());

    // View the report a little after the movements (the open shift's window runs up to "now").
    $this->travelClock(30);
});

it('logs arrivals, departures and extensions with the arrivals-by-type mix', function (): void {
    $report = app(ReportService::class)->guestMovements(Hotel::branch()->id, $this->date, 'day', 'all');

    expect($report['counts']['arrival'])->toBe(3)
        ->and($report['counts']['extension'])->toBe(1)
        ->and($report['counts']['departure'])->toBe(1)
        ->and(collect($report['mix'])->sum('count'))->toBe(3)
        ->and(collect($report['rows'])->pluck('kind')->unique()->sort()->values()->all())->toBe(['arrival', 'departure', 'extension']);
});

it('filters to one movement kind', function (): void {
    $report = app(ReportService::class)->guestMovements(Hotel::branch()->id, $this->date, 'day', 'extension');

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0]['kind'])->toBe('extension');
});

it('renders for admin and exports CSV, but not for front desk', function (): void {
    $this->actingAs(Hotel::user('ramil.ortega'))->get(route('reports.guests', ['date' => $this->date]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports/Guests')->where('report.counts.arrival', 3));

    $csv = $this->actingAs(Hotel::user('ramil.ortega'))->get(route('reports.guests', ['date' => $this->date, 'export' => 'csv']));
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $this->actingAs(Hotel::frontdesk())->get(route('reports.guests', ['date' => $this->date]))->assertForbidden();
});
