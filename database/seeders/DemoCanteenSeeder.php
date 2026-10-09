<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Services\CardService;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;

class DemoCanteenSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::firstOrCreate(
            ['code' => 'PILOT'],
            ['name' => 'Pilot School', 'currency' => 'USD']
        );

        $menu = [
            'Drinks' => [['Water', 50], ['Orange juice', 150], ['Cola', 100]],
            'Food' => [['Fried rice', 250], ['Chicken sandwich', 200], ['Noodle soup', 225]],
            'Snacks' => [['Biscuits', 75], ['Banana', 50]],
        ];

        foreach ($menu as $categoryName => $products) {
            $category = Category::firstOrCreate(['school_id' => $school->id, 'name' => $categoryName]);

            foreach ($products as [$name, $price]) {
                Product::firstOrCreate(
                    ['school_id' => $school->id, 'name' => $name],
                    ['category_id' => $category->id, 'price' => $price]
                );
            }
        }

        // A demo student with a card and $20 so the till can be tried right away
        $student = Student::firstOrCreate(
            ['school_id' => $school->id, 'student_code' => 'D001'],
            ['name' => 'Dara', 'grade' => 4]
        );

        $cards = app(CardService::class);
        if (! $cards->currentCard($student)) {
            $cards->bind($student, '0A0B0C0D');
        }

        app(LedgerService::class)->topUp($student->account, 2000, 'demo-topup-d001');
    }
}
