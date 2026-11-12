<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/** One owner account (made up). Sample password: "password". */
class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->create([
            'branch_id' => null,
            'name' => 'Ramon Alvarez',
            'username' => 'ramon.alvarez',
            'password' => 'password',
            'role' => Role::Owner,
        ]);
    }
}
