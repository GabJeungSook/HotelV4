<?php

declare(strict_types=1);

use App\Models\Concerns\AppendOnly;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/** @return list<Model> */
function modelInstances(): array
{
    $models = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/app/Models')->depth(0)->name('*.php') as $file) {
        $class = 'App\\Models\\'.$file->getBasename('.php');
        $models[] = new $class;
    }

    return $models;
}

it('casts no model column to float, double or decimal (TRD §3.8)', function (): void {
    foreach (modelInstances() as $model) {
        foreach ($model->getCasts() as $column => $cast) {
            expect(preg_match('/^(float|double|real|decimal)/i', $cast))->toBe(0, $model::class.".{$column}");
        }
    }
});

it('casts every *_amount and *_cash column to Money', function (): void {
    foreach (modelInstances() as $model) {
        foreach ($model->getFillable() as $column) {
            if (preg_match('/(_amount|_cash)$/', $column) === 1) {
                expect($model->getCasts()[$column] ?? null)->toBe(Money::class, $model::class.".{$column}");
            }
        }
    }
});

it('has no $guarded = [] model (DATABASE_SCHEMA §6)', function (): void {
    foreach (modelInstances() as $model) {
        expect($model->getFillable())->not->toBe([], $model::class);
    }
});

it('keeps append-only models without an updated_at column', function (): void {
    foreach (modelInstances() as $model) {
        if (in_array(AppendOnly::class, class_uses_recursive($model), true)) {
            expect($model->getUpdatedAtColumn())->toBeNull($model::class);
        }
    }
});
