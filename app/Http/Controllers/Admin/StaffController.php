<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * The school's own staff: admins, managers and cashiers. Parents and super-admins are never listed or touched here.
 * "Disabled" means no role, no sessions, no tokens and a scrambled password.
 * Role changes are written to the audit log here, because they are not changes of the user record itself.
 */
class StaffController extends Controller
{
    private const ROLES = ['admin', 'manager', 'cashier'];

    public function index(Request $request)
    {
        $staff = $this->staffQuery($this->schoolId($request))->with('roles')->orderBy('name')->get();

        return view('admin.staff.index', [
            'staff' => $staff,
            'roles' => self::ROLES,
            'meId' => $request->user()->id,
        ]);
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(self::ROLES)],
            'password' => ['required', 'string', 'min:12', 'max:100'],
        ]);

        $this->ensureRoles();

        $user = User::forceCreate([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'school_id' => $schoolId,
        ]);
        $user->assignRole($data['role']);

        AuditRecorder::log(
            'staff.role_assigned', 'user', $user->name,
            "“{$user->name}” given the role {$data['role']}",
            [], ['role' => $data['role']], $schoolId, $user
        );

        return redirect()->route('admin.staff')->with('status', "{$user->name} can now sign in as {$data['role']}.");
    }

    public function role(Request $request, User $staffUser)
    {
        $this->ownStaff($request, $staffUser);

        $data = $request->validate(['role' => ['required', Rule::in(self::ROLES)]]);

        if ($this->isMe($request, $staffUser)) {
            return back()->withErrors(['staff' => 'You cannot change your own role.']);
        }

        if ($staffUser->roles->isEmpty()) {
            return back()->withErrors(['staff' => 'This account is disabled. Use Enable, which also sets a new password.']);
        }

        $previous = $staffUser->roles->first()->name;

        $this->ensureRoles();
        $staffUser->syncRoles([$data['role']]);

        AuditRecorder::log(
            'staff.role_changed', 'user', $staffUser->name,
            "“{$staffUser->name}”: role {$previous} → {$data['role']}",
            ['role' => $previous], ['role' => $data['role']], (int) $staffUser->school_id, $staffUser
        );

        return back()->with('status', "{$staffUser->name} is now {$data['role']}.");
    }

    public function password(Request $request, User $staffUser)
    {
        $this->ownStaff($request, $staffUser);

        $data = $request->validate(['password' => ['required', 'string', 'min:12', 'max:100']]);

        // The user record's own update event writes "password changed" (without the password) to the audit log
        $staffUser->forceFill(['password' => Hash::make($data['password'])])->save();
        $this->signOut($request, $staffUser);

        return back()->with('status', "New password set for {$staffUser->name}. They have been signed out everywhere.");
    }

    public function disable(Request $request, User $staffUser)
    {
        $this->ownStaff($request, $staffUser);

        if ($this->isMe($request, $staffUser)) {
            return back()->withErrors(['staff' => 'You cannot disable your own account.']);
        }

        $previous = $staffUser->roles->first()?->name;

        $staffUser->syncRoles([]);
        $staffUser->forceFill(['password' => Hash::make(Str::random(64))])->save();   // nobody knows it
        $this->signOut($request, $staffUser);

        AuditRecorder::log(
            'staff.disabled', 'user', $staffUser->name,
            "“{$staffUser->name}” disabled" . ($previous ? " (was {$previous})" : ''),
            ['role' => $previous], [], (int) $staffUser->school_id, $staffUser
        );

        return back()->with('status', "{$staffUser->name} is disabled and has been signed out.");
    }

    public function enable(Request $request, User $staffUser)
    {
        $this->ownStaff($request, $staffUser);

        $data = $request->validate([
            'role' => ['required', Rule::in(self::ROLES)],
            'password' => ['required', 'string', 'min:12', 'max:100'],
        ]);

        if ($staffUser->roles->isNotEmpty()) {
            return back()->withErrors(['staff' => 'This account is already active.']);
        }

        $this->ensureRoles();
        $staffUser->forceFill(['password' => Hash::make($data['password'])])->save();
        $staffUser->assignRole($data['role']);

        AuditRecorder::log(
            'staff.enabled', 'user', $staffUser->name,
            "“{$staffUser->name}” enabled as {$data['role']}",
            [], ['role' => $data['role']], (int) $staffUser->school_id, $staffUser
        );

        return back()->with('status', "{$staffUser->name} is active again as {$data['role']}.");
    }

    // ------------------------------------------------------------------ helpers

    private function schoolId(Request $request): int
    {
        abort_unless($request->user()->school_id, 403, 'Log in as a school admin to manage staff.');

        return (int) $request->user()->school_id;
    }

    private function staffQuery(int $schoolId)
    {
        // The User model has no tenant scope, so the school is filtered by hand
        return User::where('school_id', $schoolId)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'parent'));
    }

    /** Staff of this school only: never a parent, a super-admin (no school), or someone from another school. */
    private function ownStaff(Request $request, User $staffUser): void
    {
        abort_unless(
            $staffUser->school_id !== null
                && (int) $staffUser->school_id === $this->schoolId($request)
                && ! $staffUser->hasRole('parent'),
            404
        );
    }

    private function isMe(Request $request, User $staffUser): bool
    {
        return (int) $staffUser->id === (int) $request->user()->id;
    }

    private function ensureRoles(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /** Ends every login of this person: API tokens and browser sessions. */
    private function signOut(Request $request, User $user): void
    {
        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->when(
                    $this->isMe($request, $user),
                    fn ($q) => $q->where('id', '!=', $request->session()->getId())   // do not log yourself out
                )
                ->delete();
        }
    }
}
