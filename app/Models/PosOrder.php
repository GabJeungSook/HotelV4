<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PosMode;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\PosOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Header for a POS sale (DATABASE_SCHEMA §4.13). */
class PosOrder extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<PosOrderFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'shift_session_id', 'stay_id', 'mode', 'total_amount', 'created_by',
        'order_no', 'idempotency_key', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => PosMode::class,
            'total_amount' => Money::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class);
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<FolioLine, $this> */
    public function folioLines(): HasMany
    {
        return $this->hasMany(FolioLine::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
