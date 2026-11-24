<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HallPackageKind;
use App\Enums\HallPricing;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Money;
use Database\Factories\HallPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A priced item a hall booking is built from (DATABASE_SCHEMA §4.14): a venue, food package, host or
 * other, charged per pax or flat. Admin-managed config; a booking line may copy one or be custom.
 */
class HallPackage extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<HallPackageFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'name', 'kind', 'pricing', 'unit_amount', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => HallPackageKind::class,
            'pricing' => HallPricing::class,
            'unit_amount' => Money::class,
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
