<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\HallPackageKind;
use App\Enums\HallPricing;
use App\Exceptions\ActionRefused;
use App\Models\HallPackage;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Add or edit a hall package (DATABASE_SCHEMA §4.14): a venue/food/host/other item priced per pax or
 * flat, that bookings are built from. Name unique within the branch. One transaction + audit row.
 *
 * @phpstan-type PackageData array{name: string, kind: HallPackageKind, pricing: HallPricing, unit_amount: Money, is_active: bool}
 */
final class SaveHallPackage
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    /** @param  PackageData  $data */
    public function handle(User $by, int $branchId, ?int $packageId, array $data): HallPackage
    {
        return $this->transaction->run(function () use ($by, $branchId, $packageId, $data): HallPackage {
            $package = $packageId === null ? null : HallPackage::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($packageId);

            $name = trim($data['name']);

            if ($name === '') {
                throw ActionRefused::because('Give the package a name.');
            }

            if (! $data['unit_amount']->isPositive()) {
                throw ActionRefused::because('A package price is more than ₱0.');
            }

            $taken = HallPackage::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('name', $name)
                ->when($package !== null, fn ($q) => $q->whereKeyNot($package?->id))->exists();

            if ($taken) {
                throw ActionRefused::because("There is already a package called {$name}.");
            }

            $after = [
                'name' => $name,
                'kind' => $data['kind']->value,
                'pricing' => $data['pricing']->value,
                'unit_amount' => $data['unit_amount']->centavos(),
                'is_active' => $data['is_active'],
            ];
            $values = ['name' => $name, 'kind' => $data['kind'], 'pricing' => $data['pricing'], 'unit_amount' => $data['unit_amount'], 'is_active' => $data['is_active']];

            if ($package === null) {
                $package = HallPackage::query()->create(['branch_id' => $branchId, ...$values]);
                $this->audit->record($by, $branchId, 'hall_package.created', 'hall_package', $package->id, null, $after);

                return $package;
            }

            [$old, $new] = AuditTrail::diff([
                'name' => $package->name,
                'kind' => $package->kind->value,
                'pricing' => $package->pricing->value,
                'unit_amount' => $package->unit_amount?->centavos(),
                'is_active' => $package->is_active,
            ], $after);

            $package->update($values);

            if ($new !== []) {
                $this->audit->record($by, $branchId, 'hall_package.updated', 'hall_package', $package->id, $old, $new);
            }

            return $package;
        });
    }
}
