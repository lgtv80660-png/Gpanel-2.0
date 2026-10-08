<?php
// Presentation helpers only. No provider credentials or playback URLs are rendered here.
function gp_escape($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function gp_image_url($value): string {
    $value=is_scalar($value)?trim((string)$value):'';
    if(preg_match('#^/[A-Za-z0-9_/.\-]+\.(jpg|jpeg|png|webp)$#i',$value)) return 'https://image.tmdb.org/t/p/w780'.$value;
    $p=parse_url($value);
    return $p && in_array(strtolower($p['scheme']??''),['https','http'],true) && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']) && !preg_match('/(?:api[_-]?key|token|password|secret|auth)=/i',(string)($p['query']??'')) && !preg_match('/[\x00-\x20\x7f]/',$value) ? $value : '';
}
function gp_media(array $row): array {
    $poster='';foreach(['stream_icon','poster_path','poster','cover'] as $key){$poster=gp_image_url($row[$key]??'');if($poster)break;}
    $backdrop='';
    foreach(['backdrop_path','backdrop','vod_backdrop','fanart'] as $key) { $v=$row[$key]??''; if(is_string($v) && str_starts_with($v,'[')) $v=json_decode($v,true); foreach(is_array($v)?$v:[$v] as $image){$backdrop=gp_image_url($image);if($backdrop)break;}if($backdrop)break; }
    $id=(int)($row['tmdb_id']??0);
    return ['poster'=>$poster,'backdrop'=>$backdrop,'id'=>$id,'type'=>($row['stream_type']??'movie')==='series'?'tv':'movie'];
}
function gp_picture(array $row,string $kind='poster',string $class=''): string {
    $m=gp_media($row);$src=$m[$kind]??'';if($kind==='backdrop'&&!$src)$src=$m['poster']?:'assets/cinema-fallback.svg';
    $attrs='';
    if($kind==='backdrop')$attrs=' data-gp-banner="1"';
    if($m['id']) $attrs.=' data-tmdb-id="'.$m['id'].'" data-tmdb-type="'.$m['type'].'" data-media-kind="'.$kind.'"';
    return '<span class="gp-picture '.gp_escape($class).'"'.$attrs.'><span class="gp-picture-placeholder" aria-hidden="true">'.gp_icon('film').'</span><img data-gp-fallback="'.gp_escape($kind==='backdrop'&&$m['poster']&&$m['poster']!==$src?$m['poster']:'assets/cinema-fallback.svg').'" '.($src?'src="'.gp_escape($src).'"':'').' alt="'.gp_escape($row['stream_name']??'').'" loading="'.($kind==='backdrop'?'eager':'lazy').'" decoding="async" referrerpolicy="no-referrer"'.(!$src?' hidden':'').'></span>';
}
function gp_icon(string $name): string {
    $paths=['home'=>'M3 10 12 3l9 7v11H3zM9 21v-8h6v8','users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M18 8a3 3 0 0 1 0 6m1 3a4 4 0 0 1 3 4','film'=>'M3 3h18v18H3zM7 3v18M17 3v18M3 8h4m-4 8h4M17 8h4m-4 8h4','tv'=>'M3 5h18v14H3zM8 23h8M12 19v4M8 1l4 4 4-4','pack'=>'m3 8 9-5 9 5v10l-9 5-9-5zM3 8l9 5 9-5M12 13v10','chart'=>'M3 3v18h18M7 16v-4m5 4V6m5 10V9','monitor'=>'M2 4h20v14H2zM8 22h8M12 18v4M4 11h4l2-4 4 8 2-4h4','settings'=>'M12 3l8 4v5c0 5-8 9-8 9s-8-4-8-9V7zM8 12l3 3 5-6','search'=>'M21 21l-6-6M17 9a8 8 0 1 0-16 0 8 8 0 0 0 16 0','import'=>'M12 3v12m-5-5 5 5 5-5M3 17v4h18v-4','folder'=>'M3 5h7l2 3h9v13H3z','crown'=>'M3 7l4 5 5-9 5 9 4-5-2 14H5z','arrow'=>'M5 12h14m-6-6 6 6-6 6'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.($paths[$name]??$paths['folder']).'"/></svg>';
}
function gp_navigation(): void {
    $route=basename($_SERVER['SCRIPT_NAME']??'admin.php');$view=$_GET['view']??'';$type=$_GET['type']??'';
    $links=[['admin.php','Dashboard','home'],['admin.php?view=clients','Clients','users'],['packs.php','Packs','pack'],['panel_catalog.php?type=live','Live TV','tv'],['panel_catalog.php?type=movie','Films','film'],['panel_catalog.php?type=series','Séries','film'],['statistics.php','Statistiques','chart'],['live_monitor.php','Monitoring Live','monitor'],['admin.php?view=sources','Sources','folder'],['editor.php','Bouquets','folder'],['import_selective.php','Imports sélectifs','import'],['importer.php','Synchronisation','import'],['movie_duplicates.php','Regroupement VOD','film'],['personal_content.php','Contenus personnels','folder'],['panel_diagnostics.php','Diagnostics','monitor'],['system_admin.php','Administration système','settings']];
    ?><aside class="sidebar gp-cinema-sidebar" id="gp-navigation"><a class="gp-wordmark" href="admin.php"><span class="gp-brand-symbol"><?=gp_icon('tv')?></span>G-PANEL<span class="gp-brand-version">V2</span></a><p class="gp-section-label">WORKSPACE</p><nav aria-label="Navigation principale"><?php foreach($links as [$href,$label,$icon]):$path=parse_url($href,PHP_URL_PATH);parse_str(parse_url($href,PHP_URL_QUERY)??'',$args);$active=$route===$path && ($route!=='admin.php'||$view===($args['view']??'')) && ($route!=='panel_catalog.php'||$type===($args['type']??'movie'));?><a href="<?=gp_escape($href)?>" <?=$active?'class="active" aria-current="page"':''?>><?=gp_icon($icon)?><span><?=gp_escape($label)?></span></a><?php endforeach?></nav><div class="gp-sidebar-footer"><form method="post" action="admin.php" class="gp-logout"><?=panel_csrf_field()?><button name="logout" value="1" type="submit">Déconnexion</button></form><div class="gp-brand-note">G-PANEL <span>CONTROL CENTER</span></div></div></aside><?php
}
function gp_header(string $title): void { ?><header class="top-header gp-cinema-header"><div><p class="gp-section-label">G-PANEL / ADMINISTRATION</p><h1><?=gp_escape($title)?></h1></div><form action="panel_catalog.php" method="get" class="gp-global-search"><?=gp_icon('search')?><input type="search" name="q" placeholder="Rechercher dans le catalogue…" aria-label="Recherche globale du catalogue"><input type="hidden" name="type" value="all"><button type="submit" aria-label="Rechercher"><?=gp_icon('arrow')?></button></form><a class="gp-admin-profile" href="system_admin.php"><span>A</span><div>Administration<small>Espace sécurisé</small></div></a></header><?php }
function gp_kpis(array $items): void { ?><div class="gp-kpi-grid"><?php foreach($items as [$label,$value,$icon,$tone]):?><section class="gp-kpi" data-tone="<?=gp_escape($tone)?>"><span class="gp-kpi-icon"><?=gp_icon($icon)?></span><div><span><?=gp_escape($label)?></span><strong><?=is_numeric($value)?number_format((float)$value,0,',',' '):gp_escape($value)?></strong></div></section><?php endforeach?></div><?php }
function gp_media_card(array $row): void { ?><a class="gp-media-card" href="content_edit.php?id=<?=(int)$row['stream_id']?>"><?=gp_picture($row)?><div class="gp-media-caption"><h3 data-gp-skip><?=gp_escape($row['stream_name'])?></h3><small><?=gp_escape($row['vod_release_date']??'')?> <span><?=gp_escape($row['vod_rating']??'')?></span></small></div></a><?php }

// Prefer real imported backdrops across recent catalogue entries, then bounded TMDB.
function gp_banner(array $rows): array {
 foreach($rows as $row)if(gp_media($row)['backdrop'])return $row;
 require_once __DIR__.'/panel_artwork_cache.php';
 $attempts=0;
 foreach($rows as $row){$m=gp_media($row);if(!$m['id'])continue;$art=gp_artwork($m['type'],(string)$m['id']);if(!empty($art['backdrop'])){$row['backdrop']=$art['backdrop'];return $row;}if(++$attempts>=2)break;}
 foreach($rows as $row)if(gp_media($row)['poster'])return $row;
 return $rows[0]??[];
}
