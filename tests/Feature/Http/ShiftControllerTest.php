<?php

declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\JoinShift;
use App\Actions\Stays\AddCharges;
use App\Enums\CashMovementKind;
use App\Enums\PrintDocument;
use App\Enums\ReasonKind;
use App\Enums\ShiftStatus;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\CashCount;
use App\Models\CashCountDraft;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\PrintLog;
use App\Models\ShiftSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| ShiftController, Phase 5 methods: shift cash (21) and its void, the close guide (22–25) one step
| at a time, the count draft, the slip (26) and its print log, and the bars (54, "… is closing").
| Happy path, validation and permission per method; the rules are tested on the actions
| (tests/Feature/Shifts). The count stays blind: responses before the count carry no expected cash.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 11:00:00'); // 7:00 PM in Manila, AM shift (ends 8:00 PM)
    $this->shift = Hotel::openShift();
    $this->device = (string) Str::uuid();
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, $this->device);
});

/** @return array<string, mixed> */
function movementForm(array $overrides = []): array
{
    return [
        'kind' => 'expense',
        'amount' => 25000,
        'note' => 'Drinking water refill · 5 gallons',
        'expense_category_id' => ExpenseCategory::query()->where('name', 'Drinking water')->value('id'),
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

/** Every key of a nested props array, for the blind-count checks. */
function propKeys(array $props): array
{
    $keys = [];
    $walk = function (array $a) use (&$walk, &$keys): void {
        foreach ($a as $k => $v) {
            $keys[] = (string) $k;
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($props);

    return array_values(array_unique($keys));
}

// ——— Shift cash (21) ———

it('shows shift cash with the entries, the three sums and no expected cash', function (): void {
    Hotel::checkIn();
    Hotel::movement(CashMovementKind::Expense, 25000, note: 'Drinking water refill');

    $response = $this->get('/shift')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Shift/Cash')
        ->where('period.period_name', 'AM')
        ->where('shift.overdue', false) // the shared shift prop is not shadowed by the page
        ->where('stats.opening', 500000)
        ->where('stats.expenses', 25000)
        ->where('stats.remittance', 0)
        ->has('movements', 1)
        ->where('movements.0.note', 'Drinking water refill')
        ->has('categories')
        ->has('reasons')
        ->has('approvers'));

    $props = $response->viewData('page')['props'];
    expect(propKeys($props))->not->toContain('expected_cash')->not->toContain('expected')->not->toContain('collected');
});

it('records an expense from the form and toasts it', function (): void {
    $this->post('/shift/movements', movementForm())->assertRedirect('/shift');

    expect(CashMovement::query()->count())->toBe(1)
        ->and(session('inertia.flash_data.toast.message'))->toBe('Expense of ₱250.00 saved.');
});

it('saves one entry when the same form is posted twice', function (): void {
    $form = movementForm();
    $this->post('/shift/movements', $form);
    $this->post('/shift/movements', $form);

    expect(CashMovement::query()->count())->toBe(1);
});

it('validates the cash entry form', function (array $bad, string $field): void {
    $this->post('/shift/movements', movementForm($bad))->assertSessionHasErrors($field);
    expect(CashMovement::query()->count())->toBe(0);
})->with([
    'no amount' => [['amount' => 0], 'amount'],
    'no note' => [['note' => ''], 'note'],
    'no key' => [['idempotency_key' => 'nope'], 'idempotency_key'],
    'no category' => [['expense_category_id' => null], 'expense_category_id'],
    'bad kind' => [['kind' => 'refund'], 'kind'],
    'no short shift' => [['kind' => 'shortage_payment', 'for_shift_id' => null], 'for_shift_id'],
]);

it('refuses a remittance above the drawer with a message that states no amount', function (): void {
    $this->post('/shift/movements', movementForm(['kind' => 'remittance', 'amount' => 600000, 'expense_category_id' => null]))->assertRedirect();

    expect(session('inertia.flash_data.error'))->toBe(['code' => 'not_enough_cash', 'message' => 'There is not that much cash in the drawer.'])
        ->and(CashMovement::query()->count())->toBe(0);
});

it('voids an entry with a reason and a supervisor PIN', function (): void {
    $m = Hotel::movement(CashMovementKind::Expense, 300000);

    $this->post("/shift/movements/{$m->id}/void", ['reason_id' => Hotel::reason(ReasonKind::VoidCashMovement, 'Typed wrong amount')->id, 'approver_id' => Hotel::supervisor()->id, 'pin' => Hotel::PIN])
        ->assertRedirect('/shift');

    expect($m->refresh()->voided_at)->not->toBeNull();
});

it('validates the void and keeps the entry on a wrong PIN', function (): void {
    $m = Hotel::movement(CashMovementKind::Expense, 300000);

    $this->post("/shift/movements/{$m->id}/void", [])->assertSessionHasErrors(['reason_id', 'approver_id', 'pin']);
    $this->post("/shift/movements/{$m->id}/void", ['reason_id' => Hotel::reason(ReasonKind::VoidCashMovement, 'Typed wrong amount')->id, 'approver_id' => Hotel::supervisor()->id, 'pin' => '9999']);

    expect(session('inertia.flash_data.error.code'))->toBe('pin_invalid')->and($m->refresh()->voided_at)->toBeNull();
});

it('keeps shift cash and the close guide from other roles and from non-members', function (): void {
    $this->actingAs(Hotel::user('grace.tolentino'))->get('/shift')->assertForbidden();
    $this->actingAs(Hotel::user('grace.tolentino'))->get('/shift/close')->assertForbidden();
    $this->actingAs(Hotel::user('mark.sumagaysay'))->get('/shift')->assertRedirect('/shift/open');
    $this->actingAs(Hotel::user('mark.sumagaysay'))->post('/shift/movements', movementForm())->assertRedirect('/shift/open');
    $this->actingAs(Hotel::user('mark.sumagaysay'))->post('/shift/close', ['step' => 'begin'])->assertRedirect('/shift/open');
    $this->actingAs(Hotel::supervisor())->get('/shift')->assertOk();
    expect(CashMovement::query()->count())->toBe(0);
});

// ——— Close guide (22–25) ———

it('shows step 1 with counts only and starts the close on Next', function (): void {
    Hotel::checkIn();
    Hotel::movement(CashMovementKind::Expense, 25000);

    $response = $this->get('/shift/close')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Shift/Close')
        ->where('step', 1)
        ->where('papers.0', ['label' => 'Expense receipts', 'count' => '1'])
        ->where('papers.4', ['label' => 'Key deposits taken / returned', 'count' => '1 / 0'])
        ->has('check'));
    $props = $response->viewData('page')['props'];
    expect(propKeys($props))->not->toContain('expected')->not->toContain('amount')->not->toContain('counted');

    $this->post('/shift/close', ['step' => 'begin'])->assertRedirect('/shift/close');
    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Closing);
});

it('sends no expected cash and no figure to derive it from until the count is confirmed', function (): void {
    $stay = Hotel::checkIn();
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 2]);
    $this->post('/shift/close', ['step' => 'begin']);

    $response = $this->get('/shift/close')->assertInertia(fn (Assert $page) => $page->where('step', 2)->has('count.bills')->missing('result')->missing('handover'));
    $json = json_encode($response->viewData('page')['props']);
    $expected = (string) Hotel::expectedCash()->centavos();

    expect($json)->not->toContain('expected')->not->toContain($expected)
        ->and(propKeys($response->viewData('page')['props']))->not->toContain('lines');

    // The shared shift prop on any page carries no money either.
    $today = $this->get('/today');
    expect(json_encode($today->viewData('page')['props']['shift']))->not->toContain($expected);
});

