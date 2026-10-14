<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;

class CreateSchool extends Command
{
    protected $signature = 'canteen:create-school
        {code : Short code, e.g. PILOT}
        {name : The school\'s name}
        {--currency=USD : USD or KHR: prices and balances are kept in this currency}
        {--timezone=Asia/Phnom_Penh : Used for buying hours, reports and daily limits}
        {--sis-branch= : The school\'s branch id in the SIS, if it has one}';

    protected $description = 'Creates a school.';

    public function handle(): int
    {
        $code = strtoupper((string) $this->argument('code'));
        $currency = strtoupper((string) $this->option('currency'));
        $timezone = (string) $this->option('timezone');
        $branch = $this->option('sis-branch');

        if (! preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
            $this->error('The code must be 2 to 20 letters, digits, dashes or underscores.');

            return self::FAILURE;
        }

        if (! in_array($currency, ['USD', 'KHR'], true)) {
            $this->error('The currency must be USD or KHR.');

            return self::FAILURE;
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->error("'{$timezone}' is not a known timezone (for example Asia/Phnom_Penh).");

            return self::FAILURE;
        }

        if ($branch !== null && (! ctype_digit((string) $branch) || (int) $branch < 1)) {
            $this->error('The SIS branch must be a whole number.');

            return self::FAILURE;
        }

        if (School::where('code', $code)->exists()) {
            $this->error("A school with the code {$code} already exists.");

            return self::FAILURE;
        }

        if ($branch !== null && School::where('sis_branch_id', (int) $branch)->exists()) {
            $this->error("SIS branch {$branch} is already linked to another school.");

            return self::FAILURE;
        }

        School::create([
            'name' => (string) $this->argument('name'),
            'code' => $code,
            'currency' => $currency,
            'timezone' => $timezone,
            'sis_branch_id' => $branch !== null ? (int) $branch : null,
        ]);

        $this->info("School {$code} created. Next: php artisan canteen:create-admin {$code} <email>");

        return self::SUCCESS;
    }
}
