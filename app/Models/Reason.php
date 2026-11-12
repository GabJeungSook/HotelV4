<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReasonKind;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\ReasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Reason lists for voids, cancels, variances, voided cash entries and wastage
 * (DATABASE_SCHEMA §4.16). Transfer reasons and expense categories keep their own tables.
 */
class Reason extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ReasonFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'kind', 'name', 'needs_note', 'needs_manager', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => ReasonKind::class,
            'needs_note' => 'boolean',
            'needs_manager' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
