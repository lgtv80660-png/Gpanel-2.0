<?php
require_once __DIR__.'/panel_security.php';
panel_require_admin('catalog:import'); panel_check_csrf();
require __DIR__.'/config.php';
require_once __DIR__.'/panel_movie_groups.php';
function dup_h($v) { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
if (!movie_groups_ready($pdo)) { http_response_code(503); exit('Migration V2 requise sur copie MySQL avant utilisation.'); }
$message='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try { $roles=$_POST['roles']??[]; if(!is_array($roles)) throw new RuntimeException('Choix invalide.'); $roles=array_filter($roles,fn($r)=>$r!=='ignore'); movie_confirm($pdo,$roles); $message='Groupe confirmé. Choix enregistrés.'; }
    catch(Throwable $e) { $message=$e instanceof RuntimeException?$e->getMessage():'Enregistrement impossible.'; }
}
// Local catalogue only; no provider requests. Bound display, not detection.
$rows=$pdo->query("SELECT s.stream_id,s.stream_name,s.fournisseur_id,s.vod_release_date,f.nom,i.tmdb_id,i.imdb_id,i.release_year,g.group_id,g.role FROM streams s JOIN fournisseurs f ON f.id=s.fournisseur_id LEFT JOIN movie_identifiers i ON i.stream_id=s.stream_id LEFT JOIN movie_group_sources g ON g.stream_id=s.stream_id WHERE s.stream_type='movie' ORDER BY s.stream_id")->fetchAll(PDO::FETCH_ASSOC);
$buckets=[];
foreach($rows as $r) {
    if ($r['group_id']) $buckets['groupe confirmé:'.$r['group_id']][]=$r;
    foreach(movie_candidate_keys($r) as $key) $buckets[$key][]=$r;
}
$candidates=[];
foreach($buckets as $key=>$members) if(count($members)>1 && count(array_unique(array_column($members,'fournisseur_id')))>1) $candidates[$key]=$members;
$page=max(1,(int)($_GET['page']??1)); $total=count($candidates);
?>
<!doctype html><html lang="fr"><meta charset="utf-8"><title>Doublons films</title>
<style>body{font:16px system-ui;max-width:1000px;margin:30px auto;padding:16px}section{border:1px solid #ccc;padding:16px;margin:16px 0}label{display:block;margin:8px}select,button{padding:8px}</style>
<a href="admin.php">Administration</a><h1>Doublons entre fournisseurs — films</h1>
<p>Les propositions restent à confirmer, même avec TMDB/IMDb. Aucun film supprimé, aucun ID modifié. Un principal par groupe. Le secours se demande explicitement avec backup_stream=ID sur vod.php ; aucun basculement automatique après une redirection.</p>
<p><?=dup_h($message)?></p><p><?= $total ?> propositions / groupes. Page <?= $page ?></p>
<?php foreach(array_slice($candidates,($page-1)*20,20,true) as $key=>$members): ?>
<section><h2><?=dup_h($key)?></h2><form method="post"><?=panel_csrf_field()?>
<?php foreach($members as $r): ?><label>#<?= (int)$r['stream_id'] ?> <?=dup_h($r['stream_name'])?> — <?=dup_h($r['nom'])?>
<select name="roles[<?= (int)$r['stream_id'] ?>]">
<?php foreach(['ignore'=>'Ne pas inclure','primary'=>'Principal','backup'=>'Secours','disabled'=>'Désactivé'] as $value=>$label): ?><option value="<?=$value?>" <?=($r['role']??'ignore')===$value?'selected':''?>><?=$label?></option><?php endforeach ?>
</select></label><?php endforeach ?><button>Confirmer le groupe et les rôles</button></form></section>
<?php endforeach ?>
<?php if($page>1):?><a href="?page=<?=$page-1?>">Précédent</a><?php endif ?>
<?php if($page*20<$total):?><a href="?page=<?=$page+1?>">Suivant</a><?php endif ?>
</html>
