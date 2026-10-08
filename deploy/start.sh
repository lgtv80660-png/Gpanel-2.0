#!/bin/sh
set -eu
case "${PORT:-8080}" in *[!0-9]*|'') echo 'Invalid PORT' >&2; exit 1;; esac
test "${PANEL_STATE_DIR:-}" = "/var/lib/gpanel/state" || { echo 'Private state directory required.' >&2; exit 1; }
mkdir -p "$PANEL_STATE_DIR"
chown www-data:www-data "$PANEL_STATE_DIR"
chmod 700 "$PANEL_STATE_DIR"
cp /opt/gpanel/deploy/apache.conf /etc/apache2/sites-available/000-default.conf
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:8080>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
if [ "${PANEL_TEST_ENV:-}" = "1" ]; then
    test "${MYSQLDATABASE:-}" = "gpanel_v2_test" || { echo 'Dedicated test database required.' >&2; exit 1; }
    php /opt/gpanel/deploy/initialize-test.php
fi
php /var/www/html/migrate_packs.php
# Enforce the single PHP-compatible MPM at runtime as well as image build.
a2dismod mpm_event mpm_worker
a2enmod mpm_prefork
apache2ctl -t -f /opt/gpanel/deploy/httpd.conf
exec apache2-foreground -f /opt/gpanel/deploy/httpd.conf
