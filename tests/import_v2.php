<?php
// Local in-memory data only; no configuration, provider or application DB loaded.
require __DIR__.'/../panel_import.php';
function check($ok, $name) { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE import_exclusions(fournisseur_id INTEGER,content_type TEXT,remote_stream_id TEXT,PRIMARY KEY(fournisseur_id,content_type,remote_stream_id))');
$pdo->exec('CREATE TABLE categories(category_id INTEGER PRIMARY KEY,category_name TEXT,parent_id INTEGER,visible INTEGER,fournisseur_id INTEGER,remote_category_id TEXT,content_type TEXT)');
$pdo->exec('CREATE TABLE streams(stream_id INTEGER PRIMARY KEY,fournisseur_id INTEGER,stream_name TEXT,stream_icon TEXT,stream_type TEXT,category_id TEXT,direct_source TEXT,visible INTEGER,container_extension TEXT,remote_stream_id TEXT,vod_plot TEXT,vod_cast TEXT,vod_director TEXT,vod_genre TEXT,vod_release_date TEXT,vod_rating TEXT,vod_rating_5based TEXT,vod_added TEXT,vod_backdrop TEXT,vod_trailer TEXT,vod_runtime TEXT)');
$pdo->exec("INSERT INTO categories VALUES(10,'Custom category',0,0,1,'cat','movie'),(11,'Unselected',0,1,1,'other','movie')");
$insert = $pdo->prepare("INSERT INTO streams(stream_id,fournisseur_id,stream_name,stream_icon,stream_type,category_id,direct_source,visible,container_extension,remote_stream_id,vod_plot) VALUES(?,1,'Custom title','custom-icon','movie',11,'old',0,'mkv',?,'Custom plot')");
for ($id = 1; $id <= 512; $id++) $insert->execute([$id, (string)$id]);
$pdo->exec("INSERT INTO streams(stream_id,fournisseur_id,stream_name,stream_type,direct_source,remote_stream_id) VALUES(800,1,'Episode','episode','ep','ep'),(801,2,'Other provider','movie','1','1')");
$initial = $pdo->query('SELECT * FROM streams ORDER BY stream_id')->fetchAll(PDO::FETCH_ASSOC);
panel_import_require_transactions($pdo);
$remote = [['category_id'=>'cat','category_name'=>'Remote rename']];
$items = [['stream_id'=>'1','name'=>'Provider rename','stream_icon'=>'remote-icon','category_id'=>'cat','container_extension'=>'mp4'],['stream_id'=>'new','name'=>'New movie','category_id'=>'cat','container_extension'=>'mp4']];
for ($run = 0; $run < 2; $run++) {
    $pdo->beginTransaction();
    $map = panel_import_categories($pdo,1,'movie',$remote,['cat'],'Fixture');
    check($map['cat'] === 10, 'category ID stable');
    check(process_and_insert_streams($pdo,1,'movie','xtream',array_merge($items,$items),$map) === 2,'duplicate payload handled once');
    $pdo->commit();
}
$current = $pdo->query('SELECT * FROM streams WHERE stream_id=1')->fetch(PDO::FETCH_ASSOC);
foreach (['stream_id','stream_name','stream_icon','visible','category_id','container_extension','vod_plot'] as $field) check($current[$field] === $initial[0][$field], 'customization preserved '.$field);
check($current['direct_source'] === '1','provider source refreshed');
check((int)$pdo->query('SELECT COUNT(*) FROM streams')->fetchColumn() === 515,'512 originals plus episode and other provider preserved; one new item');
check($pdo->query("SELECT category_name FROM categories WHERE category_id=10")->fetchColumn() === 'Custom category','category rename preserved');
check($pdo->query('SELECT visible FROM categories WHERE category_id=10')->fetchColumn() == 0,'hidden category preserved');
check($pdo->query('SELECT * FROM streams WHERE stream_id=800')->fetch(PDO::FETCH_ASSOC) === $initial[512],'episodes preserved');
check($pdo->query('SELECT * FROM streams WHERE stream_id=801')->fetch(PDO::FETCH_ASSOC) === $initial[513],'other provider isolated');
$before = $pdo->query('SELECT * FROM streams ORDER BY stream_id')->fetchAll(PDO::FETCH_ASSOC);
$pdo->beginTransaction();
process_and_insert_streams($pdo,1,'movie','xtream',[['stream_id'=>'rollback','name'=>'Temporary','category_id'=>'cat']],$map);
$pdo->rollBack();
check($pdo->query('SELECT * FROM streams ORDER BY stream_id')->fetchAll(PDO::FETCH_ASSOC) === $before,'rollback preserves catalogue');
check(process_and_insert_streams($pdo,1,'movie','xtream',[], $map) === 0,'empty payload no writes');
check(process_and_insert_streams($pdo,1,'movie','xtream',[['stream_id'=>'skip','name'=>'Skip','category_id'=>'other']],$map) === 0,'unselected category skipped');
$m3u = [['name'=>'Long URL fixture','url'=>'https://fixture.invalid/'.str_repeat('x',300).'.ts','group'=>'cat']];
for ($run=0;$run<2;$run++) process_and_insert_streams($pdo,3,'live','m3u',$m3u,$map);
check((int)$pdo->query('SELECT COUNT(*) FROM streams WHERE fournisseur_id=3')->fetchColumn() === 1,'long M3U URL imports idempotently with bounded hashed identity');
