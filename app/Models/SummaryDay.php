<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasCompositeKey;
use Database\Factories\SummaryDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Which branch-days need a summary rebuild (DATABASE_SCHEMA §4.16).
 * "Dirty" is dirty_at >= rebuilt_at.
 */
class SummaryDay extends Model
{
    use BelongsToBranch;
    use HasCompositeKey;

    /** @use HasFactory<SummaryDayFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $primaryKey = 'branch_id';

    protected $fillable = ['branch_id', 'business_date', 'dirty_at', 'rebuilt_at'];

    public function compositeKey(): array
    {
        return ['branch_id', 'business_date'];
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'dirty_at' => 'immutable_datetime',
            'rebuilt_at' => 'immutable_datetime',
        ];
    }
}
