<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Hotel;

/*
| A temporary password is replaced at first sign-in, before the PIN (TRD §4.9, APP_FLOW §8.1
| /password/change; Gab, 16 Nov).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('sends a user with a temporary password to change it before anything else', function (): void {
    $admin = Hotel::user('ramil.ortega');
    $admin->forceFill(['password' => 'Temp-1234x', 'must_change_password' => true, 'pin_hash' => null])->save();

    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('password.edit'));
    $this->actingAs($admin)->get('/pin/setup')->assertRedirect(route('password.edit'));
    $this->actingAs($admin)->get('/password/change')->assertOk();

    $this->actingAs($admin)->put('/password/change', ['password' => 'my-own-pass', 'password_confirmation' => 'my-own-pass'])->assertRedirect(route('home'));

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse()
        ->and(Hash::check('my-own-pass', $admin->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.password_changed')->where('subject_id', $admin->id)->exists())->toBeTrue();

    // Then the PIN, as before.
    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('pin.edit'));
});

it('refuses a short, mismatched or unchanged password', function (string $password, string $again): void {
    $admin = Hotel::user('ramil.ortega');
    $admin->forceFill(['password' => 'Temp-1234x', 'must_change_password' => true])->save();

    $this->actingAs($admin)->put('/password/change', ['password' => $password, 'password_confirmation' => $again]);

    expect($admin->fresh()->must_change_password)->toBeTrue();
})->with([
    'too short' => ['short', 'short'],
    'not the same' => ['long-enough-1', 'long-enough-2'],
    'the temporary one' => ['Temp-1234x', 'Temp-1234x'],
]);

it('lets a user without a temporary password skip the page', function (): void {
    $this->actingAs(Hotel::user('ramil.ortega'))->get('/password/change')->assertRedirect(route('home'));
});
