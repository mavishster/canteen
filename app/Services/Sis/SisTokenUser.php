<?php

namespace App\Services\Sis;

/** Who the SIS says owns a login token. Only the id is used; everything else comes from the parent record. */
final class SisTokenUser
{
    public function __construct(public readonly string $id)
    {
    }
}
