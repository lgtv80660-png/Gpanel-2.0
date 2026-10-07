<?php
require __DIR__.'/import_v2.php';
require __DIR__.'/../panel_import_catalog.php';
check(panel_import_container(['codec'=>'h264'],'xtream') === 'unknown','codec is not a container');
check(panel_import_container(['container_extension'=>'HEVC'],'xtream') === 'unknown','codec in extension remains unknown');
check(panel_import_container(['direct_source'=>'https://fixture.invalid/file.MKV?token=x'],'xtream') === 'mkv','URL container without query');
check(panel_import_quality(['name'=>'Movie 4K','codec'=>'hevc']) === '4K','explicit title quality estimated');
foreach ([720=>'720p',1080=>'1080p',2160=>'4K',480=>'SD'] as $height=>$quality) check(panel_import_quality(['info'=>['video'=>['height'=>$height]]]) === $quality,'metadata height '.$height);
check(panel_import_quality(['resolution'=>'1920x1080']) === '1080p','explicit resolution');
$fixture = [];
for($i=1;$i<=103;$i++) $fixture[(string)$i] = ['stream_id'=>(string)$i,'name'=>'Fixture '.$i,'category_id'=>'cat','container_extension'=>'mp4','height'=>1080];
$page=panel_import_page($fixture,'xtream','cat','mp4','1080p',2);
check(count($page['items'])===50 && $page['total']===103 && $page['pages']===3 && array_key_first($page['items'])===51,'filtered pagination keeps identities');
check(count(panel_import_page($fixture,'xtream','cat','mkv','',1)['items'])===0,'container filter');
check(panel_import_page($fixture,'xtream','','','',999)['page']===3,'page clamped');
check(panel_import_page($fixture,'xtream','other','','',1)['total']===0,'category filter');
check(panel_import_page($fixture,'xtream','','','unknown',1)['total']===0,'quality filter');
$before=$pdo->query('SELECT * FROM streams ORDER BY stream_id')->fetchAll(PDO::FETCH_ASSOC);
$pdo->beginTransaction();
panel_import_decisions($pdo,1,'movie',['blocked'=>'exclude','1'=>'exclude']);
$pdo->commit();
$blocked=[['stream_id'=>'blocked','name'=>'Rejected','category_id'=>'cat']];
for($run=0;$run<2;$run++) check(process_and_insert_streams($pdo,1,'movie','xtream',array_merge($blocked,[$items[0]]),$map)===0,'reimport honors persistent exclusions '.$run);
check($pdo->query('SELECT * FROM streams ORDER BY stream_id')->fetchAll(PDO::FETCH_ASSOC)===$before,'exclusion does not delete or modify existing content');
check(process_and_insert_streams($pdo,2,'movie','xtream',$blocked,$map)===1,'exclusions isolated by provider');
check(process_and_insert_streams($pdo,1,'series','xtream',[['series_id'=>'blocked','name'=>'Series','category_id'=>'cat']],$map)===1,'exclusions isolated by type');
$pdo->beginTransaction(); panel_import_decisions($pdo,1,'movie',['blocked'=>'import']);
check(process_and_insert_streams($pdo,1,'movie','xtream',$blocked,$map)===1,'explicit import lifts refusal'); $pdo->rollBack();
check(isset(panel_import_exclusions($pdo,1,'movie')['blocked']),'rollback restores exclusions');
$url=['name'=>'Rejected M3U','url'=>'https://fixture.invalid/blocked.ts','group'=>'cat'];
panel_import_decisions($pdo,3,'live',[panel_import_identity($url,'m3u')=>'exclude']);
check(process_and_insert_streams($pdo,3,'live','m3u',[$url],$map)===0,'M3U refusal uses same hashed identity');
panel_import_decisions($pdo,4,'live',['st-id'=>'exclude']);
check(process_and_insert_streams($pdo,4,'live','stalker',[['id'=>'st-id','name'=>'Rejected','cmd'=>'ffmpeg fixture','tv_genre_id'=>'cat']],$map)===0,'Stalker refusal uses same identity');
$calls=[];
$catalog=panel_import_catalog(['type'=>'xtream','url_base'=>'https://fixture.invalid','user'=>'fixture','pass'=>'fixture'],'series',function($url) use (&$calls){ $calls[]=$url; return strpos($url,'categories')!==false?'[{"category_id":"cat","category_name":"Series"}]':'[{"series_id":"s1","name":"Series","category_id":"cat"}]'; });
check(isset($catalog['items']['s1']) && count($calls)===2 && strpos($calls[1],'get_series')!==false,'Xtream selected type only');
$catalog=panel_import_catalog(['type'=>'m3u','url_base'=>'fixture'],'live',function(){ return "#EXTM3U\n#EXTINF:-1 group-title=\"cat\",Fixture\nhttps://fixture.invalid/movie.mp4\n"; });
check(count($catalog['items'])===1 && panel_import_container(reset($catalog['items']),'m3u')==='mp4','M3U catalogue parses explicit containers');
echo "Selective import fixtures complete\n";
// Stalker transport stubs: no real portal or handshake is contacted.
function stalker_normalize_portal($s) { return $s; }
function stalker_normalize_mac($s) { return $s; }
function stalker_handshake($portal,$mac,$proxy) { return ['ok'=>true,'token'=>'fixture','path'=>'/c/']; }
function stalker_js_list($data) { $raw=json_decode($data,true); return $raw['js']['data'] ?? []; }
function stalker_load($portal,$mac,$token,$type,$action,$params,$path,$proxy) {
    if ($action==='get_categories' || $action==='get_genres') return ['ok'=>true,'data'=>json_encode(['js'=>['data'=>[['id'=>'cat','title'=>'Fixture']]]])];
    $p=$params['p']??1;
    $items=$p<=2 ? [['id'=>'st'.$p,'name'=>'Fixture '.$p,'cmd'=>'fixture'.$p]] : [];
    if ($type==='series' && $action==='get_ordered_list') $items=[];
    return ['ok'=>true,'data'=>json_encode(['js'=>['data'=>$items,'total_items'=>2]])];
}
foreach(['movie','series'] as $kind) {
    $catalog=panel_import_catalog(['type'=>'stalker','url_base'=>'fixture','mac_address'=>'fixture'],$kind,function(){throw new RuntimeException('Unexpected network');});
    check(count($catalog['items'])===2 && panel_import_category($catalog['items']['st2'],'stalker')==='cat','Stalker pages and category '.$kind);
}
