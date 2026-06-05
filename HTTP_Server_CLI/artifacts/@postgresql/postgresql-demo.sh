#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../../.." && pwd)"
BOOTGLY_DIR="$ROOT_DIR/../../../../bootgly"

PGHOST="${PGHOST:-127.0.0.1}"
PGPORT="${PGPORT:-5432}"
PGDATABASE="${PGDATABASE:-bootgly}"
PGUSER="${PGUSER:-postgres}"
PGDATA="${PGDATA:-$BOOTGLY_DIR/workdata/temp/postgresql-demo}"
PGLOG="${PGLOG:-$PGDATA/postgresql.log}"

find_pg_bin () {
   if [[ -n "${POSTGRES_BIN:-}" ]]; then
      printf '%s\n' "$POSTGRES_BIN"
      return
   fi

   if [[ -x /usr/lib/postgresql/13/bin/postgres ]]; then
      printf '%s\n' /usr/lib/postgresql/13/bin
      return
   fi

   local Candidate
   Candidate="$(find /usr/lib/postgresql -maxdepth 3 -type f -name postgres 2>/dev/null | sort -V | tail -n 1 || true)"

   if [[ -n "$Candidate" ]]; then
      dirname -- "$Candidate"
      return
   fi

   printf '%s\n' ''
}

PG_BIN="$(find_pg_bin)"

check () {
   if [[ -z "$PG_BIN" || ! -x "$PG_BIN/initdb" || ! -x "$PG_BIN/pg_ctl" || ! -x "$PG_BIN/postgres" ]]; then
      printf 'PostgreSQL server binaries not found. Set POSTGRES_BIN=/path/to/postgresql/bin.\n' >&2
      exit 1
   fi
}

init () {
   mkdir -p "$(dirname -- "$PGDATA")"

   if [[ -s "$PGDATA/PG_VERSION" ]]; then
      return
   fi

   "$PG_BIN/initdb" \
      -D "$PGDATA" \
      --username="$PGUSER" \
      --auth=trust \
      --encoding=UTF8
}

start () {
   check
   init

   if "$PG_BIN/pg_ctl" -D "$PGDATA" status >/dev/null 2>&1; then
      printf 'PostgreSQL demo already running.\n'
   else
      "$PG_BIN/pg_ctl" \
         -D "$PGDATA" \
         -l "$PGLOG" \
         -o "-h $PGHOST -p $PGPORT -k $PGDATA" \
         start
   fi

   "$PG_BIN/createdb" \
      -h "$PGHOST" \
      -p "$PGPORT" \
      -U "$PGUSER" \
      "$PGDATABASE" 2>/dev/null || true

   "$PG_BIN/pg_isready" \
      -h "$PGHOST" \
      -p "$PGPORT" \
      -U "$PGUSER" \
      -d "$PGDATABASE"

   printf '\nPostgreSQL demo ready for Bootgly.\n'
   printf 'DB_HOST=%s\n' "$PGHOST"
   printf 'DB_PORT=%s\n' "$PGPORT"
   printf 'DB_NAME=%s\n' "$PGDATABASE"
   printf 'DB_USER=%s\n' "$PGUSER"
   printf 'DB_PASS=<empty>\n'
   printf 'PGDATA=%s\n' "$PGDATA"
   printf 'PGLOG=%s\n' "$PGLOG"
}

stop () {
   check

   if [[ ! -s "$PGDATA/PG_VERSION" ]]; then
      printf 'PostgreSQL demo data directory does not exist: %s\n' "$PGDATA"
      return
   fi

   "$PG_BIN/pg_ctl" -D "$PGDATA" stop
}

status () {
   check

   if [[ ! -s "$PGDATA/PG_VERSION" ]]; then
      printf 'PostgreSQL demo data directory does not exist: %s\n' "$PGDATA"
      return
   fi

   "$PG_BIN/pg_ctl" -D "$PGDATA" status || true
   "$PG_BIN/pg_isready" \
      -h "$PGHOST" \
      -p "$PGPORT" \
      -U "$PGUSER" \
      -d "$PGDATABASE" || true
}

case "${1:-start}" in
   start)
      start
      ;;
   stop)
      stop
      ;;
   restart)
      stop || true
      start
      ;;
   status)
      status
      ;;
   *)
      printf 'Usage: %s [start|stop|restart|status]\n' "$0" >&2
      exit 1
      ;;
esac
