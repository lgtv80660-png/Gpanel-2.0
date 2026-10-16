<?php
// Explicit CLI migration, never included by playback, startup or administration.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!in_array('--apply',$argv,true)){
    echo "PLAN ONLY: create source_vod_policy, source_vod_links, source_series_matches, source_live_disabled (InnoDB).\nNo existing tables altered; no catalogue or group rows migrated.\nPrerequisites: existing Content V2/movie groups, Sources schema and verified backup.\nApply only to a dedicated local copy: --apply --backup-confirmed --local-copy-confirmed\n";exit;
}
foreach(['--backup-confirmed','--local-copy-confirmed'] as $flag)if(!in_array($flag,$argv,true))throw new RuntimeException('Required: '.$flag);
require __DIR__.'/config.php';
foreach(['streams'=>['stream_id','stream_type','fournisseur_id'], 'content_settings'=>['stream_id','series_parent','season_number','episode_number'], 'movie_group_sources'=>['stream_id','group_id','role'], 'source_policy_version'=>['id','revision']] as $table=>$columns){
    $pdo->query('SELECT '.implode(',',$columns).' FROM '.$table.' LIMIT 0');
    $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
    if(strcasecmp((string)$q->fetchColumn(),'InnoDB')!==0)throw new RuntimeException('InnoDB required: '.$table);
}
if(!$pdo->query('SELECT revision FROM source_policy_version WHERE id=1')->fetchColumn())throw new RuntimeException('Sources migration incomplete');
if((int)$pdo->query("SELECT GET_LOCK('gpanel-vod-sources',10)")->fetchColumn()!==1)throw new RuntimeException('Migration busy');
try{
    foreach(explode(';',file_get_contents(__DIR__.'/vod_sources.sql')) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    foreach(['source_vod_policy'=>['stream_id','mode','revision'],'source_vod_links'=>['stream_id','position','enabled','candidate_id','manual_url','variant_note','source_key'],'source_series_matches'=>['series_a','series_b'],'source_live_disabled'=>['stream_id','source_key']] as $table=>$columns){
        $pdo->query('SELECT '.implode(',',$columns).' FROM '.$table.' LIMIT 0');
        $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
        if(strcasecmp((string)$q->fetchColumn(),'InnoDB')!==0)throw new RuntimeException('Existing configuration table must use InnoDB: '.$table);
    }
    echo "VOD configuration tables ready. No existing content or group changed.\n";
}finally{$pdo->query("SELECT RELEASE_LOCK('gpanel-vod-sources')");}
