<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AsksForPassword;
use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class CreateAdmin extends Command
{
    use AsksForPassword;

    protected $signature = 'canteen:create-admin
        {school : School code}
        {email : The admin\'s email (used to sign in)}
        {--name= : The admin\'s name}';

    protected $description = 'Creates the admin of a school, who can then create managers, cashiers and everything else in the web app.';

    public function handle(): int
    {
        $school = School::where('code', strtoupper((string) $this->argument('school')))->first();

        if (! $school) {
            $this->error('No school has that code. Create it first with canteen:create-school.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $name = $this->option('name') ?: $this->ask('Admin name');

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

        Role::findOrCreate('admin', 'web');

        $user = User::forceCreate([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'school_id' => $school->id,
        ]);
        $user->assignRole('admin');

        $this->info("Admin {$email} created for {$school->name}.");

        return self::SUCCESS;
    }
}
