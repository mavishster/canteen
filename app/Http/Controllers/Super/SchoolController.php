<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Schools, for the super-admin only. A super-admin belongs to no school and sees all of them. */
class SchoolController extends Controller
{
    public function index()
    {
        $schools = School::orderBy('name')->get();
        $ids = $schools->pluck('id');

        $students = Student::withoutGlobalScopes()
            ->whereIn('school_id', $ids)
            ->selectRaw('school_id, count(*) as n, sum(is_active) as active')
            ->groupBy('school_id')
            ->get()
            ->keyBy('school_id');

        $wallets = Account::withoutGlobalScopes()
            ->whereIn('school_id', $ids)
            ->selectRaw('school_id, sum(balance) as total')
            ->groupBy('school_id')
            ->pluck('total', 'school_id');

        $parentCounts = User::whereIn('school_id', $ids)
            ->whereHas('roles', fn ($q) => $q->where('name', 'parent'))
            ->selectRaw('school_id, count(*) as n')
            ->groupBy('school_id')
            ->pluck('n', 'school_id');

        $staffCounts = User::whereIn('school_id', $ids)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'parent'))
            ->selectRaw('school_id, count(*) as n')
            ->groupBy('school_id')
            ->pluck('n', 'school_id');

        $lastActivity = LedgerEntry::withoutGlobalScopes()
            ->whereIn('school_id', $ids)
            ->selectRaw('school_id, max(created_at) as last')
            ->groupBy('school_id')
            ->pluck('last', 'school_id');

        return view('super.schools.index', compact('schools', 'students', 'wallets', 'parentCounts', 'staffCounts', 'lastActivity'));
    }

    public function create()
    {
        return view('super.schools.create', ['timezones' => timezone_identifiers_list()]);
    }

    public function store(Request $request)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'regex:/^[A-Z0-9_-]{2,20}$/', Rule::unique('schools', 'code')],
            'currency' => ['required', 'in:USD,KHR'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'sis_branch_id' => ['nullable', 'integer', 'min:1', Rule::unique('schools', 'sis_branch_id')],
            'admin_name' => ['nullable', 'required_with:admin_email', 'string', 'max:255'],
            'admin_email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['nullable', 'required_with:admin_email', 'string', 'min:12', 'max:100'],
        ]);

        $school = DB::transaction(function () use ($data) {
            $school = School::create([
                'name' => trim($data['name']),
                'code' => $data['code'],
                'currency' => $data['currency'],
                'timezone' => $data['timezone'],
                'sis_branch_id' => $data['sis_branch_id'] ?? null,
            ]);

            AuditRecorder::log(
                'school.created', 'school', $school->name,
                "School “{$school->name}” ({$school->code}) created: {$school->currency}, {$school->timezone}",
                [], ['code' => $school->code, 'currency' => $school->currency, 'timezone' => $school->timezone],
                $school->id, $school
            );

            if (filled($data['admin_email'] ?? null)) {
                $this->makeAdmin($school, $data['admin_name'], $data['admin_email'], $data['admin_password']);
            }

            return $school;
        });

        return redirect()->route('super.schools.show', $school)->with('status', "School {$school->code} created.");
    }

    public function show(School $school)
    {
        $staff = User::where('school_id', $school->id)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'parent'))
            ->with('roles')
            ->orderBy('name')
            ->get();

        $studentCount = Student::withoutGlobalScopes()->where('school_id', $school->id)->count();

        return view('super.schools.show', [
            'school' => $school,
            'staff' => $staff,
            'students' => $studentCount,
            'parents' => User::where('school_id', $school->id)->whereHas('roles', fn ($q) => $q->where('name', 'parent'))->count(),
            'wallets' => (int) Account::withoutGlobalScopes()->where('school_id', $school->id)->sum('balance'),
            'locked' => $this->isLocked($school),
            'timezones' => timezone_identifiers_list(),
            'branding' => (array) data_get($school->settings, 'branding', []),
        ]);
    }

    public function update(Request $request, School $school)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sis_branch_id' => ['nullable', 'integer', 'min:1', Rule::unique('schools', 'sis_branch_id')->ignore($school->id)],
            'currency' => ['nullable', 'in:USD,KHR'],
            'timezone' => ['nullable', Rule::in(timezone_identifiers_list())],
            'display_name' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'contact' => ['nullable', 'string', 'max:200'],
        ]);

        $changes = [];

        foreach (['currency', 'timezone'] as $field) {
            if (filled($data[$field] ?? null) && $data[$field] !== $school->{$field}) {
                if ($this->isLocked($school)) {
                    throw ValidationException::withMessages([
                        $field => 'The ' . $field . ' cannot be changed once the school has students or money.',
                    ]);
                }

                $changes[$field] = [$school->{$field}, $data[$field]];
            }
        }

        $newBranch = isset($data['sis_branch_id']) ? (int) $data['sis_branch_id'] : null;

        if ($newBranch !== ($school->sis_branch_id !== null ? (int) $school->sis_branch_id : null)) {
            $changes['sis_branch_id'] = [$school->sis_branch_id, $newBranch];
        }

        $settings = $school->settings ?? [];

        foreach (['display_name', 'color', 'contact'] as $key) {
            filled($data[$key] ?? null)
                ? Arr::set($settings, "branding.{$key}", trim($data[$key]))
                : Arr::forget($settings, "branding.{$key}");
        }

        $school->update([
            'name' => trim($data['name']),
            'currency' => $data['currency'] ?: $school->currency,
            'timezone' => $data['timezone'] ?: $school->timezone,
            'sis_branch_id' => $newBranch,
            'settings' => $settings,
        ]);

        // The audit log already records a changed name and changed settings; these fields it cannot see by itself
        if ($changes) {
            $parts = [];
            foreach ($changes as $field => [$old, $new]) {
                $parts[] = str_replace('_', ' ', $field) . ': ' . ($old ?? '—') . ' → ' . ($new ?? '—');
            }

            AuditRecorder::log(
                'school.updated', 'school', $school->name,
                "School “{$school->name}”: " . implode('; ', $parts),
                array_map(fn ($c) => $c[0], $changes), array_map(fn ($c) => $c[1], $changes),
                $school->id, $school
            );
        }

        return redirect()->route('super.schools.show', $school)->with('status', 'School saved.');
    }

    public function addAdmin(Request $request, School $school)
    {
        $data = $request->validate([
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:12', 'max:100'],
        ]);

        $this->makeAdmin($school, $data['admin_name'], $data['admin_email'], $data['admin_password']);

        return redirect()->route('super.schools.show', $school)->with('status', "{$data['admin_name']} is now an admin of {$school->name}.");
    }

    /** For a school admin who is locked out. Signs the person out everywhere. */
    public function resetPassword(Request $request, School $school, User $user)
    {
        // Only staff of THIS school: never a parent, a super-admin (no school), or someone from another school
        abort_unless((int) $user->school_id === (int) $school->id && ! $user->hasRole('parent'), 404);

        $data = $request->validate(['password' => ['required', 'string', 'min:12', 'max:100']]);

        // The user record's own update event writes "password changed" (without the password) to the audit log
        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return redirect()->route('super.schools.show', $school)->with('status', "New password set for {$user->name}. They were signed out everywhere.");
    }

    // ------------------------------------------------------------------ helpers

    private function makeAdmin(School $school, string $name, string $email, string $password): User
    {
        Role::findOrCreate('admin', 'web');

        $user = User::forceCreate([
            'name' => trim($name),
            'email' => $email,
            'password' => Hash::make($password),
            'school_id' => $school->id,
        ]);
        $user->assignRole('admin');

        AuditRecorder::log(
            'staff.role_assigned', 'user', $user->name,
            "“{$user->name}” given the role admin at {$school->name}",
            [], ['role' => 'admin'], $school->id, $user
        );

        return $user;
    }

    /** Once a school has students or money, its currency and time zone are fixed. */
    private function isLocked(School $school): bool
    {
        return Student::withoutGlobalScopes()->where('school_id', $school->id)->exists()
            || LedgerEntry::withoutGlobalScopes()->where('school_id', $school->id)->exists();
    }
}
