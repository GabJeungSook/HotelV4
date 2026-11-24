<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StayKind;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\RateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Price for one stay option (DATABASE_SCHEMA §4.5), scoped to exactly one of: a room type (the
 * base rate), a whole floor (flat for every room on it) or a single room. stay_kind is hourly or
 * overnight only (long stay is priced from the 24-hour rate).
 */
class Rate extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<RateFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'room_type_id', 'floor_id', 'room_id', 'stay_kind', 'hours', 'amount'];

    protected function casts(): array
    {
        return [
            'stay_kind' => StayKind::class,
            'amount' => Money::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsTo<Floor, $this> */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsToMany<Discount, $this> */
    public function discounts(): BelongsToMany
    {
        return $this->belongsToMany(Discount::class, 'discount_rate');
    }
}
