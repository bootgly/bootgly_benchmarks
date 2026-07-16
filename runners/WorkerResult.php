<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — strict load-worker result boundary
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Benchmarks\Runners;


use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Time\Series;
use Throwable;

require_once __DIR__ . '/MeasurementBarrier.php';


/** Revalidate the complete worker telemetry contract before reporting RPS. */
final class WorkerResult
{
   public const string SCHEMA = 'bootgly.benchmark-result.v2';


   public static function parse (string $output): Result
   {
      /** @var array<string,mixed>|null $data */
      $data = json_decode($output, true);
      if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA) {
         return new Result(accounting: false);
      }

      $fields = [
         'schema', 'rps', 'latency', 'transfer', 'elapsed', 'scheduled',
         'sent', 'responses', 'informational', 'outstanding', 'failed',
         'censored', 'write_failed', 'write_censored', 'connection_failed',
         'accounting', 'statuses', 'failures', 'censors', 'write_failures',
         'write_censors', 'partial_writes', 'start_lag_ns', 'latency_summary',
         'latency_histogram', 'time_series',
      ];
      if (count($data) !== count($fields)) {
         return new Result(accounting: false);
      }
      foreach ($fields as $field) {
         if (!array_key_exists($field, $data)) {
            return new Result(accounting: false);
         }
      }

      $accountingReported = $data['accounting'] ?? null;
      $latency = $data['latency'] ?? null;
      $transfer = $data['transfer'] ?? null;
      $elapsed = $data['elapsed'] ?? null;
      $RPS = $data['rps'] ?? null;
      $statuses = $data['statuses'] ?? null;
      $failures = $data['failures'] ?? null;
      $censors = $data['censors'] ?? null;
      $writeFailures = $data['write_failures'] ?? null;
      $writeCensors = $data['write_censors'] ?? null;
      $histogramData = $data['latency_histogram'] ?? null;
      $seriesData = $data['time_series'] ?? null;
      $reportedSummary = $data['latency_summary'] ?? null;

      if (
         !is_bool($accountingReported)
         || (!is_string($latency) && $latency !== null)
         || !is_string($transfer)
         || (!is_int($elapsed) && !is_float($elapsed))
         || !is_finite((float) $elapsed)
         || (float) $elapsed <= 0
         || (!is_int($RPS) && !is_float($RPS))
         || !is_finite((float) $RPS)
         || (float) $RPS < 0
         || !is_array($statuses)
         || !is_array($failures)
         || !is_array($censors)
         || !is_array($writeFailures)
         || !is_array($writeCensors)
         || !is_array($histogramData)
         || !is_array($seriesData)
         || !is_array($reportedSummary)
      ) {
         return new Result(accounting: false);
      }

      foreach ($statuses as $status => $count) {
         if (!is_int($status) || $status < 100 || $status > 599 || !is_int($count) || $count < 0) {
            return new Result(accounting: false);
         }
      }
      foreach ([$failures, $censors, $writeFailures, $writeCensors] as $counts) {
         foreach ($counts as $reason => $count) {
            if (!is_string($reason) || $reason === '' || !is_int($count) || $count < 0) {
               return new Result(accounting: false);
            }
         }
      }

      $counters = [
         'scheduled', 'sent', 'responses', 'informational', 'outstanding',
         'failed', 'censored', 'write_failed', 'write_censored',
         'connection_failed', 'partial_writes', 'start_lag_ns',
      ];
      foreach ($counters as $counter) {
         if (!is_int($data[$counter] ?? null) || $data[$counter] < 0) {
            return new Result(accounting: false);
         }
      }

      try {
         $Histogram = Histogram::import($histogramData);
         $Series = Series::import($seriesData);
      }
      catch (Throwable) {
         return new Result(accounting: false);
      }

      /** @var array<int,int> $statuses */
      /** @var array<string,int> $failures */
      /** @var array<string,int> $censors */
      /** @var array<string,int> $writeFailures */
      /** @var array<string,int> $writeCensors */
      /** @var int $scheduled */
      $scheduled = $data['scheduled'];
      /** @var int $sent */
      $sent = $data['sent'];
      /** @var int $responses */
      $responses = $data['responses'];
      /** @var int $informational */
      $informational = $data['informational'];
      /** @var int $outstanding */
      $outstanding = $data['outstanding'];
      /** @var int $failedReported */
      $failedReported = $data['failed'];
      /** @var int $censoredReported */
      $censoredReported = $data['censored'];
      /** @var int $writeFailedReported */
      $writeFailedReported = $data['write_failed'];
      /** @var int $writeCensoredReported */
      $writeCensoredReported = $data['write_censored'];
      /** @var int $connectionFailed */
      $connectionFailed = $data['connection_failed'];
      /** @var int $partialWrites */
      $partialWrites = $data['partial_writes'];
      /** @var int $startLagNS */
      $startLagNS = $data['start_lag_ns'];

