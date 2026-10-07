<?php
// Local catalogue operations only; no provider transport or schema side effects.
function content_settings(PDO $pdo, int $id): array {
    $q=$pdo->prepare('SELECT * FROM content_settings WHERE stream_id=?'); $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}
function content_setting(PDO $pdo, int $id, string $field, $value): void {
    if (!in_array($field,['quality','quality_origin','source_override','source_provider','source_remote','series_parent','season_number','episode_number','production_ref'],true)) throw new InvalidArgumentException('Champ invalide');
    $q=$pdo->prepare('SELECT stream_id FROM content_settings WHERE stream_id=?'); $q->execute([$id]);
    if (!$q->fetchColumn()) $pdo->prepare('INSERT INTO content_settings(stream_id) VALUES (?)')->execute([$id]);
    $pdo->prepare("UPDATE content_settings SET $field=? WHERE stream_id=?")->execute([$value,$id]);
}
function content_url(string $url, bool $optional=false): string {
    $url=trim($url); if ($optional && $url==='') return '';
    $p=parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme']??''),['http','https'],true) || empty($p['host']) || preg_match('/[\x00-\x20\x7f]/',$url) || strlen($url)>8192) throw new RuntimeException('URL HTTP/HTTPS valide requise.');
    return $url;
}
function content_delete(PDO $pdo, array $row, string $confirmation): void {
    $id=(int)$row['stream_id'];
    if ($confirmation!=='SUPPRIMER '.$id) throw new RuntimeException('Confirmer avec SUPPRIMER '.$id);
    $q=$pdo->prepare('SELECT COUNT(*) FROM content_settings WHERE series_parent=?'); $q->execute([$id]);
    if ($q->fetchColumn() && (int)$row['fournisseur_id']===0) throw new RuntimeException('Supprimer explicitement les épisodes avant la série.');
    if ((int)$row['fournisseur_id']!==0) {
        require_once __DIR__.'/panel_import.php';
        $rid=(string)($row['remote_stream_id']?:$row['direct_source']);
        $q=$pdo->prepare('SELECT type FROM fournisseurs WHERE id=?');$q->execute([$row['fournisseur_id']]);if($q->fetchColumn()==='m3u')$rid='m3u:'.hash('sha256',(string)$row['direct_source']);
        if ($rid!=='') panel_import_decisions($pdo,(int)$row['fournisseur_id'],$row['stream_type'],[$rid=>'exclude']);
    }
    if ($row['stream_type']==='movie') {
        $q=$pdo->prepare('SELECT group_id FROM movie_group_sources WHERE stream_id=?');$q->execute([$id]);
        if ($q->fetchColumn()) throw new RuntimeException('Retirer du groupe VOD avant suppression.');
        $pdo->prepare('DELETE FROM movie_identifiers WHERE stream_id=?')->execute([$id]);
    }
    $pdo->prepare('DELETE FROM content_settings WHERE stream_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM streams WHERE stream_id=?')->execute([$id]);
}
function content_save(PDO $pdo, array $post, ?array $row): int {
    $personal=!$row || (int)$row['fournisseur_id']===0;
    $kind=$row['stream_type']??($post['kind']??'live');
    if (!in_array($kind,['live','movie','series','episode'],true)) throw new RuntimeException('Type invalide.');
    $name=trim((string)($post['name']??''));
    if ($name==='' || mb_strlen($name)>900) throw new RuntimeException('Titre requis, maximum 900 caractères.');
    $q=$pdo->prepare('SELECT * FROM categories WHERE category_id=?');$q->execute([(int)($post['category']??0)]);$cat=$q->fetch(PDO::FETCH_ASSOC);
    if (!$cat || ($personal ? (int)$cat['fournisseur_id']!==0 : (int)$cat['fournisseur_id']!==(int)$row['fournisseur_id']) || $cat['content_type']!==($kind==='episode'?'series':$kind)) throw new RuntimeException('Catégorie du même type et propriétaire requise.');
    $icon=content_url((string)($post['icon']??''),true);
    $source=content_url((string)($post['source']??''),!$personal || $kind==='series');
    $ext=(string)($post['extension']??'');
    if (!in_array($ext,['','mp4','mkv','avi','ts','m2ts','mov','wmv','flv','webm','m4v','mpg','mpeg','m3u8'],true)) throw new RuntimeException('Conteneur invalide.');
    $quality=(string)($post['quality']??'');
    if (!in_array($quality,['','unknown','SD','720p','1080p','4K'],true)) throw new RuntimeException('Qualité invalide.');
    $replacement=(int)($post['source_provider']??0);$remote=trim((string)($post['source_remote']??''));
    if (!$personal && $replacement) {
        $q=$pdo->prepare('SELECT type FROM fournisseurs WHERE id=? AND active=1');$q->execute([$replacement]);$replacementType=$q->fetchColumn();
        if(!$replacementType || $remote==='' || strlen($remote)>255 || preg_match('/[\x00-\x1f\x7f]/',$remote) || ($kind==='series'&&$replacementType!=='xtream'))throw new RuntimeException('Fournisseur actif et identité distante requis ; séries : Xtream uniquement.');
        if($replacementType==='m3u')content_url($remote);
        if($source!=='')throw new RuntimeException('Choisir une URL directe ou un fournisseur de remplacement.');
    }
    if (!$personal && $kind==='series' && $source!=='')throw new RuntimeException('Pour une série, choisir le fournisseur et son ID distant.');
    $parent=0;
    if ($personal && $kind==='episode') {
        $parent=(int)($post['parent']??0);$q=$pdo->prepare("SELECT stream_id FROM streams WHERE stream_id=? AND fournisseur_id=0 AND stream_type='series' AND category_id=?");$q->execute([$parent,$cat['category_id']]);
        if (!$q->fetchColumn() || (int)($post['season']??0)<1 || (int)($post['episode']??0)<1) throw new RuntimeException('Série personnelle, saison et épisode valides requis.');
    }
    $fields=['stream_name'=>$name,'stream_icon'=>$icon,'category_id'=>$cat['category_id'],'container_extension'=>$ext,'visible'=>isset($post['visible'])?1:0];
    foreach (['plot','cast','director','genre','release_date','rating','runtime'] as $field) $fields['vod_'.$field]=mb_substr(trim((string)($post[$field]??'')),0,4000);
    if ($personal) $fields['direct_source']=$source;
    if ($row) { $id=(int)$row['stream_id'];$pdo->prepare('UPDATE streams SET '.implode(',',array_map(fn($f)=>$f.'=?',array_keys($fields))).' WHERE stream_id=?')->execute([...array_values($fields),$id]); }
    else { $fields['fournisseur_id']=0;$fields['stream_type']=$kind;$pdo->prepare('INSERT INTO streams ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')')->execute(array_values($fields));$id=(int)$pdo->lastInsertId(); }
    content_setting($pdo,$id,'quality',$quality?:null);content_setting($pdo,$id,'quality_origin',$quality!==''?'manual':null);
    if (!$personal) {content_setting($pdo,$id,'source_override',$source?:null);content_setting($pdo,$id,'source_provider',$replacement?:null);content_setting($pdo,$id,'source_remote',$replacement?$remote:null);}
    if ($parent) {content_setting($pdo,$id,'series_parent',$parent);content_setting($pdo,$id,'season_number',(int)$post['season']);content_setting($pdo,$id,'episode_number',(int)$post['episode']);}
    if ($personal) { $ref=trim((string)($post['production_ref']??''));if (strlen($ref)>128) throw new RuntimeException('Référence production trop longue.');content_setting($pdo,$id,'production_ref',$ref?:null); }
    return $id;
}
function content_playback(PDO $pdo, array $row): array {
    $settings=content_settings($pdo,(int)$row['stream_id']);
    if ($row['stream_type']==='episode' && !empty($settings['series_parent']) && empty($settings['source_provider']) && empty($settings['source_override'])) {
        $parent=content_settings($pdo,(int)$settings['series_parent']);
        if(!empty($parent['source_provider'])) {$settings['source_provider']=$parent['source_provider'];$settings['source_remote']=$row['direct_source'];}
    }
    if (!empty($settings['source_provider'])) {
        $q=$pdo->prepare('SELECT * FROM fournisseurs WHERE id=? AND active=1');$q->execute([$settings['source_provider']]);$provider=$q->fetch(PDO::FETCH_ASSOC);
        if(!$provider)throw new RuntimeException('Replacement provider unavailable');
        foreach(['type','url_base','user','pass','mac_address','proxy'] as $key)$row[$key]=$provider[$key]??null;
        $row['direct_source']=$settings['source_remote'];
    }
    if (!empty($settings['source_override'])) { $row['direct_source']=$settings['source_override'];$row['type']='m3u'; }
    if ((int)$row['fournisseur_id']===0) $row['type']='m3u';
    return $row;
}
function content_episode_allowed(PDO $pdo, int $id): bool {
    $q=$pdo->prepare("SELECT s.visible,c.visible AS category_visible,f.active,s.fournisseur_id FROM content_settings x LEFT JOIN streams s ON s.stream_id=x.series_parent LEFT JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id WHERE x.stream_id=? AND x.series_parent IS NOT NULL");$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return !$row || ($row['visible'] && $row['category_visible'] && ((int)$row['fournisseur_id']===0 || $row['active']));
}
