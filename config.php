<?php
// Configuration locale WAMP uniquement.
// Ne pas utiliser ce fichier en production.

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
    $host = panel_db_env('MYSQLHOST', '127.0.0.1');
    $port = panel_db_env('MYSQLPORT', '3306');
    $db   = panel_db_env('MYSQLDATABASE', 'Gpanel');
    $user = panel_db_env('MYSQLUSER', 'root');
    $pass = panel_db_env('MYSQLPASSWORD', '');

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
