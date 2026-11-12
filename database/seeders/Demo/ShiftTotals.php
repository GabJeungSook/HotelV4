<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\ShiftForwardedStay;
use App\Models\ShiftSession;
use App\Services\ReportService;
use Illuminate\Support\Collection;

/**
 * The frozen `totals` JSON (DATABASE_SCHEMA §4.7) of the demo shifts. Since Phase 5 this is
 * ReportService::computeShiftTotals(), the builder the real close uses, so the seeded papers
 * and a shift closed in the app are built the same way
 * (tests/Feature/Database/DemoShiftSeederTest.php pins the figures to the mockup).
 */
final class ShiftTotals
{
    /**
     * @param  Collection<int, ShiftForwardedStay>  $forwardedIn  rows the previous shift forwarded out
     * @param  Collection<int, ShiftForwardedStay>  $forwardedOut  rows this shift forwards out
     * @return array<string, mixed>
     */
    public static function build(ShiftSession $shift, Collection $forwardedIn, Collection $forwardedOut): array
    {
        return app(ReportService::class)->computeShiftTotals($shift, $forwardedIn, $forwardedOut);
    }
}
