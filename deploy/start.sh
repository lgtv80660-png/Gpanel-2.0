#!/bin/sh
set -eu
case "${PORT:-8080}" in *[!0-9]*|'') echo 'Invalid PORT' >&2; exit 1;; esac
test "${PANEL_STATE_DIR:-}" = "/var/lib/gpanel/state" || { echo 'Private state directory required.' >&2; exit 1; }
mkdir -p "$PANEL_STATE_DIR"
mkdir -p "$PANEL_STATE_DIR/sessions"
chown www-data:www-data "$PANEL_STATE_DIR"
chown www-data:www-data "$PANEL_STATE_DIR/sessions"
chmod 700 "$PANEL_STATE_DIR/sessions"
chmod 700 "$PANEL_STATE_DIR"
cp /opt/gpanel/deploy/apache.conf /etc/apache2/sites-available/000-default.conf
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:8080>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
php /opt/gpanel/deploy/bootstrap.php
# Enforce the single PHP-compatible MPM at runtime as well as image build.
a2dismod mpm_event mpm_worker
a2enmod mpm_prefork
apache2ctl -t -f /opt/gpanel/deploy/httpd.conf
exec apache2-foreground -f /opt/gpanel/deploy/httpd.conf
