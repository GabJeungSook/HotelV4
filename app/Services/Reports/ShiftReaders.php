<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\PrintDocument;
use App\Enums\ShiftStatus;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\DayClosing;
use App\Models\Payment;
use App\Models\PrintLog;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftHandover;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The shift readers of ReportService (SYSTEM_DESIGN §5.2): one shift's figures, its Z paper
 * (37b), its slip (26), its shift report (37) and the day paper (38b).
 *
 * A closed shift is read from its frozen totals, Z number, grand totals and frozen expected,
 * counted and variance; nothing is recomputed. An open (or closing) shift is a preview: the
 * same totals are built live by computeShiftTotals(), with the guests in house now standing in
 * for the forwarded-out rows, and the expected cash from CashMath.
 *
 * Used by ReportService only; it relies on its clock, stays, cash, days and queries.
 */
trait ShiftReaders
{
    /**
     * @return array{shift: ShiftSession, preview: bool, totals: array<array-key, mixed>, expected: int|null, counted: int|null, variance: int|null, note: string|null, grand_total_before: int, grand_total_after: int}
     */
    public function figures(ShiftSession $shift): array
    {
        if ($shift->status === ShiftStatus::Closed) {
            return [
                'shift' => $shift,
                'preview' => false,
                'totals' => $shift->totals ?? [],
                'expected' => $shift->expected_cash?->centavos(),
                'counted' => $shift->counted_cash?->centavos(),
                'variance' => $shift->variance_amount?->centavos(),
                'note' => $shift->variance_note,
                'grand_total_before' => $shift->grand_total_before?->centavos() ?? 0,
                'grand_total_after' => $shift->grand_total_after?->centavos() ?? 0,
            ];
        }

        $now = $this->clock->now();
        $inHouse = collect($this->stays->inHouse($shift->branch_id))->map(fn (array $row): ShiftForwardedStay => new ShiftForwardedStay([
            'shift_session_id' => $shift->id,
            'stay_id' => $row['stay_id'],
            'room_id' => $row['room_id'],
            'guest_name' => $row['guest_name'],
            'expected_checkout_at' => $row['expected_checkout_at'],
            'unpaid_amount' => $row['unpaid'],
            'key_deposit_held' => $row['key_deposit_held'],
            'guest_deposit_held' => $row['guest_deposit_held'],
        ]));
        $totals = $this->computeShiftTotals($shift, $this->forwardedIn($shift), $inHouse, $now);

        $payments = Payment::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get();
        $movements = CashMovement::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get();
        $expected = $this->cash->expectedFromRows($shift->opening_cash ?? Money::zero(), $payments, $movements)->centavos();
        $before = $this->lastGrandTotal($shift->branch_id);

        return [
            'shift' => $shift,
            'preview' => true,
            'totals' => $totals,
            'expected' => $expected,
            'counted' => null,
            'variance' => null,
            'note' => null,
            'grand_total_before' => $before,
            'grand_total_after' => $before + Totals::int($totals, 'sales.total'),
        ];
    }

