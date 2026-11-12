<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasCompositeKey;
use App\Support\Money;
use Database\Factories\HourlySalesSummaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Net sales and check-ins per local hour (DATABASE_SCHEMA §4.16). Hours are bucketed in PHP, branch local time. */
class HourlySalesSummary extends Model
{
    use BelongsToBranch;
    use HasCompositeKey;

    /** @use HasFactory<HourlySalesSummaryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $primaryKey = 'branch_id';

    protected $fillable = ['branch_id', 'business_date', 'local_date', 'hour', 'net_sales_amount', 'checkins'];

    public function compositeKey(): array
    {
        return ['branch_id', 'business_date', 'local_date', 'hour'];
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'local_date' => 'immutable_date',
            'net_sales_amount' => Money::class,
        ];
    }
}
