<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\DayClosingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per closed business day, the day-level Z (DATABASE_SCHEMA §4.16, screen 38b). */
class DayClosing extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<DayClosingFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'business_date', 'day_z_no', 'net_sales_amount', 'short_amount', 'over_amount',
        'grand_total_before', 'grand_total_after', 'shift_z_from', 'shift_z_to', 'closed_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'net_sales_amount' => Money::class,
            'short_amount' => Money::class,
            'over_amount' => Money::class,
            'grand_total_before' => Money::class,
            'grand_total_after' => Money::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
