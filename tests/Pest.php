<?php

declare(strict_types=1);

use App\Support\Invariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Feature tests boot Laravel and run on the hotelv4_test MySQL database
| (phpunit.xml). Unit tests boot Laravel too, so config() and the container
| are available, but they do not touch the database.
|
| After every Feature test the data must satisfy the invariants of TRD §8,
| items 1–5. The constraint tests in Feature/Database are left out: they write
| raw rows on purpose to prove the database itself refuses bad data.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->afterEach(function (): void {
        if (str_contains(static::class, '\\Feature\\Database\\')) {
            return;
        }

        expect(Invariants::check())->toBe([], 'TRD §8 invariants 1–6 and 8 after the test');
    })
    ->in('Feature');

// Reconciliation: real ledger activity, so the TRD §8 invariants must hold here too.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->afterEach(function (): void {
        expect(Invariants::check())->toBe([], 'TRD §8 invariants 1–6 and 8 after the test');
    })
    ->in('Reconciliation');

pest()->extend(TestCase::class)
    ->in('Unit');

// Performance budgets (TRD §7): bulk-generated rows, so no per-test invariant check.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Performance');

// Builds and empties the database itself (see SimultaneousCheckInTest).
pest()->extend(TestCase::class)
    ->in('Concurrency');
