<?php
// G-PANEL 2.0: explicit Railway MySQL variables only.

function panel_db_env($name, $default = null) {
    $value = $_ENV[$name]
        ?? $_SERVER[$name]
        ?? getenv($name);

    if ($value === false || $value === null) {
        return $default;
    }

    return (string)$value;
}

try {
    $host = panel_db_env('MYSQLHOST');
    $port = panel_db_env('MYSQLPORT', '3306');
    $db   = panel_db_env('MYSQLDATABASE');
    $user = panel_db_env('MYSQLUSER');
    $pass = panel_db_env('MYSQLPASSWORD', '');

    if (!$host || !$db || !$user) throw new RuntimeException('Explicit database configuration required');
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

} catch (Throwable $e) {
    http_response_code(503);
    exit('Database unavailable.');
}
