<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\PaymentKind;
use App\Enums\RoomStatusReason;
use App\Enums\StayKind;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Models\User;

/**
 * The all-time history of one room as a single timeline (new screen; no mockup frame): every stay
 * that occupied the room (transfers included), every cleaning, and manual status changes. Read-only.
 * A stay entry carries its transactions and a receipt, both built from StayQuery::folio().
 */
final class RoomHistoryQuery
{
    public function __construct(private readonly StayQuery $stays) {}

    /**
     * Newest first. The whole timeline is assembled light, sorted and sliced to one page; only the
     * stays on that page are hydrated with their folio, so the cost follows the page, not the history.
     *
     * @param  string|null  $type  limit to one kind: 'stay', 'cleaning' or 'status' (null = all).
     * @param  string  $q  free text matched against the guest, code, staff, status and notes.
     * @return array{entries: list<array<string, mixed>>, total: int}
     */
    public function timeline(Room $room, int $limit = 20, int $offset = 0, ?string $type = null, string $q = ''): array
    {
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($room->branch_id);
        $raw = [];

        // Every occupancy of this room (stay_rooms carries transfers, so a stay that passed through shows too).
        $occupancies = StayRoom::query()
            ->where('room_id', $room->id)
            ->with(['stay' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($occupancies as $occ) {
            $stay = $occ->stay;

            if ($stay === null) {
                continue;
            }

            $at = $occ->from_at;
            $raw[] = [
                'kind' => 'stay',
                'time' => $at->toIso8601String(),
                'sort' => $at->getTimestamp(),
                'stay_id' => $stay->id,
                'guest' => $stay->guest_name,
                'code' => $stay->code,
                'option' => self::optionLabel($stay),
                'source' => $stay->source->label(),
                'state' => $stay->state->label(),
                'checked_in_at' => $stay->checked_in_at?->toIso8601String(),
                'checked_out_at' => $stay->checked_out_at?->toIso8601String(),
                'room_from' => $at->toIso8601String(),
                'room_to' => $occ->to_at?->toIso8601String(),
                'in_by_id' => $stay->checked_in_by,
                'out_by_id' => $stay->checked_out_by,
            ];
        }

        foreach (CleaningTask::query()->withoutGlobalScopes()->where('room_id', $room->id)->get() as $c) {
            $raw[] = [
                'kind' => 'cleaning',
                'time' => $c->queued_at->toIso8601String(),
                'sort' => $c->queued_at->getTimestamp(),
                'status' => $c->status->label(),
                'trigger' => $c->trigger_kind->label(),
                'queued_at' => $c->queued_at->toIso8601String(),
                'started_at' => $c->started_at?->toIso8601String(),
                'finished_at' => $c->finished_at?->toIso8601String(),
                'is_late' => (bool) $c->is_late,
                'result' => $c->result?->label(),
                'problem_note' => $c->problem_note,
                'cleaned_by_id' => $c->cleaned_by,
            ];
        }

        // Manual status changes only (block, maintenance, reopen). The routine check-in → clean →
        // available cycle is already told by the stay and cleaning entries.
        foreach (RoomStatusLog::query()->where('room_id', $room->id)->where('reason', RoomStatusReason::Admin)->get() as $s) {
            $raw[] = [
                'kind' => 'status',
                'time' => $s->created_at->toIso8601String(),
                'sort' => $s->created_at->getTimestamp(),
                'from' => $s->from_status->label(),
                'to' => $s->to_status->label(),
                'by_id' => $s->user_id,
            ];
        }

        // Resolve every referenced person once, so search can match staff names too (folios are
        // still loaded only for the final page below).
        $ids = [];
        foreach ($raw as $e) {
            foreach (['in_by_id', 'out_by_id', 'cleaned_by_id', 'by_id'] as $k) {
                if (! empty($e[$k])) {
                    $ids[] = (int) $e[$k];
                }
            }
        }
        $names = User::query()->withoutGlobalScopes()->whereIn('id', array_values(array_unique($ids)))->pluck('name', 'id');
        $short = static fn (mixed $id): ?string => $id !== null && isset($names[$id]) ? StayScreenQuery::shortName((string) $names[$id]) : null;

        $entries = [];
        foreach ($raw as $e) {
            if ($e['kind'] === 'stay') {
                $e['in_by'] = $short($e['in_by_id']);
                $e['out_by'] = $short($e['out_by_id']);
                $e['_search'] = mb_strtolower(implode(' ', [$e['guest'], $e['code'], $e['option'], $e['source'], $e['state'], $e['in_by'] ?? '', $e['out_by'] ?? '']));
                unset($e['in_by_id'], $e['out_by_id']);
            } elseif ($e['kind'] === 'cleaning') {
                $e['cleaned_by'] = $short($e['cleaned_by_id']);
                $e['_search'] = mb_strtolower(implode(' ', ['cleaning', $e['status'], $e['result'] ?? '', $e['cleaned_by'] ?? '', $e['trigger'], $e['problem_note'] ?? '']));
                unset($e['cleaned_by_id']);
            } else {
                $e['by'] = $short($e['by_id']);
                $e['_search'] = mb_strtolower(implode(' ', ['status', $e['from'], $e['to'], $e['by'] ?? '']));
                unset($e['by_id']);
            }

            $entries[] = $e;
        }

        // Filters: by kind, then free text. Both run across the whole history, not just a page.
        if ($type !== null && $type !== '') {
            $entries = array_values(array_filter($entries, static fn (array $e): bool => $e['kind'] === $type));
        }

        $q = mb_strtolower(trim($q));
        if ($q !== '') {
            $entries = array_values(array_filter($entries, static fn (array $e): bool => str_contains((string) $e['_search'], $q)));
        }

        usort($entries, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);
        $total = count($entries);
        $page = array_slice($entries, max(0, $offset), $limit);

        $out = [];
        foreach ($page as $e) {
            if ($e['kind'] === 'stay') {
                $e += $this->money((int) $e['stay_id'], $branch, $e['out_by']);
            }

            unset($e['sort'], $e['_search']);
            $out[] = $e;
        }

        return ['entries' => $out, 'total' => $total];
    }

    /**
     * The stay's transactions (for the expandable panel) and its receipt (for the slip), both from
     * one folio read.
     *
     * @return array{transactions: array<string, mixed>, receipt: array<string, mixed>}
     */
    private function money(int $stayId, Branch $branch, ?string $by): array
    {
        $folio = $this->stays->folio($stayId);

        $lines = array_map(static fn (array $l): array => [
            'label' => $l['description'] !== '' ? $l['description'] : ucwords(str_replace('_', ' ', $l['kind']->value)),
            'amount' => $l['amount']->centavos(),
            'status' => $l['status'],
        ], $folio['lines']);

        $payments = array_map(static fn (Payment $p): array => [
            'label' => match ($p->kind) {
                PaymentKind::Payment => 'Paid '.$p->method->label(),
                PaymentKind::DepositIn => 'Deposit in',
                PaymentKind::DepositRefund => 'Deposit returned',
                PaymentKind::DepositApplied => 'From deposit',
                PaymentKind::Refund => 'Refund',
            },
            'amount' => $p->amount?->centavos() ?? 0,
        ], $folio['payments']);

        $charges = array_sum(array_column($lines, 'amount'));

        return [
            'transactions' => [
                'lines' => $lines,
                'payments' => $payments,
                'deposits' => [
                    ['label' => "{$branch->deposit_name} deposit", 'amount' => $folio['deposits']['key']->centavos()],
                    ['label' => 'Guest deposit', 'amount' => $folio['deposits']['guest']->centavos()],
                ],
                'charges' => $charges,
                'balance' => $folio['balance_due']->centavos(),
            ],
            'receipt' => [
                'header' => $branch->slip_header,
                'lines' => array_map(static fn (array $l): array => ['label' => $l['label'], 'amount' => $l['amount']], $lines),
                'payments' => $payments,
                'total' => $charges,
                'by' => $by ?? '',
            ],
        ];
    }

    private static function optionLabel(Stay $stay): string
    {
        return match ($stay->stay_kind) {
            StayKind::Hourly => ($stay->hours ?? 0).' hrs',
            StayKind::Overnight => ($stay->nights ?? 1) === 1 ? 'overnight' : ($stay->nights ?? 1).' nights',
            StayKind::LongStay => ($stay->days ?? 1).' '.(($stay->days ?? 1) === 1 ? 'day' : 'days'),
        };
    }
}
