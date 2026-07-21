/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Express (Node.js) TechEmpower bootable
 * --------------------------------------------------------------------------
 * The 7 TFB routes (/plaintext, /json, /db, /query, /fortunes, /updates,
 * /cached-queries) on Express 5 over node:http.
 *
 * Worker model mirrors SWOOLE_BASE: a thin supervisor forks SERVER_WORKER_NUM
 * plain child processes and each worker binds the port itself with
 * SO_REUSEPORT — no master dispatcher on the accept path. Workers are never
 * respawned: a worker death must fail the benchmark's generation proof, not
 * be papered over.
 *
 * Database parity: ONE persistent pg Client per worker (fixed-one), prepared
 * statements, and the ordered-lock batched /updates write (locks rows in
 * ascending id order before updating — removes the PostgreSQL lock-order
 * deadlocks the unordered batched UPDATE hits at high worker counts).
 */

import cluster from 'node:child_process';
import { cpus } from 'node:os';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const SELF = fileURLToPath(import.meta.url);
const PORT = Number.parseInt(process.env.SERVER_PORT ?? '8082', 10);
const WORKERS = Math.max(1, Number.parseInt(
   process.env.SERVER_WORKER_NUM ?? String(Math.max(1, cpus().length >> 1)), 10,
) || 1);

// # Supervisor — fork the workers, publish the PID file, forward termination.
if (process.env.BENCHMARK_JS_WORKER !== '1') {
   const PIDFile = process.env.SERVER_PID_FILE;
   if (typeof PIDFile === 'string' && PIDFile !== '') {
      writeFileSync(PIDFile, `${process.pid}\n`);
   }

   const children = [];
   for (let index = 0; index < WORKERS; index++) {
      const child = cluster.fork(SELF, [], {
         env: { ...process.env, BENCHMARK_JS_WORKER: '1' },
         stdio: ['ignore', 'inherit', 'inherit', 'ipc'],
      });
      // ! No respawn: a dead worker must surface as a failed generation proof.
      child.on('exit', (code, signal) => {
         console.error(`express worker ${child.pid} exited (code=${code} signal=${signal})`);
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
}
// # Worker — evidence lease, one pg Client, the 7 TFB routes, SO_REUSEPORT.
else {
   const { default: express } = await import('express');
   const { default: pg } = await import('pg');
   const { default: WorkerEvidence } = await import('../WorkerEvidence.js');

   WorkerEvidence.boot();
   // ? Exit with the supervisor instead of surviving it as an orphan.
   process.on('disconnect', () => process.exit(0));

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
      if (Array.isArray(value)) {
         value = value[0];
      }
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

   const app = express();
   app.set('etag', false);
   app.set('x-powered-by', false);
   app.set('query parser', 'simple');

   // @ Warmup evidence — cold no-op outside harness runs (guard disabled).
   app.use((request, response, next) => {
      if (WorkerEvidence.enabled) {
         const identity = WorkerEvidence.identify(
            request.headers['x-bootgly-benchmark-warmup'],
            request.headers['x-bootgly-benchmark-nonce'],
            request.headers['x-bootgly-benchmark-seal'],
         );
         if (identity !== null) {
            response.set('X-Bootgly-Benchmark-Worker', identity);
         }
      }
      next();
   });

   app.get('/plaintext', (request, response) => {
      response.type('text/plain').send('Hello, World!');
   });
   app.get('/json', (request, response) => {
      response.json({ message: 'Hello, World!' });
   });
   app.get('/db', async (request, response) => {
      response.json(await fetchWorld(randomId()));
   });
   app.get('/query', async (request, response) => {
      let queries = clamp(request.query.queries);
      const worlds = [];
      while (queries-- > 0) {
         worlds.push(await fetchWorld(randomId()));
      }
      response.json(worlds);
   });
   app.get('/fortunes', async (request, response) => {
      const fortunes = [[0, 'Additional fortune added at request time.']];
      for (const row of (await database.query(FORTUNES)).rows) {
         fortunes.push([row.id, row.message]);
      }
      fortunes.sort((a, b) => (a[1] < b[1] ? -1 : a[1] > b[1] ? 1 : 0));

      let rows = '';
      for (const [id, message] of fortunes) {
         rows += `<tr><td>${id}</td><td>${escape(message)}</td></tr>`;
      }
      response.type('text/html; charset=utf-8').send(
         '<!DOCTYPE html><html><head><title>Fortunes</title></head><body><table>'
         + `<tr><th>id</th><th>message</th></tr>${rows}</table></body></html>`,
      );
   });
   app.get('/updates', async (request, response) => {
      let queries = clamp(request.query.queries);
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
      response.json(worlds);
   });
   app.get('/cached-queries', (request, response) => {
      let count = clamp(request.query.count);
      const max = cachedWorlds.length - 1;
      const worlds = [];
      while (count-- > 0) {
         worlds.push(cachedWorlds[1 + ((Math.random() * max) | 0)] ?? null);
      }
      response.json(worlds);
   });
   // @ Readiness/warmup probe target.
   app.get('/', (request, response) => {
      response.type('text/plain').send('TechEmpower Benchmark');
   });

   const server = app.listen({ port: PORT, host: '0.0.0.0', reusePort: true });
   server.keepAliveTimeout = 60_000;

   process.on('SIGTERM', () => process.exit(0));
}
