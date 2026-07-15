<?php

declare(strict_types=1);

namespace Bootgly\Benchmarks\HTTP_Server_CLI;

final class WorkerEvidence
{
   public static bool $enabled = true;

   private static ?int $PID = null;
   private static ?string $identity = null;
   /** @var resource|null */
   private static mixed $lease = null;

   /** Register this serving worker before it begins accepting requests. */
   public static function boot(): void
   {
      $token = getenv('BENCHMARK_WARMUP_TOKEN');
      if (!is_string($token) || $token === '') {
         // # Direct/manual bootables have no proof protocol to serve. Keep the
         //   request guard cold instead of parsing evidence headers forever.
         self::$enabled = false;

         return;
      }

      self::register();
   }

   public static function identify(?string $header, ?string $nonce, ?string $seal = null): ?string
   {
      if (self::$enabled === false || $header === null || $header === '') {
         return null;
      }

      // ! Evidence is valid only after this exact process ran its serving-worker
      //   lifecycle hook. Never repair missing lifecycle registration from a
      //   request: a fork that skipped boot() must not inherit its parent's
      //   identity or create a lease from benchmark traffic.
      $PID = getmypid();
      if (
         !is_int($PID)
         || self::$PID !== $PID
         || self::$identity === null
      ) {
         return null;
      }

      $token = getenv('BENCHMARK_WARMUP_TOKEN');
      if (is_string($token) === false || $token === '' || hash_equals($token, $header) === false) {
         return null;
      }
      if ($nonce === null || preg_match('/\A[0-9a-f]{64}\z/D', $nonce) !== 1) {
         return null;
      }

      $acknowledgement = $token . ':' . $nonce . ':' . self::$identity;

      if ($seal !== null && hash_equals($token, $seal)) {
         self::$enabled = false;
      }

      return $acknowledgement;
   }

   /**
    * Register one process-local identity and retain an exclusive lease for the
    * worker lifetime. The persisted metadata contains neither the warmup token
    * nor the raw response identity.
    */
   private static function register(): void
   {
      $PID = getmypid();
      if (!is_int($PID) || $PID < 1) {
         throw new \RuntimeException('Could not resolve the worker evidence process ID.');
      }
      if (self::$PID === $PID && self::$identity !== null) {
         return;
      }

      // ! A fork inherits PHP statics and file descriptors. Close this
      //   process' duplicate of the parent's lease before allocating a distinct
      //   child identity; the parent's own descriptor keeps its lock held.
      if (is_resource(self::$lease)) {
         fclose(self::$lease);
      }
      self::$lease = null;
      self::$PID = $PID;
      self::$identity = null;
      // ! A fork from a sealed serving process inherits the disabled flag.
      //   The distinct PID is a new worker generation and must register/answer
      //   independently rather than remaining invisible behind that flag.
      self::$enabled = true;

      $serverDirectory = getenv('BENCHMARK_SERVER_DIR');
      if (!is_string($serverDirectory) || $serverDirectory === '') {
         self::$identity = $PID . '-' . bin2hex(random_bytes(16));

         return;
      }
      if (!is_dir($serverDirectory) || is_link($serverDirectory)) {
         throw new \RuntimeException('Worker evidence server directory is unavailable or unsafe.');
      }

      $directory = rtrim($serverDirectory, DIRECTORY_SEPARATOR) . '/workers';
      if (
         !is_dir($directory)
         && !@mkdir($directory, 0o700)
         && !is_dir($directory)
      ) {
         throw new \RuntimeException('Could not create the worker evidence directory.');
      }
      if (is_link($directory)) {
         throw new \RuntimeException('Worker evidence directory must not be a symbolic link.');
      }

      for ($attempt = 0; $attempt < 16; $attempt++) {
         $identity = $PID . '-' . bin2hex(random_bytes(16));
         $SHA = hash('sha256', "worker\0{$identity}");
         $fingerprint = 'sha256:' . $SHA;
         $file = $directory . '/worker-' . $SHA . '.lease';
         $Handle = @fopen($file, 'x+b');

         if ($Handle === false) {
            if (file_exists($file) || is_link($file)) {
               continue;
            }

            throw new \RuntimeException('Could not create the worker evidence lease.');
         }

         $registered = false;
         try {
            // ! FrankenPHP workers are threads, so process-global umask()
            //   changes would race. The 0700 parent directory protects the
            //   creation window; set and verify the final file mode directly.
            if (!@chmod($file, 0o600)) {
               throw new \RuntimeException('Could not protect the worker evidence lease.');
            }
            $metadata = fstat($Handle);
            if (!is_array($metadata) || ($metadata['mode'] & 0o777) !== 0o600) {
               throw new \RuntimeException('Worker evidence lease permissions are invalid.');
            }
            if (!flock($Handle, LOCK_EX | LOCK_NB)) {
               throw new \RuntimeException('Could not lock the worker evidence lease.');
            }

            $JSON = json_encode([
               'schema' => 'bootgly.worker-lease',
               'version' => 1,
               'fingerprint' => $fingerprint,
               'pid' => $PID,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $contents = $JSON . "\n";
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
               $written = fwrite($Handle, substr($contents, $offset));
               if ($written === false || $written === 0) {
                  throw new \RuntimeException('Could not write the worker evidence lease.');
               }
               $offset += $written;
            }
            if (!fflush($Handle) || (function_exists('fsync') && !fsync($Handle))) {
               throw new \RuntimeException('Could not sync the worker evidence lease.');
            }

            self::$identity = $identity;
            self::$lease = $Handle;
            $registered = true;

            return;
         }
         finally {
            if (!$registered) {
               fclose($Handle);
               @unlink($file);
            }
         }
      }

      throw new \RuntimeException('Could not allocate a unique worker evidence lease.');
   }
}
