<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Security\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'km:create-admin {email} {--first=Super} {--last=Admin} {--phone=} {--role=super_admin}';

    protected $description = 'Create (or promote) an admin account. Asks for the password.';

    public function handle(): int
    {
        $role = (string) $this->option('role');
        if (! array_key_exists($role, config('permissions.roles'))) {
            $this->error("Unknown role [$role].");

            return self::FAILURE;
        }
        $min = PasswordPolicy::minLength(true);
        $password = (string) $this->secret("Password (min {$min} characters, not a common one)");
        $v = Validator::make(['email' => $this->argument('email'), 'password' => $password], [
            'email' => ['required', 'email'], 'password' => PasswordPolicy::rules(true, ['email' => (string) $this->argument('email')]),
        ]);
        if ($v->fails()) {
            $this->error($v->errors()->first());

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $this->argument('email')]);
        $user->fill([
            'first_name' => $user->first_name ?: (string) $this->option('first'),
            'last_name' => $user->last_name ?: (string) $this->option('last'),
            'phone' => $user->phone ?: ($this->option('phone') ?: null),
            'password' => $password,
        ]);
        $user->forceFill(['role' => $role, 'status' => 'active'])->save();

        $this->info("Admin ready: {$user->email} ({$role}). Sign in at /admin and set up 2FA.");

        return self::SUCCESS;
    }
}
