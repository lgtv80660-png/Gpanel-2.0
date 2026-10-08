<?php
require_once __DIR__.'/panel_operations.php';require_once __DIR__.'/panel_packs.php';
$pdo=ops_page('Statistiques');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') panel_fail(405,'method_not_allowed');
$period=(string)($_GET['period']??'7');$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$end=$now->modify('tomorrow')->setTime(0,0);$start=$now->setTime(0,0)->modify('-'.(['today'=>0,'7'=>6,'30'=>29][$period]??6).' days');
$error='';
if($period==='custom') {
    $a=DateTimeImmutable::createFromFormat('!Y-m-d',(string)($_GET['from']??''),new DateTimeZone('UTC'));
    $b=DateTimeImmutable::createFromFormat('!Y-m-d',(string)($_GET['to']??''),new DateTimeZone('UTC'));
    if(!$a||!$b||$a->format('Y-m-d')!==($_GET['from']??'')||$b->format('Y-m-d')!==($_GET['to']??'')||$b<$a||$b>$now||$b->diff($a)->days>365) {$error='Période invalide (maximum 366 jours, dates UTC).';}
    else {$start=$a;$end=$b->modify('+1 day');}
}
$params=[$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')];
$where='e.created_at>=? AND e.created_at<?';
$q=$pdo->prepare("SELECT COUNT(DISTINCT client_id) AS clients,SUM(event='request') AS requests,SUM(event='start') AS starts,SUM(event='error') AS errors,SUM(duration_seconds) AS seconds,SUM(event<>'request') AS player_events,SUM(event='heartbeat') AS heartbeat_events FROM panel_events e WHERE $where");$q->execute($params);$kpi=$q->fetch();
$active=(int)$pdo->query('SELECT COUNT(*) FROM clients c JOIN client_packs cp ON cp.client_id=c.id JOIN packs p ON p.id=cp.pack_id WHERE c.active=1 AND p.active=1 AND (cp.expires_at IS NULL OR cp.expires_at>UTC_TIMESTAMP())')->fetchColumn();
$q=$pdo->prepare("SELECT p.name,p.level,SUM(e.event='request') AS requests,SUM(e.event='start') AS starts,SUM(e.duration_seconds) AS seconds,SUM(e.event<>'request') AS player_events,SUM(e.event='heartbeat') AS heartbeat_events FROM panel_events e LEFT JOIN packs p ON p.id=e.pack_id WHERE $where GROUP BY e.pack_id,p.name,p.level ORDER BY requests DESC");$q->execute($params);$byPack=$q->fetchAll();
$q=$pdo->prepare("SELECT content_type,event,COUNT(*) AS total FROM panel_events e WHERE $where GROUP BY content_type,event ORDER BY content_type,event");$q->execute($params);$types=$q->fetchAll();
$q=$pdo->prepare("SELECT device,COUNT(*) AS total FROM panel_events e WHERE $where AND device IS NOT NULL GROUP BY device ORDER BY total DESC");$q->execute($params);$devices=$q->fetchAll();
v2_begin('Statistiques',['assets/packs.css']);echo ops_links(); ?>
<form class="v2-form" method="get"><label>Période<select name="period"><?php foreach(['today'=>"Aujourd’hui",'7'=>'7 jours','30'=>'30 jours','custom'=>'Personnalisée'] as $v=>$label):?><option value="<?=$v?>" <?=$period===$v?'selected':''?>><?=v2_h($label)?></option><?php endforeach?></select></label><label>Du (UTC)<input type="date" name="from" value="<?=v2_h($start->format('Y-m-d'))?>"></label><label>Au (UTC)<input type="date" name="to" value="<?=v2_h($end->modify('-1 day')->format('Y-m-d'))?>"></label><button>Afficher</button></form>
<?php if($error):?><p class="v2-notice"><?=v2_h($error)?></p><?php endif?>
<div class="v2-grid"><?php foreach(['Clients autorisés actuellement'=>$active,'Clients observés sur la période'=>$kpi['clients'],'Demandes de lecture dédupliquées'=>$kpi['requests']??0,'Démarrages déclarés G-TV'=>(int)$kpi['player_events']?$kpi['starts']:null,'Erreurs reçues G-TV'=>(int)$kpi['player_events']?$kpi['errors']:null,'Secondes déclarées G-TV'=>(int)$kpi['heartbeat_events']?$kpi['seconds']:null] as $label=>$value):?><section class="glass-panel v2-stat"><?=v2_h($label)?><strong><?=$value===null?'—':(int)$value?></strong></section><?php endforeach?></div>
<p class="v2-notice">Demandes autorisées : une par client/contenu/minute, sans preuve de lecture réussie. Sessions simultanées, octets transférés et erreurs après redirection : non disponibles. Démarrages, appareils et durée nécessitent les événements G-TV ; zéro événement signifie aucune donnée reçue. Rétention configurée : <?=(int)ops_setting($pdo,'telemetry_retention_days','90')?> jours (purge CLI à planifier).</p>
<section class="glass-panel"><h2>Consommation par Pack</h2><div class="v2-table"><table><tr><th>Pack</th><th>Demandes</th><th>Démarrages G-TV</th><th>Secondes G-TV</th></tr><?php foreach($byPack as $r):?><tr><td><?=packs_badge($r['name']??'Pack supprimé',$r['level']??'')?></td><td><?=(int)$r['requests']?></td><td><?=(int)$r['player_events']?(int)$r['starts']:'—'?></td><td><?=(int)$r['heartbeat_events']?(int)$r['seconds']:'—'?></td></tr><?php endforeach?></table></div><?php if(!$byPack):?><p class="v2-muted">Aucun événement sur cette période.</p><?php endif?></section>
<section class="glass-panel"><h2>Live / VOD / Séries</h2><div class="v2-table"><table><tr><th>Type</th><th>Événement</th><th>Total</th></tr><?php foreach($types as $r):?><tr><td><?=v2_h($r['content_type'])?></td><td><?=v2_h($r['event'])?></td><td><?=(int)$r['total']?></td></tr><?php endforeach?></table></div></section>
<?php foreach(['live'=>'Top Live','movie'=>'Top films / VOD','episode'=>'Top séries (épisodes demandés)'] as $type=>$label):
$q=$pdo->prepare("SELECT COALESCE(parent.stream_name,s.stream_name,'Contenu supprimé') AS name,COUNT(*) AS total FROM panel_events e LEFT JOIN streams s ON s.stream_id=e.stream_id LEFT JOIN content_settings cs ON cs.stream_id=s.stream_id LEFT JOIN streams parent ON parent.stream_id=cs.series_parent WHERE $where AND e.content_type=? AND e.event='request' GROUP BY COALESCE(cs.series_parent,e.stream_id),name ORDER BY total DESC LIMIT 10");$q->execute(array_merge($params,[$type]));$rows=$q->fetchAll(); ?>
<section class="glass-panel"><h2><?=v2_h($label)?></h2><div class="v2-table"><table><tr><th>Contenu</th><th>Demandes</th></tr><?php foreach($rows as $r):?><tr><td><?=v2_h($r['name'])?></td><td><?=(int)$r['total']?></td></tr><?php endforeach?></table></div><?php if(!$rows):?><p class="v2-muted">Aucune demande collectée.</p><?php endif?></section><?php endforeach?>
<section class="glass-panel"><h2>Appareils déclarés G-TV</h2><?php foreach($devices as $r):?><p><?=v2_h($r['device'])?> : <?=(int)$r['total']?> événements</p><?php endforeach?><?php if(!$devices):?><p class="v2-muted">Non disponible : G-TV doit déclarer la catégorie d’appareil.</p><?php endif?></section>
<?php v2_end();
