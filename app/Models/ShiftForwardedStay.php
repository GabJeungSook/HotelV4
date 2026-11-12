<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\Money;
use Database\Factories\ShiftForwardedStayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The in-house list saved when a shift closes (DATABASE_SCHEMA §4.8): shift N's
 * "forwarded out" and shift N+1's "forwarded in". Append-only.
 */
class ShiftForwardedStay extends Model
{
    use AppendOnly;

    /** @use HasFactory<ShiftForwardedStayFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'shift_session_id', 'stay_id', 'room_id', 'guest_name', 'expected_checkout_at',
        'unpaid_amount', 'key_deposit_held', 'guest_deposit_held', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_checkout_at' => 'immutable_datetime',
            'unpaid_amount' => Money::class,
            'key_deposit_held' => Money::class,
            'guest_deposit_held' => Money::class,
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

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
