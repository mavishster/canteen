<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CardException;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Student;
use App\Services\CardService;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        Paginator::useBootstrapFive();

        $students = Student::with([
                'school:id,currency',
                'account',
                'cards' => fn ($q) => $q->whereIn('status', [Card::ACTIVE, Card::BLOCKED]),
            ])
            ->when($request->query('q'), function ($query, $term) {
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('student_code', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.students.index', compact('students'));
    }

    public function store(Request $request)
    {
        $schoolId = $request->user()->school_id;

        if (! $schoolId) {
            return back()->withErrors(['student_code' => 'Log in as a school admin to add students.']);
        }

        $data = $request->validate([
            'student_code' => ['required', 'string', 'max:50',
                Rule::unique('students')->where('school_id', $schoolId)],
            'name' => ['required', 'string', 'max:255'],
            'grade' => ['nullable', 'integer', 'between:1,12'],
        ]);

        Student::create($data + ['school_id' => $schoolId]);

        return redirect()->route('admin.students')->with('status', 'Student added.');
    }

    /** First card or replacement: one endpoint, the service decides. */
    public function bindCard(Request $request, Student $student, CardService $cards)
    {
        $data = $request->validate(['uid' => ['required', 'string', 'max:40']]);

        try {
            $cards->currentCard($student)
                ? $cards->replace($student, $data['uid'])
                : $cards->bind($student, $data['uid']);
        } catch (CardException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function blockCard(Card $card, CardService $cards)
    {
        return $this->change(fn () => $cards->block($card));
    }

    public function unblockCard(Card $card, CardService $cards)
    {
        return $this->change(fn () => $cards->unblock($card));
    }

    private function change(callable $action)
    {
        try {
            $action();
        } catch (CardException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['ok' => true]);
    }
}
