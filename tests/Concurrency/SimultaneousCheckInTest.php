<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Models\Payment;
use App\Models\Stay;
use App\Support\Invariants;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\Hotel;

/*
| "Two simultaneous check-ins to one room: exactly one succeeds" (TRD §10). Two real PHP
| processes check guests into room 205 at the same moment against committed rows on the
| MySQL test database, so this file cannot use RefreshDatabase's wrapping transaction. It
| builds the database before the test and empties it again after.
*/

beforeEach(function (): void {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    Hotel::openShift(); // on the real clock, like the two processes
});

afterEach(function (): void {
    Artisan::call('migrate:fresh');
});

it('lets exactly one of two simultaneous check-ins to one room succeed', function (): void {
    $counter = Hotel::branch()->next_stay_number;
    $startAt = sprintf('%.3f', microtime(true) + 6);
    $env = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'hotelv4_test', 'APP_TIMEZONE' => 'UTC',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4',
    ];

    $racers = [];
    foreach ([['jessa.delacruz', 'Rico Manalastas'], ['rhea.bautista', 'Ana Reyes']] as [$user, $guest]) {
        $process = new Process([PHP_BINARY, base_path('tests/Support/race-check-in.php'), $startAt, '205', $user, $guest], base_path(), $env, null, 60);
        $process->start();
        $racers[] = $process;
    }

    $results = [];
    foreach ($racers as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
        $results[] = trim($process->getOutput());
    }

    $wins = array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'ok:')));
    $losses = array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'refused:')));

    expect($wins)->toHaveCount(1)
        ->and($losses)->toHaveCount(1)
        ->and($losses[0])->toBeIn(['refused:RoomNotAvailable', 'refused:RoomTaken']);

    $stayId = (int) substr($wins[0], 3);
    expect(Stay::query()->pluck('id')->all())->toBe([$stayId])
        ->and(Payment::query()->pluck('stay_id')->unique()->all())->toBe([$stayId])
        ->and(Hotel::branch()->next_stay_number)->toBe($counter + 1)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Occupied)
        ->and(Invariants::check())->toBe([]);
});
