<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;

/** The seeded branch, looked up by its prefix so seeders do not hard-code ids. */
final class DemoBranch
{
    public const PREFIX = 'DGS';

    public static function get(): Branch
    {
        return Branch::query()->where('code_prefix', self::PREFIX)->firstOrFail();
    }
}
