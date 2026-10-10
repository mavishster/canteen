<?php

namespace App\Http\Controllers\ParentApi;

use App\Http\Controllers\Controller;
use App\Models\GuardianLink;
use App\Models\School;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

abstract class ParentBaseController extends Controller
{
    /** @return Collection<int, int> */
    protected function linkedIds(Request $request): Collection
    {
        return GuardianLink::where('user_id', $request->user()->id)->pluck('student_id');
    }

    /** A child of this parent, or 404. A parent can never reach someone else's child. */
    protected function child(Request $request, int|string $id): Student
    {
        abort_unless($this->linkedIds($request)->contains((int) $id), 404);

        return Student::with('account')->findOrFail($id);
    }

    protected function school(Request $request): School
    {
        return School::findOrFail($request->user()->school_id);
    }
}
