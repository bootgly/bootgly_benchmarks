/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Bun (Bun.serve) TechEmpower bootable
 * --------------------------------------------------------------------------
 * The 7 TFB routes (/plaintext, /json, /db, /query, /fortunes, /updates,
 * /cached-queries) on Bun's native HTTP server.
 *
 * Worker model mirrors SWOOLE_BASE: a thin supervisor spawns SERVER_WORKER_NUM
 * plain child processes and each worker binds the port itself with
 * SO_REUSEPORT (`Bun.serve({ reusePort: true })`) — no master dispatcher on
 * the accept path. Workers are never respawned: a worker death must fail the
 * benchmark's generation proof, not be papered over.
 *
 * Database parity: ONE persistent pg Client per worker (fixed-one), prepared
 * statements, and the ordered-lock batched /updates write — the same driver
 * and statements as the Express opponent, so the two JS opponents differ only
 * by runtime/server, not by database strategy.
 */

import { writeFileSync } from 'node:fs';

const SELF = import.meta.path;
const PORT = Number.parseInt(process.env.SERVER_PORT ?? '8082', 10);
const WORKERS = Math.max(1, Number.parseInt(
   process.env.SERVER_WORKER_NUM ?? String(Math.max(1, navigator.hardwareConcurrency >> 1)), 10,
) || 1);

