<?php
// CLI only. Bootstrap a NEW empty database; never reset or migrate an unknown database.
if (PHP_SAPI !== 'cli') exit(2);
try {
    foreach (['MYSQLHOST','MYSQLDATABASE','MYSQLUSER'] as $key) if (!getenv($key)) throw new RuntimeException('configuration');
    $port=getenv('MYSQLPORT')?:'3306';if(!ctype_digit($port))throw new RuntimeException('port');
    $pdo=new PDO('mysql:host='.getenv('MYSQLHOST').';port='.$port.';dbname='.getenv('MYSQLDATABASE').';charset=utf8mb4',getenv('MYSQLUSER'),getenv('MYSQLPASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if ((int)$pdo->query("SELECT GET_LOCK('gpanel-clean-bootstrap', 30)")->fetchColumn() !== 1) throw new RuntimeException('lock');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('panel_installation', $tables, true)) {
        if ($pdo->query('SELECT version FROM panel_installation WHERE id=1')->fetchColumn() !== '2.0') throw new RuntimeException('version');
        echo "G-PANEL 2.0 database retained.\n";
    } else {
        if ($tables || getenv('PANEL_BOOTSTRAP') !== '1') throw new RuntimeException('Dedicated empty database and PANEL_BOOTSTRAP=1 required');
        $username = getenv('PANEL_ADMIN_USERNAME');
        $password = getenv('PANEL_ADMIN_PASSWORD');
        if (!$username || !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username) || !is_string($password) || strlen($password)<16) throw new RuntimeException('admin');
        foreach (['/opt/gpanel/mysql.sql','/var/www/html/content_v2.sql','/var/www/html/movie_groups.sql'] as $file) {
            $schema=file_get_contents($file);
            foreach (explode(';', preg_replace('/--[^\n]*/','',$schema)) as $sql) if (trim($sql)!=='') $pdo->exec($sql);
        }
        // Adds catalog indexes to this freshly-created database only.
        $pdo->exec('CREATE INDEX idx_v2_catalog ON streams(stream_type,visible,category_id)');
        $pdo->exec('CREATE INDEX idx_v2_identity ON streams(fournisseur_id,stream_type,remote_stream_id)');
        $pdo->exec('CREATE INDEX idx_v2_episode_transport ON streams(fournisseur_id,stream_type,direct_source(191))');
        $pdo->exec('CREATE INDEX idx_v2_category_identity ON categories(fournisseur_id,content_type,remote_category_id)');
        $pdo->prepare('INSERT INTO admins(username,password) VALUES(?,?)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);
        $pdo->exec('CREATE TABLE panel_installation(id INT PRIMARY KEY,version VARCHAR(20) NOT NULL) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO panel_installation VALUES(1,'2.0')");
        echo "G-PANEL 2.0 initialized; no clients or provider credentials seeded.\n";
    }
    $pdo->query("SELECT RELEASE_LOCK('gpanel-clean-bootstrap')");
} catch (Throwable $e) {
    fwrite(STDERR,"Initialization refused or failed. Check empty dedicated MySQL database, bootstrap variables and credentials. A partial initialization requires a new empty database; no automatic deletion occurs.\n");
    exit(1);
}
