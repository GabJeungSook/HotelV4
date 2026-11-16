<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\ActionRefused;
use App\Models\Floor;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Transaction;

/**
 * Add or edit a floor of the branch (44, floors tab; DATABASE_SCHEMA §4.4). The number is unique
 * within the branch. One transaction with its audit_logs row ('floor.created', 'floor.updated').
 */
final class SaveFloor
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, ?int $floorId, int $number, string $label): Floor
    {
        return $this->transaction->run(function () use ($by, $branchId, $floorId, $number, $label): Floor {
            $floor = $floorId === null ? null : Floor::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($floorId);

            $taken = Floor::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('number', $number)
                ->when($floor !== null, fn ($q) => $q->whereKeyNot($floor?->id))->exists();

            if ($taken) {
                throw ActionRefused::because("Floor {$number} is already in this branch.");
            }

            $after = ['number' => $number, 'label' => trim($label)];

            if ($floor === null) {
                $floor = Floor::query()->create(['branch_id' => $branchId, ...$after]);
                $this->audit->record($by, $branchId, 'floor.created', 'floor', $floor->id, null, $after);

                return $floor;
            }

            [$old, $new] = AuditTrail::diff(['number' => $floor->number, 'label' => $floor->label], $after);
            $floor->update($after);

            if ($new !== []) {
                $this->audit->record($by, $branchId, 'floor.updated', 'floor', $floor->id, $old, $new);
            }

            return $floor;
        });
    }
}
