<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\ActionRefused;
use App\Models\RoomType;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Transaction;

/**
 * Add or edit a room type (44, room types tab; DATABASE_SCHEMA §4.4). The name is unique within
 * the branch; the description is what the kiosk (28) shows. A type is switched off, never
 * deleted. One transaction with its audit_logs row ('room_type.created', 'room_type.updated').
 */
final class SaveRoomType
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, ?int $typeId, string $name, ?string $description, bool $isActive = true): RoomType
    {
        return $this->transaction->run(function () use ($by, $branchId, $typeId, $name, $description, $isActive): RoomType {
            $type = $typeId === null ? null : RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($typeId);
            $name = trim($name);

            $taken = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('name', $name)
                ->when($type !== null, fn ($q) => $q->whereKeyNot($type?->id))->exists();

            if ($taken) {
                throw ActionRefused::because("There is already a room type called {$name}.");
            }

            $after = ['name' => $name, 'description' => $description === null || trim($description) === '' ? null : trim($description), 'is_active' => $isActive];

            if ($type === null) {
                $type = RoomType::query()->create(['branch_id' => $branchId, ...$after]);
                $this->audit->record($by, $branchId, 'room_type.created', 'room_type', $type->id, null, $after);

                return $type;
            }

            [$old, $new] = AuditTrail::diff(['name' => $type->name, 'description' => $type->description, 'is_active' => $type->is_active], $after);
            $type->update($after);

            if ($new !== []) {
                $this->audit->record($by, $branchId, 'room_type.updated', 'room_type', $type->id, $old, $new);
            }

            return $type;
        });
    }
}
