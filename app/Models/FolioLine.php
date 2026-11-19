<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LineKind;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\FolioLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The sales ledger (DATABASE_SCHEMA §4.11). Append-only: a void is a new row
 * with reversal_of_id, stamped with the shift open at that moment.
 */
class FolioLine extends Model
{
    use AppendOnly;
    use BelongsToBranch;

    /** @use HasFactory<FolioLineFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'shift_session_id', 'stay_id', 'pos_order_id', 'room_id', 'kind', 'product_id',
        'description', 'quantity', 'unit_amount', 'amount', 'reversal_of_id', 'reversal_reason',
        'approval_id', 'created_by', 'occurred_at', 'created_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'kind' => LineKind::class,
            'unit_amount' => Money::class,
            'amount' => Money::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<PosOrder, $this> */
    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class);
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

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * The line that reverses this one, if any.
     *
     * @return HasOne<FolioLine, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<FolioLine, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
