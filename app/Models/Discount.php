<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountKind;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * DATABASE_SCHEMA §4.5. `value` is centavos for a fixed discount and a whole
 * percent for a percent discount, so it is an integer, not a Money cast.
 */
class Discount extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<DiscountFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'name', 'kind', 'value', 'all_rates', 'kiosk_enabled', 'is_vat_exempt', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'value' => 'integer',
            'all_rates' => 'boolean',
            'kiosk_enabled' => 'boolean',
            'is_vat_exempt' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Rates this discount is limited to when all_rates is off.
     *
     * @return BelongsToMany<Rate, $this>
     */
    public function rates(): BelongsToMany
    {
        return $this->belongsToMany(Rate::class, 'discount_rate');
    }
}
