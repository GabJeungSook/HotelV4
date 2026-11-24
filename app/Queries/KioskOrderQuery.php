<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\RoomRotation;
use App\Services\Rotation\RotationCandidate;
use App\Support\Clock;

/**
 * What the kiosk will offer, per room type, so the front desk can watch kiosk availability (a board
 * add-on). For each type: the rooms available right now in the exact order the kiosk gives them
 * (RoomRotation, TRD §4.8) — the first is "next up" — and the rooms that are busy with when and why
 * they will free up. Read-only; never changes the rotation.
 */
final class KioskOrderQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RoomQuery $rooms,
        private readonly RoomRotation $rotation,
    ) {}

    /** @return array<string, mixed> */
    public function forBranch(int $branchId): array
    {
        $now = $this->clock->now();
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);

        if (! $branch->kiosk_enabled) {
            return ['enabled' => false, 'types' => [], 'now' => $now->toIso8601String()];
        }

        // When each occupied room is due out, to show "free after 2:00 PM".
        $checkouts = Stay::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('state', StayState::CheckedIn)
            ->whereNotNull('active_room_id')
            ->get(['active_room_id', 'expected_checkout_at'])
            ->keyBy('active_room_id');

        $types = [];

        foreach (RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)->orderBy('id')->get() as $type) {
            $candidates = $this->rooms->kioskCandidates($branchId, $type->id, $now, $now);

            if ($candidates === []) {
                continue;
            }

            $ordered = $this->rotation->order($candidates);
            $offerable = array_map(static fn (RotationCandidate $c): int => $c->id, $ordered);

            $queue = array_map(static fn (RotationCandidate $c): array => [
                'number' => $c->number,
                'fresh' => $c->lastCheckoutAt === null, // never used yet — offered first
            ], $ordered);

            $busy = [];
            foreach ($candidates as $c) {
                if (in_array($c->id, $offerable, true)) {
                    continue;
                }

                [$reason, $freeAt] = $this->busyState($c, $checkouts->get($c->id)?->expected_checkout_at);
                $busy[] = ['number' => $c->number, 'reason' => $reason, 'free_at' => $freeAt];
            }

            // Occupied rooms soonest to free first, then the rest.
            usort($busy, static fn (array $a, array $b): int => match (true) {
                $a['free_at'] !== null && $b['free_at'] !== null => strcmp((string) $a['free_at'], (string) $b['free_at']),
                $a['free_at'] !== null => -1,
                $b['free_at'] !== null => 1,
                default => strnatcasecmp($a['number'], $b['number']),
            });

            $types[] = [
                'name' => $type->name,
                'queue' => $queue,
                'queue_count' => count($queue),
                'busy' => $busy,
            ];
        }

        return ['enabled' => true, 'types' => $types, 'now' => $now->toIso8601String()];
    }

    /** @return array{0: string, 1: string|null} reason and, for an occupied room, when it is due out */
    private function busyState(RotationCandidate $c, ?\DateTimeInterface $checkout): array
    {
        if ($c->hasActiveStay || $c->status === RoomStatus::Occupied) {
            return ['Occupied', $checkout?->format('c')];
        }

        return match ($c->status) {
            RoomStatus::Uncleaned => ['To clean', null],
            RoomStatus::Cleaning => ['Cleaning', null],
            RoomStatus::Maintenance => ['Maintenance', null],
            default => $c->hasReservationInPeriod ? ['Reserved', null] : ($c->kioskEnabled ? ['Unavailable', null] : ['Off kiosk', null]),
        };
    }
}
