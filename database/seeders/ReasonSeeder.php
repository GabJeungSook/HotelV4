<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReasonKind;
use App\Models\Reason;
use Illuminate\Database\Seeder;

/** Four reasons per kind (DATABASE_SCHEMA §4.16). "Other" always needs a note. */
class ReasonSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const REASONS = [
        'void_line' => ['Typed wrong amount', 'Charged to the wrong room', 'Guest returned the item'],
        'cancel_stay' => ['Guest changed mind', 'Wrong room checked in', 'Duplicate check-in'],
        'variance' => ['Wrong change given', 'Counting error at open', 'Unrecorded expense'],
        'void_cash_movement' => ['Typed wrong amount', 'Wrong category', 'Entered twice'],
        'wastage' => ['Expired', 'Damaged', 'Spilled'],
        'cancel_reservation' => ['Guest cancelled', 'Booked by mistake', 'Hotel cannot honour'],
    ];

    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        foreach (self::REASONS as $kind => $names) {
            foreach ([...$names, 'Other'] as $name) {
                Reason::query()->create([
                    'branch_id' => $branchId,
                    'kind' => ReasonKind::from($kind),
                    'name' => $name,
                    'needs_note' => $name === 'Other' || $kind === 'variance',
                    'needs_manager' => $name === 'Other',
                ]);
            }
        }
    }
}
