<?php

declare(strict_types=1);

namespace App\Http\Middleware;

require_once dirname(__DIR__, 4) . '/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class WorkerEvidenceMiddleware
{
    public function handle(Request $Request, Closure $Next): Response
    {
        if (WorkerEvidence::$enabled === false) {
            return $Next($Request);
        }

        $Response = $Next($Request);
        $identity = WorkerEvidence::identify(
            $Request->headers->get('X-Bootgly-Benchmark-Warmup'),
            $Request->headers->get('X-Bootgly-Benchmark-Seal'),
        );
        if ($identity !== null) {
            $Response->headers->set('X-Bootgly-Benchmark-Worker', $identity);
        }

        return $Response;
    }
}
