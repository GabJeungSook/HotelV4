<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasCompositeKey;
use App\Support\Money;
use Database\Factories\DailySummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-side figures per branch-day for dashboards and Stats (DATABASE_SCHEMA §4.16).
 * Rebuilt from the ledgers by RebuildSummaries; never read by closed-shift reports.
 */
class DailySummary extends Model
{
    use BelongsToBranch;
    use HasCompositeKey;

    /** @use HasFactory<DailySummaryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $primaryKey = 'branch_id';

    protected $fillable = [
        'branch_id', 'business_date',
        'gross_sales_amount', 'discount_amount', 'net_sales_amount', 'room_sales_amount', 'other_sales_amount',
        'collected_amount', 'refund_amount', 'short_amount', 'over_amount',
        'checkins', 'checkouts', 'stays_in_house_end', 'room_count', 'cleans', 'late_cleans',
    ];

    public function compositeKey(): array
    {
        return ['branch_id', 'business_date'];
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'gross_sales_amount' => Money::class,
            'discount_amount' => Money::class,
            'net_sales_amount' => Money::class,
            'room_sales_amount' => Money::class,
            'other_sales_amount' => Money::class,
            'collected_amount' => Money::class,
            'refund_amount' => Money::class,
            'short_amount' => Money::class,
            'over_amount' => Money::class,
        ];
    }
}
