<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Model;

/**
 * Ledger and log rows are written once (DATABASE_SCHEMA §1, CLAUDE.md rule 3).
 * A correction is a new reversing row, never an update or a delete.
 *
 * @mixin Model
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (Model $model): never {
            throw AppendOnlyViolation::update($model);
        });

        static::deleting(function (Model $model): never {
            throw AppendOnlyViolation::delete($model);
        });
    }
}
