<?php

declare(strict_types=1);

namespace App;

require_once dirname(__DIR__, 2) . '/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class WorkerEvidenceMiddleware implements MiddlewareInterface
{
   public function process(ServerRequestInterface $Request, RequestHandlerInterface $Handler): ResponseInterface
   {
      if (WorkerEvidence::$enabled === false) {
         return $Handler->handle($Request);
      }

      $Response = $Handler->handle($Request);
      $identity = WorkerEvidence::identify(
         $Request->getHeaderLine('X-Bootgly-Benchmark-Warmup'),
         $Request->getHeaderLine('X-Bootgly-Benchmark-Seal'),
      );

      return $identity === null
         ? $Response
         : $Response->withHeader('X-Bootgly-Benchmark-Worker', $identity);
   }
}
