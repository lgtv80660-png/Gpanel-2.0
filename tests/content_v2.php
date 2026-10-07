<?php
require __DIR__.'/import_v2.php';require_once __DIR__.'/../panel_content.php';
$pdo->exec('CREATE TABLE content_settings(stream_id INTEGER PRIMARY KEY,quality TEXT,quality_origin TEXT,source_override TEXT,source_provider INTEGER,source_remote TEXT,series_parent INTEGER,season_number INTEGER,episode_number INTEGER,production_ref TEXT)');
$pdo->exec('CREATE TABLE fournisseurs(id INTEGER PRIMARY KEY,nom TEXT,type TEXT,active INTEGER,url_base TEXT,user TEXT,pass TEXT)');
$pdo->exec('CREATE TABLE movie_group_sources(stream_id INTEGER PRIMARY KEY,group_id INTEGER,role TEXT)');
$pdo->exec('CREATE TABLE movie_identifiers(stream_id INTEGER PRIMARY KEY,tmdb_id TEXT,imdb_id TEXT,release_year TEXT)');
$pdo->exec("INSERT INTO fournisseurs VALUES(1,'Original','xtream',1,'https://fixture.invalid','u','p'),(2,'Replacement','xtream',1,'https://second.invalid','u2','p2'),(3,'M3U','m3u',1,'https://playlist.invalid','','')");
check(panel_import_quality_details(['name'=>'Film 4K','height'=>720])===['value'=>'720p','origin'=>'verified'],'metadata wins over title');
check(panel_import_quality_details(['name'=>'Movie 2160p'])===['value'=>'4K','origin'=>'estimated'],'2160p title estimated');
foreach(['4Kids','SDCard','H264 HEVC MKV','Movie 720p 1080p','720people'] as $name)check(panel_import_quality(['name'=>$name])==='unknown','ambiguous or codec title ignored '.$name);
panel_import_decisions($pdo,1,'movie-category',['cat'=>'exclude']);
check(panel_import_categories($pdo,1,'movie',[['category_id'=>'cat','category_name'=>'Remote']],['cat'],'Original')===[],'future category exclusion blocks category map');
panel_import_decisions($pdo,1,'movie-category',['cat'=>'import']);
check(panel_import_categories($pdo,1,'movie',[['category_id'=>'cat','category_name'=>'Remote']],['cat'],'Original')['cat']===10,'explicit category reactivation preserves ID');
$map=['cat'=>10];$items=[['stream_id'=>'1','name'=>'Remote 4K','category_id'=>'cat','height'=>1080]];
process_and_insert_streams($pdo,1,'movie','xtream',$items,$map);
check(content_settings($pdo,1)['quality']==='1080p' && content_settings($pdo,1)['quality_origin']==='verified','trusted metadata saved for local management');
content_setting($pdo,1,'quality','720p');content_setting($pdo,1,'quality_origin','manual');content_setting($pdo,1,'source_override','https://manual.invalid/a.mp4');
process_and_insert_streams($pdo,1,'movie','xtream',$items,$map);
check(content_settings($pdo,1)['quality']==='720p','reimport preserves manual quality');
$row=$pdo->query('SELECT * FROM streams WHERE stream_id=1')->fetch(PDO::FETCH_ASSOC);$row['type']='xtream';
check(content_playback($pdo,$row)['direct_source']==='https://manual.invalid/a.mp4','manual URL overrides provider transport');
content_setting($pdo,1,'source_override',null);content_setting($pdo,1,'source_provider',2);content_setting($pdo,1,'source_remote','replacement-remote');
$resolved=content_playback($pdo,$row);check($resolved['stream_id']==1 && $resolved['direct_source']==='replacement-remote' && $resolved['url_base']==='https://second.invalid','replacement provider preserves canonical ID');
$pdo->exec("INSERT INTO streams(stream_id,fournisseur_id,stream_name,stream_type,category_id,visible,direct_source,remote_stream_id) VALUES(910,1,'Series','series',10,1,'series-old','series-old'),(911,1,'Episode','episode',10,1,'episode-id','episode-id')");
content_setting($pdo,910,'source_provider',2);content_setting($pdo,910,'source_remote','series-new');content_setting($pdo,911,'series_parent',910);
$ep=$pdo->query('SELECT * FROM streams WHERE stream_id=911')->fetch(PDO::FETCH_ASSOC);$ep['type']='xtream';check(content_playback($pdo,$ep)['url_base']==='https://second.invalid' && content_playback($pdo,$ep)['direct_source']==='episode-id','series replacement inherited by episode without changing ID');
$pdo->exec('UPDATE fournisseurs SET active=0 WHERE id=2');try{content_playback($pdo,$ep);check(false,'inactive replacement should fail');}catch(RuntimeException $e){check(true,'inactive replacement fails closed');}
$pdo->exec('UPDATE fournisseurs SET active=1 WHERE id=2');
$pdo->beginTransaction();try{content_delete($pdo,$row,'wrong');check(false,'wrong confirmation');}catch(RuntimeException $e){$pdo->rollBack();check(true,'explicit deletion confirmation required');}
$pdo->beginTransaction();content_delete($pdo,$row,'SUPPRIMER 1');$pdo->commit();check(!isset(panel_import_existing($pdo,1,'movie')['1'])&&isset(panel_import_exclusions($pdo,1,'movie')['1']),'delete blocks future reimport');
check(process_and_insert_streams($pdo,1,'movie','xtream',$items,$map)===0,'explicitly deleted supplier content not resurrected');
echo "Content V2 fixtures complete\n";
