<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A programming error: code tried to change or remove an append-only row. */
final class AppendOnlyViolation extends LogicException
{
    public static function update(Model $model): self
    {
        return new self($model::class.' rows are append-only and cannot be updated; write a reversing row instead.');
    }

    public static function delete(Model $model): self
    {
        return new self($model::class.' rows are append-only and cannot be deleted; write a reversing row instead.');
    }
}
