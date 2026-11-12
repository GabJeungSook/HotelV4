<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Floor;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 11 branch users (made up): 1 admin, 1 supervisor, 4 front desks, 1 back office,
 * 3 housekeepers, 1 kiosk device. Sample password "password"; sample PIN "1234"
 * for admin, supervisor and front desk, except Liza Gonzaga, who has not set one (47).
 */
class UserSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: Role, 3: bool, 4: bool, 5: list<int>}> name, username, role, has PIN, active, floors */
    private const USERS = [
        ['Ramil Ortega', 'ramil.ortega', Role::Admin, true, true, []],
        ['Carol Villanueva', 'carol.villanueva', Role::Supervisor, true, true, []],
        ['Jessa Mae Dela Cruz', 'jessa.delacruz', Role::Frontdesk, true, true, []],
        ['Rhea Bautista', 'rhea.bautista', Role::Frontdesk, true, true, []],
        ['Mark Anthony Sumagaysay', 'mark.sumagaysay', Role::Frontdesk, true, true, []],
        ['Liza Gonzaga', 'liza.gonzaga', Role::Frontdesk, false, true, []],
        ['Grace Tolentino', 'grace.tolentino', Role::Backoffice, false, true, []],
        ['Dodong Lumayag', 'dodong.lumayag', Role::Housekeeper, false, true, [1, 2]],
        ['Joy Pacaña', 'joy.pacana', Role::Housekeeper, false, true, [2, 3]],
        ['Nonoy Cabrera', 'nonoy.cabrera', Role::Housekeeper, false, false, [3]],
        ['Lobby kiosk 1', 'kiosk-gensan-1', Role::Kiosk, false, true, []],
    ];

    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        foreach (self::USERS as [$name, $username, $role, $hasPin, $active, $floors]) {
            $user = User::query()->create([
                'branch_id' => $branchId,
                'name' => $name,
                'username' => $username,
                'password' => 'password',
                'pin_hash' => $hasPin ? '1234' : null,
                'role' => $role,
                'is_active' => $active,
            ]);

            $user->floors()->attach(Floor::query()->where('branch_id', $branchId)->whereIn('number', $floors)->pluck('id'));
        }
    }
}
