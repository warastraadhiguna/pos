#!/usr/bin/env bash
# Pemasangan pertama kali untuk kantin.gelorasports.com di server 402287.
# Jalankan SEBAGAI ROOT, dari dalam kode yang sudah di-clone:
#   git clone <repo> /var/www/gelora/kantin
#   cd /var/www/gelora/kantin
#   bash deploy/install.sh
#
# Server ini dipakai bersama ±40 situs lain. Skrip ini HANYA MENAMBAH
# (database baru, vhost baru, service baru) dan berhenti di kejutan pertama
# (set -e) alih-alih menebak dan melanjutkan.
set -euo pipefail

APP_SHORT_NAME="kantin"
APP_DOMAIN="kantin.gelorasports.com"
APP_DIR="/var/www/gelora/${APP_SHORT_NAME}"
DB_NAME="kantin-gelora"
DB_USER="kantin"
PHP_BIN="/usr/bin/php8.4"
QUEUE_SERVICE="${APP_SHORT_NAME}-queue"
OTHER_SITE_CHECK="fitbull.id"
BACKUP_DIR="/var/backups/${APP_SHORT_NAME}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Jalankan sebagai root." >&2
  exit 1
fi

echo "== 0. Sanity check server =="
nginx -t
echo "-- situs lain (${OTHER_SITE_CHECK}) sebelum mulai --"
curl -sS -o /dev/null -w "  HTTP %{http_code}\n" "https://${OTHER_SITE_CHECK}" || echo "  (tidak terjangkau dari sini — cek manual kalau perlu)"

echo "== 1. Domain belum dipakai di nginx? =="
if [ -e "/etc/nginx/sites-enabled/${APP_DOMAIN}" ]; then
  echo "Domain ${APP_DOMAIN} SUDAH ada di nginx. Berhenti — cek manual." >&2
  exit 1
fi

echo "== 2. DNS sudah aktif? =="
if ! getent hosts "${APP_DOMAIN}" > /dev/null; then
  echo "DNS untuk ${APP_DOMAIN} belum aktif dari server ini. Tunggu propagasi lalu ulangi." >&2
  exit 1
fi

echo "== 3. Kode sudah di-clone? =="
if [ ! -f "${APP_DIR}/artisan" ]; then
  echo "Tidak ada ${APP_DIR}/artisan. Clone dulu repo ke situ, baru jalankan skrip ini." >&2
  exit 1
fi
cd "${APP_DIR}"

echo "== 4. composer install =="
composer install --no-dev --optimize-autoloader

echo "== 5. Database MariaDB =="
DB_PASSWORD="$(openssl rand -base64 24 | tr -d '=+/')"
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"
# TCP 127.0.0.1:3306 ternyata ditutup di server ini (MariaDB cuma terima
# koneksi lewat unix socket) -- ditemukan 2026-09-30 saat `artisan migrate`
# gagal "Connection refused" walau `mysql -e` di atas berhasil (itu lewat
# socket, bukan TCP). Pakai unix_socket eksplisit supaya PDO ikut lewat
# socket juga.
DB_SOCKET="$(mysql -N -e "SHOW VARIABLES LIKE 'socket';" | awk '{print $2}')"

echo "== 6. Menulis .env =="
cat > .env <<ENV_EOF
APP_NAME="Kantin - Wedangan Sante"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${APP_DOMAIN}

APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
DB_SOCKET=${DB_SOCKET}

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="hello@${APP_DOMAIN}"
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"
ENV_EOF

${PHP_BIN} artisan key:generate --force

echo "== 7. npm build =="
npm ci
npm run build

echo "== 8. Migrasi & seeder (fondasi, tanpa user) =="
${PHP_BIN} artisan migrate --force
${PHP_BIN} artisan db:seed --force

echo "== 9. storage:link (untuk foto produk) =="
${PHP_BIN} artisan storage:link

echo "== 10. Kepemilikan file (storage & bootstrap/cache milik www-data) =="
chown -R www-data:www-data storage bootstrap/cache
chown root:www-data .env
chmod 640 .env

echo "== 11. Cache config (production) =="
sudo -u www-data ${PHP_BIN} artisan config:cache

echo "== 12. Nginx vhost =="
cp deploy/nginx-vhost.conf.template "/etc/nginx/sites-available/${APP_DOMAIN}"
ln -sf "/etc/nginx/sites-available/${APP_DOMAIN}" "/etc/nginx/sites-enabled/${APP_DOMAIN}"
nginx -t
systemctl reload nginx

echo "== 13. HTTPS (certbot) =="
certbot --nginx -d "${APP_DOMAIN}"

echo "== 14. Queue worker (systemd) =="
cp deploy/kantin-queue.service.template "/etc/systemd/system/${QUEUE_SERVICE}.service"
systemctl daemon-reload
systemctl enable --now "${QUEUE_SERVICE}"

echo "== 15. Cron: scheduler tiap menit + backup harian 02:45 =="
mkdir -p "${BACKUP_DIR}"
chmod +x "${APP_DIR}/deploy/backup.sh"
cat > "/etc/cron.d/${APP_SHORT_NAME}" <<CRON_EOF
* * * * * www-data cd ${APP_DIR} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1
45 2 * * * root ${APP_DIR}/deploy/backup.sh >> /var/log/${APP_SHORT_NAME}-backup.log 2>&1
CRON_EOF

echo "== 16. Cek akhir =="
echo "-- ${APP_DOMAIN} --"
curl -sS -o /dev/null -w "  HTTP %{http_code}\n" "https://${APP_DOMAIN}" || true
echo "-- situs lain (${OTHER_SITE_CHECK}) setelah selesai --"
curl -sS -o /dev/null -w "  HTTP %{http_code}\n" "https://${OTHER_SITE_CHECK}" || true
echo "-- queue worker --"
systemctl is-active "${QUEUE_SERVICE}"
echo "-- Postgres/Redis (harus cuma localhost) --"
ss -ltn | grep -E ':5432|:6379' || true

echo ""
echo "Database kantin dibuat. Kredensial ada di ${APP_DIR}/.env (root:www-data, 640)."
echo ""
echo "== 17. Buat akun admin (interaktif) =="
sudo -u www-data ${PHP_BIN} artisan admin:create
