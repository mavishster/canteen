<?php

namespace App\Http\Controllers\ParentApi;

use App\Exceptions\ConsentException;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\ConsentService;
use Illuminate\Http\Request;

class ConsentController extends Controller
{
    /** The school's newest text and whether this parent still has to accept it. */
    public function show(Request $request, ConsentService $consent)
    {
        $user = $request->user();
        $school = School::findOrFail($user->school_id);
        $document = $consent->current($school);
        $acceptance = $document ? $consent->acceptance($user, $document) : null;

        return response()->json([
            'required' => $consent->required($school) && $document !== null,
            'must_accept' => $consent->mustAccept($user),
            'version' => $document?->version,
            'text' => $document?->text,
            'accepted_at' => $acceptance?->created_at?->toIso8601String(),
        ]);
    }

    public function accept(Request $request, ConsentService $consent)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        try {
            $record = $consent->accept($request->user(), (int) $data['version'], $request->ip());
        } catch (ConsentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        return response()->json([
            'ok' => true,
            'version' => (int) $data['version'],
            'accepted_at' => $record->created_at->toIso8601String(),
        ]);
    }
}
