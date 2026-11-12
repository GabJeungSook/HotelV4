<?php

declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\JoinShift;
use App\Actions\Shifts\RecordCashMovement;
use App\Actions\Shifts\VoidCashMovement;
use App\Enums\ApprovalAction;
use App\Enums\CashMovementKind;
use App\Enums\ReasonKind;
use App\Exceptions\ActionRefused;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\NotEnoughCash;
use App\Exceptions\ShiftClosing;
use App\Models\Approval;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| Shift cash (21): RecordCashMovement and VoidCashMovement, APP_FLOW §4.15, TRD §4.5 and §4.12.
| TRD §10 "Shifts": expected cash formula with every movement kind · voided expense excluded.
| "Shift safeguards": double-tapped expense saves once · expense above expected cash refused ·
| reason needing a note or manager is enforced · second member refused while closing.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM in Manila, AM shift
    $this->branch = Hotel::branch();
    $this->shift = Hotel::openShift(openingCash: 500000);
});

function record(CashMovementKind $kind, int $amount, array $extra = []): CashMovement
{
    return app(RecordCashMovement::class)->handle(
        $extra['by'] ?? Hotel::frontdesk(), Hotel::branch()->id, $kind, Money::ofCentavos($amount), $extra['note'] ?? 'Entry',
        $extra['key'] ?? (string) Str::uuid(),
        array_key_exists('category', $extra) ? $extra['category'] : ExpenseCategory::query()->where('name', 'Drinking water')->first(),
        $extra['for'] ?? null, $extra['approver'] ?? null, $extra['pin'] ?? null, $extra['device'] ?? null,
    );
}

function voidEntry(CashMovement $m, string $reason = 'Typed wrong amount', ?string $note = null, ?User $approver = null): CashMovement
{
    return app(VoidCashMovement::class)->handle(
        Hotel::frontdesk(), Hotel::branch()->id, $m->id, Hotel::reason(ReasonKind::VoidCashMovement, $reason), $note,
        $approver ?? Hotel::supervisor(), Hotel::PIN, Hotel::device(),
    );
}

it('records an expense, a remittance and a shortage payment on the open shift with the person who typed them', function (): void {
    $short = ShiftSession::factory()->closed()->create(['branch_id' => $this->branch->id, 'business_date' => '2026-11-10', 'variance_amount' => -5000, 'variance_note' => 'Wrong change', 'counted_cash' => 0, 'expected_cash' => 5000]);

    $e = record(CashMovementKind::Expense, 25000, ['note' => 'Drinking water refill', 'by' => Hotel::user('rhea.bautista')]);
    $r = record(CashMovementKind::Remittance, 100000, ['note' => 'To Ma\'am Carol']);
    $s = record(CashMovementKind::ShortagePayment, 5000, ['note' => 'Paid back', 'for' => $short->id]);

    expect([$e->shift_session_id, $r->shift_session_id, $s->shift_session_id])->each->toBe($this->shift->id)
        ->and($e->created_by)->toBe(Hotel::user('rhea.bautista')->id)
        ->and($e->expense_category_id)->not->toBeNull()
        ->and($r->expense_category_id)->toBeNull()
        ->and($s->for_shift_id)->toBe($short->id);
});

it('works out expected cash from the opening cash, cash payments and every movement kind', function (): void {
    Hotel::checkIn(); // cash: room + ₱200 key deposit
    $afterCheckIn = Hotel::expectedCash();
    $short = ShiftSession::factory()->closed()->create(['branch_id' => $this->branch->id, 'business_date' => '2026-11-10', 'variance_amount' => -5000, 'variance_note' => 'x', 'counted_cash' => 0, 'expected_cash' => 5000]);

    record(CashMovementKind::Expense, 25000);
    record(CashMovementKind::Remittance, 100000);
    record(CashMovementKind::ShortagePayment, 5000, ['for' => $short->id]);

    expect($afterCheckIn->centavos())->toBeGreaterThan(500000)
        ->and(Hotel::expectedCash()->centavos())->toBe($afterCheckIn->centavos() - 25000 - 100000 + 5000);
});

it('leaves a voided expense visible and out of the expected cash', function (): void {
    $before = Hotel::expectedCash();
    $wrong = record(CashMovementKind::Expense, 300000, ['note' => 'Light bulbs × 3']);
    voidEntry($wrong, note: 'typed 3,000');
    record(CashMovementKind::Expense, 30000, ['note' => 'Light bulbs × 3']);

    $wrong->refresh();
    expect($wrong->voided_at)->not->toBeNull()
        ->and($wrong->void_reason)->toBe('Typed wrong amount — typed 3,000')
        ->and($wrong->approval_id)->not->toBeNull()
        ->and(Approval::query()->find($wrong->approval_id)?->action)->toBe(ApprovalAction::VoidCashMovement)
        ->and(CashMovement::query()->count())->toBe(2)
        ->and(Hotel::expectedCash()->centavos())->toBe($before->centavos() - 30000);
});

