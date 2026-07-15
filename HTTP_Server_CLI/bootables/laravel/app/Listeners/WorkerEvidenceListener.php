<?php

declare(strict_types=1);

namespace App\Listeners;

require_once dirname(__DIR__, 3) . '/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Laravel\Octane\Events\WorkerStarting;

final class WorkerEvidenceListener
{
    public function handle(WorkerStarting $Event): void
    {
        WorkerEvidence::boot();
    }
}
