<?php

namespace App\Support\Ops;

use RuntimeException;

/** For a development computer that uses a SQLite file: a compressed copy of the file. */
class SqliteDumper implements Dumper
{
    public function dump(string $gzPath): void
    {
        $file = (string) config('database.connections.' . config('database.default') . '.database');

        if ($file === '' || $file === ':memory:' || ! is_file($file)) {
            throw new RuntimeException('Backups need a MySQL database or a SQLite file.');
        }

        $in = fopen($file, 'rb');
        $gz = gzopen($gzPath, 'wb9');

        while (! feof($in)) {
            gzwrite($gz, (string) fread($in, 1 << 20));
        }

        gzwrite($gz, "\n-- Dump completed\n");
        gzclose($gz);
        fclose($in);
    }
}
