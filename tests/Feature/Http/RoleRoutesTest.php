<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use Tests\Support\Hotel;

/*
| TRD §10 "Branches and permissions": each role is refused on every other role's routes
| (TRD §4.1). One page of each group, opened by every role that may not open it: screen 51.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

const ACCOUNTS = [
    'owner' => 'ramon.alvarez',
    'admin' => 'ramil.ortega',
    'supervisor' => 'carol.villanueva',
    'frontdesk' => 'jessa.delacruz',
    'backoffice' => 'grace.tolentino',
    'housekeeper' => 'dodong.lumayag',
    'kiosk' => 'kiosk-gensan-1',
];

/** route => the roles that may open it */
const ALLOWED = [
    '/today' => ['frontdesk', 'supervisor'],
    '/board' => ['frontdesk', 'supervisor'],
    '/shift' => ['frontdesk', 'supervisor'],
    '/housekeeping' => ['housekeeper'],
    '/kiosk' => ['kiosk'],
    '/reports' => ['backoffice', 'supervisor', 'admin', 'owner'],
    '/reports/daily' => ['backoffice', 'supervisor', 'admin', 'owner'],
    '/dashboard' => ['admin', 'backoffice', 'supervisor', 'owner'],
    '/stats' => ['admin', 'backoffice', 'supervisor', 'owner'],
    '/admin/exceptions' => ['admin', 'supervisor', 'owner'],
    '/admin/rooms' => ['admin', 'owner'],
    '/admin/rates' => ['admin', 'owner'],
    '/admin/settings' => ['admin', 'owner'],
    '/admin/users' => ['admin', 'owner'],
    '/admin/products' => ['admin', 'owner'],
    '/owner' => ['owner'],
    '/owner/branches' => ['owner'],
    '/owner/branches/create' => ['owner'],
    '/owner/summary' => ['owner'],
    '/owner/accounts' => ['owner'],
];

it('refuses every role on the routes of the other roles', function (string $role): void {
    $user = Hotel::user(ACCOUNTS[$role]);
    $user->forceFill(['pin_hash' => Hotel::PIN, 'must_change_password' => false])->save();
    $checked = 0;

    foreach (ALLOWED as $url => $roles) {
        if (in_array($role, $roles, true) || ! Route::getRoutes()->match(Request::create($url))->getName()) {
            continue;
        }

        $this->actingAs($user)->get($url)->assertForbidden();
        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(5);
})->with(array_keys(ACCOUNTS));

it('lets each role open its own pages', function (string $role, string $url): void {
    $user = Hotel::user(ACCOUNTS[$role]);
    $user->forceFill(['pin_hash' => Hotel::PIN])->save();

    $status = $this->actingAs($user)->get($url)->getStatusCode();

    // A front desk page without an open shift redirects to /shift/open; the owner's branch pages
    // without an opened branch redirect to the branches list. Neither is a refusal.
    expect($status)->not->toBe(403);
})->with(function (): array {
    $cases = [];
    foreach (ALLOWED as $url => $roles) {
        foreach ($roles as $role) {
            $cases["{$role} {$url}"] = [$role, $url];
        }
    }

    return $cases;
});
