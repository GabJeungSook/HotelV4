<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\DiscountKind;
use App\Models\Discount;
use Illuminate\Support\Facades\DB;

/** A discount as plain values (TRD §4.2): fixed centavos or whole percent, and the rates it may be used on. */
final class DiscountTerms
{
    /** @param  list<int>  $rateIds  used only when $allRates is false */
    public function __construct(
        public readonly string $name,
        public readonly DiscountKind $kind,
        public readonly int $value,
        public readonly bool $allRates = true,
        public readonly array $rateIds = [],
        public readonly ?int $id = null,
    ) {}

    public static function of(Discount $discount): self
    {
        $rateIds = [];

        if (! $discount->all_rates) {
            foreach (DB::table('discount_rate')->where('discount_id', $discount->id)->pluck('rate_id') as $id) {
                $rateIds[] = (int) $id;
            }
        }

        return new self($discount->name, $discount->kind, $discount->value, $discount->all_rates, $rateIds, $discount->id);
    }

    public function allows(int $rateId): bool
    {
        return $this->allRates || in_array($rateId, $this->rateIds, true);
    }
}
