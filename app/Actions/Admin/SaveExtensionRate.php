<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\ActionRefused;
use App\Models\ExtensionRate;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Set the price of an extension block for all room types (45; DATABASE_SCHEMA §4.5). An amount
 * above zero writes or changes the row; a blank amount (null) removes it, so that block is no
 * longer offered on extend (12). An extension is priced when it is sold, so no open stay quotes
 * a block. One transaction with its audit_logs row: 'extension_rate.created', '.updated', '.removed'.
 */
final class SaveExtensionRate
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, int $hours, ?Money $amount): ?ExtensionRate
    {
        if ($hours < 1 || $hours > 24) {
            throw ActionRefused::because('An extension block is 1 to 24 hours.');
        }

        if ($amount !== null && $amount->centavos() <= 0) {
            throw ActionRefused::because('An extension rate is more than ₱0. Remove the block to stop offering it.');
        }

        return $this->transaction->run(function () use ($by, $branchId, $hours, $amount): ?ExtensionRate {
            $rate = ExtensionRate::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('hours', $hours)->lockForUpdate()->first();

            if ($amount === null) {
                if ($rate !== null) {
                    $this->audit->record($by, $branchId, 'extension_rate.removed', 'extension_rate', $rate->id, ['hours' => $hours, 'amount' => $rate->amount?->centavos()], null);
                    $rate->delete();
                }

                return null;
            }

            if ($rate === null) {
                $rate = ExtensionRate::query()->create(['branch_id' => $branchId, 'hours' => $hours, 'amount' => $amount]);
                $this->audit->record($by, $branchId, 'extension_rate.created', 'extension_rate', $rate->id, null, ['hours' => $hours, 'amount' => $amount->centavos()]);

                return $rate;
            }

            $old = $rate->amount?->centavos();

            if ($old !== $amount->centavos()) {
                $rate->update(['amount' => $amount]);
                $this->audit->record($by, $branchId, 'extension_rate.updated', 'extension_rate', $rate->id, ['hours' => $hours, 'amount' => $old], ['hours' => $hours, 'amount' => $amount->centavos()]);
            }

            return $rate;
        });
    }
}
