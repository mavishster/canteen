<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GuardianLink;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ParentAccountController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $parents = User::role('parent')->where('school_id', $schoolId)->orderBy('name')->get();

        $links = GuardianLink::with('student:id,name,student_code')
            ->whereIn('user_id', $parents->pluck('id'))
            ->get()
            ->groupBy('user_id');

        $students = Student::orderBy('name')->limit(1000)->get(['id', 'name', 'student_code']);

        return view('admin.parents.index', compact('parents', 'links', 'students'));
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        Role::findOrCreate('parent', 'web');

        $parent = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'school_id' => $schoolId,
        ]);
        $parent->assignRole('parent');

        return redirect()->route('admin.parents')->with('status', 'Parent account created. Give them the email and password.');
    }

    public function link(Request $request, User $parentUser)
    {
        $this->ownParent($request, $parentUser);

        $data = $request->validate(['student_id' => ['required', 'integer']]);
        $student = Student::findOrFail($data['student_id']);   // limited to this school by the tenant scope

        GuardianLink::firstOrCreate([
            'school_id' => $student->school_id,
            'user_id' => $parentUser->id,
            'student_id' => $student->id,
        ]);

        return redirect()->route('admin.parents')->with('status', "{$student->name} linked to {$parentUser->name}.");
    }

    public function unlink(GuardianLink $link)
    {
        $link->delete();

        return redirect()->route('admin.parents')->with('status', 'Link removed.');
    }

    /** Sets a new password and signs the parent out of every phone. */
    public function password(Request $request, User $parentUser)
    {
        $this->ownParent($request, $parentUser);

        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:100']]);

        $parentUser->update(['password' => Hash::make($data['password'])]);
        $parentUser->tokens()->delete();

        return redirect()->route('admin.parents')->with('status', "New password set for {$parentUser->name}.");
    }

    private function schoolId(Request $request): int
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school admin to manage parents.');

        return (int) $request->user()->school_id;
    }

    /** The User model has no tenant scope, so check the school by hand. */
    private function ownParent(Request $request, User $parentUser): void
    {
        abort_unless(
            (int) $parentUser->school_id === $this->schoolId($request) && $parentUser->hasRole('parent'),
            404
        );
    }
}
