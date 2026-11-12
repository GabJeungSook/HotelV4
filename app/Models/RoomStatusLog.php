<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Models\Concerns\AppendOnly;
use Database\Factories\RoomStatusLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Every room status change, who and why (DATABASE_SCHEMA §4.4). Append-only; scoped through its room. */
class RoomStatusLog extends Model
{
    use AppendOnly;

    /** @use HasFactory<RoomStatusLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['room_id', 'from_status', 'to_status', 'reason', 'stay_id', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'from_status' => RoomStatus::class,
            'to_status' => RoomStatus::class,
            'reason' => RoomStatusReason::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
