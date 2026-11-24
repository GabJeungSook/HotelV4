<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Role;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\Stay;
use App\Models\User;
use App\Services\AttentionList;
use App\Services\CurrentBranch;
use App\Support\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The notification feed behind the top-bar bell and the All notifications page (49 sibling). It is
 * read-only live state, never stored: the branch's "Needs attention" rows (AttentionList, already
 * role-aware) plus, for the front desk, the live desk work the attention list does not carry —
 * rooms due out soon, kiosk holds waiting to be confirmed, and rooms with an unpaid balance.
 *
 * The badge count is simply how many items are open now; it falls as the work is handled, so there
 * is no per-user read state. No peso amount leaves here (CLAUDE.md rule 9 / the Today convention):
 * money items name the room and point to the folio, where the amount is shown.
 *
 * @phpstan-type Item array{kind: string, severity: 'high'|'normal', title: string, detail: string, since: string|null, since_text: string, href: string}
 */
final class NotificationQuery
{
    /**
     * Attention kinds the front desk and supervisor can both see and act on from their own screens.
     * Left off: closed-shift variance, day not closed, missing PINs and nightly checks (back office),
     * and a stuck clean (only a supervisor/admin can reset it, on the "Needs attention" screen).
     */
    private const DESK_ATTENTION = ['overdue_stay', 'no_open_shift', 'shift_open_too_long', 'stock'];

    /** Roles that get a notification feed; housekeepers (phone) and the kiosk device never do. */
    private const FED_ROLES = [Role::Frontdesk, Role::Supervisor, Role::Backoffice, Role::Admin, Role::Owner];

    public function __construct(
        private readonly AttentionList $attention,
        private readonly CurrentBranch $branches,
        private readonly Clock $clock,
    ) {}

    /**
     * The feed for the viewer at the branch they are in (the owner with no branch open sees every
     * active branch). The one entry point for the bell and the All notifications page.
     *
     * @return list<Item>
     */
    public function for(User $viewer): array
    {
        return $this->feed($viewer, $this->branchIds($viewer));
    }

