<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementKind;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock in, sold, wastage, reversal (DATABASE_SCHEMA §4.13). Quantity is signed. Append-only. */
class StockMovement extends Model
{
    use AppendOnly;
    use BelongsToBranch;

    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'product_id', 'shift_session_id', 'kind', 'quantity', 'balance_after',
        'folio_line_id', 'reason_id', 'note', 'created_by', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => StockMovementKind::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class);
    }

    /** @return BelongsTo<FolioLine, $this> */
    public function folioLine(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class);
    }

    /**
     * The wastage reason.
     *
     * @return BelongsTo<Reason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