    /**
     * The Z-report of one shift (37b): one array of sections and lines (TRD §4.10). For an open
     * shift it is the preview: no Z number, marked "Preview. Do not sign.".
     *
     * @return array<string, mixed>
     */
    public function zReport(ShiftSession $shift): array
    {
        $f = $this->figures($shift);
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($shift->branch_id);
        $receiver = ShiftHandover::query()->with('receiver')->where('from_shift_id', $shift->id)->first()?->receiver;
        $closer = $shift->closed_by === null ? null : User::query()->find($shift->closed_by);
        $variance = $f['variance'];

        $paper = ZPaper::build($f['totals'], [
            'kind' => 'shift',
            'preview' => $f['preview'],
            'number' => $shift->z_no === null ? null : self::zNumber($shift->z_no),
            'branch' => $branch->name,
            'title' => "Z-READ · {$shift->period_name} shift · ".$this->businessDate($shift->business_date),
            'opened' => 'Opened '.$this->time($shift->opened_at),
            'closed' => $shift->closed_at === null ? 'Still open' : 'Closed '.$this->time($shift->closed_at),
            'printed' => $this->printedLine($shift->branch_id, PrintDocument::ZReport, $shift->id),
            'tin' => $branch->tin,
            'registered_name' => $branch->registered_name,
            'expected' => $f['expected'],
            'counted' => $f['counted'],
            'short' => $variance !== null && $variance < 0 ? -$variance : 0,
            'over' => $variance !== null && $variance > 0 ? $variance : 0,
            'variance_note' => $f['note'],
            'grand_total_before' => $f['grand_total_before'],
            'grand_total_after' => $f['grand_total_after'],
            'signatures' => [
                ['name' => $closer?->name, 'role' => 'Closed by'],
                ['name' => $receiver?->name, 'role' => 'Received by'],
                ['name' => null, 'role' => 'Back office · name and date'],
            ],
        ]);

        return [...$paper, 'shift_id' => $shift->id, 'z_no' => $shift->z_no];
    }

    /**
     * The 80 mm slip of a closed shift (26): the same frozen totals in the slip's lines.
     *
     * @return list<list<array{label: string, amount?: int, text?: string, bold?: bool}>>
     */
    public function slip(ShiftSession $shift): array
    {
        $f = $this->figures($shift);

        return ZPaper::slip($f['totals'], $f['expected'], $f['counted']);
    }

