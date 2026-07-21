# syntax=docker/dockerfile:1
# ============================================================================
# bootgly/bootgly_benchmarks — self-contained cross-framework bench harness
#
# One image that runs the WHOLE benchmark in a single `docker run` — Bootgly
# plus one opponent, all in-process on loopback, no host setup. Competitor
# runtimes (Swoole, Workerman, RoadRunner, FrankenPHP, Hyperf, ReactPHP, AMPHP,
# Laravel Octane, Express/Node.js, Bun and PostgreSQL) live ONLY in this image —
# never in bootgly:slim / bootgly:full, which stay dependency-free. Opt in per
# opponent with build ARGs.
#
# The opponent scripts launch their server natively (no docker-in-docker) —
# each guards on its own runtime capability (extension / vendor / binary), all
# baked in here — so the runner spawns every server locally and the client +
# servers share loopback: fair, and zero setup for the user.
#
# PostgreSQL (server + client) is ALWAYS baked in: every benchmark load set hits
# the database — `techempower` via /db /query /fortunes /updates /cached-queries,
# `benchmark` via the Bootgly /database/* probes — so the image is never useful
# without it. The entrypoint boots + seeds it locally, zero host setup.
#
# Build the framework image first (bootgly:full), then:
#   docker build -f Dockerfile --build-arg WITH_SWOOLE=1 \
#     -t bootgly/bootgly_benchmarks:swoole .
#
#   docker run --rm bootgly/bootgly_benchmarks:swoole test benchmark HTTP_Server_CLI \
#     --opponents=bootgly,swoole --runner=tcp_client --loads=techempower:1
# ============================================================================

ARG BOOTGLY_FULL_IMAGE=bootgly:full
FROM ${BOOTGLY_FULL_IMAGE} AS bench

# * Opt-in opponents (0 = off, 1 = on). PostgreSQL is NOT an opt-in — it is
#   always installed below (every load set needs the database).
ARG WITH_SWOOLE=0
ARG WITH_WORKERMAN=0
ARG WITH_ROADRUNNER=0
ARG WITH_FRANKENPHP=0
ARG WITH_HYPERF=0
ARG WITH_REACTPHP=0
ARG WITH_AMPHP=0
ARG WITH_LARAVEL_OCTANE=0
ARG WITH_EXPRESS=0
ARG WITH_BUN=0

# ! Composer (build-time) for opponents that vendor PHP packages
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV BOOTABLES=/bootgly_benchmarks/HTTP_Server_CLI/bootables

