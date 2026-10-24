<?php

namespace App\Support\Ops;

use RuntimeException;
use Symfony\Component\Process\Process;

/** mysqldump, streamed straight into a gzip file. The password goes in the environment, never on the command line. */
class MysqlDumper implements Dumper
{
    public function dump(string $gzPath): void
    {
        $c = (array) config('database.connections.' . config('database.default'));

        $command = ['mysqldump', '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4'];

        if (! empty($c['unix_socket'])) {
            $command[] = '--socket=' . $c['unix_socket'];
        } else {
            array_push($command, '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306));
        }

        array_push($command, '-u', (string) ($c['username'] ?? ''), (string) ($c['database'] ?? ''));

        $process = new Process($command, null, ['MYSQL_PWD' => (string) ($c['password'] ?? '')], null, 3600);

        $gz = gzopen($gzPath, 'wb9');

        if ($gz === false) {
            throw new RuntimeException("Cannot write {$gzPath}.");
        }

        $tail = '';

        $process->run(function (string $type, string $buffer) use ($gz, &$tail) {
            if ($type === Process::OUT) {
                gzwrite($gz, $buffer);
                $tail = substr($tail . $buffer, -400);
            }
        });

        gzclose($gz);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: ' . trim($process->getErrorOutput()));
        }

        // mysqldump writes this line only when it reached the end
        if (! str_contains($tail, 'Dump completed')) {
            throw new RuntimeException('The dump looks incomplete: it does not end the way mysqldump ends.');
        }
    }
}