    /**
     * The shift report (37): sales charged, the drawer, and the three roll-forwards, every
     * figure with the code of the ledger rows behind it.
     *
     * @return array<string, mixed>
     */
    public function shift(ShiftSession $shift): array
    {
        $f = $this->figures($shift);
        $t = $f['totals'];
        $i = fn (string $path): int => Totals::int($t, $path);
        $checks = [];

        $sales = [];
        foreach (['room' => 'Room', 'extension' => 'Extension', 'transfer_difference' => 'Transfer difference', 'food_drink' => 'Food & drinks', 'amenity' => 'Amenities', 'damage' => 'Damages', 'event_hall' => 'Event hall', 'forfeited_deposit' => 'Forfeited deposits'] as $kind => $label) {
            // Room is shown net of its discounts (37, 37c); the discounts are named on 37b.
            $amount = $kind === 'room' ? $i('sales.room') + $i('sales.discount') : $i("sales.{$kind}");

            if (in_array($kind, ['event_hall', 'forfeited_deposit'], true) && $amount === 0) {
                continue;
            }

            $sales[] = ['key' => $kind, 'label' => $label, 'count' => $kind === 'forfeited_deposit' ? $i('exceptions.forfeited_deposits.count') : $i("sales_counts.{$kind}"), 'amount' => $amount, 'drill' => "sales:{$kind}"];
        }

        $total = $i('sales.total');
        if (array_sum(array_column($sales, 'amount')) !== $total) {
            $checks[] = 'The sales lines do not add up to the sales charged.';
        }

        $cash = [
            ['label' => 'Opening cash', 'amount' => $i('cash.opening')],
            ['label' => 'Collected from guests', 'amount' => $i('drawer.cash_collected'), 'drill' => 'cash_collected'],
            ['label' => 'Deposits taken ('.$i('drawer.deposits_taken.count').')', 'amount' => $i('drawer.deposits_taken.amount'), 'drill' => 'deposits_taken'],
            ['label' => 'Deposits returned ('.$i('drawer.deposits_returned.count').')', 'amount' => -$i('drawer.deposits_returned.amount'), 'drill' => 'deposits_returned'],
        ];
        if ($i('drawer.guest_deposits.amount') !== 0) {
            $cash[] = ['label' => 'Guest deposits, net ('.$i('drawer.guest_deposits.count').')', 'amount' => $i('drawer.guest_deposits.amount'), 'drill' => 'guest_deposits'];
        }
        if ($i('drawer.other_cash') !== 0) {
            $cash[] = ['label' => 'Other cash', 'amount' => $i('drawer.other_cash'), 'drill' => 'other_cash'];
        }
        $cash[] = ['label' => 'Expenses ('.$i('drawer.counts.expenses').')', 'amount' => -$i('cash.expenses'), 'drill' => 'expenses'];
        $cash[] = ['label' => 'Remittance ('.$i('drawer.counts.remittance').')', 'amount' => -$i('cash.remittance'), 'drill' => 'remittance'];
        if ($i('cash.shortage_payments') !== 0) {
            $cash[] = ['label' => 'Shortage payments ('.$i('drawer.counts.shortage_payments').')', 'amount' => $i('cash.shortage_payments'), 'drill' => 'shortage_payments'];
        }

        $sum = array_sum(array_column($cash, 'amount'));
        $expected = $f['expected'] ?? $sum;
        if ($sum !== $expected) {
            $checks[] = 'The drawer lines add up to '.ZPaper::csvAmount($sum).', not to the expected '.ZPaper::csvAmount($expected).'.';
        }

        $guests = [
            'forwarded_in' => $i('guests.forwarded_in'), 'checked_in' => $i('guests.checked_in'),
            'checked_out' => $i('guests.checked_out'), 'forwarded_out' => $i('guests.forwarded_out'),
            'cancelled' => $i('guests.cancelled'),
        ];
        $deposits = [
            'held_open' => $i('deposits.held_open'), 'taken' => $i('deposits.taken'), 'returned' => $i('deposits.returned'),
            'forfeited' => $i('deposits.forfeited'), 'applied' => $i('deposits.applied'), 'held_close' => $i('deposits.held_close'),
        ];
        $unpaid = [
            'forwarded_in' => $i('unpaid.forwarded_in'), 'charged' => $i('unpaid.charged'),
            'collected' => $i('unpaid.collected'), 'forwarded_out' => $i('unpaid.forwarded_out'),
        ];
        foreach ($this->cash->rollForwardsBalance($t) as $name => $balances) {
            if (! $balances) {
                $checks[] = "The {$name} roll-forward does not balance.";
            }
        }

        $members = ShiftSession::query()->withoutGlobalScopes()->whereKey($shift->id)->first()?->members()->with('user')->orderBy('joined_at')->get()
            ->map(fn ($m): string => $m->user->name ?? '')->values()->all() ?? [];
        $receiver = ShiftHandover::query()->with('receiver')->where('from_shift_id', $shift->id)->first()?->receiver?->name;

        return [
            'shift' => [
                'id' => $shift->id,
                'period_name' => $shift->period_name,
                'business_date' => $shift->business_date->toDateString(),
                'status' => $shift->status->value,
                'z_no' => $shift->z_no,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'members' => $members,
                'handed_to' => $receiver,
                'is_force_closed' => $shift->is_force_closed,
            ],
            'preview' => $f['preview'],
            'sales' => $sales,
            'sales_total' => $total,
            'cash' => $cash,
            'expected' => $expected,
            'counted' => $f['counted'],
            'variance' => $f['variance'],
            'variance_note' => $f['note'],
            'not_in_drawer' => $i('drawer.non_cash.gcash') + $i('drawer.non_cash.card'),
            'guests' => $guests,
            'deposits' => $deposits,
            'unpaid' => $unpaid,
            'checks' => $checks,
        ];
    }

    /**
     * The day paper (38b): the sum of the day's shift totals, in the 37b layout, with the day
     * number once the day is closed. Short and over are summed separately, never netted.
     *
     * @return array<string, mixed>
     */
    public function dayZ(int $branchId, string $date): array
    {
        $shifts = $this->days->shifts($branchId, $date);
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);
        $closing = DayClosing::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereDate('business_date', $date)->first();
        $figures = $shifts->map(fn (ShiftSession $s): array => $this->figures($s))->values();
        $preview = $closing === null || $figures->contains(fn (array $f): bool => $f['preview']);

