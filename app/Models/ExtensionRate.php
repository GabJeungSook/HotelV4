<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\ExtensionRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Price per extension block (DATABASE_SCHEMA §4.5). */
class ExtensionRate extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ExtensionRateFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'hours', 'amount'];

    protected function casts(): array
    {
        return [
            'amount' => Money::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