// # Supervisor — spawn the workers, publish the PID file, forward termination.
if (process.env.BENCHMARK_JS_WORKER !== '1') {
   const PIDFile = process.env.SERVER_PID_FILE;
   if (typeof PIDFile === 'string' && PIDFile !== '') {
      writeFileSync(PIDFile, `${process.pid}\n`);
   }

   const children = [];
   for (let index = 0; index < WORKERS; index++) {
      const child = Bun.spawn([process.execPath, SELF], {
         env: { ...process.env, BENCHMARK_JS_WORKER: '1' },
         stdio: ['ignore', 'inherit', 'inherit'],
         // ! No respawn: a dead worker must surface as a failed generation proof.
         onExit (subprocess, code, signal) {
            console.error(`bun worker ${subprocess.pid} exited (code=${code} signal=${signal})`);
         },
      });
      children.push(child);
   }

   const terminate = () => {
      for (const child of children) {
         try { child.kill('SIGTERM'); } catch {}
      }
      process.exit(0);
   };
   process.on('SIGTERM', terminate);
   process.on('SIGINT', terminate);

   // @ Stay resident while the workers serve.
   await Promise.all(children.map((child) => child.exited));
}
// # Worker — evidence lease, one pg Client, the 7 TFB routes, SO_REUSEPORT.
else {
   const { default: pg } = await import('pg');
   const { default: WorkerEvidence } = await import('../WorkerEvidence.js');

   WorkerEvidence.boot();

   // ! ONE persistent connection per worker, held for the worker lifetime.
   const database = new pg.Client({
      host: process.env.DB_HOST || '127.0.0.1',
      port: Number.parseInt(process.env.DB_PORT || '5432', 10),
      database: process.env.DB_NAME || 'bootgly',
      user: process.env.DB_USER || 'postgres',
      password: process.env.DB_PASS || '',
   });
   await database.connect();

   // ! Prime the per-worker in-memory CachedWorld pool (no DB on hot path).
   const cachedWorlds = [];
   for (const row of (await database.query(
      'SELECT id, randomNumber AS "randomNumber" FROM CachedWorld',
   )).rows) {
      cachedWorlds[row.id] = { id: row.id, randomNumber: row.randomNumber };
   }

   const randomId = () => 1 + ((Math.random() * 10000) | 0);
   const clamp = (value) => {
      const count = Number.parseInt(value, 10);

      return count > 1 ? (count > 500 ? 500 : count) : 1;
   };
   const escape = (text) => text
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');

   const WORLD = {
      name: 'world',
      text: 'SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1',
   };
   const FORTUNES = { name: 'fortunes', text: 'SELECT id, message FROM Fortune' };
   // @ Ordered-lock batched write — same statement Bootgly uses.
   const UPDATE = {
      name: 'update-worlds',
      text: 'UPDATE World SET randomNumber = data.new'
         + ' FROM (SELECT d.id, d.new FROM unnest($1::integer[], $2::integer[]) AS d(id, new)'
         + ' JOIN World w ON w.id = d.id ORDER BY d.id FOR UPDATE OF w) AS data'
         + ' WHERE World.id = data.id',
   };
   const fetchWorld = async (id) => {
      const row = (await database.query({ ...WORLD, values: [id] })).rows[0] ?? {};

      return { id: row.id ?? 0, randomNumber: row.randomNumber ?? 0 };
   };

   const TEXT = { 'Content-Type': 'text/plain' };
   const JSON_TYPE = { 'Content-Type': 'application/json' };
   const HTML = { 'Content-Type': 'text/html; charset=utf-8' };
   const respond = (request, body, headers, status = 200) => {
      if (WorkerEvidence.enabled) {
         const identity = WorkerEvidence.identify(
            request.headers.get('x-bootgly-benchmark-warmup'),
            request.headers.get('x-bootgly-benchmark-nonce'),
            request.headers.get('x-bootgly-benchmark-seal'),
         );
         if (identity !== null) {
            headers = { ...headers, 'X-Bootgly-Benchmark-Worker': identity };
         }
      }

      return new Response(body, { status, headers });
   };

   Bun.serve({
      port: PORT,
      hostname: '0.0.0.0',
      reusePort: true,

      async fetch (request) {
         const url = request.url;
         const query = url.indexOf('?');
         const path = url.substring(
            url.indexOf('/', 10),
            query === -1 ? url.length : query,
         );

         switch (path) {
            case '/plaintext':
               return respond(request, 'Hello, World!', TEXT);

            case '/json':
               return respond(request, '{"message":"Hello, World!"}', JSON_TYPE);

            case '/db':
               return respond(request, JSON.stringify(await fetchWorld(randomId())), JSON_TYPE);

            case '/query': {
               let queries = clamp(
                  query === -1 ? undefined : new URLSearchParams(url.substring(query + 1)).get('queries'),
               );
               const worlds = [];
               while (queries-- > 0) {
                  worlds.push(await fetchWorld(randomId()));
               }

               return respond(request, JSON.stringify(worlds), JSON_TYPE);
            }

            case '/fortunes': {
               const fortunes = [[0, 'Additional fortune added at request time.']];
               for (const row of (await database.query(FORTUNES)).rows) {
                  fortunes.push([row.id, row.message]);
               }
               fortunes.sort((a, b) => (a[1] < b[1] ? -1 : a[1] > b[1] ? 1 : 0));

               let rows = '';
               for (const [id, message] of fortunes) {
                  rows += `<tr><td>${id}</td><td>${escape(message)}</td></tr>`;
               }

               return respond(
                  request,
                  '<!DOCTYPE html><html><head><title>Fortunes</title></head><body><table>'
                  + `<tr><th>id</th><th>message</th></tr>${rows}</table></body></html>`,
                  HTML,
               );
            }

            case '/updates': {
               let queries = clamp(
                  query === -1 ? undefined : new URLSearchParams(url.substring(query + 1)).get('queries'),
               );
               const worlds = [];
               const ids = [];
               const values = [];
               while (queries-- > 0) {
                  const world = await fetchWorld(randomId());
                  world.randomNumber = randomId();
                  worlds.push(world);
                  ids.push(world.id);
                  values.push(world.randomNumber);
               }
               await database.query({ ...UPDATE, values: [ids, values] });

               return respond(request, JSON.stringify(worlds), JSON_TYPE);
            }

            case '/cached-queries': {
               let count = clamp(
                  query === -1 ? undefined : new URLSearchParams(url.substring(query + 1)).get('count'),
               );
               const max = cachedWorlds.length - 1;
               const worlds = [];
               while (count-- > 0) {
                  worlds.push(cachedWorlds[1 + ((Math.random() * max) | 0)] ?? null);
               }

               return respond(request, JSON.stringify(worlds), JSON_TYPE);
            }

            case '/':
               // @ Readiness/warmup probe target.
               return respond(request, 'TechEmpower Benchmark', TEXT);

            default:
               return respond(request, 'Not Found', TEXT, 404);
         }
      },

      error (error) {
         return new Response(String(error?.message ?? error), { status: 500, headers: TEXT });
      },
   });

   process.on('SIGTERM', () => process.exit(0));
}
