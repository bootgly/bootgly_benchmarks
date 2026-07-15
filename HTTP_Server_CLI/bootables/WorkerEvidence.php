<?php

declare(strict_types=1);

namespace Bootgly\Benchmarks\HTTP_Server_CLI;

final class WorkerEvidence
{
   public static bool $enabled = true;

   public static function identify(?string $header, ?string $seal = null): ?string
   {
      if (self::$enabled === false || $header === null || $header === '') {
         return null;
      }

      $token = getenv('BENCHMARK_WARMUP_TOKEN');
      if (is_string($token) === false || $token === '' || hash_equals($token, $header) === false) {
         return null;
      }

      static $PID = null;
      static $identity = null;

      $currentPID = getmypid();
      if ($identity === null || $PID !== $currentPID) {
         $PID = $currentPID;
         $identity = $currentPID . '-' . bin2hex(random_bytes(16));
      }

      $acknowledgement = $token . ':' . $identity;

      if ($seal !== null && hash_equals($token, $seal)) {
         self::$enabled = false;
      }

      return $acknowledgement;
   }
}
