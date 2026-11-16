<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| TRD §8 "Unexpected exception": rolled back, logged with user, branch, route and request id; the
| user sees "Something went wrong. Nothing was saved." with the request id. Also the 404 and 503
| pages and the expired session (APP_FLOW §6 case 55).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-16 01:00:00');
    config(['app.debug' => false]);

    Route::middleware(['web', 'auth'])->group(function (): void {
        Route::get('/_test/boom', fn () => throw new RuntimeException('boom'));
        Route::post('/_test/boom', fn () => throw new RuntimeException('boom'));
    });
    // What the CSRF check throws when the session behind a form has ended.
    Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
});

it('gives every response a readable request id', function (): void {
    $id = $this->get('/')->assertOk()->headers->get(AssignRequestId::HEADER);

    expect($id)->toMatch('/^[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/')
        ->and($this->get('/')->headers->get(AssignRequestId::HEADER))->not->toBe($id);
});

it('sends a failed form back to its page with "nothing was saved" and the request id', function (): void {
    Log::spy();
    $this->actingAs(Hotel::frontdesk());

    $response = $this->from('/board')->post('/_test/boom', [], ['X-Inertia' => 'true']);
    $id = $response->headers->get(AssignRequestId::HEADER);

    $response->assertRedirect('/board')->assertStatus(303);
    expect(session('inertia.flash_data.error'))->toBe(['code' => 'server_error', 'message' => 'Something went wrong. Nothing was saved.', 'request_id' => $id]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'boom'
        && $context['request_id'] === $id
        && $context['user_id'] === Hotel::frontdesk()->id
        && $context['route'] === '_test/boom')->once();
});

it('shows the error page with the request id when a page cannot load', function (): void {
    $this->actingAs(Hotel::frontdesk());

    $response = $this->get('/_test/boom')->assertStatus(500);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Errors/Error')
        ->where('status', 500)
        ->where('requestId', $response->headers->get(AssignRequestId::HEADER))
        ->where('home', '/home'));
});

it('answers a JSON request with the message and the request id', function (): void {
    $this->actingAs(Hotel::frontdesk());

    $response = $this->postJson('/_test/boom')->assertStatus(500);
    expect($response->json())->toBe(['message' => 'Something went wrong. Nothing was saved.', 'request_id' => $response->headers->get(AssignRequestId::HEADER)]);
});

it('keeps the debug page in development', function (): void {
    config(['app.debug' => true]);
    $this->actingAs(Hotel::frontdesk());

    $this->get('/_test/boom')->assertStatus(500)->assertDontSee('"component":"Errors\/Error"', false);
});

it('shows the not-found page to visitors and staff', function (): void {
    $this->get('/no-such-page')->assertNotFound()->assertInertia(fn (Assert $page) => $page
        ->component('Errors/Error')->where('status', 404)->where('requestId', null)->where('home', '/'));

    $this->actingAs(Hotel::frontdesk())->get('/stays/999999')->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('Errors/Error')->where('home', '/home'));
});

it('shows the update page during maintenance', function (): void {
    $this->app->maintenanceMode()->activate([]);

    $this->get('/')->assertStatus(503)->assertInertia(fn (Assert $page) => $page->component('Errors/Error')->where('status', 503));

    $this->app->maintenanceMode()->deactivate();
});

it('sends a form posted after the session ended back to the sign-in', function (): void {
    $this->post('/_test/expired', [], ['X-Inertia' => 'true'])->assertRedirect('/login');

    expect(session('inertia.flash_data.toast.message'))->toBe('Your session ended, so nothing was saved. Sign in again.');
});
