<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\HallBookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\HallPackage;
use App\Models\User;
use App\Services\HallLedger;
use App\Support\Clock;
use App\Support\Money;

/** The front-desk Function halls screen (DATABASE_SCHEMA §4.14): bookings, plus the halls and
 * packages a new booking is built from. */
final class HallScreenQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly HallLedger $ledger,
        private readonly StayScreenQuery $stays,
    ) {}

    /** @return array<string, mixed> */
    public function index(Branch $branch, User $viewer): array
    {
        $bookings = HallBooking::query()->withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->with(['hall' => fn ($q) => $q->withoutGlobalScopes(), 'items' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderByDesc('start_at')
            ->limit(100)
            ->get();

        return [
            'halls' => Hall::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Hall $h): array => ['id' => $h->id, 'name' => $h->name, 'capacity' => $h->capacity, 'base_rate_amount' => $h->base_rate_amount?->centavos()])->values()->all(),
            'packages' => HallPackage::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (HallPackage $p): array => ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind->value, 'pricing' => $p->pricing->value, 'unit_amount' => $p->unit_amount?->centavos()])->values()->all(),
            'bookings' => $bookings->map(function (HallBooking $b): array {
                $held = $this->ledger->depositHeld($b->id);
                $quoted = $b->quoted_amount ?? Money::zero();

                return [
                    'id' => $b->id,
                    'code' => $b->code,
                    'hall' => $b->hall->name ?? '',
                    'status' => $b->status->value,
                    'customer_name' => $b->customer_name,
                    'customer_mobile' => $b->customer_mobile,
                    'event_date' => $b->event_date->toDateString(),
                    'start_at' => $b->start_at->toIso8601String(),
                    'end_at' => $b->end_at->toIso8601String(),
                    'pax' => $b->pax,
                    'quoted' => $quoted->centavos(),
                    'deposit_held' => $held->centavos(),
                    'remaining' => $b->status === HallBookingStatus::Reserved ? $quoted->minus($held)->centavos() : 0,
                    'cancel_reason' => $b->cancel_reason,
                    'items' => $b->items->map(fn ($i): array => [
                        'id' => $i->id,
                        'label' => $i->label,
                        'pricing' => $i->pricing->value,
                        'quantity' => $i->quantity,
                        'unit_amount' => $i->unit_amount?->centavos(),
                        'amount' => $i->amount?->centavos(),
                    ])->values()->all(),
                ];
            })->values()->all(),
            'methods' => array_map(
                fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()],
                [PaymentMethod::Cash, PaymentMethod::Gcash, PaymentMethod::Card],
            ),
            'approvers' => $this->stays->approvers($branch->id, $viewer),
            'now' => $this->clock->now()->toIso8601String(),
        ];
    }
}
