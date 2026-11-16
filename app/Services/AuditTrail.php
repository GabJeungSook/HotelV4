<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Clock;

/**
 * Writes the audit_logs row of a settings, rate, price, room or user change (DATABASE_SCHEMA
 * §4.15, ROADMAP Phase 9). Called inside the action's transaction, so the row is saved only
 * when the change is. Actions are named `subject.verb` ('rate.updated', 'user.pin_reset').
 * Never pass a password or a PIN in before or after.
 */
final class AuditTrail
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(User $by, ?int $branchId, string $action, string $subjectType, int $subjectId, ?array $before = null, ?array $after = null): AuditLog
    {
        return AuditLog::query()->create([
            'branch_id' => $branchId,
            'actor_id' => $by->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * Only the keys whose value changed, as [before, after]; both empty when nothing changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                $old[$key] = $before[$key] ?? null;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }
}
