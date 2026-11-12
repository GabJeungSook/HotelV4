<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * DATABASE_SCHEMA §4.4. `status` is changed only by RoomStatusService (Phase 3),
 * which writes a room_status_logs row for every move.
 */
class Room extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id', 'floor_id', 'room_type_id', 'number', 'area', 'status',
        'is_kiosk_enabled', 'is_active', 'last_checkout_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'is_kiosk_enabled' => 'boolean',
            'is_active' => 'boolean',
            'last_checkout_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return HasMany<Stay, $this> */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    /** @return HasMany<StayRoom, $this> */
    public function stayRooms(): HasMany
    {
        return $this->hasMany(StayRoom::class);
    }

    /** @return HasMany<CleaningTask, $this> */
    public function cleaningTasks(): HasMany
    {
        return $this->hasMany(CleaningTask::class);
    }

    /** @return HasMany<RoomStatusLog, $this> */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(RoomStatusLog::class);
    }

    /**
     * The held or checked-in stay (at most one, enforced by stays_one_active_per_room).
     *
     * @return HasOne<Stay, $this>
     */
    public function activeStay(): HasOne
    {
        return $this->hasOne(Stay::class)->whereIn('state', [StayState::Held, StayState::CheckedIn]);
    }
}
