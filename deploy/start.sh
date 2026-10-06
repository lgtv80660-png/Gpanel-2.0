#!/bin/sh
set -eu
case "${PORT:-8080}" in *[!0-9]*|'') echo 'Invalid PORT' >&2; exit 1;; esac
test "${PANEL_TEST_ENV:-}" = "1" || { echo 'This image requires an isolated test environment.' >&2; exit 1; }
test "${MYSQLDATABASE:-}" = "gpanel_v2_test" || { echo 'Dedicated gpanel_v2_test database required.' >&2; exit 1; }
test "${PANEL_STATE_DIR:-}" = "/var/lib/gpanel/state" || { echo 'Private state directory required.' >&2; exit 1; }
mkdir -p "$PANEL_STATE_DIR"
chown www-data:www-data "$PANEL_STATE_DIR"
chmod 700 "$PANEL_STATE_DIR"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:8080>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
php /opt/gpanel/deploy/initialize-test.php
php /var/www/html/migrate_v2.php --apply --backup-confirmed
php /var/www/html/migrate_v2.php --apply --backup-confirmed
php /opt/gpanel/deploy/verify-test.php
apache2ctl -t
exec apache2-foreground
