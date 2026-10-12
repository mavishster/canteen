<?php

namespace App\Services\Sis;

use App\Exceptions\SisLoginException;
use App\Models\GuardianLink;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Parents who sign in through the SIS. The SIS stays the source of truth for who the parent is and
 * which children they have; the canteen keeps a short-lived copy of the links.
 */
class SisParentAccounts
{
    /** @return array{token: string, user: array<string, mixed>, children: int} */
    public function login(string $sisToken, string $deviceName): array
    {
        $tokenUser = $this->guard(fn () => SisIdentity::auth()->userForToken($sisToken));

        if (! $tokenUser) {
            throw SisLoginException::invalidToken();
        }

        // Authoritative data comes from the SIS parent record, not from the token answer
        $parent = $this->guard(fn () => SisIdentity::parents()->parent($tokenUser->id));

        if (! $parent || ! $parent->active) {
            throw SisLoginException::notAllowed();
        }

        $school = $parent->branchId ? School::where('sis_branch_id', $parent->branchId)->first() : null;

        if (! $school) {
            throw SisLoginException::schoolUnknown();
        }

        $user = $this->provision($parent, $school);
        $children = $this->syncLinks($user, $parent);

        return [
            'token' => $user->createToken($deviceName, ['parent'])->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'children' => $children,
        ];
    }

    /**
     * Called on every parent API request. Cheap while the saved links are fresh; otherwise it asks the SIS again.
     * Parents created by the school admin (no SIS id) are never touched.
     */
    public function refresh(User $user, bool $force = false): void
    {
        if (! $user->sis_user_id) {
            return;
        }

        $syncedAt = $user->sis_synced_at ? Carbon::parse($user->sis_synced_at) : null;

        if (! $force && $syncedAt && $syncedAt->gt(now()->subMinutes((int) config('sis.link_cache_minutes', 10)))) {
            return;
        }

        try {
            $parent = SisIdentity::parents()->parent($user->sis_user_id);
        } catch (SisUnavailableException) {
            // The SIS is down: keep working on the saved links for a while, then stop (fail closed)
            if ($syncedAt && $syncedAt->gt(now()->subMinutes((int) config('sis.link_grace_minutes', 60)))) {
                return;
            }

            throw SisLoginException::unavailable();
        }

        if (! $parent || ! $parent->active) {
            $this->revoke($user);

            throw SisLoginException::disabled();
        }

        try {
            $this->syncLinks($user, $parent);
        } catch (SisUnavailableException) {
            if ($syncedAt && $syncedAt->gt(now()->subMinutes((int) config('sis.link_grace_minutes', 60)))) {
                return;
            }

            throw SisLoginException::unavailable();
        }
    }

    /** Signs the parent out of every phone and removes their access to the children. */
    private function revoke(User $user): void
    {
        $user->tokens()->delete();
        GuardianLink::withoutGlobalScopes()->where('user_id', $user->id)->delete();
        $user->forceFill(['sis_synced_at' => null])->save();
    }

    private function provision(SisParent $parent, School $school): User
    {
        $user = User::where('sis_user_id', $parent->id)->first();

        if ($user) {
            if ((int) $user->school_id !== (int) $school->id) {
                $user->forceFill(['school_id' => $school->id])->save();
            }

            return $user;
        }

        Role::findOrCreate('parent', 'web');

        try {
            $user = User::forceCreate([
                'name' => $parent->name,
                // The SIS may share or omit emails, so the SIS id is the identity. This address is never used.
                'email' => "sis-parent-{$parent->id}@sis.invalid",
                'password' => Hash::make(Str::random(64)),   // nobody knows it: these parents cannot use a canteen password
                'school_id' => $school->id,
                'sis_user_id' => $parent->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return User::where('sis_user_id', $parent->id)->firstOrFail();   // two first logins at once
        }

        $user->assignRole('parent');

        return $user;
    }

    /** Makes the saved links match the SIS. Returns how many children are linked. */
    private function syncLinks(User $user, SisParent $parent): int
    {
        $links = $this->guard(fn () => SisIdentity::parents()->childrenOf($parent->id));

        $codes = collect($links)->map(fn (SisChildLink $l) => $l->studentId)->unique()->values()->all();

        // Only children that exist in THIS school's canteen. The same SIS id in another school is a different child.
        $studentIds = Student::withoutGlobalScopes()
            ->where('school_id', $user->school_id)
            ->whereIn('student_code', $codes)
            ->pluck('id')
            ->all();

        $existing = GuardianLink::withoutGlobalScopes()->where('user_id', $user->id)->pluck('student_id')->all();

        foreach (array_diff($studentIds, $existing) as $studentId) {
            GuardianLink::withoutGlobalScopes()->create([
                'school_id' => $user->school_id,
                'user_id' => $user->id,
                'student_id' => $studentId,
            ]);
        }

        GuardianLink::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotIn('student_id', $studentIds)
            ->delete();

        $user->forceFill(['name' => $parent->name, 'sis_synced_at' => now()])->save();

        return count($studentIds);
    }

    /** SIS trouble becomes a clear 503 instead of a crash. */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (SisUnavailableException) {
            throw SisLoginException::unavailable();
        }
    }
}
