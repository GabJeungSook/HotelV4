<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\Money;
use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which payment settled which line (DATABASE_SCHEMA §4.12). Append-only; scoped through its payment. */
class PaymentAllocation extends Model
{
    use AppendOnly;

    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'folio_line_id', 'amount', 'created_at'];

    protected function casts(): array
    {
        return [
            'amount' => Money::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<FolioLine, $this> */
    public function folioLine(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class);
    }
}
