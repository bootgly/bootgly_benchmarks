<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP server readiness evidence
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Benchmarks\Runners;


final class ServerReadiness
{
   /**
    * Inspect one Linux PID artifact and return a stable process identity.
    *
    * The PID alone is not an identity because the kernel may reuse it. The
    * process start time and expected command are part of the ownership proof.
    */
   public static function inspect (?string $PIDFile, string $command): ?string
   {
      if (!\is_string($PIDFile) || !\is_file($PIDFile)) {
         return null;
      }

      $value = \trim((string) @\file_get_contents($PIDFile));
      if ($value === '' || !\ctype_digit($value) || (int) $value < 2) {
         return null;
      }

      $PID = (int) $value;
      $status = @\file_get_contents("/proc/{$PID}/stat");
      $arguments = @\file_get_contents("/proc/{$PID}/cmdline");
      if ($status === false || $arguments === false || $arguments === '') {
         return null;
      }

      $end = \strrpos($status, ') ');
      if ($end === false) {
         return null;
      }

      $fields = \preg_split('/\s+/', \trim(\substr($status, $end + 2)));
      if (
         !\is_array($fields)
         || ($fields[0] ?? null) === 'Z'
         || !isset($fields[19])
         || !\ctype_digit($fields[19])
      ) {
         return null;
      }

      $arguments = \str_replace("\0", ' ', $arguments);
      if (!\str_contains($arguments, $command)) {
         return null;
      }

      return "{$PID}:{$fields[19]}";
   }

   /** Probe the HTTP application, not merely an open TCP listener. */
   public static function probe (int $port): bool
   {
      $socket = @\fsockopen('127.0.0.1', $port, $error, $message, 1);
      if ($socket === false) {
         return false;
      }

      \stream_set_timeout($socket, 2);
      @\fwrite(
         $socket,
         "GET / HTTP/1.0\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n",
      );
      $response = @\fread($socket, 16);
      \fclose($socket);

      return \is_string($response)
         && \strncmp($response, 'HTTP/', 5) === 0
         && \preg_match('#^HTTP/\S+ 50[234]#', $response) !== 1;
   }
}
