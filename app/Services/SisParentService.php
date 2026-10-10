<?php

namespace App\Services;

use App\Exceptions\SisIntegrationUnavailableException;
use App\Models\Student;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDOException;

class SisParentService
{
    public function authenticate(string $accessToken): ?string
    {
        $url = config('services.sis.userinfo_url');
        $claim = config('services.sis.parent_id_claim');

        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! is_string($claim) || $claim === '') {
            throw new SisIntegrationUnavailableException('SIS OAuth user-info is not configured.');
        }

        if (app()->isProduction() && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new SisIntegrationUnavailableException('SIS OAuth user-info must use HTTPS in production.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new SisIntegrationUnavailableException('SIS authentication is temporarily unavailable.', previous: $exception);
        }

        if (in_array($response->status(), [401, 403], true)) {
            return null;
        }

        if (! $response->successful()) {
            throw new SisIntegrationUnavailableException('SIS authentication is temporarily unavailable.');
        }

        $parentId = data_get($response->json(), $claim);
        if ((! is_string($parentId) && ! is_int($parentId)) || ! preg_match('/\A[0-9]{1,10}\z/', (string) $parentId)) {
            return null;
        }

        return (string) $parentId;
    }

    /** @return Collection<int, Student> */
    public function linkedStudents(string $parentId): Collection
    {
        $studentIds = $this->studentIdsForParent($parentId);

        if ($studentIds === []) {
            return collect();
        }

        $query = fn () => Student::withoutGlobalScopes()
            ->with(['account', 'school:id,currency'])
            ->orderBy('id');
        $students = $query()->whereIn('student_code', $studentIds)->get();
        $matchedIds = $students
            ->map(fn (Student $student) => $this->canonicalStudentId($student->student_code))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $unmatchedIds = array_values(array_diff($studentIds, $matchedIds));

        if ($unmatchedIds !== []) {
            $expression = match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => 'CAST(student_code AS UNSIGNED)',
                'sqlite' => 'CAST(student_code AS INTEGER)',
                default => throw new SisIntegrationUnavailableException('Student ID matching is not supported by this database driver.'),
            };
            $placeholders = implode(',', array_fill(0, count($unmatchedIds), '?'));
            $paddedMatches = $query()
                ->whereRaw("{$expression} IN ({$placeholders})", $unmatchedIds)
                ->get()
                ->filter(fn (Student $student) => $this->canonicalStudentId($student->student_code) !== null
                    && in_array($this->canonicalStudentId($student->student_code), $unmatchedIds, true));

            $students = $students->concat($paddedMatches)->unique('id')->sortBy('id')->values();
        }

        if ($students->groupBy(fn (Student $student) => $this->canonicalStudentId($student->student_code))
            ->contains(fn (Collection $matches) => $matches->count() > 1)) {
            throw new SisIntegrationUnavailableException('An SIS student ID maps to multiple canteen students.');
        }

        return $students;
    }

    public function parentCanAccessStudent(string $parentId, Student $student): bool
    {
        return $this->linkedStudents($parentId)->contains('id', $student->id);
    }

    /** @return array<int, string> */
    private function studentIdsForParent(string $parentId): array
    {
        if (! filled(config('database.connections.sis.database'))) {
            throw new SisIntegrationUnavailableException('The read-only SIS database connection is not configured.');
        }

        try {
            return DB::connection('sis')
                ->table('student_parents')
                ->where('parent_id', $parentId)
                ->distinct()
                ->pluck('student_id')
                ->map(fn ($studentId) => $this->canonicalStudentId((string) $studentId))
                ->filter()
                ->unique()
                ->filter(fn (string $studentId) => preg_match('/\A[0-9]+\z/', $studentId) === 1)
                ->values()
                ->all();
        } catch (PDOException $exception) {
            throw new SisIntegrationUnavailableException('SIS student relationships are temporarily unavailable.', previous: $exception);
        }
    }

    private function canonicalStudentId(string $studentId): ?string
    {
        if (! preg_match('/\A[0-9]+\z/', $studentId)) {
            return null;
        }

        $canonical = ltrim($studentId, '0');

        return $canonical === '' ? '0' : $canonical;
    }
}
