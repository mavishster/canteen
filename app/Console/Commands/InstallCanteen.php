<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AsksForPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class InstallCanteen extends Command
{
    use AsksForPassword;

    protected $signature = 'canteen:install
        {--name= : Name of the first super-admin}
        {--email= : Email of the first super-admin}';

    protected $description = 'Creates the roles and the first super-admin on a new server. Safe to run again.';

    public function handle(): int
    {
        foreach (['super-admin', 'admin', 'manager', 'cashier', 'parent'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->info('Roles are in place.');

        if (User::role('super-admin')->exists()) {
            $this->info('A super-admin already exists, so nothing else was created.');

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: $this->ask('Super-admin name');
        $email = $this->option('email') ?: $this->ask('Super-admin email');

        $check = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email']]
        );

        if ($check->fails()) {
            foreach ($check->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $password = $this->askForPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user = User::forceCreate([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'school_id' => null,   // a super-admin belongs to no school
        ]);
        $user->assignRole('super-admin');

        $this->info("Super-admin {$email} created. Next: php artisan canteen:create-school, then canteen:create-admin.");

        return self::SUCCESS;
    }
}
