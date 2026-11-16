<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\ActionRefused;
use App\Models\ShiftDefinition;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Transaction;

/**
 * Add or edit one of the branch's shifts (46, shifts group; DATABASE_SCHEMA §4.3): a name unique
 * within the branch, a start and an end in branch time (the end may be earlier than the start
 * when the shift crosses midnight). A shift is switched off, never deleted, and the branch keeps
 * at least one active shift. A shift already open keeps the period it was opened with. One
 * transaction with its audit_logs row ('shift_definition.created', 'shift_definition.updated').
 */
final class SaveShiftDefinition
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, ?int $definitionId, string $name, string $startsAt, string $endsAt, bool $isActive = true): ShiftDefinition
    {
        $name = trim($name);
        $startsAt = self::time($startsAt);
        $endsAt = self::time($endsAt);

        if ($startsAt === $endsAt) {
            throw ActionRefused::because('A shift cannot start and end at the same time.');
        }

        return $this->transaction->run(function () use ($by, $branchId, $definitionId, $name, $startsAt, $endsAt, $isActive): ShiftDefinition {
            $definition = $definitionId === null ? null : ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($definitionId);

            $taken = ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('name', $name)
                ->when($definition !== null, fn ($q) => $q->whereKeyNot($definition?->id))->exists();

            if ($taken) {
                throw ActionRefused::because("There is already a shift called {$name}.");
            }

            if (! $isActive) {
                $others = ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)
                    ->when($definition !== null, fn ($q) => $q->whereKeyNot($definition?->id))->count();

                if ($others === 0) {
                    throw ActionRefused::because('The branch needs at least one active shift.');
                }
            }

            $after = ['name' => $name, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_active' => $isActive];

            if ($definition === null) {
                $sort = (int) ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branchId)->max('sort_order') + 1;
                $definition = ShiftDefinition::query()->create(['branch_id' => $branchId, ...$after, 'sort_order' => $sort]);
                $this->audit->record($by, $branchId, 'shift_definition.created', 'shift_definition', $definition->id, null, $after);

                return $definition;
            }

            $before = ['name' => $definition->name, 'starts_at' => self::time((string) $definition->starts_at), 'ends_at' => self::time((string) $definition->ends_at), 'is_active' => $definition->is_active];
            [$old, $new] = AuditTrail::diff($before, $after);
            $definition->update($after);

            if ($new !== []) {
                $this->audit->record($by, $branchId, 'shift_definition.updated', 'shift_definition', $definition->id, $old, $new);
            }

            return $definition;
        });
    }

    /** "8:00" or "08:00" or "08:00:00" → "08:00:00". */
    private static function time(string $value): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw ActionRefused::because("{$value} is not a time of day.");
        }

        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }
}
