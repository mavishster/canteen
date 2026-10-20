<?php

namespace App\Console\Commands\Concerns;

use App\Models\ConsentDocument;
use App\Models\School;
use Illuminate\Support\Facades\Schema;

/** Added to `canteen:check`: does every school ask its parents for consent? */
trait ChecksConsent
{
    private function checkConsent(bool $production): void
    {
        if (! Schema::hasTable('schools') || ! Schema::hasTable('consent_documents')) {
            return;
        }

        $schools = School::all();

        if ($schools->isEmpty()) {
            return;
        }

        $notRequired = $schools->filter(fn (School $s) => ! data_get($s->settings, 'consent.required', false))->count();

        $noText = $schools
            ->filter(fn (School $s) => data_get($s->settings, 'consent.required', false)
                && ! ConsentDocument::withoutGlobalScopes()->where('school_id', $s->id)->exists())
            ->count();

        if ($noText > 0) {
            $this->add('WARN', 'Parental consent', "{$noText} school(s) require consent but have not published a text yet (Admin, Consent).");
        } elseif ($notRequired > 0) {
            $this->add('WARN', 'Parental consent', "{$notRequired} school(s) do not ask parents for consent yet. Publish a text and make it compulsory (Admin, Consent).");
        } else {
            $this->add('OK', 'Parental consent', 'Every school asks its parents for consent.');
        }
    }
}
