<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Recognises MySQL duplicate-key errors (1062) by the unique index they hit, so an action
 * can turn stays_one_active_per_room into RoomTaken and an idempotency key into the first
 * result (TRD §5).
 */
final class DuplicateKey
{
    public static function on(QueryException $e, string $index): bool
    {
        $info = $e->errorInfo;

        if (! is_array($info) || ($info[1] ?? null) !== 1062) {
            return false;
        }

        // MySQL 8 names the key as `table.index`.
        return preg_match("/for key '(?:[a-z_]+\.)?".preg_quote($index, '/')."'/", $e->getMessage()) === 1;
    }
}
