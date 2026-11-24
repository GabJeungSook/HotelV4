<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Outlet;
use App\Enums\ProductKind;
use App\Enums\ReportGroup;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Food, drinks, amenities, damages (DATABASE_SCHEMA §4.13). stock_on_hand is a
 * cache of the latest stock_movements.balance_after, written in the same transaction.
 */
class Product extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id', 'name', 'kind', 'category', 'report_group', 'outlet',
        'price_amount', 'sellable', 'tracks_stock', 'stock_on_hand', 'low_stock_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'report_group' => ReportGroup::class,
            'outlet' => Outlet::class,
            'price_amount' => Money::class,
            'sellable' => 'boolean',
            'tracks_stock' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<ProductPriceLog, $this> */
    public function priceLogs(): HasMany
    {
        return $this->hasMany(ProductPriceLog::class);
    }

    /** The recipe: the stock items this product consumes when sold. A product with rows here is a meal. */
    /** @return HasMany<ProductIngredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(ProductIngredient::class);
    }
}