    /**
     * The branches the viewer's feed covers: the open branch, or every active branch for an owner
     * who is on "All branches".
     *
     * @return list<int>
     */
    public function branchIds(User $viewer): array
    {
        $branch = $this->branches->branch();

        if ($branch !== null) {
            return [$branch->id];
        }

        if ($viewer->role === Role::Owner) {
            return Branch::query()->withoutGlobalScopes()->where('status', 'active')->orderBy('name')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        return [];
    }

    /**
     * The whole feed for one or more branches, most urgent first (high severity, then oldest).
     *
     * @param  list<int>  $branchIds
     * @return list<Item>
     */
    public function feed(User $viewer, array $branchIds): array
    {
        if ($branchIds === [] || ! in_array($viewer->role, self::FED_ROLES, true)) {
            return [];
        }

        $isDesk = in_array($viewer->role, [Role::Frontdesk, Role::Supervisor], true);

        /** @var list<Item> $rows */
        $rows = array_map(static fn (array $r): array => [
            'kind' => $r['kind'],
            'severity' => $r['severity'],
            'title' => $r['title'],
            'detail' => $r['detail'],
            'since' => $r['since'],
            'since_text' => $r['since_text'],
            'href' => $r['route'],
        ], $this->attention->rows($branchIds, $viewer));

        if ($isDesk) {
            // The attention list links to back-office screens (the inventory report, the shift
            // report, the exceptions screen) that the desk cannot open. Point each kept kind at the
            // front-desk screen that handles it instead, so a notification never dead-ends (the Stock
            // screen, opening a shift, closing a shift).
            $deskHref = [
                'stock' => route('stock.index', absolute: false),
                'no_open_shift' => route('shift.open', absolute: false),
                'shift_open_too_long' => route('shift.close', absolute: false),
            ];

            $rows = array_values(array_map(
                static fn (array $r): array => [...$r, 'href' => $deskHref[$r['kind']] ?? $r['href']],
                array_filter($rows, static fn (array $r): bool => in_array($r['kind'], self::DESK_ATTENTION, true)),
            ));

            foreach ($branchIds as $branchId) {
                array_push($rows, ...$this->deskItems($branchId));
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['severity'] === 'high' ? 0 : 1, $a['since'] ?? '9999'] <=> [$b['severity'] === 'high' ? 0 : 1, $b['since'] ?? '9999']);

        return $rows;
    }

    /**
     * The live front-desk items for one branch that are not in the attention list.
     *
     * @return list<Item>
     */
    private function deskItems(int $branchId): array
    {
        $branch = Branch::query()->withoutGlobalScopes()->find($branchId);

        if ($branch === null) {
            return [];
        }

        $now = $this->clock->now();
        $soon = $now->addMinutes($branch->due_soon_minutes);
        $out = [];

        // Rooms due out within the branch's "due soon" window, soonest first (not yet overdue —
        // overdue stays already come from the attention list).
        $due = Stay::query()->withoutGlobalScopes()->with('room')
            ->where('branch_id', $branchId)
            ->where('state', StayState::CheckedIn)
            ->whereNotNull('expected_checkout_at')
            ->whereBetween('expected_checkout_at', [$now, $soon])
            ->orderBy('expected_checkout_at')
            ->get();

        foreach ($due as $stay) {
            $minutes = max(0, intdiv(($stay->expected_checkout_at?->getTimestamp() ?? 0) - $now->getTimestamp(), 60));
            $room = $stay->room->number ?? '—';
            $out[] = [
                'kind' => 'due_out_soon',
                'severity' => 'normal',
                'title' => "Room {$room} due out soon",
                'detail' => $stay->guest_name.' · out '.$this->time($stay->expected_checkout_at).' · in '.$minutes.' min',
                'since' => null,
                'since_text' => "in {$minutes} min",
                'href' => route('stays.show', $stay->id, false),
            ];
        }

        // Kiosk holds still waiting for the guest to be confirmed and pay at the desk.
        $holds = Stay::query()->withoutGlobalScopes()->with('room')
            ->where('branch_id', $branchId)
            ->where('state', StayState::Held)
            ->where('hold_expires_at', '>', $now)
            ->orderBy('hold_expires_at')
            ->get();

        foreach ($holds as $stay) {
            $minutes = max(0, intdiv(($stay->hold_expires_at?->getTimestamp() ?? 0) - $now->getTimestamp(), 60));
            $room = $stay->room->number ?? null;
            $out[] = [
                'kind' => 'kiosk_hold',
                'severity' => $minutes <= 5 ? 'high' : 'normal',
                'title' => 'Kiosk hold to confirm'.($room === null ? '' : " · Room {$room}"),
                'detail' => 'A guest booked at the kiosk · hold expires in '.$minutes.' min',
                'since' => null,
                'since_text' => "in {$minutes} min",
                'href' => route('stays.confirm', $stay->id, false),
            ];
        }

        // In-house rooms with an unpaid balance (the amount lives on the folio, never here).
        foreach ($this->unpaidStays($branchId) as $stay) {
            $room = $stay->room->number ?? '—';
            $out[] = [
                'kind' => 'unpaid_balance',
                'severity' => 'normal',
                'title' => "Room {$room} has an unpaid balance",
                'detail' => $stay->guest_name.' · settle before check-out',
                'since' => null,
                'since_text' => '—',
                'href' => route('stays.show', $stay->id, false),
            ];
        }

        return $out;
    }

    /**
     * In-house stays with money still owed on the folio, by room number.
     *
     * @return Collection<int, Stay>
     */
    private function unpaidStays(int $branchId): Collection
    {
        $inHouse = Stay::query()->withoutGlobalScopes()->with('room')
            ->where('branch_id', $branchId)
            ->where('state', StayState::CheckedIn)
            ->get();

        if ($inHouse->isEmpty()) {
            return $inHouse;
        }

        $unpaid = DB::table('folio_lines')
            ->leftJoinSub(
                DB::table('payment_allocations')->groupBy('folio_line_id')->selectRaw('folio_line_id, SUM(amount) AS paid'),
                'pa',
                'pa.folio_line_id',
                '=',
                'folio_lines.id',
            )
            ->whereIn('folio_lines.stay_id', $inHouse->pluck('id')->all())
            ->groupBy('folio_lines.stay_id')
            ->havingRaw('SUM(folio_lines.amount) - COALESCE(SUM(pa.paid), 0) > 0')
            ->pluck('folio_lines.stay_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $inHouse->whereIn('id', $unpaid)->sortBy(fn (Stay $s): string => (string) ($s->room->number ?? ''))->values();
    }

    private function time(?\DateTimeInterface $at): string
    {
        return $at === null ? '' : $this->clock->toLocal($at)->format('g:i A');
    }
}
