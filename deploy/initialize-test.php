<?php
// Fresh synthetic test data only. Refuses pre-existing databases without our marker.
if (PHP_SAPI !== 'cli' || getenv('PANEL_TEST_ENV') !== '1' || getenv('MYSQLDATABASE') !== 'gpanel_v2_test') exit(2);
function required_test_env(string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') throw new RuntimeException('Missing required variable: '.$name);
    return $value;
}
try {
    $adminPassword = required_test_env('TEST_ADMIN_PASSWORD');
    $clientPassword = required_test_env('TEST_CLIENT_PASSWORD');
    if (strlen($adminPassword) < 16 || strlen($clientPassword) < 16) throw new RuntimeException('Test passwords require at least 16 characters');
    $host = required_test_env('MYSQLHOST');
    $port = required_test_env('MYSQLPORT');
    if (!ctype_digit($port)) throw new RuntimeException('Invalid database port');
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", required_test_env('MYSQLUSER'), required_test_env('MYSQLPASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS gpanel_v2_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE gpanel_v2_test');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($tables) {
        if (!in_array('panel_test_bootstrap', $tables, true) || $pdo->query('SELECT marker FROM panel_test_bootstrap WHERE id=1')->fetchColumn() !== 'gpanel-v2-synthetic-test-v1') throw new RuntimeException('Existing database is not an initialized synthetic test database; no changes made');
        echo "Existing synthetic test database retained.\n";
        exit(0);
    }
    $schema = file_get_contents('/opt/gpanel/mysql.sql');
    if (preg_match('/\b(INSERT|REPLACE|DELETE|DROP|TRUNCATE)\b/i', preg_replace('/--[^\n]*/', '', $schema))) throw new RuntimeException('Schema contains data operations');
    // PDO executes individual DDL statements; no historical dump is included.
    foreach (explode(';', $schema) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
    $pdo->exec('CREATE TABLE panel_test_bootstrap (id INT PRIMARY KEY, marker VARCHAR(100) NOT NULL) ENGINE=InnoDB');
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO admins(id,username,password) VALUES(1,?,?)')->execute(['gpanel-test-admin',password_hash($adminPassword,PASSWORD_DEFAULT)]);
    $pdo->prepare('INSERT INTO clients(id,username,password,active) VALUES(1,?,?,1)')->execute(['gpanel-test-client',$clientPassword]);
    $providerUrl = 'http://127.0.0.1:'.(getenv('PORT') ?: '8080').'/__test_provider';
    $pdo->prepare("INSERT INTO fournisseurs(id,nom,type,url_base,user,pass,active) VALUES(1,'SYNTHETIC TEST ONLY','xtream',?,'fixture','fixture-only',1)")->execute([$providerUrl]);
    $category = $pdo->prepare('INSERT INTO categories(category_id,category_name,parent_id,visible,fournisseur_id,remote_category_id,content_type) VALUES(?,?,0,1,1,?,?)');
    foreach ([1=>'live',2=>'movie',3=>'series'] as $id=>$type) $category->execute([$id,'TEST '.strtoupper($type),'fixture-'.$type,$type]);
    $stream = $pdo->prepare('INSERT INTO streams(stream_id,category_id,fournisseur_id,stream_name,stream_icon,stream_type,direct_source,remote_stream_id,visible,container_extension) VALUES(?,?,1,?,?,?,?,?,1,?)');
    foreach ([1=>[1,'live','ts'],2=>[2,'movie','mp4'],3=>[3,'series',null],4=>[3,'episode','mkv']] as $id=>$data) {
        [$cat,$type,$ext]=$data;
        $stream->execute([$id,$cat,'TEST '.strtoupper($type),'',$type,'fixture-'.$type,'fixture-'.$type,$ext]);
    }
    $pdo->prepare('INSERT INTO panel_test_bootstrap VALUES(1,?)')->execute(['gpanel-v2-synthetic-test-v1']);
    $pdo->commit();
    echo "Initialized synthetic data: 1 admin, 1 client, 1 loopback mock provider, 3 categories, 4 contents.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // Do not print connection details or credentials in deployment logs.
    fwrite(STDERR, "Test initialization failed; check required variables and dedicated empty database.\n");
    exit(1);
}
