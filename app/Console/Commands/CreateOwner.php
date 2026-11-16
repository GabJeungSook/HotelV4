<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Owner\CreateOwnerAccount;
use App\Exceptions\DomainException;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The first owner account (TRD §4.9 "First owner account"), run on the server:
 * `php artisan homi:create-owner`. Asks for the full name, the username and the password
 * (typed twice, hidden, at least 8 characters, TRD §9); the password never goes on the
 * command line. Later owners are added from the owner menu.
 */
final class CreateOwner extends Command
{
    protected $signature = 'homi:create-owner {--name= : Full name} {--username= : Username for signing in}';

    protected $description = 'Create an owner account (the first one, on a new installation)';

    public function handle(CreateOwnerAccount $create): int
    {
        $name = Str::squish((string) ($this->option('name') ?? $this->ask('Full name')));
        $username = Str::lower(trim((string) ($this->option('username') ?? $this->ask('Username'))));
        $password = (string) $this->secret('Password (at least 8 characters)');
        $again = (string) $this->secret('Type the password again');

        $validator = Validator::make(
            ['name' => $name, 'username' => $username, 'password' => $password, 'password_confirmation' => $again],
            [
                'name' => ['required', 'string', 'min:3', 'max:80'],
                'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]*$/', 'unique:'.User::class.',username'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $result = $create->handle(null, $name, $username, $password);
        } catch (DomainException $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        }

        $this->info("Owner account {$result['user']->username} created. Sign in at ".route('login').'.');

        return self::SUCCESS;
    }
}
