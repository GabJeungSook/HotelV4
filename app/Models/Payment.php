<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The cash ledger (DATABASE_SCHEMA §4.12). Signed: money to the hotel is positive. Append-only. */
class Payment extends Model
{
    use AppendOnly;
    use BelongsToBranch;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'shift_session_id', 'stay_id', 'pos_order_id', 'hall_booking_id', 'kind', 'method', 'deposit_type',
        'amount', 'tendered_amount', 'change_amount', 'reference_no', 'reversal_of_id', 'approval_id',
        'note', 'idempotency_key', 'created_by', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'method' => PaymentMethod::class,
            'deposit_type' => DepositType::class,
            'amount' => Money::class,
            'tendered_amount' => Money::class,
            'change_amount' => Money::class,
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

    /** @return BelongsTo<HallBooking, $this> */
    public function hallBooking(): BelongsTo
    {
        return $this->belongsTo(HallBooking::class);
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class);
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

    /** @return BelongsTo<Payment, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
