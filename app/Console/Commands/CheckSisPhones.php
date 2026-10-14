<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Sis\SisDatabaseClient;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class CheckSisPhones extends Command
{
    protected $signature = 'sis:check-phones {school? : School code (every school linked to a SIS branch when left out)}';

    protected $description = 'Counts how many parents have a usable phone number in the SIS. Never prints a number.';

    public function handle(SisDatabaseClient $sis): int
    {
        $schools = School::query()
            ->whereNotNull('sis_branch_id')
            ->when($this->argument('school'), fn ($q, $code) => $q->where('code', $code))
            ->get();

        if ($schools->isEmpty()) {
            $this->error('No school is linked to a SIS branch (see sis_branch_id).');

            return self::FAILURE;
        }

        foreach ($schools as $school) {
            try {
                $numbers = $sis->connection()->table('users')
                    ->where('user_type', 'parent')
                    ->where('user_status', 1)
                    ->where('branch_id', $school->sis_branch_id)
                    ->pluck('mobile_phone');
            } catch (QueryException $e) {
                $this->error('Could not read users.mobile_phone from the SIS: ' . $e->getMessage());

                return self::FAILURE;
            }

            $empty = $valid = $invalid = 0;
            $shapes = [];

            foreach ($numbers as $raw) {
                $raw = trim((string) $raw);

                if ($raw === '') {
                    $empty++;
                } elseif (PhoneNumber::normalize($raw) !== null) {
                    $valid++;
                } else {
                    $invalid++;
                    // Show the SHAPE only: digits become 9, letters become a. Never the number.
                    $shape = preg_replace('/[A-Za-z]/', 'a', preg_replace('/\d/', '9', $raw));
                    $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
                }
            }

            arsort($shapes);

            $this->newLine();
            $this->info("{$school->name} ({$school->code}), SIS branch {$school->sis_branch_id}");
            $this->line('  parents: ' . $numbers->count());
            $this->line("  valid: {$valid}");
            $this->line("  empty: {$empty}");
            $this->line("  invalid: {$invalid}");

            foreach (array_slice($shapes, 0, 10, true) as $shape => $count) {
                $this->warn("  shape {$shape}  x{$count}");
            }
        }

        return self::SUCCESS;
    }
}
