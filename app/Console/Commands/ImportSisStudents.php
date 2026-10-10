<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Sis\SisClients;
use App\Services\Sis\SisStudentImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportSisStudents extends Command
{
    protected $signature = 'sis:import-students
        {school? : School code (every school linked to a SIS branch when left out)}
        {--dry-run : Show what would change without saving anything}';

    protected $description = 'Create and update students and their cards from the SIS. Safe to run again.';

    public function handle(SisStudentImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $code = $this->argument('school');

        $schools = School::query()
            ->whereNotNull('sis_branch_id')
            ->when($code, fn ($q) => $q->where('code', $code))
            ->get();

        if ($schools->isEmpty()) {
            if ($code) {
                $this->error("School '{$code}' was not found or has no sis_branch_id. Set it with: School::where('code','{$code}')->update(['sis_branch_id' => <SIS branch id>])");

                return self::FAILURE;
            }

            $this->info('No school is linked to a SIS branch yet. Nothing to do.');

            return self::SUCCESS;
        }

        $status = self::SUCCESS;

        foreach ($schools as $school) {
            $this->newLine();
            $this->info("{$school->name} ({$school->code}), SIS branch {$school->sis_branch_id}" . ($dryRun ? '  [DRY RUN: nothing is saved]' : ''));

            try {
                $r = $importer->import($school, SisClients::default(), $dryRun);
            } catch (Throwable $e) {
                $this->error('Import failed: ' . $e->getMessage());
                $status = self::FAILURE;

                continue;
            }

            $this->line("  Students: {$r->created} created, {$r->updated} updated, {$r->unchanged} unchanged");
            $this->line("  Cards:    {$r->cardsBound} bound, {$r->cardsReplaced} replaced, {$r->cardsUnchanged} unchanged");

            foreach (array_slice($r->issues, 0, 40) as $i) {
                $this->warn("  [{$i['type']}] {$i['student_code']} {$i['name']}: {$i['detail']}");
            }

            if (count($r->issues) > 40) {
                $this->line('  ... and ' . (count($r->issues) - 40) . ' more issues');
            }

            foreach (array_slice($r->missing, 0, 40) as $m) {
                $this->warn("  [not_in_sis] {$m['student_code']} {$m['name']}: active in the canteen but not listed by the SIS. Check for a balance before deactivating.");
            }
        }

        return $status;
    }
}
