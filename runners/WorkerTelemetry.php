<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — measured load-worker telemetry
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Benchmarks\Runners;


use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Benchmark\Time\Series;
use OverflowException;
use Throwable;


/** Strict, mergeable boundary for one or more aligned load workers. */
final class WorkerTelemetry
{
   public const string SCHEMA = 'bootgly.benchmark-worker.v2';
   public const string RESULT_SCHEMA = 'bootgly.benchmark-result.v2';

   /** @var list<string> */
   private const array FIELDS = [
      'schema',
      'scheduled',
      'sent',
      'responses',
      'informational',
      'outstanding',
      'statuses',
      'failures',
      'censors',
      'write_failures',
      'write_censors',
      'connection_failures',
      'partial_writes',
      'accounting',
      'bytes_read',
      'elapsed',
      'start_lag_ns',
      'latency_summary',
      'latency_histogram',
      'time_series',
   ];

   public int $scheduled;
   public int $sent;
   public int $responses;
   public int $informational;
   public int $outstanding;
   /** @var array<int,int> */
   public array $statuses;
   /** @var array<string,int> */
   public array $failures;
   /** @var array<string,int> */
   public array $censors;
   /** @var array<string,int> */
   public array $writeFailures;
   /** @var array<string,int> */
   public array $writeCensors;
   public int $connectionFailures;
   public int $partialWrites;
   public bool $accounting;
   public int $bytesRead;
   public float $elapsed;
   public int $startLagNS;
   public Histogram $Histogram;
   public Series $Series;


   /** Import and revalidate one untrusted worker document. */
   public static function import (mixed $data, float $expectedElapsed): null|self
   {
      if (!is_array($data) || count($data) !== count(self::FIELDS)) {
         return null;
      }
      foreach (self::FIELDS as $field) {
         if (!array_key_exists($field, $data)) {
            return null;
         }
      }
      if ($data['schema'] !== self::SCHEMA) {
         return null;
      }

      $elapsed = $data['elapsed'] ?? null;
      $accountingReported = $data['accounting'] ?? null;
      $statuses = $data['statuses'] ?? null;
      $failures = $data['failures'] ?? null;
      $censors = $data['censors'] ?? null;
      $writeFailures = $data['write_failures'] ?? null;
      $writeCensors = $data['write_censors'] ?? null;
      $latencySummary = $data['latency_summary'] ?? null;
      $histogramData = $data['latency_histogram'] ?? null;
      $seriesData = $data['time_series'] ?? null;

      if (
         (!is_int($elapsed) && !is_float($elapsed))
         || !is_finite((float) $elapsed)
         || (float) $elapsed !== $expectedElapsed
         || !is_bool($accountingReported)
         || !is_array($statuses)
         || !is_array($failures)
         || !is_array($censors)
         || !is_array($writeFailures)
         || !is_array($writeCensors)
         || !is_array($latencySummary)
         || !is_array($histogramData)
         || !is_array($seriesData)
      ) {
         return null;
      }

      $fields = [
         'scheduled', 'sent', 'responses', 'informational', 'outstanding',
         'connection_failures', 'partial_writes', 'bytes_read', 'start_lag_ns',
      ];
      foreach ($fields as $field) {
         if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
            return null;
         }
      }
      foreach ($statuses as $status => $count) {
         if (!is_int($status) || $status < 100 || $status > 599 || !is_int($count) || $count < 0) {
            return null;
         }
      }
      foreach ([$failures, $censors, $writeFailures, $writeCensors] as $counts) {
         foreach ($counts as $reason => $count) {
            if (!is_string($reason) || $reason === '' || !is_int($count) || $count < 0) {
               return null;
            }
         }
      }

      try {
         $Histogram = Histogram::import($histogramData);
         $Series = Series::import($seriesData);
      }
      catch (Throwable) {
         return null;
      }

