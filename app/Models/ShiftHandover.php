<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Database\Factories\ShiftHandoverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outgoing → incoming acceptance (DATABASE_SCHEMA §4.8). Not append-only: after a
 * close without handover, the next OpenShift sets to_shift_id (§8.10 step 5).
 */
class ShiftHandover extends Model
{
    /** @use HasFactory<ShiftHandoverFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['from_shift_id', 'to_shift_id', 'received_by', 'received_cash', 'difference_amount', 'note', 'created_at'];

    protected function casts(): array
    {
        return [
            'received_cash' => Money::class,
            'difference_amount' => Money::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function fromShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'from_shift_id');
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function toShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'to_shift_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
