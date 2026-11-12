<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\ShiftSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One shift actually worked (DATABASE_SCHEMA §4.7). open_branch_id is a generated
 * column (one open shift per branch) and is never written. `totals`, `z_no` and the
 * grand totals are written once, in the closing transaction.
 */
class ShiftSession extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ShiftSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id', 'shift_definition_id', 'period_name', 'business_date', 'status',
        'opened_at', 'opened_by', 'opening_cash', 'opening_note',
        'closed_at', 'closed_by', 'is_force_closed',
        'expected_cash', 'counted_cash', 'variance_amount', 'variance_reason_id', 'variance_note',
        'handover_note', 'totals', 'z_no', 'grand_total_before', 'grand_total_after',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'business_date' => 'immutable_date',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'is_force_closed' => 'boolean',
            'opening_cash' => Money::class,
            'expected_cash' => Money::class,
            'counted_cash' => Money::class,
            'variance_amount' => Money::class,
            'grand_total_before' => Money::class,
            'grand_total_after' => Money::class,
            'totals' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ShiftDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ShiftDefinition::class, 'shift_definition_id');
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<Reason, $this> */
    public function varianceReason(): BelongsTo
    {
        return $this->belongsTo(Reason::class, 'variance_reason_id');
    }

    /** @return HasMany<ShiftMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ShiftMember::class);
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

    /** @return HasMany<CashMovement, $this> */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * The stays this shift forwarded out at its close.
     *
     * @return HasMany<ShiftForwardedStay, $this>
     */
    public function forwardedStays(): HasMany
    {
        return $this->hasMany(ShiftForwardedStay::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasOne<CashCount, $this> */
    public function cashCount(): HasOne
    {
        return $this->hasOne(CashCount::class);
    }

    /** @return HasOne<ShiftHandover, $this> */
    public function handoverOut(): HasOne
    {
        return $this->hasOne(ShiftHandover::class, 'from_shift_id');
    }

    /** @return HasOne<ShiftHandover, $this> */
    public function handoverIn(): HasOne
    {
        return $this->hasOne(ShiftHandover::class, 'to_shift_id');
    }
}
