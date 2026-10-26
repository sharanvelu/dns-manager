<?php

declare(strict_types = 1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Gravatar;
use App\Support\AuthMethods;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateUser extends Command
{
    protected $signature = 'dns:create-user
                            {--name= : Display name}
                            {--email= : Email address}
                            {--password= : Password (prompted when omitted — prefer the prompt, flags land in shell history)}
                            {--super-admin : Grant the Super Admin role}';

    protected $description = 'Create a user with a password — use it to bootstrap the first Super Admin when SSO is disabled';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $password = $this->option('password') ?: $this->secret('Password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'password' => ['required', AuthMethods::passwordRule()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'avatar_url' => Gravatar::url($email),
            'roles' => $this->option('super-admin') ? [Role::SuperAdmin->value] : [],
        ]);

        $this->info("User {$email} created" . ($this->option('super-admin') ? ' as Super Admin.' : '.'));

        return self::SUCCESS;
    }
}