it('saves the count draft as JSON and restores the pieces on reload; the confirm deletes it', function (): void {
    $this->post('/shift/close', ['step' => 'begin']);

    $this->putJson('/shift/close/draft', ['pieces' => ['100000' => 8, '50000' => 6, 'coins' => 4500, 'total' => 1]])->assertOk()->assertJson(['saved' => true]);
    $this->putJson('/shift/close/draft', ['nothing' => true])->assertStatus(422);

    $this->get('/shift/close')->assertInertia(fn (Assert $page) => $page
        ->where('step', 2)
        ->where('count.pieces.100000', 8)
        ->where('count.pieces.50000', 6)
        ->where('count.pieces.coins', 4500)
        ->missing('count.pieces.total'));

    $this->post('/shift/close', ['step' => 'count', 'pieces' => ['100000' => 8, '50000' => 6, 'coins' => 4500], 'total' => 1])->assertRedirect('/shift/close');

    expect(CashCount::query()->firstOrFail()->total_cash?->centavos())->toBe(800000 + 300000 + 4500)
        ->and(CashCountDraft::query()->count())->toBe(0);
    $this->putJson('/shift/close/draft', ['pieces' => ['100000' => 1]])->assertStatus(409);
});

it('validates the count and refuses a count before the close is started', function (): void {
    $this->post('/shift/close', ['step' => 'count'])->assertSessionHasErrors('pieces');
    $this->post('/shift/close', ['step' => 'count', 'pieces' => ['coins' => 100]]);
    expect(session('inertia.flash_data.error.code'))->toBe('action_refused')->and(CashCount::query()->count())->toBe(0);
    $this->post('/shift/close', ['step' => 'jump'])->assertSessionHasErrors('step');
});

