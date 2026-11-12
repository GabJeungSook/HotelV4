<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\ShiftDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Named shifts and their hours (DATABASE_SCHEMA §4.3). Times are branch-local TIME values. */
class ShiftDefinition extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ShiftDefinitionFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'name', 'starts_at', 'ends_at', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ShiftSession, $this> */
    public function shiftSessions(): HasMany
    {
        return $this->hasMany(ShiftSession::class);
    }
}
