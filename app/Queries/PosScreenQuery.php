<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\PosMode;
use App\Enums\ProductKind;
use App\Enums\ReasonKind;
use App\Enums\StayState;
use App\Models\FolioLine;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;

/**
 * Props of the POS (19) and stock (20) screens. Prices come from the product rows; the server
 * totals every sale again. No response carries a sum of the shift's sales or cash (CLAUDE.md
 * rule 9): the sales list on 19 is one row per sale, for voiding, with no total.
 */
final class PosScreenQuery
{
    public function __construct(
        private readonly StayScreenQuery $stays,
        private readonly ReportQuery $reports,
    ) {}

    /** @return array<string, mixed> */
    public function pos(ShiftSession $shift, User $viewer): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('outlet', 'frontdesk')
            ->where('kind', '!=', ProductKind::Damage)
            ->orderBy('name')
            ->get();

        $categories = $products->pluck('category')->unique()->sort()->values()->all();

        $stays = Stay::query()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('state', StayState::CheckedIn)
            ->get()
            ->sortBy(fn (Stay $s): string => str_pad((string) $s->room?->number, 8, '0', STR_PAD_LEFT))
            ->values();

        return [
            'products' => array_values($products->map(static fn (Product $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category,
                'price' => $p->price_amount?->centavos() ?? 0,
                'stock' => $p->tracks_stock ? $p->stock_on_hand : null,
                'low' => $p->tracks_stock && $p->stock_on_hand > 0 && $p->stock_on_hand <= $p->low_stock_at,
            ])->all()),
            'categories' => array_values($categories),
            'rooms' => array_values($stays->map(static fn (Stay $s): array => [
                'stay_id' => $s->id,
                'room' => $s->room?->number,
                'guest' => BoardQuery::shortName($s->guest_name),
            ])->all()),
            'sales' => $this->sales($shift),
            'reasons' => $this->stays->reasons(ReasonKind::VoidLine),
            'approvers' => $this->stays->approvers($shift->branch_id, $viewer),
        ];
    }

    /**
     * This shift's sales, newest first, one row per sale (for "Void sale").
     *
     * @return list<array<string, mixed>>
     */
    public function sales(ShiftSession $shift): array
    {
        $orders = PosOrder::query()
            ->with(['creator:id,name', 'stay' => fn ($q) => $q->withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])])
            ->where('shift_session_id', $shift->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $lines = FolioLine::query()
            ->withExists('reversal')
            ->whereIn('pos_order_id', $orders->pluck('id'))
            ->whereNull('reversal_of_id')
            ->orderBy('id')
            ->get()
            ->groupBy('pos_order_id');

        return array_values($orders->map(static function (PosOrder $o) use ($lines): array {
            $own = $lines->get($o->id, collect());
            $open = $own->filter(static fn (FolioLine $l): bool => ! (bool) $l->getAttribute('reversal_exists'));

            return [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'occurred_at' => $o->occurred_at->toIso8601String(),
                'mode' => $o->mode->value,
                'room' => $o->mode === PosMode::RoomCharge ? $o->stay?->room?->number : null,
                'items' => $own->map(static fn (FolioLine $l): string => "{$l->description} × {$l->quantity}")->implode(', '),
                'amount' => $o->total_amount?->centavos() ?? 0,
                'by' => $o->creator === null ? null : BoardQuery::shortName($o->creator->name),
                'voided' => $own->isNotEmpty() && $open->isEmpty(),
                'room_checked_out' => $o->mode === PosMode::RoomCharge && $o->stay?->state !== StayState::CheckedIn,
            ];
        })->all());
    }

    /** @return array<string, mixed> */
    public function stock(ShiftSession $shift, User $viewer): array
    {
        $rows = $this->reports->stock($shift);

        return [
            'rows' => $rows,
            'movements' => $this->reports->stockMovements($shift),
            'products' => array_map(static fn (array $r): array => ['id' => $r['product_id'], 'name' => $r['name'], 'on_hand' => $r['closing']], $rows),
            'reasons' => $this->stays->reasons(ReasonKind::Wastage),
            'approvers' => $this->stays->approvers($shift->branch_id, $viewer),
            'opened_at' => $shift->opened_at->toIso8601String(),
            'period_name' => $shift->period_name,
        ];
    }
}
