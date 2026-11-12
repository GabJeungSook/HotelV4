<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Support\Clock;

/**
 * The next stay code of a branch ("DGS-26-004127", DATABASE_SCHEMA §8.1 step 4): the branch row
 * is locked last in the lock order and held briefly. Every action that creates a stay uses it:
 * a walk-in, a kiosk hold and a reservation.
 */
final class StayNumber
{
    public function __construct(private readonly Clock $clock) {}

    public function next(Branch $branch): string
    {
        $locked = Branch::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($branch->id);
        $number = $locked->next_stay_number;
        $locked->update(['next_stay_number' => $number + 1]);

        return sprintf('%s-%s-%06d', $locked->code_prefix, $this->clock->local()->format('y'), $number);
    }
}
