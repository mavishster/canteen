<?php

namespace App\Services\Sis;

/** For tests: returns whatever it was given. */
class FakeSisClient implements SisClient
{
    /** @param array<int, SisStudent> $students */
    public function __construct(private array $students = [])
    {
    }

    public function students(int $branchId): array
    {
        return $this->students;
    }
}
