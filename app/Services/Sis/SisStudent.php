<?php

namespace App\Services\Sis;

/** The only student fields the canteen takes from the SIS. Never passwords or personal details. */
final class SisStudent
{
    public function __construct(
        public readonly string $id,        // the SIS student ID: becomes the canteen's student_code
        public readonly string $name,
        public readonly ?int $grade,
        public readonly ?string $rfid,     // exactly as the SIS stores it, or null
    ) {
    }
}
