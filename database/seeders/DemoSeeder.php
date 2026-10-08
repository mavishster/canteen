<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['super-admin', 'admin', 'manager', 'cashier'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $school = School::firstOrCreate(
            ['code' => 'PILOT'],
            ['name' => 'Pilot School', 'currency' => 'USD']
        );

        $users = [
            ['Super Admin', 'super@canteen.test', null, 'super-admin'],
            ['School Admin', 'admin@pilot.test', $school->id, 'admin'],
            ['Manager', 'manager@pilot.test', $school->id, 'manager'],
            ['Cashier', 'cashier@pilot.test', $school->id, 'cashier'],
        ];

        foreach ($users as [$name, $email, $schoolId, $role]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'school_id' => $schoolId]
            );
            $user->syncRoles([$role]);
        }
    }
}
