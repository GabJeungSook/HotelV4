<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('renders the blank Inertia page at /', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('app.name', 'Homi'));
});

it('serves the first visit through the app Blade root with Ziggy routes', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('<title inertia>Homi</title>', false)
        ->assertSee('data-page=', false)
        ->assertSee('Ziggy.routes', false)
        ->assertSee('"welcome":{"uri":"\/"', false);
});
