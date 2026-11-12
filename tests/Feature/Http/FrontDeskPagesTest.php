<?php

declare(strict_types=1);

use App\Actions\Shifts\JoinShift;
use App\Actions\Stays\AddCharges;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| ShiftController (2, 2b), TodayController (62), BoardController (4, 4b, 4c), HomeController,
| the role and shift-membership middleware, the server-built navigation and the no-permission
| page (51). TRD §4.1, §4.11; APP_FLOW §1–2.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00');
});

/**
 * Every key anywhere in a nested array, for the "no money on front desk pages" checks.
 *
 * @param  array<mixed>  $data
 * @return list<string>
 */
function frontDeskKeys(array $data): array
{
    $keys = [];

    foreach ($data as $key => $value) {
        $keys[] = (string) $key;

        if (is_array($value)) {
            $keys = [...$keys, ...frontDeskKeys($value)];
        }
    }

    return $keys;
}

/**
 * The board's floors after its deferred prop has loaded, for the front desk user.
 *
 * @return list<array{label: string, rooms: list<array<string, mixed>>}>
 */
function boardFloors(string $url): array
{
    $floors = [];

    test()->actingAs(Hotel::frontdesk())->get($url)->assertInertia(function (Assert $page) use (&$floors): void {
        $page->loadDeferredProps(function (Assert $reload) use (&$floors): void {
            $floors = $reload->toArray()['props']['board']['floors'];
        });
    });

    return $floors;
}

it('shows Open shift (2) when the branch has no open shift', function (): void {
    $this->actingAs(Hotel::frontdesk())->get('/shift/open')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shift/Open')
            ->where('mode', 'open')
            ->where('can_change_freely', true)
            ->where('last_counted', null)
            ->has('definitions', 2));
});

it('opens the shift and goes to Today', function (): void {
    $this->actingAs(Hotel::frontdesk())->post('/shift/open', ['opening_cash' => 500000])->assertRedirect('/today');

    $shift = ShiftSession::query()->sole();
    expect([$shift->period_name, $shift->opening_cash->centavos(), $shift->opened_by])->toBe(['AM', 500000, Hotel::frontdesk()->id]);
});

it('validates the drawer count', function (): void {
    $this->actingAs(Hotel::frontdesk())->post('/shift/open', ['opening_cash' => -5])->assertSessionHasErrors('opening_cash');
    $this->post('/shift/open', [])->assertSessionHasErrors('opening_cash');

    expect(ShiftSession::query()->count())->toBe(0);
});

it('shows Join (2b) when a shift is open, and joins it', function (): void {
    Hotel::openShift();
    $rhea = Hotel::user('rhea.bautista');

    $this->actingAs($rhea)->get('/shift/open')
        ->assertInertia(fn (Assert $page) => $page->component('Shift/Join')->where('frontdesks', 1)->where('max_frontdesks', 2)->where('shift.opened_by', Hotel::frontdesk()->name));

    $this->post('/shift/join')->assertRedirect('/today');
    expect(ShiftMember::query()->where('user_id', $rhea->id)->exists())->toBeTrue();
});

it('refuses a third front desk and a second open shift', function (): void {
    Hotel::openShift();
    app(JoinShift::class)->handle(Hotel::user('rhea.bautista'), Hotel::branch()->id);

    $this->actingAs(Hotel::user('mark.sumagaysay'))->from('/shift/open')->post('/shift/join')->assertRedirect('/shift/open');
    expect(session('inertia.flash_data.error.message'))->toContain('already has two front desks');

    $this->post('/shift/open', ['opening_cash' => 0])->assertRedirect('/shift/open');
    expect(session('inertia.flash_data.error.code'))->toBe('shift_already_open')
        ->and(ShiftSession::query()->count())->toBe(1);
});

it('refuses the shift pages to roles without front desk work', function (string $username): void {
    $this->actingAs(Hotel::user($username))->get('/shift/open')->assertForbidden();
    $this->post('/shift/open', ['opening_cash' => 0])->assertForbidden();
    $this->post('/shift/join')->assertForbidden();
})->with(['ramil.ortega', 'grace.tolentino', 'dodong.lumayag', 'ramon.alvarez']);

it('sends a front desk user who is not on the shift to /shift/open, and lets a supervisor in', function (): void {
    $this->actingAs(Hotel::frontdesk())->get('/today')->assertRedirect('/shift/open');
    $this->actingAs(Hotel::supervisor())->get('/board')->assertRedirect('/shift/open');

    Hotel::openShift();
    $this->actingAs(Hotel::user('rhea.bautista'))->get('/board')->assertRedirect('/shift/open');
    $this->actingAs(Hotel::supervisor())->get('/board')->assertOk();
});

it('shows Today (62) with counts and names', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205');
    Hotel::checkIn('116', 6, guest: 'Elena Cruz');
    $this->travelClock(340); // 116 is due in 20 minutes

    $this->actingAs(Hotel::frontdesk())->get('/today')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Today')
            ->where('today.counts.due_soon', 1)
            ->where('today.counts.overdue', 0)
            ->where('today.due_soon_rooms', ['116'])
            ->where('today.leaving.0.guest', 'E. Cruz')
            ->where('today.leaving.0.minutes', 20)
            ->where('today.rooms.vacant', 44));
});

