<?php

use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Benchmark\Time\Series;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\Benchmarks\Runners\MeasurementBarrier;
use Bootgly\Benchmarks\Runners\WorkerResult;


require_once dirname(__DIR__) . '/WorkerResult.php';

return new Specification(
   description: 'It should revalidate complete latency and time-series worker telemetry',
   test: static function (): bool
   {
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new AssertionError($message);
   }
};
$Encode = static function (array $document): string {
   return json_encode($document, JSON_THROW_ON_ERROR);
};
$Reject = static function (string $JSON, string $message) use ($Check): void {
   $Result = WorkerResult::parse($JSON);
   $Check(
      $Result->accounting === false
         && $Result->rps === null
         && $Result->latency === null,
      $message,
   );
};

$Histogram = new Histogram();
foreach ([1_000_000, 2_000_000, 3_000_000] as $latencyNS) {
   $Histogram->record($latencyNS);
}

$originNS = 9_100_000_000_000_000;
$deadlineNS = $originNS + 2_000_000_000;
$Series = new Series($originNS, $deadlineNS);
$Series->record($originNS, [
   'sent' => 3,
   'responses' => 2,
   'failed' => 1,
   'bytes_read' => 200,
]);
$Series->record($originNS + 1_000_000_000, [
   'sent' => 2,
   'responses' => 1,
   'censored' => 1,
   'write_failed' => 1,
   'write_censored' => 1,
   'bytes_read' => 100,
]);

$summary = $Histogram->inspect() + ['average_ns' => 2_000_000];
$document = [
   'schema' => WorkerResult::SCHEMA,
   'rps' => 1.5,
   'latency' => '2.00ms',
   'transfer' => '150B/s',
   'elapsed' => 2.0,
   'scheduled' => 7,
   'sent' => 5,
   'responses' => 3,
   'informational' => 0,
   'outstanding' => 0,
   'failed' => 1,
   'censored' => 1,
   'write_failed' => 1,
   'write_censored' => 1,
   'connection_failed' => 0,
   'partial_writes' => 1,
   'start_lag_ns' => 500,
   'accounting' => true,
   'statuses' => [200 => 3],
   'failures' => ['peer_closed' => 1],
   'censors' => ['measurement_deadline' => 1],
   'write_failures' => ['write_error' => 1],
   'write_censors' => ['measurement_deadline' => 1],
   'latency_summary' => $summary,
   'latency_histogram' => $Histogram->export(),
   'time_series' => $Series->export(),
];

$Result = WorkerResult::parse($Encode($document));
$Check($Result->accounting === true, 'A complete internally consistent worker result was rejected.');
$Check($Result->rps === 1.5, 'A valid worker rate was not retained.');
$Check($Result->latency === '2.00ms', 'A valid worker latency label was not retained.');
$Check($Result->transfer === '150B/s', 'A valid worker transfer label was not retained.');
$Check(
   $Result->scheduled === 7
      && $Result->sent === 5
      && $Result->responses === 3
      && $Result->failed === 1
      && $Result->censored === 1
      && $Result->writeFailed === 1
      && $Result->writeCensored === 1,
   'A valid worker accounting boundary was not retained exactly.',
);
$Check($Result->statuses === [200 => 3], 'Final response statuses were not retained exactly.');
$Check($Result->failures === ['peer_closed' => 1], 'Response failures were not retained exactly.');
$Check(
   $Result->censors === ['measurement_deadline' => 1]
      && $Result->writeFailures === ['write_error' => 1]
      && $Result->writeCensors === ['measurement_deadline' => 1],
   'Censor or write-failure reasons were not retained exactly.',
);
$Check($Result->latencySummary === $summary, 'The canonical latency summary changed at the boundary.');
$Check(
   $Result->latencyHistogram === $Histogram->export(),
   'The mergeable latency histogram changed at the boundary.',
);
$Check($Result->timeSeries === $Series->export(), 'The raw time series changed at the boundary.');

$invalid = $document;
$invalid['schema'] = 'bootgly.benchmark-result.v1';
$Reject($Encode($invalid), 'A result with an obsolete telemetry schema was accepted.');

$invalid = $document;
unset($invalid['transfer']);
$Reject($Encode($invalid), 'A result missing a required telemetry field was accepted.');

$invalid = $document;
$invalid['scheduled'] = 6;
$Reject($Encode($invalid), 'A result with unclosed write accounting was accepted.');

$invalid = $document;
$invalid['sent'] = 6;
$Reject($Encode($invalid), 'A result with unclosed response accounting was accepted.');

$invalid = $document;
$invalid['statuses'] = [200 => 2];
$Reject($Encode($invalid), 'A result whose status total differs from responses was accepted.');

$invalid = $document;
$invalid['failed'] = 2;
$Reject($Encode($invalid), 'A result whose failure counter differs from its reasons was accepted.');

$invalid = $document;
$invalid['connection_failed'] = 1;
$Reject($Encode($invalid), 'A result with a failed configured connection was accepted.');

$invalid = $document;
$invalid['outstanding'] = 1;
$Reject($Encode($invalid), 'A result with an unresolved request was accepted.');

$invalid = $document;
$invalid['accounting'] = false;
$Reject($Encode($invalid), 'A worker-declared accounting failure was accepted.');

$invalid = $document;
$invalid['latency_summary']['p50_ns'] = 2_001_000;
$Reject($Encode($invalid), 'A result with a forged latency percentile was accepted.');

$invalid = $document;
$invalid['latency_summary']['unexpected'] = 1;
$Reject($Encode($invalid), 'A result with an extended latency summary was accepted.');

$invalid = $document;
$invalid['latency_histogram']['count'] = 4;
$Reject($Encode($invalid), 'A result with a malformed latency histogram was accepted.');

$invalid = $document;
$invalid['time_series']['totals']['responses'] = 4;
$Reject($Encode($invalid), 'A result with a malformed time-series total was accepted.');

$invalid = $document;
$invalid['time_series']['buckets'][0]['responses'] = 3;
$invalid['time_series']['totals']['responses'] = 4;
$Reject($Encode($invalid), 'A valid series inconsistent with the result counters was accepted.');

$invalid = $document;
$invalid['rps'] = '1.5';
$Reject($Encode($invalid), 'A textual request rate was accepted as numeric telemetry.');

$invalid = $document;
$invalid['rps'] = 1.51;
$Reject($Encode($invalid), 'A request rate inconsistent with responses and elapsed time was accepted.');

$invalid = $document;
$invalid['latency'] = '9.00ms';
$Reject($Encode($invalid), 'A latency label inconsistent with the measured distribution was accepted.');

$invalid = $document;
$invalid['transfer'] = '151B/s';
$Reject($Encode($invalid), 'A transfer label inconsistent with measured bytes was accepted.');

$invalid = $document;
$invalid['elapsed'] = 3.0;
$invalid['rps'] = 1.0;
$invalid['transfer'] = '100B/s';
$Reject($Encode($invalid), 'An elapsed time inconsistent with the monotonic series window was accepted.');

$invalid = $document;
$invalid['start_lag_ns'] = MeasurementBarrier::MAXIMUM_START_LAG_NS + 1;
$Reject($Encode($invalid), 'A load worker outside the bounded common-origin lag was accepted.');

$invalid = $document;
$invalid['unexpected'] = true;
$Reject($Encode($invalid), 'An unknown result field was accepted.');

$Reject('{invalid-json', 'Malformed worker JSON was accepted.');

return true;
   }
);