      $summary = $Histogram->inspect();
      $series = $Series->export();
      $totals = $series['totals'];
      $seriesOriginNS = (int) $series['origin_ns'];
      $seriesDeadlineNS = (int) $series['deadline_ns'];
      $seriesElapsed = (float) ($seriesDeadlineNS - $seriesOriginNS) / 1_000_000_000.0;
      if ($latencySummary !== $summary || $seriesElapsed !== $expectedElapsed) {
         return null;
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
      /** @var int $connectionFailures */
      $connectionFailures = $data['connection_failures'];
      /** @var int $partialWrites */
      $partialWrites = $data['partial_writes'];
      /** @var int $bytesRead */
      $bytesRead = $data['bytes_read'];
      /** @var int $startLagNS */
      $startLagNS = $data['start_lag_ns'];

      $failed = array_sum($failures);
      $censored = array_sum($censors);
      $writeFailed = array_sum($writeFailures);
      $writeCensored = array_sum($writeCensors);
      $accounting = $accountingReported
         && $connectionFailures === 0
         && $outstanding === 0
         && $scheduled === $sent + $writeFailed + $writeCensored
         && $sent === $responses + $failed + $censored
         && $responses === array_sum($statuses)
         && $summary['fidelity'] === true
         && $summary['count'] === $responses
         && $totals['sent'] === $sent
         && $totals['responses'] === $responses
         && $totals['failed'] === $failed
         && $totals['censored'] === $censored
         && $totals['write_failed'] === $writeFailed
         && $totals['write_censored'] === $writeCensored
         && $totals['bytes_read'] === $bytesRead;

      $Telemetry = new self;
      $Telemetry->scheduled = $scheduled;
      $Telemetry->sent = $sent;
      $Telemetry->responses = $responses;
      $Telemetry->informational = $informational;
      $Telemetry->outstanding = $outstanding;
      $Telemetry->statuses = $statuses;
      $Telemetry->failures = $failures;
      $Telemetry->censors = $censors;
      $Telemetry->writeFailures = $writeFailures;
      $Telemetry->writeCensors = $writeCensors;
      $Telemetry->connectionFailures = $connectionFailures;
      $Telemetry->partialWrites = $partialWrites;
      $Telemetry->accounting = $accounting;
      $Telemetry->bytesRead = $bytesRead;
      $Telemetry->elapsed = (float) $elapsed;
      $Telemetry->startLagNS = $startLagNS;
      $Telemetry->Histogram = $Histogram;
      $Telemetry->Series = $Series;

      return $Telemetry;
   }

   /** Merge a compatible child through temporary copies so failure is atomic. */
   public function merge (self $Telemetry): void
   {
      if ($Telemetry->elapsed !== $this->elapsed) {
         throw new \InvalidArgumentException('Load-worker measurement durations are incompatible.');
      }

      $Histogram = Histogram::import($this->Histogram->export());
      $Series = Series::import($this->Series->export());
      $Histogram->merge($Telemetry->Histogram);
      $Series->merge($Telemetry->Series);

      $scheduled = self::sum($this->scheduled, $Telemetry->scheduled);
      $sent = self::sum($this->sent, $Telemetry->sent);
      $responses = self::sum($this->responses, $Telemetry->responses);
      $informational = self::sum($this->informational, $Telemetry->informational);
      $outstanding = self::sum($this->outstanding, $Telemetry->outstanding);
      $connectionFailures = self::sum($this->connectionFailures, $Telemetry->connectionFailures);
      $partialWrites = self::sum($this->partialWrites, $Telemetry->partialWrites);
      $bytesRead = self::sum($this->bytesRead, $Telemetry->bytesRead);
      $statuses = self::combine($this->statuses, $Telemetry->statuses);
      $failures = self::combine($this->failures, $Telemetry->failures);
      $censors = self::combine($this->censors, $Telemetry->censors);
      $writeFailures = self::combine($this->writeFailures, $Telemetry->writeFailures);
      $writeCensors = self::combine($this->writeCensors, $Telemetry->writeCensors);

      /** @var array<int,int> $statuses */
      /** @var array<string,int> $failures */
      /** @var array<string,int> $censors */
      /** @var array<string,int> $writeFailures */
      /** @var array<string,int> $writeCensors */
      $this->scheduled = $scheduled;
      $this->sent = $sent;
      $this->responses = $responses;
      $this->informational = $informational;
      $this->outstanding = $outstanding;
      $this->connectionFailures = $connectionFailures;
      $this->partialWrites = $partialWrites;
      $this->bytesRead = $bytesRead;
      $this->startLagNS = max($this->startLagNS, $Telemetry->startLagNS);
      $this->statuses = $statuses;
      $this->failures = $failures;
      $this->censors = $censors;
      $this->writeFailures = $writeFailures;
      $this->writeCensors = $writeCensors;
      $this->Histogram = $Histogram;
      $this->Series = $Series;
      $this->accounting = $this->accounting
         && $Telemetry->accounting
         && $this->check();
   }

