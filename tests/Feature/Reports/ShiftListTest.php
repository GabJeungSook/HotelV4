<?php

declare(strict_types=1);

use App\Models\ShiftSession;
use App\Queries\ShiftCalendarQuery;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShiftSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Shift reports list (37a, mockup v6): filters, search, pages and the Calendar tab, and the rule
| that the open shift is listed without any money. Also the Today timeline (62) and the week of
| shifts (61) that Phase 11 added: names and times only.
*/

beforeEach(function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM Manila, Thu 12 Nov
    $this->am = ShiftSession::query()->where('z_no', 629)->sole();
    $this->backoffice = Hotel::user('grace.tolentino');
});

it('filters the list by status, shift and dates, and searches a Z number or a name', function (): void {
    $get = fn (string $query) => $this->actingAs($this->backoffice)->get("/reports/shifts?{$query}")->assertOk();

    $get('status=short')->assertInertia(fn (Assert $page) => $page->where('view', 'list')->has('list.rows', 1)->where('list.rows.0.z_no', 629));
    $get('status=open')->assertInertia(fn (Assert $page) => $page->has('list.rows', 1)->where('list.rows.0.status', 'open'));
    $get('period=AM')->assertInertia(fn (Assert $page) => $page->where('filters.period', 'AM')->where('list.rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['period_name'] === 'AM')));
    $get('from=2026-11-11&to=2026-11-11')->assertInertia(fn (Assert $page) => $page->where('list.rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['business_date'] === '2026-11-11')));
    $get('q=Z-0629')->assertInertia(fn (Assert $page) => $page->has('list.rows', 1)->where('list.rows.0.z_no', 629));
    $get('q=629')->assertInertia(fn (Assert $page) => $page->has('list.rows', 1));

    $name = $this->am->members()->with('user')->first()->user->name;
    $get('q='.urlencode(explode(' ', $name)[0]))->assertInertia(fn (Assert $page) => $page->where('list.rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['z_no'] === 629)));

    // Bad values are ignored, not errors.
    $get('status=nonsense&from=yesterday')->assertInertia(fn (Assert $page) => $page->where('filters.status', null)->where('filters.from', null)->has('list.rows', 3));
});

it('lists the open shift with no money at all', function (): void {
    $props = $this->actingAs($this->backoffice)->get('/reports/shifts?status=open')->viewData('page')['props'];

    expect($props['list']['rows'][0]['net_sales'])->toBeNull()
        ->and($props['list']['rows'][0]['variance'])->toBeNull();
});

it('shows the same shifts as week blocks on the Calendar tab', function (): void {
    $this->actingAs($this->backoffice)->get('/reports/shifts?view=calendar&week=2026-11-11')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'calendar')
        ->where('list', null)
        ->where('calendar.range', ['from' => '2026-11-09', 'to' => '2026-11-15'])
        ->has('calendar.days', 7)
        ->where('calendar.days.3.today', true)
        ->where('calendar.bands', fn ($bands) => collect($bands)->pluck('label')->contains('AM shift')));
});

it('builds calendar blocks that span a person\'s days in a row, marks who is on duty, and dashes the days not opened yet', function (): void {
    $week = app(ShiftCalendarQuery::class)->week(Hotel::branch()->id, '2026-11-12');
    $blocks = collect($week['bands'])->flatMap(fn ($b) => collect($b['rows'])->flatten(1));

    expect($blocks->where('live', true)->count())->toBeGreaterThan(0)
        ->and($blocks->where('future', true)->count())->toBeGreaterThan(0)
        ->and($blocks->every(fn ($b) => $b['start'] >= 1 && $b['start'] + $b['span'] - 1 <= 7))->toBeTrue();

    // No block on the same row overlaps another.
    foreach ($week['bands'] as $band) {
        foreach ($band['rows'] as $row) {
            $days = collect($row)->flatMap(fn ($b) => range($b['start'], $b['start'] + $b['span'] - 1));
            expect($days->count())->toBe($days->unique()->count());
        }
    }

    expect(json_encode($week))->not->toContain('net_sales')->not->toContain('collected')->not->toContain('variance');
});

it('gives Today a stays timeline with names and times only, never an amount', function (): void {
    // A member of the demo's open shift (Today needs shift membership).
    $fd = ShiftSession::query()->where('status', 'open')->sole()->members()->with('user')->firstOrFail()->user;

    $today = $this->actingAs($fd)->get('/today')->assertOk()->viewData('page')['props']['today'];
    $rooms = collect($today['timeline'])->flatMap(fn ($f) => $f['rooms']);
    // The demo has guests in house on the open shift.
    $room = $rooms->first(fn ($r) => in_array($r['kind'], ['occupied', 'due_out', 'overdue'], true));
    expect($room)->not->toBeNull()->and($rooms->firstWhere('kind', 'vacant')['checkInHref'] ?? null)->toStartWith('/stays/create?room=');

    expect($room['kind'])->toBeIn(['occupied', 'due_out', 'overdue'])
        ->and($room['start'])->not->toBeNull()
        ->and($room['person'])->not->toBeNull()
        ->and(json_encode($today['timeline']))->not->toContain('balance')->not->toContain('amount')->not->toContain('₱');
});
