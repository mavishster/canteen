<?php

namespace App\Services\Sis;

interface SisClient
{
    /**
     * Active students of the current academic year in one SIS branch.
     *
     * @return array<int, SisStudent>
     */
    public function students(int $branchId): array;
}
