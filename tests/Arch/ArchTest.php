<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Architecture rules (TRD §3, §12; ROADMAP Phase 0)
|--------------------------------------------------------------------------
*/

arch('app/ never calls now() or today(); time comes from App\Support\Clock')
    ->expect('App')
    ->not->toUse(['now', 'today']);

arch('controllers do not use the DB facade')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'DB', 'Illuminate\Database\DatabaseManager']);

arch('no debugging helpers are left in app/')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('every PHP file in app/ declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('support value objects are final')
    ->expect('App\Support')
    ->toBeFinal();

it('has no Carbon::now(), Date::now() or similar static clock calls in app/ outside Clock', function (): void {
    $app = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
    $offenders = [];

    $files = Finder::create()->files()->in($app)->name('*.php');

    foreach ($files as $file) {
        if ($file->getRelativePathname() === 'Support'.DIRECTORY_SEPARATOR.'Clock.php') {
            continue;
        }

        $code = $file->getContents();

        if (preg_match('/\b(Carbon|CarbonImmutable|Date|DateTime|DateTimeImmutable)::(now|today|yesterday|tomorrow)\s*\(|new\s+\\\\?(Carbon|CarbonImmutable|DateTime|DateTimeImmutable)\s*\(\s*\)|\btime\s*\(\s*\)|\bdate\s*\(/', $code) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
