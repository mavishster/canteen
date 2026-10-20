<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConsentDocument;
use App\Models\ParentConsent;
use App\Models\School;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ConsentController extends Controller
{
    public function index(Request $request, ConsentService $consent)
    {
        $school = $this->school($request);
        $document = $consent->current($school);

        $parents = fn () => User::where('school_id', $school->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'parent'));

        $accepted = $document
            ? ParentConsent::withoutGlobalScopes()->where('consent_document_id', $document->id)->pluck('user_id')
            : collect();

        $versions = ConsentDocument::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->orderByDesc('version')
            ->get();

        $counts = ParentConsent::withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->selectRaw('consent_document_id, count(*) as n')
            ->groupBy('consent_document_id')
            ->pluck('n', 'consent_document_id');

        return view('admin.consent.index', [
            'school' => $school,
            'required' => $consent->required($school),
            'document' => $document,
            'versions' => $versions,
            'counts' => $counts,
            'draft' => old('text', $document?->text ?? ConsentService::defaultText($school)),
            'total' => $parents()->count(),
            'acceptedCount' => $accepted->count(),
            'pending' => $document
                ? $parents()->whereNotIn('id', $accepted)->orderBy('name')->limit(100)->get(['id', 'name', 'email'])
                : collect(),
        ]);
    }

    /** A new version. The older ones stay, with everyone's acceptance of them. */
    public function publish(Request $request, ConsentService $consent)
    {
        $school = $this->school($request);

        $data = $request->validate(['text' => ['required', 'string', 'min:100', 'max:20000']]);

        $document = $consent->publish($school, $data['text'], $request->user());

        $note = $consent->required($school)
            ? ' Every parent will be asked to accept it again.'
            : ' Consent is not compulsory yet: switch it on when you are ready.';

        return redirect()->route('admin.consent')->with('status', "Version {$document->version} published." . $note);
    }

    /** Makes acceptance compulsory (or not). Needs a published text first. */
    public function toggle(Request $request, ConsentService $consent)
    {
        $school = $this->school($request);

        $turnOn = ! $consent->required($school);

        if ($turnOn && ! $consent->current($school)) {
            return back()->withErrors(['consent' => 'Publish a text first, then switch consent on.']);
        }

        $settings = $school->settings ?? [];
        Arr::set($settings, 'consent.required', $turnOn);
        $school->update(['settings' => $settings]);   // the audit log records the change

        return redirect()->route('admin.consent')->with(
            'status',
            $turnOn ? 'Consent is now compulsory: parents must accept the newest text to use the app.' : 'Consent is no longer compulsory.'
        );
    }

    private function school(Request $request): School
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school admin to manage consent.');

        return School::findOrFail($request->user()->school_id);
    }
}