        $short = 0;
        $over = 0;
        $expected = 0;
        $counted = 0;
        foreach ($figures as $f) {
            $v = $f['variance'] ?? 0;
            $short += $v < 0 ? -$v : 0;
            $over += $v > 0 ? $v : 0;
            $expected += $f['expected'] ?? 0;
            $counted += $f['counted'] ?? ($f['expected'] ?? 0);
        }

        /** @var array<string, mixed>|null $first */
        $first = $figures->first();
        /** @var array<string, mixed>|null $last */
        $last = $figures->last();
        $closer = $closing === null ? null : User::query()->find($closing->closed_by);

        $paper = ZPaper::build(Totals::sum($figures->map(fn (array $f): array => $f['totals'])->all()), [
            'kind' => 'day',
            'preview' => $preview,
            'number' => $closing === null ? null : self::dayNumber($closing->day_z_no),
            'branch' => $branch->name,
            'title' => 'DAY Z · '.$this->businessDate(CarbonImmutable::parse($date)),
            'opened' => $shifts->isEmpty() ? null : 'Shifts '.implode(', ', $shifts->map(fn (ShiftSession $s): string => $s->z_no === null ? "{$s->period_name} (open)" : "{$s->period_name} ".self::zNumber($s->z_no))->all()),
            'closed' => $closing === null ? 'Not closed yet' : 'Closed '.$this->time($closing->created_at),
            'printed' => $closing === null ? null : $this->printedLine($branchId, PrintDocument::DayZ, $closing->id),
            'tin' => $branch->tin,
            'registered_name' => $branch->registered_name,
            'expected' => $figures->isEmpty() ? 0 : $expected,
            'counted' => $preview ? null : $counted,
            'short' => $short,
            'over' => $over,
            'variance_note' => null,
            'grand_total_before' => (int) ($first['grand_total_before'] ?? 0),
            'grand_total_after' => (int) ($last['grand_total_after'] ?? 0),
            'signatures' => [
                ['name' => $closer?->name, 'role' => 'Day closed by'],
                ['name' => null, 'role' => 'Checked by · name and date'],
            ],
        ]);

        return [...$paper, 'date' => $date, 'day_closing_id' => $closing?->id];
    }

    public static function zNumber(int $zNo): string
    {
        return 'Z-'.str_pad((string) $zNo, 4, '0', STR_PAD_LEFT);
    }

    public static function dayNumber(int $no): string
    {
        return 'D-'.str_pad((string) $no, 4, '0', STR_PAD_LEFT);
    }

    /** The grand total after the branch's last closed shift (0 before the first close). */
    private function lastGrandTotal(int $branchId): int
    {
        return ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', ShiftStatus::Closed)
            ->whereNotNull('z_no')
            ->orderByDesc('z_no')
            ->first()?->grand_total_after?->centavos() ?? 0;
    }

    /** "Printed 8:06 PM by Jessa Mae D.": the first print or export of the document. */
    private function printedLine(int $branchId, PrintDocument $document, int $subjectId): ?string
    {
        $first = PrintLog::query()->withoutGlobalScopes()->with('user')
            ->where('branch_id', $branchId)->where('document', $document)->where('subject_id', $subjectId)
            ->orderBy('created_at')->orderBy('id')->first();

        return $first === null ? null : 'Printed '.$this->time($first->created_at).' by '.ZPaper::shortName($first->user->name ?? '');
    }

    private function time(DateTimeInterface $at): string
    {
        return $this->clock->toLocal($at)->format('g:i A');
    }

    /** "Wed 11 Nov 2026" */
    private function businessDate(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->format('D j M Y');
    }

    /**
     * @param  Collection<int, ShiftSession>  $shifts
     * @return list<int>
     */
    private static function ids(Collection $shifts): array
    {
        return $shifts->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }
}
