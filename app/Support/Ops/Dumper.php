<?php

namespace App\Support\Ops;

interface Dumper
{
    /** Writes a gzip-compressed dump of the whole database to $gzPath. Throws when it could not be completed. */
    public function dump(string $gzPath): void;
}