it('shows the result line by line after the count, and needs a reason when short', function (): void {
    Hotel::checkIn();
    $this->post('/shift/close', ['step' => 'begin']);
    $expected = Hotel::expectedCash()->centavos();
    $this->post('/shift/close', ['step' => 'count', 'pieces' => ['coins' => $expected - 5000]]);

    $this->get('/shift/close')->assertInertia(fn (Assert $page) => $page
        ->where('step', 3)
        ->where('result.expected', $expected)
        ->where('result.counted', $expected - 5000)
        ->where('result.variance', -5000)
        ->has('result.lines'));

    $this->post('/shift/close', ['step' => 'result']);
    expect(session('inertia.flash_data.error.message'))->toBe('Say what happened: pick a reason for the difference.');

    $this->post('/shift/close', ['step' => 'result', 'reason_id' => Hotel::reason(ReasonKind::Variance, 'Wrong change given')->id, 'note' => 'Gave wrong change to Room 203'])->assertRedirect('/shift/close');
    $this->get('/shift/close')->assertInertia(fn (Assert $page) => $page->where('step', 4)->has('handover.receivers'));
    expect($this->shift->refresh()->variance_amount?->centavos())->toBe(-5000);
});

it('does not offer the counter as receiver or approver on step 4', function (): void {
    app(JoinShift::class)->handle(Hotel::user('rhea.bautista'), Hotel::branch()->id);
    Hotel::closeToStep4(Hotel::frontdesk());

    $this->get('/shift/close')->assertInertia(function (Assert $page): void {
        $page->where('step', 4);
        $receivers = collect($page->toArray()['props']['handover']['receivers'])->pluck('id');
        expect($receivers)->not->toContain(Hotel::frontdesk()->id)->toContain(Hotel::user('rhea.bautista')->id);
    });
});

it('hands over, signs the other outgoing member out, and lands on the slip', function (): void {
    $rhea = Hotel::user('rhea.bautista');
    app(JoinShift::class)->handle($rhea, Hotel::branch()->id);
    DB::table('sessions')->insert(['id' => 'rhea-session', 'user_id' => $rhea->id, 'payload' => '', 'last_activity' => time()]);
    $shift = Hotel::closeToStep4(Hotel::frontdesk());

    $this->post('/shift/close', ['step' => 'handover', 'receiver_id' => Hotel::user('mark.sumagaysay')->id, 'received_cash' => $shift->counted_cash?->centavos(), 'pin' => Hotel::PIN])
        ->assertRedirect("/shift/{$shift->id}/report");

    expect($shift->refresh()->status)->toBe(ShiftStatus::Closed)
        ->and(ShiftSession::query()->where('status', ShiftStatus::Open)->value('opened_by'))->toBe(Hotel::user('mark.sumagaysay')->id)
        ->and(DB::table('sessions')->where('id', 'rhea-session')->exists())->toBeFalse();
});

it('validates the handover and keeps the shift closing on a wrong receiver PIN', function (): void {
    $shift = Hotel::closeToStep4(Hotel::frontdesk());

    $this->post('/shift/close', ['step' => 'handover'])->assertSessionHasErrors(['receiver_id', 'received_cash', 'pin']);
    $this->post('/shift/close', ['step' => 'handover', 'receiver_id' => Hotel::user('mark.sumagaysay')->id, 'received_cash' => $shift->counted_cash?->centavos(), 'pin' => '9999']);

    expect(session('inertia.flash_data.error.code'))->toBe('pin_invalid')
        ->and($shift->refresh()->status)->toBe(ShiftStatus::Closing);
});

it('closes without handover with a supervisor PIN', function (): void {
    $shift = Hotel::closeToStep4(Hotel::frontdesk());

    $this->post('/shift/close', ['step' => 'without_handover'])->assertSessionHasErrors(['approver_id', 'pin']);
    $this->post('/shift/close', ['step' => 'without_handover', 'approver_id' => Hotel::supervisor()->id, 'pin' => Hotel::PIN])->assertRedirect("/shift/{$shift->id}/report");

    expect($shift->refresh()->status)->toBe(ShiftStatus::Closed)->and(ShiftSession::query()->where('status', '!=', ShiftStatus::Closed)->count())->toBe(0);
});

