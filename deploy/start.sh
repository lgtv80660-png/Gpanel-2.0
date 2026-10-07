#!/bin/sh
set -eu
case "${PORT:-8080}" in *[!0-9]*|'') echo 'Invalid PORT' >&2; exit 1;; esac
test "${PANEL_TEST_ENV:-}" = "1" || { echo 'This image requires an isolated test environment.' >&2; exit 1; }
test "${MYSQLDATABASE:-}" = "gpanel_v2_test" || { echo 'Dedicated gpanel_v2_test database required.' >&2; exit 1; }
test "${PANEL_STATE_DIR:-}" = "/var/lib/gpanel/state" || { echo 'Private state directory required.' >&2; exit 1; }
mkdir -p "$PANEL_STATE_DIR"
chown www-data:www-data "$PANEL_STATE_DIR"
chmod 700 "$PANEL_STATE_DIR"
cp /opt/gpanel/deploy/apache.conf /etc/apache2/sites-available/000-default.conf
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:8080>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
php /opt/gpanel/deploy/initialize-test.php
# Migration exécutée séparément, après sauvegarde vérifiée.
# Les tests synthétiques restent disponibles hors démarrage.
php -r '
require "/var/www/html/config.php";
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== "mysql") exit(1);
if ($pdo->query("SELECT DATABASE()")->fetchColumn() !== "gpanel_v2_test") exit(1);
foreach (["streams", "categories", "panel_test_bootstrap"] as $table) {
    $stmt = $pdo->prepare(
        "SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    if (strcasecmp((string)$stmt->fetchColumn(), "InnoDB") !== 0) exit(1);
}
foreach (["idx_v2_catalog", "idx_v2_identity", "idx_v2_episode_transport"] as $index) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->execute(["streams", $index]);
    if ((int)$stmt->fetchColumn() === 0) exit(1);
}
$marker = $pdo->query(
    "SELECT marker FROM panel_test_bootstrap WHERE id=1"
)->fetchColumn();
if ($marker !== "gpanel-v2-synthetic-test-v1") exit(1);
echo "PASS: V2 database structure verified.\n";
'
# Enforce the single PHP-compatible MPM at runtime as well as image build.
a2dismod mpm_event mpm_worker
a2enmod mpm_prefork
apache2ctl -t -f /opt/gpanel/deploy/httpd.conf
exec apache2-foreground -f /opt/gpanel/deploy/httpd.conf
