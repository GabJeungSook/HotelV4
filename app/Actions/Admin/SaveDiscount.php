<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\DiscountKind;
use App\Exceptions\ActionRefused;
use App\Models\Discount;
use App\Models\Rate;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Transaction;

/**
 * Add or edit a discount (45; DATABASE_SCHEMA §4.5). A fixed discount's value is centavos, a
 * percent discount's a whole percent from 1 to 100. A discount applies to every rate, or only
 * to the rates listed in discount_rate (all of them the branch's own, at least one). A discount
 * is switched off, never deleted. One transaction with its audit_logs row
 * ('discount.created', 'discount.updated').
 *
 * @phpstan-type DiscountData array{name: string, kind: DiscountKind, value: int, all_rates: bool, kiosk_enabled: bool, is_vat_exempt: bool, is_active: bool}
 */
final class SaveDiscount
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  DiscountData  $data
     * @param  list<int>  $rateIds
     */
    public function handle(User $by, int $branchId, ?int $discountId, array $data, array $rateIds = []): Discount
    {
        $name = trim($data['name']);

        if ($data['kind'] === DiscountKind::Percent && ($data['value'] < 1 || $data['value'] > 100)) {
            throw ActionRefused::because('A percent discount is 1 to 100 %.');
        }

        if ($data['kind'] === DiscountKind::Fixed && $data['value'] <= 0) {
            throw ActionRefused::because('A fixed discount is more than ₱0.');
        }

        return $this->transaction->run(function () use ($by, $branchId, $discountId, $data, $rateIds, $name): Discount {
            $discount = $discountId === null ? null : Discount::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($discountId);

            $taken = Discount::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('name', $name)
                ->when($discount !== null, fn ($q) => $q->whereKeyNot($discount?->id))->exists();

            if ($taken) {
                throw ActionRefused::because("There is already a discount called {$name}.");
            }

            $rateIds = array_values(array_unique($rateIds));
            sort($rateIds);

            if (! $data['all_rates']) {
                $own = Rate::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereIn('id', $rateIds)->count();

                if ($rateIds === [] || $own !== count($rateIds)) {
                    throw ActionRefused::because('Pick the rates this discount applies to, or let it apply to every rate.');
                }
            } else {
                $rateIds = [];
            }

            $after = [
                'name' => $name,
                'kind' => $data['kind']->value,
                'value' => $data['value'],
                'all_rates' => $data['all_rates'],
                'kiosk_enabled' => $data['kiosk_enabled'],
                'is_vat_exempt' => $data['is_vat_exempt'],
                'is_active' => $data['is_active'],
                'rate_ids' => $rateIds,
            ];
            $columns = $after;
            unset($columns['rate_ids']);

            if ($discount === null) {
                $discount = Discount::query()->create(['branch_id' => $branchId, ...$columns]);
                $discount->rates()->sync($rateIds);
                $this->audit->record($by, $branchId, 'discount.created', 'discount', $discount->id, null, $after);

                return $discount;
            }

            $current = $discount->rates()->pluck('rates.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $before = [
                'name' => $discount->name,
                'kind' => $discount->kind->value,
                'value' => $discount->value,
                'all_rates' => $discount->all_rates,
                'kiosk_enabled' => $discount->kiosk_enabled,
                'is_vat_exempt' => $discount->is_vat_exempt,
                'is_active' => $discount->is_active,
                'rate_ids' => $current,
            ];
            [$old, $new] = AuditTrail::diff($before, $after);

            $discount->update($columns);
            $discount->rates()->sync($rateIds);

            if ($new !== []) {
                $this->audit->record($by, $branchId, 'discount.updated', 'discount', $discount->id, $old, $new);
            }

            return $discount;
        });
    }
}
