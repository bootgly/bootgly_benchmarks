<?php

use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Benchmark\Time\Series;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\Benchmarks\Runners\WorkerResult;
use Bootgly\Benchmarks\Runners\WorkerTelemetry;


require_once dirname(__DIR__) . '/WorkerResult.php';
require_once dirname(__DIR__) . '/WorkerTelemetry.php';

return new Specification(
   description: 'It should strictly import and atomically merge worker telemetry',
   test: static function (): bool
   {
$Check = static function (bool $condition, string $message): void {
   if ($condition === false) {
      throw new AssertionError($message);
   }
};
$Build = static function (
   array $latencies,
   array $events,
   int $originNS,
   int $startLagNS,
   null|int $highestTrackableNS = null,
): array {
   $Histogram = $highestTrackableNS === null
      ? new Histogram
      : new Histogram($highestTrackableNS);
   foreach ($latencies as $latencyNS) {
      $Histogram->record($latencyNS);
   }

   $Series = new Series($originNS, $originNS + 2 * Series::BUCKET_NS);
   $sent = 0;
   $responses = 0;
   $bytesRead = 0;
   foreach ($events as $second => $metrics) {
      $Series->record($originNS + $second * Series::BUCKET_NS, $metrics);
      $sent += $metrics['sent'] ?? 0;
      $responses += $metrics['responses'] ?? 0;
      $bytesRead += $metrics['bytes_read'] ?? 0;
   }

   return [
      'schema' => WorkerTelemetry::SCHEMA,
      'scheduled' => $sent,
      'sent' => $sent,
      'responses' => $responses,
      'informational' => 0,
      'outstanding' => 0,
      'statuses' => [200 => $responses],
      'failures' => [],
      'censors' => [],
      'write_failures' => [],
      'write_censors' => [],
      'connection_failures' => 0,
      'partial_writes' => 0,
      'accounting' => true,
      'bytes_read' => $bytesRead,
      'elapsed' => 2.0,
      'start_lag_ns' => $startLagNS,
      'latency_summary' => $Histogram->inspect(),
      'latency_histogram' => $Histogram->export(),
      'time_series' => $Series->export(),
   ];
};

$originNS = 9_200_000_000_000_000;
$leftDocument = $Build(
   [1_000_000, 1_000_000, 1_000_000],
   [
      0 => ['sent' => 2, 'responses' => 2, 'bytes_read' => 200],
      1 => ['sent' => 1, 'responses' => 1, 'bytes_read' => 100],
   ],
   $originNS,
   100,
);
$rightDocument = $Build(
   [9_000_000],
   [
      1 => ['sent' => 1, 'responses' => 1, 'bytes_read' => 400],
   ],
   $originNS,
   200,
);

try {
   $FixtureHistogram = Histogram::import($leftDocument['latency_histogram']);
   $FixtureSeries = Series::import($leftDocument['time_series']);
}
catch (Throwable $Throwable) {
   throw new AssertionError('The valid left fixture failed primitive import: ' . $Throwable->getMessage());
}
$fixtureSeries = $FixtureSeries->export();
$fixtureElapsed = (
   (int) $fixtureSeries['deadline_ns'] - (int) $fixtureSeries['origin_ns']
) / 1_000_000_000;
$Check(
   $FixtureHistogram->inspect() === $leftDocument['latency_summary'],
   'The strict left fixture changed during histogram import.',
);
$Check(
   (float) $fixtureElapsed === 2.0,
   'The strict left fixture has a mismatched series duration: '
      . $fixtureSeries['origin_ns'] . '..' . $fixtureSeries['deadline_ns']
      . " => {$fixtureElapsed}s.",
);
$Check(
   array_keys($leftDocument['statuses']) === [200],
   'The strict left fixture has a non-integer response status.',
);

$Left = WorkerTelemetry::import($leftDocument, 2.0);
$Right = WorkerTelemetry::import($rightDocument, 2.0);
if (!$Left instanceof WorkerTelemetry) {
   throw new AssertionError('The complete left worker document was rejected.');
}
if (!$Right instanceof WorkerTelemetry) {
   throw new AssertionError('The complete right worker document was rejected.');
}
$Check(
   $Left->accounting
      && $Right->accounting
      && $Left->export() === $leftDocument
      && $Right->export() === $rightDocument,
   'Strict worker import did not preserve the validated child contract.',
);

$invalid = $leftDocument;
$invalid['latency_summary']['p50_ns'] = 9_000_000;
$Check(
   WorkerTelemetry::import($invalid, 2.0) === null,
   'A worker with a forged latency summary was accepted.',
);
$Check(
   WorkerTelemetry::import($leftDocument, 3.0) === null,
   'A worker outside the expected measurement duration was accepted.',
);
$invalid = $leftDocument;
$invalid['unexpected'] = true;
$Check(
   WorkerTelemetry::import($invalid, 2.0) === null,
   'A worker with an unknown top-level field was accepted.',
);
$invalid = $leftDocument;
$invalid['time_series']['totals']['responses']++;
$Check(
   WorkerTelemetry::import($invalid, 2.0) === null,
   'A worker with inconsistent one-second totals was accepted.',
);

$leftP50NS = $Left->Histogram->inspect()['p50_ns'];
$rightP50NS = $Right->Histogram->inspect()['p50_ns'];
$Left->merge($Right);

$ExpectedHistogram = new Histogram;
foreach ([1_000_000, 1_000_000, 1_000_000, 9_000_000] as $latencyNS) {
   $ExpectedHistogram->record($latencyNS);
}
$ExpectedSeries = new Series($originNS, $originNS + 2 * Series::BUCKET_NS);
$ExpectedSeries->record($originNS, [
   'sent' => 2,
   'responses' => 2,
   'bytes_read' => 200,
]);
$ExpectedSeries->record($originNS + Series::BUCKET_NS, [
   'sent' => 2,
   'responses' => 2,
   'bytes_read' => 500,
]);
$summary = $Left->Histogram->inspect();
$naiveP50NS = is_int($leftP50NS) && is_int($rightP50NS)
   ? intdiv($leftP50NS + $rightP50NS, 2)
   : null;

$Check(
   $Left->scheduled === 4
      && $Left->sent === 4
      && $Left->responses === 4
      && $Left->statuses === [200 => 4]
      && $Left->bytesRead === 700
      && $Left->startLagNS === 200
      && $Left->accounting,
   'Merged scalar accounting did not retain the exact child totals.',
);
$Check(
   $Left->Histogram->export() === $ExpectedHistogram->export()
      && $summary === $ExpectedHistogram->inspect()
      && $summary['p50_ns'] !== $naiveP50NS,
   'Latency distributions were not merged atomically, or child percentiles were averaged.',
);
$Check(
   $Left->Series->export() === $ExpectedSeries->export(),
   'Aligned one-second buckets were not merged counter by counter.',
);

$rendered = $Left->render();
$document = json_decode($rendered, true, flags: JSON_THROW_ON_ERROR);
$Check(
   array_keys($document) === [
      'schema', 'rps', 'latency', 'transfer', 'elapsed', 'scheduled', 'sent',
      'responses', 'informational', 'outstanding', 'failed', 'censored',
      'write_failed', 'write_censored', 'connection_failed', 'accounting',
      'statuses', 'failures', 'censors', 'write_failures', 'write_censors',
      'partial_writes', 'start_lag_ns', 'latency_summary',
      'latency_histogram', 'time_series',
   ]
      && $document['schema'] === WorkerTelemetry::RESULT_SCHEMA
      && $document['rps'] === 2
      && $document['latency'] === '3.00ms'
      && $document['transfer'] === '350B/s'
      && $document['latency_summary'] === $summary + ['average_ns' => 3_000_000]
      && $document['latency_histogram'] === $ExpectedHistogram->export()
      && $document['time_series'] === $ExpectedSeries->export(),
   'The merged telemetry did not render the exact final runner contract.',
);
$Result = WorkerResult::parse($rendered);
$Check(
   $Result->accounting
      && $Result->responses === 4
      && $Result->rps === 2.0
      && $Result->latencySummary === $summary + ['average_ns' => 3_000_000],
   'The rendered final contract was rejected by its strict consumer.',
);

$histogramMismatchDocument = $Build(
   [2_000_000],
   [0 => ['sent' => 1, 'responses' => 1, 'bytes_read' => 50]],
   $originNS,
   300,
   120_000_000_000,
);
$HistogramMismatch = WorkerTelemetry::import($histogramMismatchDocument, 2.0);
if (!$HistogramMismatch instanceof WorkerTelemetry) {
   throw new AssertionError('The valid histogram-mismatch fixture was rejected before merge.');
}
$before = $Left->export();
$rejected = false;
try {
   $Left->merge($HistogramMismatch);
}
catch (InvalidArgumentException) {
   $rejected = true;
}
$Check(
   $rejected && $Left->export() === $before,
   'An incompatible histogram was accepted or partially mutated the aggregate.',
);

$seriesMismatchDocument = $Build(
   [2_000_000],
   [0 => ['sent' => 1, 'responses' => 1, 'bytes_read' => 50]],
   $originNS + 10 * Series::BUCKET_NS,
   300,
);
$SeriesMismatch = WorkerTelemetry::import($seriesMismatchDocument, 2.0);
if (!$SeriesMismatch instanceof WorkerTelemetry) {
   throw new AssertionError('The valid series-mismatch fixture was rejected before merge.');
}
$before = $Left->export();
$rejected = false;
try {
   $Left->merge($SeriesMismatch);
}
catch (InvalidArgumentException) {
   $rejected = true;
}
$Check(
   $rejected && $Left->export() === $before,
   'An unaligned time series was accepted or partially mutated the aggregate.',
);

return true;
   }
);
