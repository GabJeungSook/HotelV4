<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Database\Factories\ProductPriceLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Price change history (DATABASE_SCHEMA §4.13). Scoped through its product. */
class ProductPriceLog extends Model
{
    /** @use HasFactory<ProductPriceLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'old_amount', 'new_amount', 'changed_by', 'created_at'];

    protected function casts(): array
    {
        return [
            'old_amount' => Money::class,
            'new_amount' => Money::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