   /** Export the deterministic child-worker schema. */
   /** @return array<string,mixed> */
   public function export (): array
   {
      ksort($this->statuses);
      ksort($this->failures);
      ksort($this->censors);
      ksort($this->writeFailures);
      ksort($this->writeCensors);

      return [
         'schema' => self::SCHEMA,
         'scheduled' => $this->scheduled,
         'sent' => $this->sent,
         'responses' => $this->responses,
         'informational' => $this->informational,
         'outstanding' => $this->outstanding,
         'statuses' => $this->statuses,
         'failures' => $this->failures,
         'censors' => $this->censors,
         'write_failures' => $this->writeFailures,
         'write_censors' => $this->writeCensors,
         'connection_failures' => $this->connectionFailures,
         'partial_writes' => $this->partialWrites,
         'accounting' => $this->accounting && $this->check(),
         'bytes_read' => $this->bytesRead,
         'elapsed' => $this->elapsed,
         'start_lag_ns' => $this->startLagNS,
         'latency_summary' => $this->Histogram->inspect(),
         'latency_histogram' => $this->Histogram->export(),
         'time_series' => $this->Series->export(),
      ];
   }

   /** Render the exact final JSON contract consumed by the benchmark runner. */
   public function render (): string
   {
      $accounting = $this->accounting && $this->check();
      $summary = $this->Histogram->inspect();
      $sumNS = $summary['sum_ns'];
      $averageNS = $this->responses > 0 && is_int($sumNS)
         ? $sumNS / $this->responses
         : null;
      $RPS = $accounting ? $this->responses / $this->elapsed : null;
      $transfer = $this->bytesRead / $this->elapsed;

      $latency = null;
      if ($averageNS !== null) {
         $latency = $averageNS >= 1_000_000
            ? number_format($averageNS / 1_000_000, 2) . 'ms'
            : number_format($averageNS / 1_000, 2) . 'us';
      }

      if ($transfer >= 1_073_741_824) {
         $transferText = number_format($transfer / 1_073_741_824, 2) . 'GB/s';
      }
      else if ($transfer >= 1_048_576) {
         $transferText = number_format($transfer / 1_048_576, 2) . 'MB/s';
      }
      else if ($transfer >= 1_024) {
         $transferText = number_format($transfer / 1_024, 2) . 'KB/s';
      }
      else {
         $transferText = number_format($transfer, 0) . 'B/s';
      }

      return json_encode([
         'schema' => self::RESULT_SCHEMA,
         'rps' => $RPS !== null ? round($RPS, 2) : null,
         'latency' => $latency,
         'transfer' => $transferText,
         'elapsed' => $this->elapsed,
         'scheduled' => $this->scheduled,
         'sent' => $this->sent,
         'responses' => $this->responses,
         'informational' => $this->informational,
         'outstanding' => $this->outstanding,
         'failed' => array_sum($this->failures),
         'censored' => array_sum($this->censors),
         'write_failed' => array_sum($this->writeFailures),
         'write_censored' => array_sum($this->writeCensors),
         'connection_failed' => $this->connectionFailures,
         'accounting' => $accounting,
         'statuses' => (object) $this->statuses,
         'failures' => (object) $this->failures,
         'censors' => (object) $this->censors,
         'write_failures' => (object) $this->writeFailures,
         'write_censors' => (object) $this->writeCensors,
         'partial_writes' => $this->partialWrites,
         'start_lag_ns' => $this->startLagNS,
         'latency_summary' => $summary + ['average_ns' => $averageNS],
         'latency_histogram' => $this->Histogram->export(),
         'time_series' => $this->Series->export(),
      ], JSON_THROW_ON_ERROR);
   }

   /** Recheck every cross-field equation after import or merge. */
   private function check (): bool
   {
      $summary = $this->Histogram->inspect();
      $totals = $this->Series->export()['totals'];
      $failed = array_sum($this->failures);
      $censored = array_sum($this->censors);
      $writeFailed = array_sum($this->writeFailures);
      $writeCensored = array_sum($this->writeCensors);

      return $this->connectionFailures === 0
         && $this->outstanding === 0
         && $this->scheduled === $this->sent + $writeFailed + $writeCensored
         && $this->sent === $this->responses + $failed + $censored
         && $this->responses === array_sum($this->statuses)
         && $summary['fidelity'] === true
         && $summary['count'] === $this->responses
         && $totals['sent'] === $this->sent
         && $totals['responses'] === $this->responses
         && $totals['failed'] === $failed
         && $totals['censored'] === $censored
         && $totals['write_failed'] === $writeFailed
         && $totals['write_censored'] === $writeCensored
         && $totals['bytes_read'] === $this->bytesRead;
   }

   /**
    * @param array<int|string,int> $left
    * @param array<int|string,int> $right
    * @return array<int|string,int>
    */
   private static function combine (array $left, array $right): array
   {
      foreach ($right as $key => $count) {
         $left[$key] = self::sum($left[$key] ?? 0, $count);
      }

      return $left;
   }

   /** Add non-negative counters without integer wraparound. */
   private static function sum (int $left, int $right): int
   {
      if ($right > PHP_INT_MAX - $left) {
         throw new OverflowException('Load-worker telemetry counter overflow.');
      }

      return $left + $right;
   }
}
