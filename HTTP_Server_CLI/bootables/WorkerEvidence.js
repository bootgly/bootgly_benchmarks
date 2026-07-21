/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Worker evidence (JS runtimes)
 * --------------------------------------------------------------------------
 * ESM port of bootables/WorkerEvidence.php for Node.js and Bun opponents.
 * The protocol is identical: each serving worker registers one process-local
 * identity, retains an exclusive flock() lease for its whole lifetime, and
 * acknowledges warmup probes with `X-Bootgly-Benchmark-Worker` until sealed.
 *
 * flock(2) is not exposed by either runtime's standard library, so it is
 * reached through FFI: `bun:ffi` on Bun (zero dependencies) and `koffi`
 * (prebuilt, resolved from the entry script's node_modules) on Node.js.
 * The lock MUST be held by the worker process itself — an external helper
 * would survive the worker's death and falsify the generation evidence the
 * runner probes via flock(LOCK_EX|LOCK_NB) + /proc liveness.
 */

import { createHash, randomBytes, timingSafeEqual } from 'node:crypto';
import {
   closeSync, existsSync, fchmodSync, fstatSync, fsyncSync,
   lstatSync, mkdirSync, openSync, unlinkSync, writeSync,
} from 'node:fs';

const LOCK_EX = 2;
const LOCK_NB = 4;

// ! Platform flock(2) — bun:ffi on Bun, koffi on Node.js.
const flock = await (async () => {
   if (typeof Bun !== 'undefined') {
      const { dlopen, FFIType, suffix } = await import('bun:ffi');
      const libc = dlopen(`libc.${suffix}.6`, {
         flock: { args: [FFIType.i32, FFIType.i32], returns: FFIType.i32 },
      });

      return (fd, operation) => libc.symbols.flock(fd, operation);
   }

   const { createRequire } = await import('node:module');
   // ? koffi lives in the entry bootable's own node_modules
   const require = createRequire(process.argv[1] ?? `${process.cwd()}/`);
   const libc = require('koffi').load('libc.so.6');
   const nativeFlock = libc.func('int flock(int fd, int operation)');

   return (fd, operation) => nativeFlock(fd, operation);
})();

function safeEqual (known, candidate) {
   const a = Buffer.from(known);
   const b = Buffer.from(candidate);

   return a.length === b.length && timingSafeEqual(a, b);
}

const WorkerEvidence = {
   enabled: true,

   _PID: null,
   _identity: null,
   _lease: null,

   /** Register this serving worker before it begins accepting requests. */
   boot () {
      const token = process.env.BENCHMARK_WARMUP_TOKEN;
      if (typeof token !== 'string' || token === '') {
         // # Direct/manual bootables have no proof protocol to serve. Keep the
         //   request guard cold instead of parsing evidence headers forever.
         this.enabled = false;

         return;
      }

      this._register();
   },

   /**
    * Acknowledge one warmup probe: `token:nonce:identity`, or null when the
    * probe is invalid or evidence is disabled. A matching seal disables
    * further evidence after this acknowledgement.
    */
   identify (header, nonce, seal = null) {
      if (this.enabled === false || header == null || header === '') {
         return null;
      }

      // ! Evidence is valid only after this exact process ran its lifecycle
      //   boot. A process that skipped boot() must not answer.
      if (this._PID !== process.pid || this._identity === null) {
         return null;
      }

      const token = process.env.BENCHMARK_WARMUP_TOKEN;
      if (typeof token !== 'string' || token === '' || safeEqual(token, header) === false) {
         return null;
      }
      if (nonce == null || /^[0-9a-f]{64}$/.test(nonce) === false) {
         return null;
      }

      const acknowledgement = `${token}:${nonce}:${this._identity}`;

      if (seal != null && safeEqual(token, seal)) {
         this.enabled = false;
      }

      return acknowledgement;
   },

   /**
    * Register one process-local identity and retain an exclusive lease for
    * the worker lifetime. The persisted metadata contains neither the warmup
    * token nor the raw response identity.
    */
   _register () {
      const PID = process.pid;
      if (Number.isInteger(PID) === false || PID < 1) {
         throw new Error('Could not resolve the worker evidence process ID.');
      }
      if (this._PID === PID && this._identity !== null) {
         return;
      }

      this._PID = PID;
      this._identity = null;
      this.enabled = true;

      const serverDirectory = process.env.BENCHMARK_SERVER_DIR;
      if (typeof serverDirectory !== 'string' || serverDirectory === '') {
         this._identity = `${PID}-${randomBytes(16).toString('hex')}`;

         return;
      }
      if (lstatSync(serverDirectory, { throwIfNoEntry: false })?.isDirectory() !== true) {
         throw new Error('Worker evidence server directory is unavailable or unsafe.');
      }

      const directory = `${serverDirectory.replace(/\/+$/, '')}/workers`;
      mkdirSync(directory, { mode: 0o700, recursive: true });
      if (lstatSync(directory).isSymbolicLink()) {
         throw new Error('Worker evidence directory must not be a symbolic link.');
      }

      for (let attempt = 0; attempt < 16; attempt++) {
         const identity = `${PID}-${randomBytes(16).toString('hex')}`;
         const SHA = createHash('sha256').update(`worker\0${identity}`).digest('hex');
         const fingerprint = `sha256:${SHA}`;
         const file = `${directory}/worker-${SHA}.lease`;

         let fd;
         try {
            // ? 'wx+' = exclusive create — a fingerprint collision retries
            fd = openSync(file, 'wx+', 0o600);
         }
         catch {
            if (existsSync(file)) {
               continue;
            }

            throw new Error('Could not create the worker evidence lease.');
         }

         let registered = false;
         try {
            // ! Set and verify the final file mode directly (umask-proof).
            fchmodSync(fd, 0o600);
            if ((fstatSync(fd).mode & 0o777) !== 0o600) {
               throw new Error('Worker evidence lease permissions are invalid.');
            }
            if (flock(fd, LOCK_EX | LOCK_NB) !== 0) {
               throw new Error('Could not lock the worker evidence lease.');
            }

            const contents = JSON.stringify({
               schema: 'bootgly.worker-lease',
               version: 1,
               fingerprint,
               pid: PID,
            }, null, 4) + '\n';
            const buffer = Buffer.from(contents);
            let offset = 0;
            while (offset < buffer.length) {
               const written = writeSync(fd, buffer, offset, buffer.length - offset);
               if (written <= 0) {
                  throw new Error('Could not write the worker evidence lease.');
               }
               offset += written;
            }
            fsyncSync(fd);

            this._identity = identity;
            // ! Keep the descriptor (and therefore the flock) for the whole
            //   worker lifetime — releasing it would break generation proof.
            this._lease = fd;
            registered = true;

            return;
         }
         finally {
            if (registered === false) {
               closeSync(fd);
               try { unlinkSync(file); } catch {}
            }
         }
      }

      throw new Error('Could not allocate a unique worker evidence lease.');
   },
};

export default WorkerEvidence;