# ! PostgreSQL — ALWAYS installed: server + client + the `pdo_pgsql` PHP driver.
#   Every benchmark load set hits the DB (techempower /db /query /fortunes /updates
#   /cached-queries + the Bootgly /database/* probes), and every opponent EXCEPT
#   Bootgly talks to PostgreSQL through pdo_pgsql (Bootgly uses its own native wire
#   driver). libpq5 (pdo_pgsql runtime) ships with the postgresql packages; only the
#   libpq-dev build header is purged. The entrypoint boots + seeds the DB at run time.
RUN set -eux; \
      apt-get update; \
      apt-get install -y --no-install-recommends postgresql postgresql-client libpq-dev; \
      docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
      apt-get purge -y libpq-dev; apt-get autoremove -y; \
      rm -rf /var/lib/apt/lists/*

# # Opponents
# ! Swoole — cross-framework opponent (also the runtime Hyperf is built on). pdo_pgsql
#   is already installed above; here only the swoole pecl build deps are added + purged.
RUN if [ "$WITH_SWOOLE" = "1" ] || [ "$WITH_HYPERF" = "1" ] || [ "$WITH_LARAVEL_OCTANE" = "1" ]; then set -eux; \
      apt-get update; \
      apt-get install -y --no-install-recommends $PHPIZE_DEPS libbrotli-dev libssl-dev; \
      pecl install swoole; \
      docker-php-ext-enable swoole; \
      printf 'swoole.use_shortname=Off\n' > /usr/local/etc/php/conf.d/zz-swoole.ini; \
      apt-get purge -y $PHPIZE_DEPS libbrotli-dev libssl-dev; \
      rm -rf /var/lib/apt/lists/* /tmp/pear; \
    fi

# ! Workerman — pure-PHP, composer vendor only
RUN if [ "$WITH_WORKERMAN" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends git unzip; rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/workerman"; composer install --no-interaction --no-progress --no-dev; \
    fi

# ! RoadRunner — composer vendor + the `rr` Go binary
RUN if [ "$WITH_ROADRUNNER" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends git unzip; rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/roadrunner"; composer install --no-interaction --no-progress; \
      php ./vendor/bin/rr get-binary; chmod +x ./rr; \
    fi

# ! Hyperf — Swoole framework (Swoole already installed above), composer vendor
RUN if [ "$WITH_HYPERF" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends git unzip; rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/hyperf"; composer install --no-interaction --no-progress; \
    fi

# ! FrankenPHP — pinned linux/amd64 standalone binary. Verify the release asset
#   before installing it so a mutable or corrupted download cannot enter a
#   benchmark image unnoticed.
RUN if [ "$WITH_FRANKENPHP" = "1" ]; then set -eux; \
      curl -fsSL https://github.com/php/frankenphp/releases/download/v1.12.4/frankenphp-linux-x86_64 \
        -o /usr/local/bin/frankenphp; \
      echo '4868ea3260a661f8abb881e92e7c713bc93c8f6676f9034b9d9b4551fcf19670  /usr/local/bin/frankenphp' \
        | sha256sum -c -; \
      chmod +x /usr/local/bin/frankenphp; \
    fi

# ! ReactPHP — pure-PHP async (react/*, voryx/pgasync over the wire protocol). pcntl +
#   sockets are already in the base image, so only the composer vendor is added here.
RUN if [ "$WITH_REACTPHP" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends git unzip; rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/reactphp"; composer install --no-interaction --no-progress --no-dev; \
    fi

# ! AMPHP — Amp v3 fibers; amphp/postgres needs ext-pgsql (the `pgsql` extension, NOT
#   pdo_pgsql). pcntl is already in the base; libpq5 (ext-pgsql runtime) stays alive via
#   postgresql-client, so only the libpq-dev header + phpize deps are added then purged.
RUN if [ "$WITH_AMPHP" = "1" ]; then set -eux; \
      apt-get update; \
      apt-get install -y --no-install-recommends $PHPIZE_DEPS libpq-dev git unzip; \
      docker-php-ext-install -j"$(nproc)" pgsql; \
      cd "$BOOTABLES/amphp"; composer install --no-interaction --no-progress --no-dev; \
      apt-get purge -y $PHPIZE_DEPS libpq-dev; apt-get autoremove -y; \
      rm -rf /var/lib/apt/lists/* /tmp/pear; \
    fi

# ! Laravel Octane — Swoole-served Laravel (swoole installed above; pdo_pgsql from the PG
#   block, pcntl in the base). composer vendors laravel/octane + the runtime tree; clear
#   the bootstrap cache and create the storage dirs Octane expects.
RUN if [ "$WITH_LARAVEL_OCTANE" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends git unzip; rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/laravel"; rm -rf bootstrap/cache/*.php; \
      for i in 1 2 3; do \
        composer install --no-interaction --no-progress --no-dev --optimize-autoloader && break; \
        echo "composer retry $i"; sleep 5; \
      done; \
      mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs run; \
    fi

# ! Express — Node.js 22 (NodeSource) + the bootable's npm vendor (express, pg,
#   koffi — koffi ships prebuilt FFI binaries, no node-gyp toolchain needed).
#   The worker evidence lease uses flock(2) through koffi at run time.
RUN if [ "$WITH_EXPRESS" = "1" ]; then set -eux; \
      apt-get update; \
      apt-get install -y --no-install-recommends ca-certificates curl gnupg; \
      curl -fsSL https://deb.nodesource.com/setup_22.x | bash -; \
      apt-get install -y --no-install-recommends nodejs; \
      rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/express"; npm ci --omit=dev --no-audit --no-fund; \
    fi

# ! Bun — pinned linux/amd64 release binary (same verify-before-install policy
#   as FrankenPHP: a mutable or corrupted download must not enter a benchmark
#   image unnoticed) + the bootable's vendor (pg only; flock(2) comes from
#   bun:ffi, zero dependencies).
RUN if [ "$WITH_BUN" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends unzip; rm -rf /var/lib/apt/lists/*; \
      curl -fsSL https://github.com/oven-sh/bun/releases/download/bun-v1.1.43/bun-linux-x64.zip \
        -o /tmp/bun.zip; \
      echo '8f98aa87091557647dc23c65c3206b14ee902c8e27d0203daa1200b2d1fa3c21  /tmp/bun.zip' \
        | sha256sum -c -; \
      unzip -j /tmp/bun.zip bun-linux-x64/bun -d /usr/local/bin; \
      chmod +x /usr/local/bin/bun; rm /tmp/bun.zip; \
      cd "$BOOTABLES/bun"; bun install --frozen-lockfile --production; \
    fi

# # Entrypoint
# ! PID 1 must reap orphaned opponent descendants. Keep this late in the image
#   so lifecycle hardening does not invalidate the expensive opponent layers.
RUN set -eux; \
      apt-get update; \
      apt-get install -y --no-install-recommends tini; \
      rm -rf /var/lib/apt/lists/*

# ! Boot + seed a local PostgreSQL (trust auth) so every DB route works with zero
#   host setup, then hand off to the bootgly CLI. --no-sync: this is a throwaway
#   bench database, so skip fsync (also avoids an initdb fsync storm on overlayfs).
#   DB_* default to the local cluster but stay overridable via `docker run -e`.
RUN cat > /usr/local/bin/bench-entrypoint.sh <<'EOF' && chmod +x /usr/local/bin/bench-entrypoint.sh
#!/bin/sh
set -e

export DB_HOST="${DB_HOST:-127.0.0.1}" DB_PORT="${DB_PORT:-5432}" \
       DB_NAME="${DB_NAME:-bootgly}" DB_USER="${DB_USER:-postgres}" DB_PASS="${DB_PASS:-}"

PGBIN="$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | sort -V | tail -1)"
PGDATA=/var/lib/postgresql/bench
if [ -n "$PGBIN" ]; then
   if [ ! -s "$PGDATA/PG_VERSION" ]; then
      install -d -o postgres -g postgres "$PGDATA"
      runuser -u postgres -- "$PGBIN/initdb" -D "$PGDATA" -A trust -U postgres --no-sync >/dev/null
   fi
   # Scale max_connections to the host. Bootgly runs ~nproc/2 workers, each an async
   # event loop with a DB pool of up to 8 connections (ADI\Database DEFAULT_POOL_MAX),
   # so the held-connection routes (/query, /updates pipeline N reads on one pooled
   # connection) peak around nproc*4 open connections — plus idle pooled connections
   # carried over between loads and short bursts. PostgreSQL's default cap (100) is far
   # too low on many-core hosts and surfaces as HTTP 500 ("sorry, too many clients
   # already") on exactly those routes. Give generous headroom (like the TechEmpower
   # reference PG configs), scaled to the box so small hosts still start cleanly.
   MAXC=$(( $(nproc 2>/dev/null || echo 4) * 8 + 256 ))
   runuser -u postgres -- "$PGBIN/pg_ctl" -D "$PGDATA" \
      -o "-c listen_addresses=127.0.0.1 -p $DB_PORT -k /tmp -c max_connections=$MAXC" -w -t 25 start >/dev/null
   runuser -u postgres -- "$PGBIN/createdb" -h 127.0.0.1 -p "$DB_PORT" -U postgres "$DB_NAME" 2>/dev/null || true
fi

exec bootgly "$@"
EOF

# ! Keep a real PID 1 reaper around the benchmark supervisor. Opponent wrappers
#   intentionally create isolated sessions; if one of their leaders crashes,
#   tini adopts and reaps the orphaned descendants instead of leaving zombies
#   that make a quiescence check observe the process group forever.
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/bench-entrypoint.sh"]
CMD ["help"]
