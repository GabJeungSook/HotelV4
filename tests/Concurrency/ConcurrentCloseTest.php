<?php

declare(strict_types=1);

use App\Enums\ShiftStatus;
use App\Models\ShiftSession;
use App\Support\Invariants;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\Hotel;

/*
| "Z numbers are sequential per branch with no gaps or repeats under concurrent closes"
| (TRD §10). One branch has one open shift, so the race is two computers pressing "Accept" on
| the same handover at the same moment (step 4, 25). Two real PHP processes race against
| committed rows on the MySQL test database: exactly one closes the shift and takes the next Z
| number; the other is refused; no number is skipped or used twice. Then more shifts close in
| turn and the numbers stay consecutive.
*/

beforeEach(function (): void {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
});

afterEach(function (): void {
    Artisan::call('migrate:fresh');
});

it('gives one Z number to one of two simultaneous handovers, with no gap or repeat', function (): void {
    $first = Hotel::branch()->next_z_number;
    Hotel::openShift();
    Hotel::checkIn('205', 12);
    $closing = Hotel::closeToStep4();

    $startAt = sprintf('%.3f', microtime(true) + 6);
    $env = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'hotelv4_test', 'APP_TIMEZONE' => 'UTC',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4',
        'RACE_RECEIVED' => (string) $closing->counted_cash->centavos(),
    ];

    $racers = [];
    foreach (['mark.sumagaysay', 'rhea.bautista'] as $receiver) {
        $process = new Process([PHP_BINARY, base_path('tests/Support/race-hand-over.php'), $startAt, 'jessa.delacruz', $receiver], base_path(), $env, null, 60);
        $process->start();
        $racers[] = $process;
    }

    $results = [];
    foreach ($racers as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
        $results[] = trim($process->getOutput());
    }

    expect(array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'ok:'))))->toHaveCount(1)
        ->and(array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'refused:'))))->toHaveCount(1)
        ->and(ShiftSession::query()->whereNotNull('z_no')->pluck('z_no')->all())->toBe([$first])
        ->and(ShiftSession::query()->whereIn('status', [ShiftStatus::Open, ShiftStatus::Closing])->count())->toBe(1)
        ->and(Hotel::branch()->next_z_number)->toBe($first + 1);

    // Two more closes in turn: Z numbers stay consecutive and the grand total chains.
    $open = ShiftSession::query()->where('status', ShiftStatus::Open)->sole();
    $by = Hotel::user((string) $open->opener->username);
    Hotel::closeAndHandOver(Hotel::frontdesk()->is($by) ? Hotel::user('mark.sumagaysay') : Hotel::frontdesk(), $by);
    $open = ShiftSession::query()->where('status', ShiftStatus::Open)->sole();
    $by = Hotel::user((string) $open->opener->username);
    Hotel::closeAndHandOver(Hotel::frontdesk()->is($by) ? Hotel::user('mark.sumagaysay') : Hotel::frontdesk(), $by);

    $closed = ShiftSession::query()->whereNotNull('z_no')->orderBy('z_no')->get();
    expect($closed->pluck('z_no')->all())->toBe([$first, $first + 1, $first + 2])
        ->and($closed[1]->grand_total_before->centavos())->toBe($closed[0]->grand_total_after->centavos())
        ->and($closed[2]->grand_total_before->centavos())->toBe($closed[1]->grand_total_after->centavos())
        ->and(Invariants::check())->toBe([]);
});
