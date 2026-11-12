<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A front desk user of a new branch by default; password "password", PIN "1234".
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    protected static ?string $pin = null;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'pin_hash' => static::$pin ??= Hash::make('1234'),
            'role' => Role::Frontdesk,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function owner(): static
    {
        return $this->state(['role' => Role::Owner, 'branch_id' => null, 'pin_hash' => null]);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function supervisor(): static
    {
        return $this->role(Role::Supervisor);
    }

    public function frontdesk(): static
    {
        return $this->role(Role::Frontdesk);
    }

    public function backoffice(): static
    {
        return $this->state(['role' => Role::Backoffice, 'pin_hash' => null]);
    }

    public function housekeeper(): static
    {
        return $this->state(['role' => Role::Housekeeper, 'pin_hash' => null]);
    }

    public function kiosk(): static
    {
        return $this->state(['role' => Role::Kiosk, 'pin_hash' => null]);
    }

    public function withoutPin(): static
    {
        return $this->state(['pin_hash' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
