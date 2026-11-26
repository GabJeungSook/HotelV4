<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\StayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One guest stay, from held/reserved to closed (DATABASE_SCHEMA §4.10).
 * active_room_id is a generated column (one active stay per room) and is never written.
 * Balance and deposits held are derived from the ledgers, never stored here.
 */
class Stay extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<StayFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id', 'code', 'state', 'source', 'room_id', 'room_type_id',
        'guest_name', 'guest_mobile', 'plate_number',
        'stay_kind', 'hours', 'nights', 'days', 'discount_id', 'discount_unverified', 'quoted_amount',
        'arrival_at', 'hold_expires_at', 'checked_in_at', 'expected_checkout_at', 'checked_out_at',
        'cycle_hours_used', 'checkin_shift_id', 'checkout_shift_id', 'checked_in_by', 'checked_out_by',
        'closed_reason', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'state' => StayState::class,
            'source' => StaySource::class,
            'stay_kind' => StayKind::class,
            'discount_unverified' => 'boolean',
            'quoted_amount' => Money::class,
            'arrival_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'expected_checkout_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsTo<Discount, $this> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function checkinShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'checkin_shift_id');
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function checkoutShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'checkout_shift_id');
    }

    /** @return HasMany<StayRoom, $this> */
    public function stayRooms(): HasMany
    {
        return $this->hasMany(StayRoom::class);
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

    /** @return HasMany<CleaningTask, $this> */
    public function cleaningTasks(): HasMany
    {
        return $this->hasMany(CleaningTask::class);
    }
}
