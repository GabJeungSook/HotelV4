<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasCompositeKey;
use App\Support\Money;
use Database\Factories\DailyPaymentSummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Collections per branch-day, method and person (DATABASE_SCHEMA §4.16). */
class DailyPaymentSummary extends Model
{
    use BelongsToBranch;
    use HasCompositeKey;

    /** @use HasFactory<DailyPaymentSummaryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $primaryKey = 'branch_id';

    protected $fillable = ['branch_id', 'business_date', 'method', 'user_id', 'payment_count', 'amount', 'refund_amount'];

    public function compositeKey(): array
    {
        return ['branch_id', 'business_date', 'method', 'user_id'];
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'method' => PaymentMethod::class,
            'amount' => Money::class,
            'refund_amount' => Money::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
