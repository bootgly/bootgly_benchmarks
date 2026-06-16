# syntax=docker/dockerfile:1
# ============================================================================
# bootgly-bench — Bootgly cross-framework benchmark harness
#
# Competitor runtimes (Swoole, Workerman, RoadRunner, FrankenPHP, Hyperf and
# PostgreSQL) live ONLY in this image — never in bootgly:slim / bootgly:full,
# which stay dependency-free. Opt in per opponent with build ARGs. The runner
# spawns every server locally, so all servers + the client share loopback and
# the comparison is fair.
#
# Build the framework image first (bootgly:full), then:
#   docker build -f Dockerfile \
#     --build-arg WITH_SWOOLE=1 --build-arg WITH_POSTGRES=1 \
#     -t bootgly-bench:swoole .
#
#   docker run --rm bootgly-bench:swoole test benchmark HTTP_Server_CLI \
#     --opponents=bootgly,swoole-base --runner=tcp_client --loads=1
# ============================================================================

ARG BOOTGLY_FULL_IMAGE=bootgly:full
FROM ${BOOTGLY_FULL_IMAGE} AS bench

# * Opt-in opponents (0 = off, 1 = on)
ARG WITH_SWOOLE=0
ARG WITH_POSTGRES=0
ARG WITH_WORKERMAN=0
ARG WITH_ROADRUNNER=0
ARG WITH_FRANKENPHP=0
ARG WITH_HYPERF=0

# ! Composer (build-time) for opponents that vendor PHP packages
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV BOOTABLES=/bootgly_benchmarks/HTTP_Server_CLI/bootables

# ! Swoole (+ pdo_pgsql) — main cross-framework opponent; also required by Hyperf.
#   libpq5 (pdo_pgsql runtime) is kept; only the -dev/build tools are purged.
RUN if [ "$WITH_SWOOLE" = "1" ] || [ "$WITH_HYPERF" = "1" ]; then set -eux; \
      apt-get update; \
      apt-get install -y --no-install-recommends $PHPIZE_DEPS libpq-dev libbrotli-dev; \
      docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
      pecl install swoole; \
      docker-php-ext-enable swoole; \
      printf 'swoole.use_shortname=Off\n' > /usr/local/etc/php/conf.d/zz-swoole.ini; \
      apt-get purge -y $PHPIZE_DEPS libpq-dev libbrotli-dev; \
      rm -rf /var/lib/apt/lists/* /tmp/pear; \
    fi

# ! PostgreSQL (TechEmpower DB loads, e.g. swoole-techempower) — server + client
RUN if [ "$WITH_POSTGRES" = "1" ]; then set -eux; \
      apt-get update; \
      apt-get install -y --no-install-recommends postgresql postgresql-client; \
      rm -rf /var/lib/apt/lists/*; \
    fi

# ! Workerman — pure-PHP, composer vendor only
RUN if [ "$WITH_WORKERMAN" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends git unzip; rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/workerman"; composer install --no-interaction --no-progress --no-dev; \
    fi

# ! RoadRunner — composer vendor + the `rr` Go binary
RUN if [ "$WITH_ROADRUNNER" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends git unzip; rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/roadrunner"; composer install --no-interaction --no-progress; php ./vendor/bin/rr get-binary; \
    fi

# ! Hyperf — Swoole framework (Swoole already installed above), composer vendor
RUN if [ "$WITH_HYPERF" = "1" ]; then set -eux; \
      apt-get update; apt-get install -y --no-install-recommends git unzip; rm -rf /var/lib/apt/lists/*; \
      cd "$BOOTABLES/hyperf"; composer install --no-interaction --no-progress; \
    fi

# ! FrankenPHP — single Go binary
RUN if [ "$WITH_FRANKENPHP" = "1" ]; then set -eux; \
      curl -fsSL https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 \
        -o /usr/local/bin/frankenphp; \
      chmod +x /usr/local/bin/frankenphp; \
    fi