it('sends no money to the front desk on Today, and no expected cash or collected figures anywhere in the shared props', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205', tendered: 100000);
    app(AddCharges::class)->handle(Hotel::frontdesk(), Hotel::room('205')->activeStay()->firstOrFail(), [Hotel::product('Cup noodles')->id => 2]);

    $page = $this->actingAs(Hotel::frontdesk())->get('/today')->viewData('page');
    $keys = frontDeskKeys($page['props']);

    expect($keys)->not->toContain('expected_cash')
        ->not->toContain('collected')
        ->not->toContain('amount')
        ->not->toContain('balance')
        ->not->toContain('totals')
        ->not->toContain('opening_cash')
        ->and($page['props']['today']['counts']['unpaid'])->toBe(1)
        ->and($page['props']['today']['unpaid_rooms'])->toBe(['205']);
});

it('refuses Today to roles that do not work the front desk', function (string $username): void {
    $this->actingAs(Hotel::user($username))->get('/today')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Errors/Forbidden')->has('account.name')->where('home', '/home'));
})->with(['grace.tolentino', 'ramil.ortega', 'dodong.lumayag']);

it('loads the board (4) as a deferred prop with one row per room', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205');
    $stay = Hotel::room('205')->activeStay()->firstOrFail();

    $this->actingAs(Hotel::frontdesk())->get('/board')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Board/Index')
            ->missing('board')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('board.total', 46)
                ->has('board.floors', 3)
                ->where('board.floors.1.rooms.4.number', '205')
                ->where('board.floors.1.rooms.4.status', 'occupied')
                ->where('board.floors.1.rooms.4.guest', 'R. Manalastas')
                ->where('board.floors.1.rooms.4.stayId', $stay->id)
                ->where('board.floors.1.rooms.4.balance', null)));
});

it('searches the board by room number and checked-in guest name only (4c)', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205');

    $search = fn (string $q): array => boardFloors('/board?q='.$q);

    expect($search('manalastas'))->toHaveCount(1)
        ->and($search('manalastas')[0]['rooms'][0]['number'])->toBe('205')
        ->and($search('12'))->not->toBeEmpty()
        ->and($search('manalo'))->toBe([]);
});

it('marks due out and overdue rooms on the board', function (): void {
    Hotel::openShift();
    Hotel::checkIn('101', 6);
    Hotel::checkIn('102', 12);
    $this->travelClock(360 + 5); // 101 five minutes over, 102 still has 5 h 55 m

    $rooms = collect(boardFloors('/board')[0]['rooms'])->keyBy('number');

    expect($rooms['101']['status'])->toBe('overdue')
        ->and($rooms['101']['minutes'])->toBe(-5)
        ->and($rooms['102']['status'])->toBe('occupied');
});

it('refuses the board to roles without front desk work', function (): void {
    Hotel::openShift();

    $this->actingAs(Hotel::user('ramil.ortega'))->get('/board')->assertForbidden();
});

it('builds the navigation on the server from the role and the pages that exist', function (): void {
    Hotel::openShift();

    $nav = $this->actingAs(Hotel::frontdesk())->get('/today')->viewData('page')['props']['nav'];

    expect($nav)->toBe([
        ['heading' => 'Front desk', 'items' => [
            ['key' => 'today', 'label' => 'Today', 'route' => '/today', 'icon' => 'i-lucide-clock', 'subtitle' => null],
            ['key' => 'board', 'label' => 'Room board', 'route' => '/board', 'icon' => 'i-lucide-bed', 'subtitle' => null],
            ['key' => 'pos', 'label' => 'POS', 'route' => '/pos', 'icon' => 'i-lucide-shopping-cart', 'subtitle' => null],
            ['key' => 'stock', 'label' => 'Stock', 'route' => '/stock', 'icon' => 'i-lucide-package', 'subtitle' => null],
        ]],
        ['heading' => 'Shift', 'items' => [
            ['key' => 'shift', 'label' => 'Shift cash', 'route' => '/shift', 'icon' => 'i-lucide-banknote', 'subtitle' => null],
        ]],
    ]);

    $adminNav = $this->actingAs(Hotel::user('ramil.ortega'))->get('/dashboard')->viewData('page')['props']['nav'];
    expect(collect($adminNav)->pluck('items')->flatten(1)->pluck('key')->all())->toBe(['dashboard']);
});

it('shares the open shift without any money', function (): void {
    Hotel::openShift();

    $this->actingAs(Hotel::frontdesk())->get('/today')
        ->assertInertia(fn (Assert $page) => $page
            ->where('shift.period_name', 'AM')
            ->where('shift.members.0.name', Hotel::frontdesk()->name)
            ->where('shift.overdue', false)
            ->missing('shift.opening_cash')
            ->missing('shift.expected_cash'));
});

it('shows placeholder homes for pages of later phases', function (string $username, string $url, string $layout): void {
    $this->actingAs(Hotel::user($username))->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Placeholder')->where('layout', $layout));
})->with([
    ['ramil.ortega', '/dashboard', 'backoffice'],
    ['ramon.alvarez', '/owner', 'owner'],
    ['kiosk-gensan-1', '/kiosk', 'kiosk'],
]);

it('answers the heartbeat for a signed-in user', function (): void {
    $this->actingAs(Hotel::frontdesk())->get('/heartbeat')->assertNoContent();
    auth()->logout();
    $this->get('/heartbeat')->assertRedirect('/login');
});
