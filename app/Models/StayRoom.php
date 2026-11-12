<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StayRoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which room a stay was in, and when (DATABASE_SCHEMA §4.10). Scoped through its stay. */
class StayRoom extends Model
{
    /** @use HasFactory<StayRoomFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['stay_id', 'room_id', 'from_at', 'to_at', 'transfer_reason_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'from_at' => 'immutable_datetime',
            'to_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
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

    /**
     * The reason for leaving this room.
     *
     * @return BelongsTo<TransferReason, $this>
     */
    public function transferReason(): BelongsTo
    {
        return $this->belongsTo(TransferReason::class);
    }
}
