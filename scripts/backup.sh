#!/usr/bin/env bash
# Nightly database backup (TRD §5, START_HERE §5). Run by cron on the server:
#
#   15 2 * * *  /var/www/homi/scripts/backup.sh >> /var/log/homi-backup.log 2>&1
#
# Dumps the database named in the app's .env with mysqldump into BACKUP_DIR (a second disk or
# a folder synced to free cloud storage), gzipped, one file per day, and keeps the newest 14.
# The MySQL password is never on the command line: mysqldump reads it from ~/.my.cnf
# ([client] user=… password=…, chmod 600), or else from DB_PASSWORD in .env via the environment.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/mnt/backup/homi}"
KEEP="${KEEP:-14}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"

env_value() { grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | tr -d '"'"'"; }
DB="${DB_DATABASE:-$(env_value DB_DATABASE)}"
HOST="${DB_HOST:-$(env_value DB_HOST)}"
PORT="${DB_PORT:-$(env_value DB_PORT)}"
DBUSER="${DB_USERNAME:-$(env_value DB_USERNAME)}"
# Without a ~/.my.cnf, the .env password is passed in the environment, never on the command line.
if [ ! -f "$HOME/.my.cnf" ] && [ -n "$(env_value DB_PASSWORD)" ]; then export MYSQL_PWD="$(env_value DB_PASSWORD)"; fi

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y-%m-%d)"
FILE="$BACKUP_DIR/$DB-$STAMP.sql.gz"
TMP="$FILE.part"

# --single-transaction: a consistent snapshot of the InnoDB tables without locking the front desk.
"$MYSQLDUMP" --host="${HOST:-127.0.0.1}" --port="${PORT:-3306}" ${DBUSER:+--user="$DBUSER"} \
    --single-transaction --quick --routines --triggers --events --hex-blob \
    --default-character-set=utf8mb4 --set-gtid-purged=OFF \
    "$DB" | gzip -9 > "$TMP"

# A dump that did not finish has no "Dump completed" trailer: keep the old copies, fail loudly.
if ! gzip -dc "$TMP" | tail -n 1 | grep -q "Dump completed"; then
    rm -f "$TMP"
    echo "$(date -Is) backup of $DB FAILED: the dump is incomplete" >&2
    exit 1
fi
mv "$TMP" "$FILE"

# Keep the newest $KEEP daily copies of this database.
ls -1t "$BACKUP_DIR/$DB"-*.sql.gz | tail -n +"$((KEEP + 1))" | xargs -r rm -f

echo "$(date -Is) backup of $DB written to $FILE ($(du -h "$FILE" | cut -f1)); $(ls -1 "$BACKUP_DIR/$DB"-*.sql.gz | wc -l) copies kept"