it('saves a double-tapped expense once', function (): void {
    $key = (string) Str::uuid();
    $first = record(CashMovementKind::Expense, 25000, ['key' => $key]);
    $second = record(CashMovementKind::Expense, 25000, ['key' => $key]);

    expect($second->id)->toBe($first->id)->and(CashMovement::query()->count())->toBe(1);
});

it('refuses an expense or remittance above the cash in the drawer without saying how much is there', function (): void {
    $expected = Hotel::expectedCash()->centavos();

    expect(fn () => record(CashMovementKind::Remittance, $expected + 1))->toThrow(NotEnoughCash::class, 'There is not that much cash in the drawer.');
    expect(fn () => record(CashMovementKind::Expense, $expected + 1))->toThrow(NotEnoughCash::class);
    expect(CashMovement::query()->count())->toBe(0);

    record(CashMovementKind::Remittance, $expected);
    expect(Hotel::expectedCash()->isZero())->toBeTrue();
});

it('needs an amount above zero, a note, a category for an expense and a short shift for a shortage payment', function (): void {
    expect(fn () => record(CashMovementKind::Expense, 0))->toThrow(ActionRefused::class);
    expect(fn () => record(CashMovementKind::Expense, 100, ['note' => '  ']))->toThrow(ActionRefused::class);
    expect(fn () => record(CashMovementKind::Expense, 100, ['category' => null]))->toThrow(ActionRefused::class, 'Pick an expense category.');
    expect(fn () => record(CashMovementKind::ShortagePayment, 100, ['for' => $this->shift->id]))->toThrow(ActionRefused::class, 'Pick the shift that was short.');
    expect(CashMovement::query()->count())->toBe(0);
});

it('asks a supervisor PIN for a category that needs a manager, and records the approval', function (): void {
    $food = ExpenseCategory::query()->where('name', 'Food for staff')->firstOrFail();

    expect(fn () => record(CashMovementKind::Expense, 15000, ['category' => $food]))->toThrow(ActionRefused::class, 'Food for staff needs a supervisor PIN.');
    expect(CashMovement::query()->count())->toBe(0);

    $m = record(CashMovementKind::Expense, 15000, ['category' => $food, 'approver' => Hotel::supervisor(), 'pin' => Hotel::PIN, 'device' => Hotel::device()]);

    expect(Approval::query()->where('action', ApprovalAction::ReasonRequiresManager)->where('subject_id', $m->id)->exists())->toBeTrue();
});

it('enforces the note of a void reason that needs one, and the approval, before voiding', function (): void {
    $m = record(CashMovementKind::Expense, 25000);

    expect(fn () => voidEntry($m, 'Other'))->toThrow(ActionRefused::class);
    expect(fn () => voidEntry($m, approver: Hotel::frontdesk()))->toThrow(ApprovalRefused::class);
    expect($m->refresh()->voided_at)->toBeNull();
});

it('refuses a cash movement by the other member once the close has started, naming who is closing', function (): void {
    app(JoinShift::class)->handle(Hotel::user('rhea.bautista'), $this->branch->id);
    app(CloseShift::class)->begin(Hotel::frontdesk(), $this->branch->id);

    expect(fn () => record(CashMovementKind::Expense, 100, ['by' => Hotel::user('rhea.bautista')]))
        ->toThrow(ShiftClosing::class, 'Jessa Mae is closing the shift. Nothing was saved.');
    expect(CashMovement::query()->count())->toBe(0);

    // "Not yet" opens the shift again and the save can be repeated (APP_FLOW §6 case 69).
    app(CloseShift::class)->notYet(Hotel::frontdesk(), $this->branch->id);
    record(CashMovementKind::Expense, 100, ['by' => Hotel::user('rhea.bautista')]);
    expect(CashMovement::query()->count())->toBe(1);
});

it('refuses to void an entry of a closed shift', function (): void {
    $m = record(CashMovementKind::Expense, 25000);
    Hotel::closeAndHandOver(Hotel::user('mark.sumagaysay'));

    expect(fn () => voidEntry($m))->toThrow(ActionRefused::class, 'This entry belongs to a closed shift');
    expect($m->refresh()->voided_at)->toBeNull();
});
