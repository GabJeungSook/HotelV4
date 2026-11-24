<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HallBookingStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\HallBookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A function-hall booking (DATABASE_SCHEMA §4.14): a time slot for a customer, priced from its items.
 * Balance and deposits are never stored — they are derived from the ledgers, like a stay. The money
 * (a third owner on folio_lines/payments) is written at reserve (deposit) and complete (charges).
 */
class HallBooking extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<HallBookingFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id', 'hall_id', 'code', 'status', 'customer_name', 'customer_mobile',
        'event_date', 'start_at', 'end_at', 'pax', 'quoted_amount',
        'reserve_shift_id', 'complete_shift_id', 'cancel_reason', 'created_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => HallBookingStatus::class,
            'event_date' => 'immutable_date',
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'pax' => 'integer',
            'quoted_amount' => Money::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Hall, $this> */
    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    /** @return HasMany<HallBookingItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(HallBookingItem::class);
    }
}
