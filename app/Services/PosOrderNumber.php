<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;

/**
 * The next POS order number, 'P-008821', from branches.next_pos_number (DATABASE_SCHEMA §4.1).
 * The branch row is locked last and held briefly (DATABASE_SCHEMA §8 lock order).
 */
final class PosOrderNumber
{
    public function next(int $branchId): string
    {
        $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);
        $number = $branch->next_pos_number;
        $branch->update(['next_pos_number' => $number + 1]);

        return sprintf('P-%06d', $number);
    }
}
