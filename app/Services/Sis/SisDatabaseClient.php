<?php

namespace App\Services\Sis;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads the SIS MySQL database with a READ-ONLY user.
 * It selects named columns only: password columns are never read, and with column-level
 * grants (see docs/sis-integration.md) the database itself would refuse such a query.
 */
class SisDatabaseClient implements SisClient
{
    private bool $registered = false;

    public function connection(): ConnectionInterface
    {
        if (! $this->registered) {
            $c = config('sis.database');

            $settings = [
                'driver' => $c['driver'] ?? 'mysql',
                'host' => $c['host'] ?? '127.0.0.1',
                'port' => $c['port'] ?? 3306,
                'database' => $c['database'] ?? null,
                'username' => $c['username'] ?? null,
                'password' => $c['password'] ?? null,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ];

            if (! empty($c['ssl_ca']) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $settings['options'] = [\PDO::MYSQL_ATTR_SSL_CA => $c['ssl_ca']];
            }

            config(['database.connections.sis' => $settings]);
            DB::purge('sis');
            $this->registered = true;
        }

        return DB::connection('sis');
    }

    public function students(int $branchId): array
    {
        return $this->connection()->table('users')
            ->join('students_classroom', 'students_classroom.student_id', '=', 'users.id')
            ->join('classrooms', 'classrooms.classroom_id', '=', 'students_classroom.classroom_id')
            ->join('academic_year', 'academic_year.academic_year_id', '=', 'students_classroom.academic_year_id')
            ->where('users.user_type', 'student')
            ->where('users.user_status', 1)
            ->where('users.branch_id', $branchId)
            ->where('academic_year.is_active', 1)
            ->whereNull('academic_year.deleted_at')
            ->groupBy('users.id')   // a student in two classrooms appears once, with the higher grade
            ->orderBy('users.id')
            ->selectRaw('users.id, users.name, users.rfid, max(classrooms.grade_level) as grade_level')
            ->get()
            ->map(fn ($row) => new SisStudent(
                (string) $row->id,
                trim((string) $row->name),
                $this->grade($row->grade_level),
                $this->clean($row->rfid),
            ))
            ->all();
    }

    private function clean(mixed $rfid): ?string
    {
        $rfid = trim((string) $rfid);

        return $rfid === '' ? null : $rfid;
    }

    private function grade(mixed $grade): ?int
    {
        return ($grade !== null && (int) $grade >= 0 && (int) $grade <= 255) ? (int) $grade : null;
    }
}
