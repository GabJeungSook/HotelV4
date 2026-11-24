<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\StayKind;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\Floor;
use App\Models\Rate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Set one cell of the rate table (45; DATABASE_SCHEMA §4.5), scoped to exactly one of a room type
 * (the base rate), a whole floor (flat for every room on it) or a single room × a stay option.
 *
 * - An amount above zero writes or changes the `rates` row. A blank amount (null) removes the
 *   row: the option is "not set". Nothing is ever stored as ₱0.
 * - A base (room type) row is removed only when no open stay (reserved, held or checked in) quotes
 *   it; a long stay quotes the 24-hour rate. A floor/room override can always be removed — pricing
 *   simply falls back to the floor, then the type.
 * - Floor and room overrides require the branch's Floor & room rates switch to be on.
 * - Only the options the branch sells (46) can be set: hourly 6, 12, 18, 24 when hourly stays
 *   are on, overnight when overnight stays are on.
 *
 * One transaction with its audit_logs row: 'rate.created', 'rate.updated' or 'rate.removed'.
 * Open stays keep the price they were quoted, which lives on their folio line.
 */
final class SaveRate
{
    public const HOURS = [6, 12, 18, 24];

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, ?int $roomTypeId, ?int $floorId, ?int $roomId, StayKind $kind, int $hours, ?Money $amount): ?Rate
    {
        return $this->transaction->run(function () use ($by, $branchId, $roomTypeId, $floorId, $roomId, $kind, $hours, $amount): ?Rate {
            $branch = Branch::query()->findOrFail($branchId);
            $hours = $kind === StayKind::Overnight ? 0 : $hours;

            $this->refuseOption($branch, $kind, $hours);

            [$column, $scopeId, $label] = $this->resolveScope($branch, $roomTypeId, $floorId, $roomId, $kind, $hours);

            if ($amount !== null && $amount->centavos() <= 0) {
                throw ActionRefused::because('A rate is more than ₱0. Leave it blank to stop selling this option.');
            }

            $rate = Rate::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where($column, $scopeId)
                ->where('stay_kind', $kind)->where('hours', $hours)->lockForUpdate()->first();
            $subject = ['scope' => $column, 'rate' => $label, 'stay_kind' => $kind->value, 'hours' => $hours];

            if ($amount === null) {
                if ($rate === null) {
                    return null;
                }

                if ($rate->room_type_id !== null) {
                    $this->refuseWhileQuoted($rate, $label);
                }

                $old = $rate->amount?->centavos();
                $id = $rate->id;
                $rate->delete();
                $this->audit->record($by, $branchId, 'rate.removed', 'rate', $id, [...$subject, 'amount' => $old], null);

                return null;
            }

            if ($rate === null) {
                $rate = Rate::query()->create([
                    'branch_id' => $branchId, $column => $scopeId, 'stay_kind' => $kind, 'hours' => $hours, 'amount' => $amount,
                ]);
                $this->audit->record($by, $branchId, 'rate.created', 'rate', $rate->id, null, [...$subject, 'amount' => $amount->centavos()]);

                return $rate;
            }

            $old = $rate->amount?->centavos();

            if ($old !== $amount->centavos()) {
                $rate->update(['amount' => $amount]);
                $this->audit->record($by, $branchId, 'rate.updated', 'rate', $rate->id, [...$subject, 'amount' => $old], [...$subject, 'amount' => $amount->centavos()]);
            }

            return $rate;
        });
    }

    /**
     * The scope this rate is for: the column to write, its id and a human label. Exactly one of a
     * room type, a floor or a room; floor and room need the branch's Floor & room rates switch on.
     *
     * @return array{string, int, string}
     */
    private function resolveScope(Branch $branch, ?int $roomTypeId, ?int $floorId, ?int $roomId, StayKind $kind, int $hours): array
    {
        $given = ($roomTypeId !== null ? 1 : 0) + ($floorId !== null ? 1 : 0) + ($roomId !== null ? 1 : 0);

        if ($given !== 1) {
            throw ActionRefused::because('A rate is for exactly one of a room type, a floor or a room.');
        }

        $option = $kind === StayKind::Overnight ? 'overnight' : "{$hours} hrs";

        if ($roomTypeId !== null) {
            $type = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->findOrFail($roomTypeId);

            return ['room_type_id', $type->id, "{$type->name} {$option}"];
        }

        if (! $branch->floor_room_rates_enabled) {
            throw ActionRefused::because('Floor & room rates are switched off for this branch. Switch them on in Settings first.');
        }

        if ($floorId !== null) {
            $floor = Floor::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->findOrFail($floorId);

            return ['floor_id', $floor->id, "Floor {$floor->number} {$option}"];
        }

        $room = Room::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->findOrFail($roomId);

        return ['room_id', $room->id, "Room {$room->number} {$option}"];
    }

    private function refuseOption(Branch $branch, StayKind $kind, int $hours): void
    {
        if ($kind === StayKind::LongStay) {
            throw ActionRefused::because('A long stay is priced from the 24-hour rate; it has no rate of its own.');
        }

        if ($kind === StayKind::Hourly && ! $branch->hourly_enabled) {
            throw ActionRefused::because('Hourly stays are switched off for this branch. Switch them on in Settings first.');
        }

        if ($kind === StayKind::Overnight && ! $branch->overnight_enabled) {
            throw ActionRefused::because('Overnight stays are switched off for this branch. Switch them on in Settings first.');
        }

        if ($kind === StayKind::Hourly && ! in_array($hours, self::HOURS, true)) {
            throw ActionRefused::because('Hourly rates are for 6, 12, 18 or 24 hours.');
        }
    }

    private function refuseWhileQuoted(Rate $rate, string $label): void
    {
        $quoted = Stay::query()->withoutGlobalScopes()
            ->where('branch_id', $rate->branch_id)
            ->where('room_type_id', $rate->room_type_id)
            ->whereIn('state', [StayState::Reserved, StayState::Held, StayState::CheckedIn])
            ->where(function ($q) use ($rate): void {
                if ($rate->stay_kind === StayKind::Overnight) {
                    $q->where('stay_kind', StayKind::Overnight);

                    return;
                }

                $q->where(fn ($h) => $h->where('stay_kind', StayKind::Hourly)->where('hours', $rate->hours));

                if ((int) $rate->hours === 24) {
                    $q->orWhere('stay_kind', StayKind::LongStay);
                }
            })
            ->count();

        if ($quoted > 0) {
            throw ActionRefused::because("The {$label} rate is quoted by {$quoted} open ".($quoted === 1 ? 'stay' : 'stays').'. It can be removed once they are checked out or cancelled.');
        }
    }
}
