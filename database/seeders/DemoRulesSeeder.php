<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;

class DemoRulesSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::where('code', 'PILOT')->firstOrFail();

        $settings = $school->settings ?? [];

        // Default weekly caps from the requirements document, in cents (USD school)
        $settings['limits']['weekly'] = [[1, 3, 3000], [4, 6, 4000], [7, 9, 5000], [10, 12, 6000]];

        $school->update(['settings' => $settings]);
    }
}
