<?php

declare(strict_types=1);

namespace App;

require_once dirname(__DIR__, 2) . '/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;

final class WorkerEvidenceListener implements ListenerInterface
{
   /** @return list<class-string> */
   public function listen(): array
   {
      return [AfterWorkerStart::class];
   }

   public function process(object $Event): void
   {
      if ($Event instanceof AfterWorkerStart && $Event->server->taskworker !== true) {
         WorkerEvidence::boot();
      }
   }
}
