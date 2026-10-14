<?php

namespace App\Services\Sis;

use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;

/** Reads parents and their children from the SIS database with the same read-only user as the student import. */
class SisDatabaseParentClient implements SisParentClient
{
    public function __construct(private SisDatabaseClient $sis)
    {
    }

    public function parent(string $parentId): ?SisParent
    {
        $syncPhone = (bool) config('sis.sync_phone', false);

        // The phone column is read only when the SIS administrator has granted it (SIS_SYNC_PHONE)
        $columns = ['id', 'name', 'user_status', 'branch_id'];

        if ($syncPhone) {
            $columns[] = 'mobile_phone';
        }

        try {
            $row = $this->sis->connection()->table('users')
                ->where('id', $parentId)
                ->where('user_type', 'parent')
                ->select($columns)
                ->first();
        } catch (QueryException $e) {
            throw new SisUnavailableException('The SIS database could not be read.', 0, $e);
        }

        return $row
            ? new SisParent(
                (string) $row->id,
                trim((string) $row->name),
                (int) $row->user_status === 1,
                $row->branch_id !== null ? (int) $row->branch_id : null,
                $syncPhone ? PhoneNumber::normalize($row->mobile_phone ?? null) : null,
            )
            : null;
    }

    public function childrenOf(string $parentId): array
    {
        try {
            return $this->sis->connection()->table('student_parents')
                ->where('parent_id', $parentId)
                ->select('student_id', 'relation_type')
                ->get()
                ->map(fn ($row) => new SisChildLink((string) $row->student_id, $row->relation_type))
                ->all();
        } catch (QueryException $e) {
            throw new SisUnavailableException('The SIS database could not be read.', 0, $e);
        }
    }
}
