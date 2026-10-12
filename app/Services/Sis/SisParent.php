<?php

namespace App\Services\Sis;

final class SisParent
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $active,
        public readonly ?int $branchId,   // the SIS "branch" = the school
    ) {
    }
}
