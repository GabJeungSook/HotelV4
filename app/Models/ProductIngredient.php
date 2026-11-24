<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\ProductIngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a meal's recipe (DATABASE_SCHEMA §4.13): the meal (`product_id`) consumes `quantity`
 * whole units of the stock item (`ingredient_id`) each time it is sold. Editable config, replaced
 * on save — not a money ledger.
 */
class ProductIngredient extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ProductIngredientFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'product_id', 'ingredient_id', 'quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_id');
    }
}