it('lets "Not yet" reopen the shift before the count', function (): void {
    $this->post('/shift/close', ['step' => 'begin']);
    $this->post('/shift/close', ['step' => 'not_yet'])->assertRedirect('/today');

    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Open);
});

// ——— The second member while closing, and the bars ———

it('refuses the other member\'s payment while closing, keeps their page, and names who is closing', function (): void {
    $rhea = Hotel::user('rhea.bautista');
    app(JoinShift::class)->handle($rhea, Hotel::branch()->id);
    $stay = Hotel::checkIn();
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 1]);
    app(CloseShift::class)->begin(Hotel::frontdesk(), Hotel::branch()->id);
    $before = Payment::query()->count();

    $this->actingAs($rhea)->from("/stays/{$stay->id}")
        ->post("/stays/{$stay->id}/payments", ['method' => 'cash', 'amount' => 3500, 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect("/stays/{$stay->id}");

    expect(session('inertia.flash_data.error'))->toBe(['code' => 'shift_closing', 'message' => 'Jessa Mae is closing the shift. Nothing was saved.'])
        ->and(Payment::query()->count())->toBe($before);

    $this->actingAs($rhea)->get('/today')->assertInertia(fn (Assert $page) => $page->where('shift.status', 'closing')->where('shift.closing_by', 'Jessa Mae'));
});

it('shares the overdue and stale flags that drive the running-late bar', function (): void {
    $this->freezeClock('2026-11-12 12:31:00'); // 8:31 PM: 31 minutes past the AM end
    $this->get('/today')->assertInertia(fn (Assert $page) => $page->where('shift.overdue', true)->where('shift.stale', false));

    $this->freezeClock('2026-11-12 16:00:00'); // 4 hours past
    $this->get('/today')->assertInertia(fn (Assert $page) => $page->where('shift.overdue', true)->where('shift.stale', true));
});

it('leaves the shift as it was when a member signs out or the session expires mid-shift', function (): void {
    Hotel::checkIn();
    $before = $this->shift->refresh()->getRawOriginal();

    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
    // An expired session is simply gone: the next request is a guest.
    $this->flushSession();
    $this->get('/today')->assertRedirect('/login');

    expect($this->shift->refresh()->getRawOriginal())->toBe($before)
        ->and($this->shift->status)->toBe(ShiftStatus::Open);
});

// ——— Slip (26) ———

it('shows the slip of a closed shift to its members and logs each print, the second as a reprint', function (): void {
    Hotel::checkIn();
    Hotel::closeAndHandOver(Hotel::user('mark.sumagaysay'));
    $closed = $this->shift->refresh();

    $this->get("/shift/{$closed->id}/report")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Shift/ReportSlip')
        ->where('closed.z_no', 1)
        ->where('handover.received_by', 'Mark Anthony Sumagaysay')
        ->has('sections', 4));

    $this->postJson("/shift/{$closed->id}/report/print")->assertOk()->assertJson(['reprint' => false]);
    $this->postJson("/shift/{$closed->id}/report/print")->assertOk()->assertJson(['reprint' => true]);

    expect(PrintLog::query()->where('document', PrintDocument::ShiftSlip)->where('subject_id', $closed->id)->pluck('is_reprint')->all())->toBe([false, true]);
});

it('keeps the slip from front desks who were not on the shift, and an open shift has none', function (): void {
    Hotel::closeAndHandOver(Hotel::user('mark.sumagaysay'));
    $closed = $this->shift->refresh();
    $open = ShiftSession::query()->where('status', ShiftStatus::Open)->firstOrFail();

    $this->actingAs(Hotel::user('rhea.bautista'))->get("/shift/{$closed->id}/report")->assertForbidden();
    $this->actingAs(Hotel::user('rhea.bautista'))->postJson("/shift/{$closed->id}/report/print")->assertForbidden();
    $this->actingAs(Hotel::supervisor())->get("/shift/{$closed->id}/report")->assertOk();
    $this->actingAs(Hotel::user('mark.sumagaysay'))->get("/shift/{$open->id}/report")->assertNotFound();
    $this->actingAs(Hotel::user('grace.tolentino'))->get("/shift/{$closed->id}/report")->assertForbidden();
    expect(PrintLog::query()->count())->toBe(0);
});
