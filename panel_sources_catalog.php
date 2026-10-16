<?php
// Included by sources_manage.php after existing admin/CSRF checks.
if(!isset($pdo,$id) || basename($_SERVER['SCRIPT_NAME']??'')!=='sources_manage.php'){http_response_code(404);return;}
$kind=(string)($_GET['browse']??'movie');if(!in_array($kind,['live','movie','series','episode'],true))panel_fail(422,'invalid_request');
$page=max(1,min(100000,(int)($_GET['page']??1)));$search=trim((string)($_GET['q']??''));
$q=$pdo->prepare('SELECT s.stream_id,s.stream_name,s.stream_type,f.nom FROM streams s LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id WHERE s.stream_type=? AND s.stream_name LIKE ? ORDER BY s.stream_id LIMIT 101 OFFSET '.(($page-1)*100));$q->execute([$kind,'%'.$search.'%']);$items=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($items)>100;$items=array_slice($items,0,100);
v2_begin('Sources & Backups · catalogue');
?>
<p><a href="sources_manage.php">Hosts et rôles</a> · <a href="sources_manage.php?browse=live">Live</a> · <a href="sources_manage.php?browse=movie">Films</a> · <a href="sources_manage.php?browse=series">Séries</a> · <a href="sources_manage.php?browse=episode">Épisodes</a> · <a href="movie_duplicates.php">Groupes films existants</a></p>
<form method="get"><input type="hidden" name="browse" value="<?=v2_h($kind)?>"><label>Rechercher un contenu<input name="q" value="<?=v2_h($search)?>"></label><button>Rechercher</button></form>
<p>Choisissez un contenu pour confirmer ses alternatives et régler les priorités. Les propositions ne créent aucun lien automatiquement.</p>
<ul><?php foreach($items as $item):?><li><a href="sources_manage.php?id=<?=(int)$item['stream_id']?>">#<?=(int)$item['stream_id']?> · <?=v2_h($item['stream_name'])?> · <?=v2_h($item['nom']??'Personnel')?></a></li><?php endforeach?></ul>
<?php if(!$items):?><p>Aucun contenu trouvé.</p><?php endif?>
<?php if($page>1):?><a href="?<?=v2_h(http_build_query(['browse'=>$kind,'q'=>$search,'page'=>$page-1]))?>">Précédent</a><?php endif?>
<?php if($more):?><a href="?<?=v2_h(http_build_query(['browse'=>$kind,'q'=>$search,'page'=>$page+1]))?>">Suivant</a><?php endif?>
<?php v2_end();
