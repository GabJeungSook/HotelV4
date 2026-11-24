<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\ActionRefused;
use App\Models\Hall;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Add or edit a function hall of the branch (DATABASE_SCHEMA §4.14). The name is unique within the
 * branch; the base rate is a reference only. One transaction with its audit row (hall.created/updated).
 *
 * @phpstan-type HallData array{name: string, capacity: int, base_rate_amount: Money|null, is_active: bool}
 */
final class SaveHall
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    /** @param  HallData  $data */
    public function handle(User $by, int $branchId, ?int $hallId, array $data): Hall
    {
        return $this->transaction->run(function () use ($by, $branchId, $hallId, $data): Hall {
            $hall = $hallId === null ? null : Hall::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($hallId);

            $name = trim($data['name']);

            if ($name === '') {
                throw ActionRefused::because('Give the hall a name.');
            }

            $taken = Hall::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('name', $name)
                ->when($hall !== null, fn ($q) => $q->whereKeyNot($hall?->id))->exists();

            if ($taken) {
                throw ActionRefused::because("There is already a hall called {$name}.");
            }

            $after = [
                'name' => $name,
                'capacity' => $data['capacity'],
                'base_rate_amount' => $data['base_rate_amount']?->centavos(),
                'is_active' => $data['is_active'],
            ];

            if ($hall === null) {
                $hall = Hall::query()->create(['branch_id' => $branchId, 'name' => $name, 'capacity' => $data['capacity'], 'base_rate_amount' => $data['base_rate_amount'], 'is_active' => $data['is_active']]);
                $this->audit->record($by, $branchId, 'hall.created', 'hall', $hall->id, null, $after);

                return $hall;
            }

            [$old, $new] = AuditTrail::diff([
                'name' => $hall->name,
                'capacity' => $hall->capacity,
                'base_rate_amount' => $hall->base_rate_amount?->centavos(),
                'is_active' => $hall->is_active,
            ], $after);

            $hall->update(['name' => $name, 'capacity' => $data['capacity'], 'base_rate_amount' => $data['base_rate_amount'], 'is_active' => $data['is_active']]);

            if ($new !== []) {
                $this->audit->record($by, $branchId, 'hall.updated', 'hall', $hall->id, $old, $new);
            }

            return $hall;
        });
    }
}
