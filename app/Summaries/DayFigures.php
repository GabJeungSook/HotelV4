<?php

declare(strict_types=1);

namespace App\Summaries;

use App\Enums\CleaningStatus;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\ShiftStatus;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Models\CleaningTask;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Services\Reports\BusinessDay;
use App\Support\Clock;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * One branch-day recomputed from the ledgers (DATABASE_SCHEMA §4.16): the rows of
 * daily_summaries, hourly_sales_summaries, daily_payment_summaries and daily_report_lines.
 * Shared by RebuildSummaries (which writes them) and CheckSummaries (which compares).
 *
 * A row belongs to the business date of its shift. Net sales = Σ folio_lines.amount; gross =
 * room sales + other sales; discount is the discount lines as a positive figure, so
 * gross − discount = net. Collections are `payment` rows (and `deposit_applied` for the deposit
 * method); refunds are `refund` rows and guest-deposit refunds, as positive figures. Hours are
 * bucketed in PHP in branch local time, never with SQL DATE()/HOUR() on UTC columns.
 */
final class DayFigures
{
    public const ROOM_KINDS = [LineKind::Room, LineKind::Extension, LineKind::TransferDifference];

    public const OTHER_KINDS = [LineKind::FoodDrink, LineKind::Amenity, LineKind::Damage, LineKind::ForfeitedDeposit];

    public const PRODUCT_KINDS = [LineKind::FoodDrink, LineKind::Amenity, LineKind::Damage];

    public function __construct(
        private readonly BusinessDay $days,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{daily: array<string, int|string>, hourly: list<array<string, int|string>>, payments: list<array<string, int|string>>, lines: list<array<string, int|string>>}
     */
    public function compute(int $branchId, string $date): array
    {
        $shifts = $this->days->shifts($branchId, $date);
        $shiftIds = $shifts->pluck('id')->all();

        /** @var Collection<int, FolioLine> $lines */
        $lines = $shiftIds === [] ? collect() : FolioLine::query()->withoutGlobalScopes()->whereIn('shift_session_id', $shiftIds)->get();
        /** @var Collection<int, Payment> $payments */
        $payments = $shiftIds === [] ? collect() : Payment::query()->withoutGlobalScopes()->whereIn('shift_session_id', $shiftIds)->get();
        /** @var Collection<int, Stay> $checkins */
        $checkins = $shiftIds === [] ? collect() : Stay::query()->withoutGlobalScopes()->whereIn('checkin_shift_id', $shiftIds)->get();
        $checkouts = $shiftIds === [] ? 0 : Stay::query()->withoutGlobalScopes()->whereIn('checkout_shift_id', $shiftIds)->count();

        $roomSales = self::sum($lines->filter(fn (FolioLine $l): bool => in_array($l->kind, self::ROOM_KINDS, true)));
        $otherSales = self::sum($lines->filter(fn (FolioLine $l): bool => in_array($l->kind, self::OTHER_KINDS, true)));
        $discount = -self::sum($lines->where('kind', LineKind::Discount));

        $paymentRows = $this->paymentRows($branchId, $date, $payments);
        [$short, $over] = self::shortAndOver($shifts);
        [$start, $end] = $this->days->window($branchId, $date, $shifts);

        $cleaned = CleaningTask::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', CleaningStatus::Done)
            ->where('finished_at', '>=', $start)->where('finished_at', '<', $end)
            ->get(['id', 'is_late']);

        $daily = [
            'branch_id' => $branchId,
            'business_date' => $date,
            'gross_sales_amount' => $roomSales + $otherSales,
            'discount_amount' => $discount,
            'net_sales_amount' => self::sum($lines),
            'room_sales_amount' => $roomSales,
            'other_sales_amount' => $otherSales,
            'collected_amount' => (int) array_sum(array_column($paymentRows, 'amount')),
            'refund_amount' => (int) array_sum(array_column($paymentRows, 'refund_amount')),
            'short_amount' => $short,
            'over_amount' => $over,
            'checkins' => $checkins->count(),
            'checkouts' => $checkouts,
            'stays_in_house_end' => $this->inHouseAtEnd($branchId, $shifts),
            'room_count' => Room::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)->count(),
            'cleans' => $cleaned->count(),
            'late_cleans' => $cleaned->where('is_late', true)->count(),
        ];

        return [
            'daily' => $daily,
            'hourly' => $this->hourlyRows($branchId, $date, $lines, $checkins),
            'payments' => $paymentRows,
            'lines' => $this->reportLines($branchId, $date, $lines, $checkins),
        ];
    }

