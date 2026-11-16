#!/usr/bin/env bash
# Restore test (START_HERE §5): load a backup into a scratch database, check it, drop it.
#
#   scripts/restore-test.sh /mnt/backup/homi/hotelv4-2026-11-16.sql.gz
#
# 1. creates the scratch database (default hotelv4_restore_check; never the live one);
# 2. loads the dump into it;
# 3. lists the row count of every table beside the live database and fails on a missing table
# 4. runs `php artisan homi:check-invariants` against the scratch copy (TRD §8 invariants 1–8);
# 5. drops the scratch database.
# Credentials come from ~/.my.cnf, as for backup.sh.
set -euo pipefail

DUMP="${1:?usage: restore-test.sh <backup.sql.gz>}"
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
MYSQL="${MYSQL:-mysql}"
PHP="${PHP:-php}"

env_value() { grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | tr -d '"'"'"; }
LIVE="${DB_DATABASE:-$(env_value DB_DATABASE)}"
HOST="${DB_HOST:-$(env_value DB_HOST)}"
PORT="${DB_PORT:-$(env_value DB_PORT)}"
DBUSER="${DB_USERNAME:-$(env_value DB_USERNAME)}"
# Without a ~/.my.cnf, the .env password is passed in the environment, never on the command line.
if [ ! -f "$HOME/.my.cnf" ] && [ -n "$(env_value DB_PASSWORD)" ]; then export MYSQL_PWD="$(env_value DB_PASSWORD)"; fi
SCRATCH="${SCRATCH:-${LIVE}_restore_check}"

if [ "$SCRATCH" = "$LIVE" ]; then
    echo "The scratch database must not be the live one ($LIVE)." >&2
    exit 1
fi

# (tr: mysql on Windows ends its lines with CR LF.)
sql() { "$MYSQL" --host="${HOST:-127.0.0.1}" --port="${PORT:-3306}" ${DBUSER:+--user="$DBUSER"} --batch --skip-column-names "$@" | tr -d '\r'; }
# "table rows" for every table of a database, into a file; any failed query stops the script.
counts() {
    local tables
    tables=$(sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$1' AND table_type = 'BASE TABLE' ORDER BY table_name")
    : > "$2"
    for t in $tables; do
        printf '%s %s\n' "$t" "$(sql -e "SELECT COUNT(*) FROM \`$1\`.\`$t\`")" >> "$2"
    done
}

WORK="$(mktemp -d)"
echo "Restoring $DUMP into $SCRATCH …"
sql -e "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
trap 'sql -e "DROP DATABASE IF EXISTS \`$SCRATCH\`" || true; rm -rf "$WORK"' EXIT
gzip -dc "$DUMP" | sql "$SCRATCH"

counts "$SCRATCH" "$WORK/restored"
counts "$LIVE" "$WORK/live"
problems=0

echo "Row counts (restored / live):"
while read -r table restored live; do
    printf '  %-32s %8s / %s\n' "$table" "$restored" "$live"
done < <(join "$WORK/restored" "$WORK/live")

missing=$(join -v 2 "$WORK/restored" "$WORK/live" | cut -d' ' -f1 | tr '\n' ' ')
if [ -n "${missing// /}" ]; then
    echo "Tables missing from the backup: $missing" >&2
    problems=1
fi
if [ ! -s "$WORK/restored" ]; then
    echo "The restored database has no tables." >&2
    problems=1
fi

echo "Checking the invariants on the restored copy …"
if ! (cd "$APP_DIR" && "$PHP" artisan homi:check-invariants --database="$SCRATCH"); then
    problems=1
fi

if [ "$problems" -ne 0 ]; then
    echo "RESTORE TEST FAILED" >&2
    exit 1
fi
echo "Restore test passed: $DUMP restores and its data holds together."