      $summary = $Histogram->inspect();
      $series = $Series->export();
      $seriesTotals = $series['totals'];
      $failed = array_sum($failures);
      $censored = array_sum($censors);
      $writeFailed = array_sum($writeFailures);
      $writeCensored = array_sum($writeCensors);
      $sumNS = $summary['sum_ns'];
      $averageNS = $responses > 0 && is_int($sumNS) ? $sumNS / $responses : null;
      $canonicalSummary = $summary + ['average_ns' => $averageNS];
      $seriesOriginNS = (int) $series['origin_ns'];
      $seriesDeadlineNS = (int) $series['deadline_ns'];
      $seriesElapsed = (float) (($seriesDeadlineNS - $seriesOriginNS) / 1_000_000_000);
      $expectedRPS = round($responses / (float) $elapsed, 2);
      $expectedLatency = self::format($averageNS);
      $expectedTransfer = self::scale($seriesTotals['bytes_read'] / (float) $elapsed);

      $structural = true;
      foreach ($summary as $key => $value) {
         $structural = $structural
            && array_key_exists($key, $reportedSummary)
            && $reportedSummary[$key] === $value;
      }
      $reportedAverage = $reportedSummary['average_ns'] ?? null;
      $structural = $structural
         && ($reportedAverage === null || is_int($reportedAverage) || is_float($reportedAverage))
         && ($averageNS === null
            ? $reportedAverage === null
            : is_numeric($reportedAverage) && abs((float) $reportedAverage - $averageNS) < 0.000_001)
         && count($reportedSummary) === count($canonicalSummary)
         && $summary['fidelity'] === true
         && $summary['count'] === $responses
         && $seriesElapsed === (float) $elapsed
         && $startLagNS <= MeasurementBarrier::MAXIMUM_START_LAG_NS
         && (float) $RPS === $expectedRPS
         && $latency === $expectedLatency
         && $transfer === $expectedTransfer
         && $seriesTotals['sent'] === $sent
         && $seriesTotals['responses'] === $responses
         && $seriesTotals['failed'] === $failed
         && $seriesTotals['censored'] === $censored
         && $seriesTotals['write_failed'] === $writeFailed
         && $seriesTotals['write_censored'] === $writeCensored
         && $seriesTotals['bytes_read'] >= 0;

      $accounting = $structural
         && $accountingReported === true
         && $connectionFailed === 0
         && $outstanding === 0
         && $scheduled === $sent + $writeFailed + $writeCensored
         && $sent === $responses + $failed + $censored
         && $failedReported === $failed
         && $censoredReported === $censored
         && $writeFailedReported === $writeFailed
         && $writeCensoredReported === $writeCensored
         && $responses === array_sum($statuses);

      return new Result(
         rps: $accounting ? (float) $RPS : null,
         latency: $accounting ? $latency : null,
         transfer: $transfer,
         scheduled: $scheduled,
         sent: $sent,
         responses: $responses,
         informational: $informational,
         outstanding: $outstanding,
         failed: $failedReported,
         writeFailed: $writeFailedReported,
         connectionFailed: $connectionFailed,
         partialWrites: $partialWrites,
         accounting: $accounting,
         statuses: $statuses,
         failures: $failures,
         writeFailures: $writeFailures,
         censored: $censoredReported,
         writeCensored: $writeCensoredReported,
         censors: $censors,
         writeCensors: $writeCensors,
         latencySummary: $canonicalSummary,
         latencyHistogram: $Histogram->export(),
         timeSeries: $series,
      );
   }

   /** Format one exact average nanosecond latency like the worker renderer. */
   private static function format (null|float $averageNS): null|string
   {
      if ($averageNS === null) {
         return null;
      }

      return $averageNS >= 1_000_000
         ? number_format($averageNS / 1_000_000, 2) . 'ms'
         : number_format($averageNS / 1_000, 2) . 'us';
   }

   /** Scale one byte-per-second rate like the worker renderer. */
   private static function scale (float $transfer): string
   {
      if ($transfer >= 1_073_741_824) {
         return number_format($transfer / 1_073_741_824, 2) . 'GB/s';
      }
      if ($transfer >= 1_048_576) {
         return number_format($transfer / 1_048_576, 2) . 'MB/s';
      }
      if ($transfer >= 1_024) {
         return number_format($transfer / 1_024, 2) . 'KB/s';
      }

      return number_format($transfer, 0) . 'B/s';
   }
}
