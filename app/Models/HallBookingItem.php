<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HallPricing;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\HallBookingItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quote line of a hall booking (DATABASE_SCHEMA §4.14): a package or custom item, per pax or flat,
 * amount = quantity × unit. Editable config while the booking is reserved; the money record is the
 * folio_lines written at completion.
 */
class HallBookingItem extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<HallBookingItemFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'hall_booking_id', 'hall_package_id', 'label', 'pricing', 'quantity', 'unit_amount', 'amount'];

    protected function casts(): array
    {
        return [
            'pricing' => HallPricing::class,
            'quantity' => 'integer',
            'unit_amount' => Money::class,
            'amount' => Money::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<HallBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(HallBooking::class);
    }

    /** @return BelongsTo<HallPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(HallPackage::class, 'hall_package_id');
    }
}
