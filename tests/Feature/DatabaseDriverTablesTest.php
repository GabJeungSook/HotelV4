<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('migrates the queue, cache and session tables on MySQL 8', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and((string) DB::scalar('select version()'))->toStartWith('8.');

    foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table {$table}");
    }
});

it('uses utf8mb4_unicode_ci', function (): void {
    expect(DB::scalar('select @@collation_database'))->toBe('utf8mb4_unicode_ci');
});
