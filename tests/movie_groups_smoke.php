<?php
require __DIR__.'/../panel_movie_groups.php';
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE streams(stream_id INTEGER PRIMARY KEY,stream_name TEXT,fournisseur_id INT,stream_type TEXT,category_id INT,visible INT); CREATE TABLE categories(category_id INT,visible INT); CREATE TABLE fournisseurs(id INT,active INT,type TEXT,url_base TEXT,user TEXT,pass TEXT); CREATE TABLE movie_groups(group_id INTEGER PRIMARY KEY AUTOINCREMENT,label TEXT); CREATE TABLE movie_group_sources(stream_id INTEGER PRIMARY KEY,group_id INT,role TEXT); CREATE TABLE movie_identifiers(stream_id INTEGER PRIMARY KEY,tmdb_id TEXT,imdb_id TEXT,release_year TEXT); INSERT INTO categories VALUES(1,1); INSERT INTO fournisseurs VALUES(1,1,'xtream','http://fixture','',''),(2,1,'xtream','http://fixture','',''); INSERT INTO streams VALUES(1,'Movie',1,'movie',1,1),(2,'Movie',2,'movie',1,1),(3,'Movie',2,'movie',1,1);");
function check($ok) { if(!$ok) throw new RuntimeException('Fixture failed'); }
movie_confirm($p,[1=>'primary',2=>'backup',3=>'disabled']);
check(movie_catalog_visible($p,1));
check(!movie_catalog_visible($p,2));
check(!movie_catalog_visible($p,3));
check(movie_resolve($p,2)['stream_id']==1);
check(movie_resolve($p,1,2)['stream_id']==2);
check(movie_resolve($p,3)===false);
check(movie_resolve($p,1,3)===false);
$p->exec("UPDATE streams SET stream_name='Custom',visible=1 WHERE stream_id=3");
check(!movie_source_allowed($p,3));
$p->exec('UPDATE fournisseurs SET active=0 WHERE id=2');
check(movie_resolve($p,1,2)===false);
check(in_array('titre+année:film:2020',movie_candidate_keys(['stream_name'=>'Film (2020)'])));
check(movie_candidate_keys(['stream_name'=>'Film'])===[]);
try { movie_confirm($p,[1=>'primary',2=>'primary']); throw new LogicException('Validation missing'); } catch(RuntimeException $e) {}
echo "Movie grouping fixture OK\n";
require __DIR__.'/../panel_vod_relay.php';
$p->exec('UPDATE fournisseurs SET active=1 WHERE id=2');
check(array_column(vod_candidates($p,1),'stream_id')===[1,2]);
check(array_column(vod_candidates($p,2),'stream_id')===[1,2]);
check(array_column(vod_candidates($p,1,2),'stream_id')===[2]);
check(vod_candidates($p,3)===[]);
check(vod_candidates($p,1,3)===[]);
$p->exec('UPDATE streams SET visible=0 WHERE stream_id=2');
check(array_column(vod_candidates($p,1),'stream_id')===[1]);
check(vod_source_url(['type'=>'m3u','direct_source'=>"http://fixture/\r\nInjected: yes"])===null);
echo "VOD candidate permissions fixture OK\n";
