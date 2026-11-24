<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\HallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bookable function/event hall of the branch (DATABASE_SCHEMA §4.14). */
class Hall extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<HallFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'name', 'capacity', 'base_rate_amount', 'is_active'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'base_rate_amount' => Money::class,
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<HallBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class);
    }
}
