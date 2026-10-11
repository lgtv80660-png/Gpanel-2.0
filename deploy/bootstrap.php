
<?php
declare(strict_types=1);

// G-PANEL 2.0 — Initialisation MySQL
// Installation uniquement sur une base vide.
// Aucune suppression ni migration automatique.

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$pdo = null;
$lockAcquired = false;

try {
    foreach (['MYSQLHOST', 'MYSQLDATABASE', 'MYSQLUSER'] as $key) {
        if (!getenv($key)) {
            throw new RuntimeException("Variable manquante : {$key}");
        }
    }

    $host = getenv('MYSQLHOST');
    $database = getenv('MYSQLDATABASE');
    $user = getenv('MYSQLUSER');
    $password = getenv('MYSQLPASSWORD') ?: '';
    $port = getenv('MYSQLPORT') ?: '3306';

    if (!ctype_digit($port)) {
        throw new RuntimeException('MYSQLPORT invalide');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "Connexion MySQL établie.\n";

    if ((int) $pdo->query(
        "SELECT GET_LOCK('gpanel-clean-bootstrap', 30)"
    )->fetchColumn() !== 1) {
        throw new RuntimeException('Verrou MySQL indisponible');
    }

    $lockAcquired = true;

    $tables = $pdo->query('SHOW TABLES')
        ->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('panel_installation', $tables, true)) {
        $version = $pdo->query(
            'SELECT version FROM panel_installation WHERE id=1'
        )->fetchColumn();

        if ($version !== '2.0') {
            throw new RuntimeException(
                'Version de base incompatible'
            );
        }

        echo "G-PANEL 2.0 database retained.\n";
    } else {
        if (!empty($tables)) {
            throw new RuntimeException(
                'Base non vide : initialisation refusée'
            );
        }

        if (getenv('PANEL_BOOTSTRAP') !== '1') {
            throw new RuntimeException(
                'PANEL_BOOTSTRAP=1 requis'
            );
        }

        $adminUser = getenv('PANEL_ADMIN_USERNAME');
        $adminPass = getenv('PANEL_ADMIN_PASSWORD');

        if (
            !$adminUser ||
            !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $adminUser)
        ) {
            throw new RuntimeException(
                'PANEL_ADMIN_USERNAME invalide'
            );
        }

        if (
            !is_string($adminPass) ||
            strlen($adminPass) < 16
        ) {
            throw new RuntimeException(
                'PANEL_ADMIN_PASSWORD : 16 caractères minimum'
            );
        }

        $files = [
            '/opt/gpanel/mysql.sql',
            '/var/www/html/content_v2.sql',
            '/var/www/html/movie_groups.sql',
        ];

        foreach ($files as $file) {
            if (!is_readable($file)) {
                throw new RuntimeException(
                    "Fichier SQL introuvable : {$file}"
                );
            }

            $schema = file_get_contents($file);

            if ($schema === false) {
                throw new RuntimeException(
                    "Lecture SQL impossible : {$file}"
                );
            }

            $schema = preg_replace('/--[^\n]*/', '', $schema);

            foreach (explode(';', $schema) as $sql) {
                $sql = trim($sql);

                if ($sql !== '') {
                    $pdo->exec($sql);
                }
            }

            echo "Schéma importé : " . basename($file) . "\n";
        }

        $indexes = [
            'CREATE INDEX idx_v2_catalog ON streams(stream_type,visible,category_id)',
            'CREATE INDEX idx_v2_identity ON streams(fournisseur_id,stream_type,remote_stream_id)',
            'CREATE INDEX idx_v2_episode_transport ON streams(fournisseur_id,stream_type,direct_source(191))',
            'CREATE INDEX idx_v2_category_identity ON categories(fournisseur_id,content_type,remote_category_id)',
        ];

        foreach ($indexes as $sql) {
            $pdo->exec($sql);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO admins(username,password) VALUES(?,?)'
        );

        $stmt->execute([
            $adminUser,
            password_hash($adminPass, PASSWORD_DEFAULT),
        ]);

        $pdo->exec(
            'CREATE TABLE panel_installation (
                id INT PRIMARY KEY,
                version VARCHAR(20) NOT NULL
            ) ENGINE=InnoDB'
        );

        $pdo->exec(
            "INSERT INTO panel_installation VALUES(1,'2.0')"
        );

        echo "G-PANEL 2.0 initialized; no clients or provider credentials seeded.\n";
    }
} catch (Throwable $e) {
    // Ne jamais afficher les identifiants de connexion.
    error_log(
        'G-PANEL bootstrap error [' .
        get_class($e) .
        '] : ' .
        $e->getMessage()
    );

    exit(1);
} finally {
    if ($lockAcquired && $pdo instanceof PDO) {
        try {
            $pdo->query(
                "SELECT RELEASE_LOCK('gpanel-clean-bootstrap')"
            );
        } catch (Throwable $ignored) {
        }
    }
}
