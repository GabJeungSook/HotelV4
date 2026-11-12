<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashMovementKind;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expenses, remittances and shortage payments (DATABASE_SCHEMA §4.9). The only
 * update allowed is the void columns, while the row's shift is open.
 */
class CashMovement extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id', 'shift_session_id', 'kind', 'expense_category_id', 'for_shift_id', 'amount', 'note',
        'created_by', 'occurred_at', 'voided_at', 'voided_by', 'void_reason', 'approval_id', 'reason_id', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CashMovementKind::class,
            'amount' => Money::class,
            'occurred_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class);
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /**
     * The short shift a shortage payment repays.
     *
     * @return BelongsTo<ShiftSession, $this>
     */
    public function forShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'for_shift_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * The void_cash_movement reason.
     *
     * @return BelongsTo<Reason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
