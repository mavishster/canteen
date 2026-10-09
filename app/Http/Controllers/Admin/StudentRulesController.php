<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\RuleException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\Product;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentBan;
use App\Services\RuleService;
use App\Support\Money;
use Illuminate\Http\Request;
use InvalidArgumentException;

class StudentRulesController extends Controller
{
    public function show(Student $student)
    {
        $school = School::findOrFail($student->school_id);
        $account = Account::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();

        $bans = StudentBan::with(['product:id,name', 'category:id,name'])
            ->where('student_id', $student->id)
            ->get();

        $products = Product::where('school_id', $student->school_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $categories = Category::where('school_id', $student->school_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $currency = $school->currency;
        $caps = [
            'daily' => $school->capForGrade('daily', $student->grade),
            'weekly' => $school->capForGrade('weekly', $student->grade),
        ];

        return view('admin.students.rules', compact('student', 'account', 'bans', 'products', 'categories', 'currency', 'caps'));
    }

    public function limits(Request $request, Student $student, RuleService $rules)
    {
        $data = $request->validate([
            'daily_limit' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
            'weekly_limit' => ['nullable', 'numeric', 'min:0.01', 'max:100000000'],
        ]);

        $currency = School::findOrFail($student->school_id)->currency;

        $daily = filled($data['daily_limit'] ?? null) ? Money::toMinor($data['daily_limit'], $currency) : null;
        $weekly = filled($data['weekly_limit'] ?? null) ? Money::toMinor($data['weekly_limit'], $currency) : null;

        try {
            $rules->setLimits($student, $daily, $weekly);
        } catch (RuleException | InvalidArgumentException $e) {
            return back()->withErrors(['limits' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.students.rules', $student)->with('status', 'Limits saved.');
    }

    public function addBan(Request $request, Student $student, RuleService $rules)
    {
        $data = $request->validate([
            'ban' => ['required', 'regex:/^(product|category):\d+$/'],
        ]);

        [$type, $id] = explode(':', $data['ban']);

        try {
            if ($type === 'product') {
                $rules->banProduct($student, Product::where('school_id', $student->school_id)->findOrFail($id));
            } else {
                $rules->banCategory($student, Category::where('school_id', $student->school_id)->findOrFail($id));
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['ban' => $e->getMessage()]);
        }

        return redirect()->route('admin.students.rules', $student)->with('status', 'Ban added.');
    }

    public function removeBan(StudentBan $ban, RuleService $rules)
    {
        $studentId = $ban->student_id;
        $rules->unban($ban);

        return redirect()->route('admin.students.rules', $studentId)->with('status', 'Ban removed.');
    }
}
