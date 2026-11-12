<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('does not register the component gallery outside the local environment', function (): void {
    expect(app()->environment())->not->toBe('local');
    expect(Route::has('dev.components'))->toBeFalse();

    $this->get('/dev/components')->assertNotFound();
});

it('serves the component gallery in the local environment, inside any of the six layouts', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    Route::middleware('web')->group(base_path('routes/web.php'));
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('dev.components'))->toBeTrue();

    $this->get('/dev/components?layout=kiosk')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dev/Components')
            ->where('layout', 'kiosk')
            ->where('layouts', ['backoffice', 'frontdesk', 'admin', 'owner', 'kiosk', 'phone']));

    $this->get('/dev/components?layout=nonsense')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('layout', 'backoffice'));
});
