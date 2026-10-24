<?php

namespace App\Console\Commands\Concerns;

use App\Support\Ops\OperationsStatus;

/** Added to `canteen:check`: scheduler, queue worker, backup, disk space. */
trait ChecksOperations
{
    private function checkOperations(bool $production): void
    {
        foreach (OperationsStatus::report($production) as $line) {
            $this->add($line['level'], $line['area'], $line['message']);
        }
    }
}
