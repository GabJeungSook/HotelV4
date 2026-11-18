<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Welcome page (59): the public HOMI product landing — a scrollable page about the system, open
| to everyone, signed in or not, with no hotel-specific data.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('renders the HOMI landing for a visitor', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

it('is open to signed-in staff too', function (): void {
    $this->actingAs(Hotel::frontdesk())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

it('serves the first visit through the app Blade root with Ziggy routes', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('<script data-page="app" type="application/json">', false)
        ->assertSee('Ziggy.routes', false)
        ->assertSee('"welcome":{"uri":"\/"', false);
});
