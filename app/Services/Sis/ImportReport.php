<?php

namespace App\Services\Sis;

class ImportReport
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $cardsBound = 0;
    public int $cardsReplaced = 0;
    public int $cardsUnchanged = 0;

    /** @var array<int, array{student_code: string, name: string, type: string, detail: string}> */
    public array $issues = [];

    /** @var array<int, array{student_code: string, name: string}> active canteen students the SIS no longer lists */
    public array $missing = [];

    public function issue(string $studentCode, string $name, string $type, string $detail): void
    {
        $this->issues[] = ['student_code' => $studentCode, 'name' => $name, 'type' => $type, 'detail' => $detail];
    }
}
