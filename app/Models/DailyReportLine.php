<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportSection;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\DailyReportLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Labelled per-day lines for Stats tables (DATABASE_SCHEMA §4.16). */
class DailyReportLine extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<DailyReportLineFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['branch_id', 'business_date', 'section', 'label', 'quantity', 'amount'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'section' => ReportSection::class,
            'amount' => Money::class,
        ];
    }
}
