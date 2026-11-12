<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\Money;
use Database\Factories\CashCountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The blind count at close (DATABASE_SCHEMA §4.8). Append-only.
 * denominations: {"100000": 8, "50000": 6, …, "coins": 4500} (pieces, coins in centavos).
 */
class CashCount extends Model
{
    use AppendOnly;

    /** @use HasFactory<CashCountFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['shift_session_id', 'denominations', 'total_cash', 'counted_by', 'witness_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'denominations' => 'array',
            'total_cash' => Money::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witness_id');
    }
}
