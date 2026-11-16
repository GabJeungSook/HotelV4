<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ReasonKind;
use App\Exceptions\ActionRefused;
use App\Models\ExpenseCategory;
use App\Models\Reason;
use App\Models\TransferReason;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Add or edit a row of one of the branch's reason lists (46, APP_FLOW §8.2): transfer reasons
 * (13), expense categories (21), or a `reasons` list of one kind (void line, cancel stay, cash
 * variance, void cash entry, wastage, cancel reservation). Each row has a name unique within its
 * list and two switches: it needs a typed note, it needs a supervisor PIN. A row is switched off,
 * never deleted, and every list keeps at least one active row, so no screen is left without a
 * reason to pick. One transaction with its audit_logs row ('transfer_reason.created',
 * 'expense_category.updated', 'reason.created', …).
 */
final class SaveListReason
{
    public const TRANSFER = 'transfer_reasons';

    public const EXPENSE = 'expense_categories';

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    /** @return list<string> every list key: the two own tables and each reasons.kind */
    public static function lists(): array
    {
        return [self::TRANSFER, self::EXPENSE, ...array_map(fn (ReasonKind $k): string => $k->value, ReasonKind::cases())];
    }

    public function handle(User $by, int $branchId, string $list, ?int $id, string $name, bool $needsNote, bool $needsManager, bool $isActive = true): Model
    {
        if (! in_array($list, self::lists(), true)) {
            throw ActionRefused::because('There is no such reason list.');
        }

        $name = trim($name);

        return $this->transaction->run(function () use ($by, $branchId, $list, $id, $name, $needsNote, $needsManager, $isActive): Model {
            $row = $id === null ? null : $this->query($branchId, $list)->lockForUpdate()->findOrFail($id);

            $taken = $this->query($branchId, $list)->where('name', $name)
                ->when($row !== null, fn ($q) => $q->whereKeyNot($row?->getKey()))->exists();

            if ($taken) {
                throw ActionRefused::because("{$name} is already in this list.");
            }

            if (! $isActive) {
                $others = $this->query($branchId, $list)->where('is_active', true)
                    ->when($row !== null, fn ($q) => $q->whereKeyNot($row?->getKey()))->count();

                if ($others === 0) {
                    throw ActionRefused::because('Keep at least one active reason in this list.');
                }
            }

            $after = ['name' => $name, 'needs_note' => $needsNote, 'needs_manager' => $needsManager, 'is_active' => $isActive];
            $subject = self::subject($list);

            if ($row === null) {
                $row = $this->query($branchId, $list)->create([
                    'branch_id' => $branchId,
                    ...(self::isReasonKind($list) ? ['kind' => $list] : []),
                    ...$after,
                ]);
                $this->audit->record($by, $branchId, "{$subject}.created", $subject, (int) $row->getKey(), null, [...$after, 'list' => $list]);

                return $row;
            }

            $before = [
                'name' => $row->getAttribute('name'),
                'needs_note' => (bool) $row->getAttribute('needs_note'),
                'needs_manager' => (bool) $row->getAttribute('needs_manager'),
                'is_active' => (bool) $row->getAttribute('is_active'),
            ];
            [$old, $new] = AuditTrail::diff($before, $after);
            $row->update($after);

            if ($new !== []) {
                $this->audit->record($by, $branchId, "{$subject}.updated", $subject, (int) $row->getKey(), $old, [...$new, 'list' => $list]);
            }

            return $row;
        });
    }

    /** @return Builder<TransferReason>|Builder<ExpenseCategory>|Builder<Reason> */
    private function query(int $branchId, string $list): Builder
    {
        return match ($list) {
            self::TRANSFER => TransferReason::query()->withoutGlobalScopes()->where('branch_id', $branchId),
            self::EXPENSE => ExpenseCategory::query()->withoutGlobalScopes()->where('branch_id', $branchId),
            default => Reason::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('kind', $list),
        };
    }

    private static function subject(string $list): string
    {
        return match ($list) {
            self::TRANSFER => 'transfer_reason',
            self::EXPENSE => 'expense_category',
            default => 'reason',
        };
    }

    private static function isReasonKind(string $list): bool
    {
        return ReasonKind::tryFrom($list) !== null;
    }
}