    /**
     * Short and over of the closed shifts, each summed on its own and never netted (TRD §4.11).
     *
     * @param  Collection<int, ShiftSession>  $shifts
     * @return array{0: int, 1: int}
     */
    public static function shortAndOver(Collection $shifts): array
    {
        $variances = $shifts->where('status', ShiftStatus::Closed)
            ->map(fn (ShiftSession $s): int => $s->variance_amount?->centavos() ?? 0);

        return [(int) $variances->sum(fn (int $v): int => max(0, -$v)), (int) $variances->sum(fn (int $v): int => max(0, $v))];
    }

    /**
     * Payments of the day by method and person, the way CheckSummaries compares them.
     *
     * @param  Collection<int, Payment>  $payments
     * @return list<array{branch_id: int, business_date: string, method: string, user_id: int, payment_count: int, amount: int, refund_amount: int}>
     */
    public function paymentRows(int $branchId, string $date, Collection $payments): array
    {
        $rows = [];

        foreach ($payments as $p) {
            $collected = $p->kind === PaymentKind::Payment || $p->kind === PaymentKind::DepositApplied;
            $givenBack = $p->kind === PaymentKind::Refund || ($p->kind === PaymentKind::DepositRefund && $p->deposit_type === DepositType::Guest);

            if (! $collected && ! $givenBack) {
                continue;
            }

            $key = $p->method->value.'|'.$p->created_by;
            $rows[$key] ??= ['branch_id' => $branchId, 'business_date' => $date, 'method' => $p->method->value, 'user_id' => (int) $p->created_by, 'payment_count' => 0, 'amount' => 0, 'refund_amount' => 0];
            $amount = $p->amount?->centavos() ?? 0;

            if ($collected) {
                $rows[$key]['payment_count']++;
                $rows[$key]['amount'] += $amount;
            } else {
                $rows[$key]['refund_amount'] += -$amount;
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /** @param  Collection<int, FolioLine>  $lines */
    private static function sum(Collection $lines): int
    {
        return (int) $lines->sum(fn (FolioLine $l): int => $l->amount->centavos());
    }

    /**
     * Guests in house when the day ended: the last closed shift's forwarded-out stays, or the
     * stays checked in right now while a shift of the day is still open.
     *
     * @param  Collection<int, ShiftSession>  $shifts
     */
    private function inHouseAtEnd(int $branchId, Collection $shifts): int
    {
        if ($shifts->isEmpty()) {
            return 0;
        }

        if ($shifts->contains(fn (ShiftSession $s): bool => $s->status !== ShiftStatus::Closed)) {
            return Stay::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('state', StayState::CheckedIn)->count();
        }

        return ShiftForwardedStay::query()->where('shift_session_id', $shifts->last()->id)->count();
    }

    /**
     * @param  Collection<int, FolioLine>  $lines
     * @param  Collection<int, Stay>  $checkins
     * @return list<array<string, int|string>>
     */
    private function hourlyRows(int $branchId, string $date, Collection $lines, Collection $checkins): array
    {
        $rows = [];
        $bucket = function (DateTimeInterface $at) use (&$rows, $branchId, $date): string {
            $local = $this->clock->toLocal($at);
            $key = $local->format('Y-m-d').'|'.str_pad($local->format('G'), 2, '0', STR_PAD_LEFT);
            $rows[$key] ??= ['branch_id' => $branchId, 'business_date' => $date, 'local_date' => $local->toDateString(), 'hour' => (int) $local->format('G'), 'net_sales_amount' => 0, 'checkins' => 0];

            return $key;
        };

        foreach ($lines as $line) {
            $rows[$bucket($line->occurred_at)]['net_sales_amount'] += $line->amount->centavos();
        }

        foreach ($checkins as $stay) {
            if ($stay->checked_in_at !== null) {
                $rows[$bucket($stay->checked_in_at)]['checkins']++;
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * Sales by room type, stay option, product and discount (Stats 63).
     *
     * @param  Collection<int, FolioLine>  $lines
     * @param  Collection<int, Stay>  $checkins
     * @return list<array<string, int|string>>
     */
    private function reportLines(int $branchId, string $date, Collection $lines, Collection $checkins): array
    {
        $out = [];
        $add = function (string $section, string $label, int $quantity, int $amount) use (&$out, $branchId, $date): void {
            $label = mb_substr($label, 0, 80);
            $key = "{$section}|{$label}";
            $out[$key] ??= ['branch_id' => $branchId, 'business_date' => $date, 'section' => $section, 'label' => $label, 'quantity' => 0, 'amount' => 0];
            $out[$key]['quantity'] += $quantity;
            $out[$key]['amount'] += $amount;
        };

        $reversed = $lines->whereNotNull('reversal_of_id')->pluck('reversal_of_id')->flip();
        $counts = fn (FolioLine $l): int => $l->reversal_of_id === null && ! $reversed->has($l->id) ? 1 : 0;

        $roomTypes = Room::query()->withoutGlobalScopes()->whereIn('id', $lines->pluck('room_id')->filter()->unique()->all())->pluck('room_type_id', 'id');
        $typeNames = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->pluck('name', 'id');
        $productNames = Product::query()->withoutGlobalScopes()->whereIn('id', $lines->pluck('product_id')->filter()->unique()->all())->pluck('name', 'id');
        $stays = Stay::query()->withoutGlobalScopes()->whereIn('id', $lines->pluck('stay_id')->filter()->unique()->all())
            ->get(['id', 'stay_kind', 'hours', 'room_type_id'])->keyBy('id');

        foreach ($lines as $line) {
            $amount = $line->amount->centavos();
            $stay = $line->stay_id === null ? null : $stays->get($line->stay_id);

            if ($line->kind === LineKind::Discount || in_array($line->kind, self::ROOM_KINDS, true)) {
                $typeId = $line->room_id === null ? $stay?->room_type_id : ($roomTypes[$line->room_id] ?? null);
                $add('room_type', (string) ($typeNames[$typeId] ?? 'Other'), $line->kind === LineKind::Room ? $counts($line) : 0, $amount);
            }

            if ($line->kind === LineKind::Room && $stay !== null) {
                $add('stay_option', self::optionLabel($stay), 0, $amount);
            }

            if (in_array($line->kind, self::PRODUCT_KINDS, true)) {
                $sign = $line->reversal_of_id === null ? 1 : -1;
                $add('product', (string) ($productNames[$line->product_id] ?? $line->description), $sign * abs($line->quantity), $amount);
            }

            if ($line->kind === LineKind::Discount) {
                $add('discount', $line->description, $counts($line), -$amount);
            }
        }

        // What guests chose (63): every stay checked in that day, by option.
        foreach ($checkins as $stay) {
            if ($stay->state !== StayState::Cancelled) {
                $add('stay_option', self::optionLabel($stay), 1, 0);
            }
        }

        ksort($out);

        return array_values($out);
    }

    public static function optionLabel(Stay $stay): string
    {
        return match ($stay->stay_kind) {
            StayKind::Hourly => ($stay->hours ?? 0).' hrs',
            StayKind::Overnight => 'Overnight',
            StayKind::LongStay => 'Long stay',
        };
    }
}
