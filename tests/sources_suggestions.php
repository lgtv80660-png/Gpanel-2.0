<?php
require __DIR__.'/../panel_sources.php';
$checks=0;
function verify(bool $ok,string $label): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE fournisseurs(id INT,nom TEXT,type TEXT,active INT,url_base TEXT,user TEXT,pass TEXT);CREATE TABLE categories(category_id INT,visible INT);CREATE TABLE streams(stream_id INT,fournisseur_id INT,stream_type TEXT,stream_name TEXT,direct_source TEXT,container_extension TEXT,visible INT,category_id INT)');
$ddl=file_get_contents(__DIR__.'/../sources.sql');$ddl=str_replace(['CHARACTER SET ascii COLLATE ascii_bin','ENGINE=InnoDB'],'',$ddl);$ddl=preg_replace('/UNIQUE KEY \w+ /','UNIQUE ',$ddl);$db->exec($ddl);
$db->exec("INSERT INTO source_policy_version VALUES(1,1);INSERT INTO fournisseurs VALUES(1,'DZtv','xtream',1,'https://dz.example','u','p'),(2,'FRtv','xtream',1,'https://fr.example','u','p'),(3,'Off','xtream',0,'https://off.example','u','p'),(4,'Other','m3u',1,'https://other.example','u','p');INSERT INTO categories VALUES(1,1),(2,0)");
$insert=$db->prepare('INSERT INTO streams VALUES(?,?,?, ?,?, ?,?,?)');
foreach([
 [10,1,'live','Bein Sport 7 (A) SD','10','ts',1,1],
 [20,2,'live','|AR| BEIN SPORTS 7 SD','20','ts',1,1],
 [21,2,'live','|AR| BEIN SPORTS 7 HD','21','ts',1,1],
 [22,2,'live','|AR| BEIN SPORTS 8 SD','22','ts',1,1],
 [23,2,'live','|AR| BEIN SPORTS 7 +1 SD','23','ts',1,1],
 [24,2,'live','|AR| BEIN SPORTS 7 SD','24','ts',0,1],
 [25,2,'live','|AR| BEIN SPORTS 7 SD','25','ts',1,2],
 [26,2,'movie','|AR| BEIN SPORTS 7 SD','26','mp4',1,1],
 [27,3,'live','|AR| BEIN SPORTS 7 SD','27','ts',1,1],
 [28,4,'live','|AR| BEIN SPORTS 7 SD','28','ts',1,1],
 [29,1,'live','|AR| BEIN SPORTS 7 SD','29','ts',1,1],
] as $r)$insert->execute($r);
$row=sources_row($db,10);$before=$db->query('SELECT * FROM streams')->fetchAll();
verify(sources_candidates($db,$row)===[],'strict matching still rejects approximate names');
$s=sources_suggestions($db,$row);
verify(array_column($s,'stream_id')===[20,21],'manual suggestions retain numbers/+1 and availability filters');
verify($s[0]['manual_confirmation'] && !$s[0]['quality_diff'] && $s[1]['quality_diff'],'manual flag and quality ranking');
verify($db->query('SELECT * FROM streams')->fetchAll()===$before && sources_links($db,10)===[],'suggestion search is read only');
sources_save_hosts($db,[1=>['live'=>['role'=>'primary']],2=>['live'=>['role'=>'backup','order'=>1]]],1);
$plan=sources_plan($db,10);
verify(count($plan['sources'])===1 && count($plan['warnings'])===1,'inherited backup never uses approximate suggestion');
sources_save_channel($db,10,['mode'=>'custom','revision'=>0,'links'=>[['candidate'=>10],['candidate'=>20]]]);
verify(count(sources_plan($db,10)['sources'])===2,'explicit custom choice confirms approximate backup');
sources_save_channel($db,10,['mode'=>'inherit','revision'=>1]);
verify(count(sources_plan($db,10)['sources'])===1 && sources_links($db,10)===[],'return to inherit clears manual association');
$insert->execute([30,2,'live','Bein Sport 7 (A) HD','30','ts',1,1]);
verify(array_column(sources_candidates($db,$row),'stream_id')===[30],'strict candidate unaffected');
verify(array_column(sources_suggestions($db,$row),'stream_id')===[30,20,21],'strict suggestions rank before manual variants');
verify(sources_suggestion_name('Canal 7 Event Final')!==sources_suggestion_name('Canal 7 Event Semi Final'),'event names retained');
verify(sources_suggestion_name('FR Canal 7')!==sources_suggestion_name('UK Canal 7'),'unmarked country prefixes retained');
verify(sources_suggestions($db,['stream_name'=>'SD','fournisseur_id'=>1])===[],'empty key rejected');
echo "$checks suggestion checks passed\n";
