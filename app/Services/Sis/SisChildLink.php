<?php

namespace App\Services\Sis;

final class SisChildLink
{
    public function __construct(
        public readonly string $studentId,       // the SIS student ID = the canteen's student_code
        public readonly ?string $relationType,
    ) {
    }
}
