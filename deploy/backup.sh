#!/usr/bin/env bash
# Backup harian: dump DB, storage/app, dan .env. Dipanggil lewat /etc/cron.d/kantin.
set -euo pipefail

APP_DIR="/var/www/gelora/kantin"
BACKUP_DIR="/var/backups/kantin"
DB_NAME="kantin"
DATE="$(date +%Y%m%d-%H%M%S)"
RETAIN_DAYS=14

mkdir -p "${BACKUP_DIR}/db" "${BACKUP_DIR}/storage" "${BACKUP_DIR}/env"

DB_USER="$(grep -E '^DB_USERNAME=' "${APP_DIR}/.env" | cut -d= -f2-)"
DB_PASS="$(grep -E '^DB_PASSWORD=' "${APP_DIR}/.env" | cut -d= -f2-)"

mysqldump -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" | gzip > "${BACKUP_DIR}/db/${DB_NAME}-${DATE}.sql.gz"
tar -czf "${BACKUP_DIR}/storage/storage-${DATE}.tar.gz" -C "${APP_DIR}" storage/app
cp "${APP_DIR}/.env" "${BACKUP_DIR}/env/env-${DATE}"

find "${BACKUP_DIR}/db" -name '*.sql.gz' -mtime +${RETAIN_DAYS} -delete
find "${BACKUP_DIR}/storage" -name '*.tar.gz' -mtime +${RETAIN_DAYS} -delete
find "${BACKUP_DIR}/env" -type f -mtime +${RETAIN_DAYS} -delete

echo "Backup kantin selesai: ${DATE}"
