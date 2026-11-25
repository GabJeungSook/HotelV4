<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/** @return list<class-string<Model>> */
function allModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/app/Models')->depth(0)->name('*.php') as $file) {
        $class = 'App\\Models\\'.$file->getBasename('.php');

        if (is_subclass_of($class, Model::class)) {
            $models[] = $class;
        }
    }

    sort($models);

    return $models;
}

it('has a model for every table of the schema', function (): void {
    $laravel = ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'password_reset_tokens', 'personal_access_tokens', 'sessions'];
    $pivots = ['discount_rate', 'floor_user'];

    $tables = collect(DB::select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()'))
        ->pluck('name')->diff([...$laravel, ...$pivots])->sort()->values()->all();

    $modelTables = collect(allModels())->map(fn (string $class): string => (new $class)->getTable())->sort()->values()->all();

    expect($modelTables)->toBe($tables)->and($tables)->toHaveCount(46);
});

it('scopes every model whose table has branch_id NOT NULL, and no other', function (): void {
    $operational = collect(DB::select(
        "SELECT table_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'branch_id' AND is_nullable = 'NO'"
    ))->pluck('name')->all();

    foreach (allModels() as $class) {
        $table = (new $class)->getTable();
        $usesTrait = in_array(BelongsToBranch::class, class_uses_recursive($class), true);

        expect($usesTrait)->toBe(in_array($table, $operational, true), "{$class} ({$table})");
    }
});

it('creates a valid row from every factory', function (string $class): void {
    /** @var Model $row */
    $row = $class::factory()->create();

    expect($class::query()->withoutGlobalScopes()->count())->toBeGreaterThanOrEqual(1)
        ->and($row->exists)->toBeTrue();
})->with(fn (): array => array_combine(
    array_map(fn (string $class): string => Str::afterLast($class, '\\'), allModels()),
    array_map(fn (string $class): array => [$class], allModels()),
));

it('lists every column but generated ones and auto ids as fillable', function (string $class): void {
    /** @var Model $model */
    $model = new $class;
    $columns = collect(DB::select(
        'SELECT column_name AS name, extra AS extra FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
        [$model->getTable()],
    ))
        ->reject(fn (object $c): bool => str_contains($c->extra, 'GENERATED') || str_contains($c->extra, 'auto_increment'))
        ->pluck('name')
        ->diff(['created_at', 'updated_at', 'remember_token'])
        ->values()->all();

    $missing = array_values(array_diff($columns, $model->getFillable()));

    expect($missing)->toBe([]);
})->with(fn (): array => array_combine(
    array_map(fn (string $class): string => Str::afterLast($class, '\\'), allModels()),
    array_map(fn (string $class): array => [$class], allModels()),
));
