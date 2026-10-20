<?php

namespace App\Services;

use App\Exceptions\ConsentException;
use App\Models\ConsentDocument;
use App\Models\ParentConsent;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Clear parental consent for processing children's data. The school publishes versioned texts;
 * when a school makes consent compulsory, a parent can use the app only after accepting its newest text.
 */
class ConsentService
{
    public function required(School $school): bool
    {
        return (bool) data_get($school->settings, 'consent.required', false);
    }

    public function current(School $school): ?ConsentDocument
    {
        return ConsentDocument::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->orderByDesc('version')
            ->first();
    }

    /** True when this parent must still accept the school's newest text before using the app. */
    public function mustAccept(User $parent): bool
    {
        $school = $parent->school_id ? School::find($parent->school_id) : null;

        if (! $school || ! $this->required($school)) {
            return false;
        }

        $document = $this->current($school);

        // Consent switched on but no text published: nobody is locked out by a school's unfinished set-up
        if (! $document) {
            return false;
        }

        return $this->acceptance($parent, $document) === null;
    }

    public function acceptance(User $parent, ConsentDocument $document): ?ParentConsent
    {
        return ParentConsent::withoutGlobalScopes()
            ->where('user_id', $parent->id)
            ->where('consent_document_id', $document->id)
            ->first();
    }

    /** Publishes the next version. Older versions and everyone's acceptance of them stay on record. */
    public function publish(School $school, string $text, ?User $by = null): ConsentDocument
    {
        return DB::transaction(function () use ($school, $text, $by) {
            School::whereKey($school->id)->lockForUpdate()->first();   // one publisher at a time

            $next = (int) ConsentDocument::withoutGlobalScopes()->where('school_id', $school->id)->max('version') + 1;

            $document = ConsentDocument::create([
                'school_id' => $school->id,
                'version' => $next,
                'text' => trim($text),
                'created_by' => $by?->id,
            ]);

            AuditRecorder::log(
                'consent.published', 'consent', "Version {$next}",
                "Consent text version {$next} published",
                [], ['version' => $next], $school->id, $document
            );

            return $document;
        });
    }

    /** Records that this parent accepted this version. Safe to call twice. */
    public function accept(User $parent, int $version, ?string $ip = null): ParentConsent
    {
        $school = School::findOrFail($parent->school_id);
        $document = $this->current($school);

        if (! $document) {
            throw ConsentException::noDocument();
        }

        if ($document->version !== $version) {
            throw ConsentException::versionChanged($document->version);
        }

        if ($existing = $this->acceptance($parent, $document)) {
            return $existing;
        }

        try {
            $consent = ParentConsent::create([
                'school_id' => $school->id,
                'user_id' => $parent->id,
                'consent_document_id' => $document->id,
                'ip' => $ip,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->acceptance($parent, $document);   // two taps at once
        }

        AuditRecorder::log(
            'consent.accepted', 'consent', $parent->name,
            "“{$parent->name}” accepted the terms (version {$document->version})",
            [], ['version' => $document->version], $school->id, $consent
        );

        return $consent;
    }

    /** A plain starting point for a school to review and rewrite. It is not legal advice. */
    public static function defaultText(School $school): string
    {
        return <<<TEXT
{$school->name} cashless canteen: how we use your child's information

By accepting, you agree that {$school->name} may keep and use the following information about your child for the school canteen:
- their name, student number, grade and the number of their school ID card;
- the money on their canteen account, every top-up, purchase and refund, and what was bought;
- the limits and restrictions you set (spending limits, products your child may not buy, a blocked card).

Why: so your child can pay with their school ID card, so you can top up and control their spending, and so the school can keep the canteen accounts correct.

Who can see it: you, and the school staff who run the canteen and its accounts, each only as far as their work needs. It is not sold or shared for advertising. Card payments are handled by the payment provider on its own secure page; the school never stores card numbers.

How long: for as long as your child uses the canteen and for the period the school must keep financial records.

Your choices: you can block your child's card at any time in the app. You can ask the school to show, correct or remove your child's canteen information, and to settle any money left on the account when your child leaves.

Questions: please contact the school office.
TEXT;
    }
}
