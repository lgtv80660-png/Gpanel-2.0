<?php
require_once __DIR__.'/../panel_vod_session.php';
function vod_fixture(): PDO {
    $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $db->exec("CREATE TABLE fournisseurs(id INTEGER PRIMARY KEY,nom TEXT,type TEXT,active INT,url_base TEXT,user TEXT,pass TEXT,mac_address TEXT,proxy TEXT);
CREATE TABLE categories(category_id INT PRIMARY KEY,visible INT,category_name TEXT,content_type TEXT,fournisseur_id INT);
CREATE TABLE streams(stream_id INTEGER PRIMARY KEY,fournisseur_id INT,stream_type TEXT,stream_name TEXT,direct_source TEXT,container_extension TEXT,visible INT,category_id INT,vod_release_date TEXT);
CREATE TABLE content_settings(stream_id INT PRIMARY KEY,source_override TEXT,source_provider INT,source_remote TEXT,series_parent INT,season_number INT,episode_number INT);
CREATE TABLE movie_groups(group_id INTEGER PRIMARY KEY AUTOINCREMENT,label TEXT);
CREATE TABLE movie_group_sources(stream_id INT PRIMARY KEY,group_id INT,role TEXT);
CREATE TABLE movie_identifiers(stream_id INT PRIMARY KEY,tmdb_id TEXT,imdb_id TEXT,release_year TEXT);
CREATE TABLE clients(id INT PRIMARY KEY,username TEXT,password TEXT,active INT);
CREATE TABLE packs(id INT PRIMARY KEY,name TEXT,level TEXT,active INT,updated_at TEXT);
CREATE TABLE client_packs(client_id INT,pack_id INT,expires_at TEXT);
CREATE TABLE pack_rules(pack_id INT,scope TEXT,kind TEXT,reference_id INT);
INSERT INTO clients VALUES(1,'client','CLIENT_SECRET',1);
INSERT INTO packs VALUES(1,'Fixture','BASIC',1,'2026-10-16');INSERT INTO client_packs VALUES(1,1,NULL);
INSERT INTO pack_rules VALUES(1,'movie','all',0),(1,'series','all',0),(1,'live','all',0);
INSERT INTO fournisseurs VALUES(1,'First','xtream',1,'https://one.example','HOST_USER','HOST_SECRET',NULL,NULL),(2,'Second','xtream',1,'https://two.example','SECOND_USER','SECOND_SECRET',NULL,NULL),(3,'Third','xtream',1,'https://three.example','u','p',NULL,NULL);
INSERT INTO categories VALUES(1,1,'Movies','movie',1),(2,1,'Series','series',1),(3,1,'Live','live',1);
INSERT INTO streams VALUES(1,1,'live','Channel HD','11','m3u8',1,3,NULL),(2,2,'live','Channel HD','22','m3u8',1,3,NULL),
(100,1,'movie','Film (2020)','1000','mp4',1,1,'2020-01-01'),(200,2,'movie','Film (2020)','9876','mkv',1,1,'2020-01-01'),(300,3,'movie','Film (2020)','3000','mp4',1,1,'2020-01-01'),
(10,1,'series','Series','101','',1,2,NULL),(20,2,'series','Series','999','',1,2,NULL),(30,3,'series','Other series','77','',1,2,NULL),
(1101,1,'episode','Episode 1','501','mp4',1,2,NULL),(2101,2,'episode','Episode 1 HEVC VF','9988','mkv',1,2,NULL),(2102,2,'episode','Episode 2','9989','mkv',1,2,NULL),(3101,3,'episode','Episode 1','601','mp4',1,2,NULL),(2103,2,'episode','Episode 1 AAC','9990','mp4',1,2,NULL);
INSERT INTO content_settings(stream_id,series_parent,season_number,episode_number) VALUES(1101,10,1,1),(2101,20,1,1),(2102,20,1,2),(3101,30,1,1),(2103,20,1,1);
INSERT INTO movie_identifiers VALUES(100,'123',NULL,'2020'),(200,'123',NULL,'2020');");
    foreach(['sources.sql','vod_sources.sql'] as $file){$sql=file_get_contents(__DIR__.'/../'.$file);$sql=str_replace(['CHARACTER SET ascii COLLATE ascii_bin','ENGINE=InnoDB'],'',$sql);$sql=preg_replace('/UNIQUE KEY \w+ /','UNIQUE ',$sql);$db->exec($sql);}
    $db->exec('INSERT INTO source_policy_version VALUES(1,1)');return $db;
}
function vod_post(PDO $db,int $id,array $links,string $mode='custom'): array {
    $entries=[];foreach($links as $link)$entries[]=is_array($link)?$link:['candidate'=>(string)$link,'enabled'=>'1','confirmed'=>'1'];
    return ['mode'=>$mode,'revision'=>vod_policy($db,$id)['revision'],'expected_links'=>count($entries),'form_complete'=>'1','links'=>$entries];
}
