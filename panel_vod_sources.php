<?php
// Shared VOD policies. Live resolution and existing movie groups stay separate.
require_once __DIR__.'/panel_sources.php';
require_once __DIR__.'/panel_content.php';
require_once __DIR__.'/panel_movie_groups.php';

function vod_sources_ready(PDO $db): bool {
    if (!sources_ready($db)) return false;
    try {
        foreach (['source_vod_policy','source_vod_links','source_series_matches','source_live_disabled'] as $table) $db->query('SELECT 1 FROM '.$table.' LIMIT 0');
        return true;
    } catch (PDOException $e) { return false; }
}
function vod_content_available(PDO $db, array $row): bool {
    if (!in_array($row['stream_type'], ['movie','series','episode'], true) || empty($row['visible']) || empty($row['category_visible'])) return false;
    if ((int)$row['fournisseur_id'] !== 0 && empty($row['active'])) return false;
    if ($row['stream_type'] === 'movie' && !movie_source_allowed($db, $row['stream_id'])) return false;
    return $row['stream_type'] !== 'episode' || content_episode_allowed($db, (int)$row['stream_id']);
}
function vod_episode_identity(PDO $db, int $id): array {
    $s = content_settings($db, $id);
    return ['parent'=>(int)($s['series_parent']??0), 'season'=>isset($s['season_number'])?(int)$s['season_number']:null,
        'episode'=>isset($s['episode_number'])?(int)$s['episode_number']:null];
}
function vod_series_confirmed(PDO $db, int $a, int $b): bool {
    if ($a === $b) return $a > 0;
    $q=$db->prepare('SELECT 1 FROM source_series_matches WHERE series_a=? AND series_b=?');
    $q->execute([min($a,$b),max($a,$b)]); return (bool)$q->fetchColumn();
}
function vod_save_episode_identity(PDO $db,int $id,array $post): void {
    $season=filter_var($post['season']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    $episode=filter_var($post['episode']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($season===false || $episode===false || ($post['confirmed']??'')!=='1')throw new RuntimeException('Confirmer la saison et le numéro exacts.');
    $db->beginTransaction();
    try {
        sources_lock($db);$row=sources_row($db,$id);$identity=vod_episode_identity($db,$id);
        if($row['stream_type']!=='episode' || !$identity['parent'])throw new RuntimeException('Épisode avec série parente requis.');
        if(!hash_equals(hash('sha256',json_encode($identity)),(string)($post['identity_revision']??'')))throw new RuntimeException('Identité modifiée : rechargez.');
        content_setting($db,$id,'season_number',$season);content_setting($db,$id,'episode_number',$episode);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function vod_assert_equivalent(PDO $db, array $anchor, array $candidate): void {
    if ($candidate['stream_type'] !== $anchor['stream_type'] || !in_array($anchor['stream_type'],['movie','episode'],true)) throw new RuntimeException('Types de contenu incompatibles.');
    if (!vod_content_available($db,$candidate)) throw new RuntimeException('Source inactive, masquée ou désactivée.');
    if ((int)$anchor['stream_id'] === (int)$candidate['stream_id']) return;
    if ($anchor['stream_type'] === 'movie') return; // Explicit per-content admin confirmation, never a group merge.
    $a=vod_episode_identity($db,(int)$anchor['stream_id']); $b=vod_episode_identity($db,(int)$candidate['stream_id']);
    foreach ([$a,$b] as $identity) {
        if (!$identity['parent'] || $identity['season']===null || $identity['season']<0 || $identity['episode']===null || $identity['episode']<1) throw new RuntimeException('Série, saison et numéro d’épisode requis pour les deux sources.');
        $parent=sources_row($db,$identity['parent']);
        if ($parent['stream_type']!=='series' || !vod_content_available($db,$parent)) throw new RuntimeException('Série parente indisponible.');
    }
    if ($a['season']!==$b['season'] || $a['episode']!==$b['episode'] || !vod_series_confirmed($db,$a['parent'],$b['parent'])) throw new RuntimeException('Confirmer les séries équivalentes et choisir exactement la même saison et le même épisode.');
}
function vod_save_series_match(PDO $db,int $id,int $other,bool $remove=false): void {
    if (!$id || !$other || $id===$other) throw new RuntimeException('Deux séries distinctes requises.');
    $db->beginTransaction();
    try {
        sources_lock($db);
        foreach ([$id,$other] as $sid) { $row=sources_row($db,$sid); if ($row['stream_type']!=='series' || (!$remove && !vod_content_available($db,$row))) throw new RuntimeException('Série disponible requise.'); }
        $args=[min($id,$other),max($id,$other)];
        $db->prepare('DELETE FROM source_series_matches WHERE series_a=? AND series_b=?')->execute($args);
        if (!$remove) $db->prepare('INSERT INTO source_series_matches(series_a,series_b) VALUES(?,?)')->execute($args);
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}
function vod_policy(PDO $db,int $id): array {
    $q=$db->prepare('SELECT * FROM source_vod_policy WHERE stream_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:['mode'=>'legacy','revision'=>0];
}
function vod_links(PDO $db,int $id): array {
    $q=$db->prepare('SELECT * FROM source_vod_links WHERE stream_id=? ORDER BY position');$q->execute([$id]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function vod_save_policy(PDO $db,int $id,array $post,?callable $dns=null): void {
    if (!vod_sources_ready($db)) throw new RuntimeException('Migration VOD requise.');
    $mode=$post['mode']??'';
    if (!in_array($mode,['custom','hosts','legacy'],true)) throw new RuntimeException('Mode invalide.');
    $input=$post['links']??[];
    if (!is_array($input) || (int)($post['expected_links']??-1)!==count($input) || ($post['form_complete']??'')!=='1') throw new RuntimeException('Requête incomplète : aucune modification.');
    $db->beginTransaction();
    try {
        sources_lock($db);$anchor=sources_row($db,$id);
        if(!in_array($anchor['stream_type'],['movie','episode'],true) || !vod_content_available($db,$anchor)) throw new RuntimeException('Film ou épisode disponible requis.');
        $current=vod_policy($db,$id);
        if((int)$current['revision']!==(int)($post['revision']??-1)) throw new RuntimeException('Configuration modifiée : rechargez la page.');
        $previous=[];foreach(vod_links($db,$id) as $link)$previous[(int)$link['position']]=$link;
        $links=[];$seen=[];
        if($mode!=='legacy') foreach($input as $entry) {
            if(!is_array($entry))throw new RuntimeException('Source invalide.');
            $candidate=filter_var($entry['candidate']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if($candidate===false)throw new RuntimeException('Identifiant invalide.');
            $url=trim((string)($entry['url']??''));$old=$previous[(int)($entry['saved_position']??-1)]??null;
            if(!$candidate && $url==='' && $old && !$old['candidate_id'])$url=$old['manual_url'];
            if(!$candidate && $url==='')continue;
            if($candidate && $url!=='')throw new RuntimeException('Choisir un contenu OU une URL.');
            $unchanged=$old && (int)$old['candidate_id']===$candidate && ($candidate || $old['manual_url']===$url);
            if(!$unchanged && ($candidate!==$id) && ($entry['confirmed']??'')!=='1')throw new RuntimeException('Confirmer explicitement le contenu, l’édition et les variantes audio/codec.');
            if($candidate){vod_assert_equivalent($db,$anchor,sources_row($db,$candidate));$key=hash('sha256','stream:'.$candidate);}
            else {$url=sources_url($url,$dns)['url'];$key=hash('sha256','url:'.$url);}
            if(isset($seen[$key]))throw new RuntimeException('Source dupliquée.');$seen[$key]=true;
            $note=trim((string)($entry['variant_note']??''));if(mb_strlen($note)>255)throw new RuntimeException('Note trop longue.');
            $links[]=[$id,count($links),isset($entry['enabled'])&&$entry['enabled']==='1'?1:0,$candidate?:null,$candidate?null:$url,$note,$key];
        }
        if($mode!=='legacy' && !$links)throw new RuntimeException('Au moins une source requise.');
        $db->prepare('DELETE FROM source_vod_links WHERE stream_id=?')->execute([$id]);
        $q=$db->prepare('INSERT INTO source_vod_links(stream_id,position,enabled,candidate_id,manual_url,variant_note,source_key) VALUES(?,?,?,?,?,?,?)');foreach($links as $link)$q->execute($link);
        $db->prepare('DELETE FROM source_vod_policy WHERE stream_id=?')->execute([$id]);
        // Keep a monotonic revision even after restoring legacy resolution.
        $db->prepare('INSERT INTO source_vod_policy(stream_id,mode,revision) VALUES(?,?,?)')->execute([$id,$mode,(int)$current['revision']+1]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function vod_row_for_playback(PDO $db,int $id): array {
    $row=sources_row($db,$id);
    if(!vod_content_available($db,$row) || !in_array($row['stream_type'],['movie','episode'],true))throw new RuntimeException('Contenu indisponible.');
    $settings=content_settings($db,$id);
    if($row['stream_type']==='episode' && !empty($settings['series_parent']) && empty($settings['source_provider']) && empty($settings['source_override'])) {
        $parent=content_settings($db,(int)$settings['series_parent']);
        if(!empty($parent['source_provider']) && (int)$parent['source_provider']!==(int)$row['fournisseur_id'])throw new RuntimeException('Correspondance explicite de l’épisode requise pour changer de fournisseur.');
    }
    $row=content_playback($db,$row);
    $row['type']=$row['type']??'m3u';return $row;
}
function vod_confirmed_candidates(PDO $db,array $anchor): array {
    $ids=[(int)$anchor['stream_id']];
    if($anchor['stream_type']==='movie' && movie_groups_ready($db)) {
        $q=$db->prepare("SELECT b.stream_id FROM movie_group_sources a JOIN movie_group_sources b ON a.group_id=b.group_id WHERE a.stream_id=? AND a.role<>'disabled' AND b.role<>'disabled' ORDER BY CASE WHEN b.role='primary' THEN 0 ELSE 1 END,b.stream_id");$q->execute([$anchor['stream_id']]);
        $group=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));if($group)$ids=$group;
    }
    return array_values(array_unique($ids));
}
function vod_plan(PDO $db,int $id,?int $explicit=null): array {
    $anchor=sources_row($db,$id);if(!vod_content_available($db,$anchor) || !in_array($anchor['stream_type'],['movie','episode'],true))throw new RuntimeException('Contenu indisponible.');
    $policy=vod_sources_ready($db)?vod_policy($db,$id):['mode'=>'legacy','revision'=>0];$links=[];
    if($policy['mode']==='legacy') {
        foreach(vod_confirmed_candidates($db,$anchor) as $candidate)$links[]=['candidate_id'=>$candidate,'manual_url'=>null,'enabled'=>1];
    } else $links=vod_links($db,$id);
    $out=[];$seen=[];
    foreach($links as $position=>$link) {
        if(!$link['enabled'])continue;
        try {
            if($link['candidate_id']) {
                $candidate=sources_row($db,(int)$link['candidate_id']);vod_assert_equivalent($db,$anchor,$candidate);
                $row=vod_row_for_playback($db,(int)$candidate['stream_id']);
            } else $row=['type'=>'m3u','direct_source'=>$link['manual_url'],'stream_type'=>$anchor['stream_type'],'fournisseur_id'=>0];
            $row['_position']=$position;$row['_candidate']=(int)($link['candidate_id']??0);
            if($anchor['stream_type']==='episode')$row['_episode_identity']=$link['candidate_id']?vod_episode_identity($db,(int)$link['candidate_id']):vod_episode_identity($db,$id);
            $key=$link['candidate_id']?'id:'.$link['candidate_id']:'url:'.hash('sha256',$link['manual_url']);
            if(!isset($seen[$key])){$seen[$key]=true;$out[]=$row;}
        }catch(Throwable $e){if($position===0)throw new RuntimeException('PRIMARY indisponible ; corriger la configuration.');}
    }
    if($policy['mode']==='custom' && (!$links || !$links[0]['enabled']))$out=[];
    if($policy['mode']==='hosts') {
        $kind=$anchor['stream_type']==='movie'?'movie':'series';
        $q=$db->prepare("SELECT * FROM source_host_roles WHERE kind=? AND role<>'neutral' ORDER BY CASE WHEN role='primary' THEN 0 ELSE 1 END,backup_order");$q->execute([$kind]);$ordered=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $role){
            $matches=array_values(array_filter($out,fn($r)=>(int)$r['fournisseur_id']===(int)$role['fournisseur_id']));
            if(count($matches)!==1){if($role['role']==='primary')return ['anchor'=>$id,'kind'=>$anchor['stream_type'],'revision'=>'unavailable','rows'=>[]];continue;}
            if($role['role']==='primary')$ordered[0]=$matches[0];else $ordered[(int)$role['backup_order']]=$matches[0];
        }
        if(!isset($ordered[0]))$out=[];else {ksort($ordered);$out=array_values($ordered);}
    }
    if($explicit!==null){$out=array_values(array_filter($out,fn($r)=>$r['_candidate']===$explicit));}
    // All fields remain server-side. Fingerprint invalidates capabilities after settings,
    // metadata, group, visibility, credentials or host priorities change.
    $revision=hash('sha256',json_encode([$policy,$out],JSON_THROW_ON_ERROR));
    return ['anchor'=>$id,'kind'=>$anchor['stream_type'],'revision'=>$revision,'rows'=>$out];
}
function vod_suggestions(PDO $db,array $anchor): array {
    $q=$db->prepare("SELECT s.stream_id,s.stream_name,s.fournisseur_id,s.stream_type,s.vod_release_date,f.nom,i.tmdb_id,i.imdb_id,i.release_year FROM streams s LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id LEFT JOIN movie_identifiers i ON i.stream_id=s.stream_id WHERE s.stream_type=? AND s.stream_id<>? AND s.visible=1 ORDER BY s.stream_id");
    $q->execute([$anchor['stream_type'],$anchor['stream_id']]);$out=[];
    if($anchor['stream_type']==='movie') {
        $iq=$db->prepare('SELECT * FROM movie_identifiers WHERE stream_id=?');$iq->execute([$anchor['stream_id']]);$keys=movie_candidate_keys(array_merge($anchor,$iq->fetch(PDO::FETCH_ASSOC)?:[]));
        foreach($q as $row)if(array_intersect($keys,movie_candidate_keys($row)))$out[]=$row;
    } elseif($anchor['stream_type']==='episode') {
        foreach($q as $row){try{vod_assert_equivalent($db,$anchor,sources_row($db,(int)$row['stream_id']));$out[]=$row;}catch(Throwable $e){}}
    } else {
        $name=mb_strtolower(trim($anchor['stream_name']),'UTF-8');
        foreach($q as $row)if(mb_strtolower(trim($row['stream_name']),'UTF-8')===$name)$out[]=$row;
    }
    return $out;
}
