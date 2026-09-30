#!/usr/bin/env bash
# Rilis pembaruan kode untuk kantin.gelorasports.com.
# Jalankan: cd /var/www/gelora/kantin && bash deploy/update.sh
set -euo pipefail

APP_DIR="/var/www/gelora/kantin"
QUEUE_SERVICE="kantin-queue"
PHP_BIN="/usr/bin/php8.4"

cd "${APP_DIR}"

echo "== git pull =="
# public/build ikut ter-commit di repo ini (walau ada di .gitignore) sehingga
# build lokal di server sering bentrok dengan versi yang di-commit dari mesin
# lain. Buang perubahan lokal pada folder itu dulu sebelum pull.
git checkout -- public/build 2>/dev/null || true
git pull

echo "== composer =="
composer install --no-dev --optimize-autoloader

echo "== npm build =="
npm ci
npm run build

echo "== migrate =="
sudo -u www-data "${PHP_BIN}" artisan migrate --force

echo "== ratakan kepemilikan storage & bootstrap/cache (jaga-jaga ada file baru milik root) =="
chown -R www-data:www-data storage bootstrap/cache

echo "== cache config =="
sudo -u www-data "${PHP_BIN}" artisan config:cache

echo "== restart queue worker =="
systemctl restart "${QUEUE_SERVICE}"

echo "Selesai. Cek: systemctl is-active ${QUEUE_SERVICE}"
