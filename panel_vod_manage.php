<?php
// Shared Sources & Backups controller, reached through sources_manage.php only.
if(!isset($row,$pdo,$id) || basename($_SERVER['SCRIPT_NAME']??'')!=='sources_manage.php'){http_response_code(404);return;}
require_once __DIR__.'/panel_vod_sources.php';
$vodReady=vod_sources_ready($pdo);$notice='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!$vodReady)throw new RuntimeException('Migration VOD requise sur une copie locale.');
        if(($_POST['form_complete']??'')!=='1')throw new RuntimeException('Requête incomplète : aucune modification.');
        $action=(string)($_POST['action']??'policy');
        if($action==='series_match' && $row['stream_type']==='series'){
            if(($_POST['confirmed']??'')!=='1')throw new RuntimeException('Confirmation de correspondance requise.');
            vod_save_series_match($pdo,$id,(int)($_POST['other_series']??0),isset($_POST['remove']));
        }elseif($action==='identity' && $row['stream_type']==='episode')vod_save_episode_identity($pdo,$id,$_POST);
        elseif($action==='policy' && in_array($row['stream_type'],['movie','episode'],true))vod_save_policy($pdo,$id,$_POST);
        else throw new RuntimeException('Action invalide.');
        require_once __DIR__.'/panel_operations.php';ops_audit($pdo,'sources.vod.updated');
        header('Location: sources_manage.php?id='.$id.'&saved=1');exit;
    }catch(Throwable $e){$notice=$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Échec de sauvegarde : aucune modification appliquée.';}
}
v2_begin('Sources & Backups · '.$row['stream_name']);
?>
<style>.vs-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;border:1px solid var(--gp-border);padding:18px;margin:16px 0;border-radius:12px}.vs-row input,.vs-row select{width:100%;min-height:40px}.vs-row input[type=checkbox]{width:auto;min-height:0}.vs-wide{grid-column:1/-1}@media(max-width:700px){.vs-row{grid-template-columns:1fr}}</style>
<p><a href="sources_manage.php">Hosts et rôles</a> · <a href="sources_manage.php?browse=movie">Films</a> · <a href="sources_manage.php?browse=series">Séries</a> · <a href="sources_manage.php?browse=episode">Épisodes</a> · <a href="movie_duplicates.php">Groupes films existants</a></p>
<?php if($notice||isset($_GET['saved'])):?><p role="<?=$notice?'alert':'status'?>"><?=v2_h($notice?:'Configuration enregistrée.')?></p><?php endif?>
<h2><?=v2_h($row['stream_name'])?> · ID public #<?=$id?></h2>
<p>Les IDs publics et groupes existants restent conservés. Les propositions exigent une confirmation. Vérifiez l’édition, la langue, l’audio et le codec ; une alternative peut nécessiter de recommencer la lecture.</p>
<?php if(!$vodReady):?><p role="alert">Migration VOD requise. Aucune table créée depuis cette page.</p><?php v2_end();return;endif?>
<?php if($row['stream_type']==='series'):
$q=$pdo->prepare('SELECT series_a,series_b FROM source_series_matches WHERE series_a=? OR series_b=?');$q->execute([$id,$id]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="glass-panel"><h3>Séries équivalentes confirmées</h3>
<p>Cette association autorise la proposition d’épisodes de même saison et numéro. Chaque backup d’épisode doit ensuite être choisi et confirmé séparément. Aucune fusion ni copie de catalogue.</p>
<?php try{$seriesSuggestions=vod_suggestions($pdo,$row);}catch(Throwable $e){$seriesSuggestions=[];}?>
<h4>Séries portant le même titre : à examiner</h4><ul><?php foreach($seriesSuggestions as $candidate):?><li>#<?=(int)$candidate['stream_id']?> · <?=v2_h($candidate['stream_name'])?> · <?=v2_h($candidate['nom']??'Personnel')?></li><?php endforeach?></ul>
<p>Le titre seul ne prouve pas l’équivalence. Confirmez l’identité et la numérotation avant de l’associer.</p>
<ul><?php foreach($matches as $match):$other=(int)($match['series_a']==$id?$match['series_b']:$match['series_a']);?><li><a href="sources_manage.php?id=<?=$other?>">Série #<?=$other?></a></li><?php endforeach?></ul>
<form method="post"><?=panel_csrf_field()?><input type="hidden" name="action" value="series_match"><label>ID local de la série équivalente<input name="other_series" type="number" min="1" required></label><label><input type="checkbox" name="confirmed" value="1" required> Je confirme qu’il s’agit de la même série et de la même numérotation.</label><label><input type="checkbox" name="remove" value="1"> Retirer cette association (ses backups d’épisodes deviennent inutilisables)</label><input type="hidden" name="form_complete" value="1"><button>Enregistrer l’association</button></form>
</section>
<?php $eq=$pdo->prepare("SELECT s.stream_id,s.stream_name,x.season_number,x.episode_number FROM content_settings x JOIN streams s ON s.stream_id=x.stream_id WHERE x.series_parent=? AND s.stream_type='episode' ORDER BY x.season_number,x.episode_number,s.stream_id");$eq->execute([$id]);?>
<h3>Épisodes importés</h3><p>Les épisodes distants apparaissent après le chargement habituel des informations de la série. Aucun appel fournisseur n’est effectué par cette page.</p><ul><?php foreach($eq as $episode):?><li><a href="sources_manage.php?id=<?=(int)$episode['stream_id']?>">#<?=(int)$episode['stream_id']?> · <?=v2_h($episode['stream_name'])?> · S<?=v2_h($episode['season_number']??'?')?> E<?=v2_h($episode['episode_number']??'?')?></a></li><?php endforeach?></ul>
<?php else:
if($row['stream_type']==='episode'):$identity=vod_episode_identity($pdo,$id);?>
<section class="glass-panel"><h3>Identité de l’épisode</h3><p>Série parente : <a href="sources_manage.php?id=<?=$identity['parent']?>">#<?=$identity['parent']?></a>. Saison 0 autorisée pour les épisodes spéciaux. Les métadonnées manquantes peuvent être confirmées ici ; aucun ID ne change.</p>
<form method="post"><?=panel_csrf_field()?><input type="hidden" name="action" value="identity"><input type="hidden" name="identity_revision" value="<?=hash('sha256',json_encode($identity))?>"><label>Saison<input type="number" min="0" name="season" value="<?=v2_h($identity['season']??'')?>" required></label><label>Épisode<input type="number" min="1" name="episode" value="<?=v2_h($identity['episode']??'')?>" required></label><label><input type="checkbox" name="confirmed" value="1" required> Je confirme la numérotation exacte.</label><input type="hidden" name="form_complete" value="1"><button>Enregistrer l’identité</button></form></section>
<?php endif;
$policy=vod_policy($pdo,$id);$links=vod_links($pdo,$id);$suggestions=[];
try{$suggestions=vod_suggestions($pdo,$row);}catch(Throwable $e){echo '<p role="status">Suggestions indisponibles ; la sélection par ID reste possible.</p>';}
if(!$links)$links=[['candidate_id'=>$id,'manual_url'=>null,'enabled'=>1,'variant_note'=>'','position'=>-1]];
$labels=[$id=>$row['stream_name']];foreach($suggestions as $candidate)$labels[(int)$candidate['stream_id']]=$candidate['stream_name'];
foreach($links as $link)if($link['candidate_id']&&!isset($labels[(int)$link['candidate_id']])){try{$labels[(int)$link['candidate_id']]=sources_row($pdo,(int)$link['candidate_id'])['stream_name'];}catch(Throwable $e){$labels[(int)$link['candidate_id']]='Source absente';}}
?>
<section class="glass-panel"><h3>PRIMARY et BACKUP du contenu</h3>
<form method="post" id="vs-form"><?=panel_csrf_field()?><input type="hidden" name="action" value="policy"><input type="hidden" name="revision" value="<?=(int)$policy['revision']?>"><input type="hidden" name="expected_links" id="vs-count" value="<?=count($links)?>">
<label>Ordre de lecture<select name="mode" id="vs-mode"><?php foreach(['legacy'=>'Source catalogue / groupes films existants','custom'=>'Priorités personnalisées','hosts'=>'Rôles des hosts parmi les sources confirmées ci-dessous'] as $mode=>$label):?><option value="<?=$mode?>" <?=$policy['mode']===$mode?'selected':''?>><?=v2_h($label)?></option><?php endforeach?></select></label>
<p>En mode personnalisé, la première ligne est PRIMARY. Les lignes suivantes sont les backups dans l’ordre affiché. Un PRIMARY désactivé bloque la lecture. En mode hosts, un PRIMARY unique et disponible est requis ; les variantes ambiguës d’un même host ne sont pas choisies automatiquement. Les URLs manuelles utilisent le mode personnalisé.</p>
<div id="vs-links"><?php foreach($links as $i=>$link):?>
<div class="vs-row"><strong class="vs-wide vs-rank"><?=$i===0?'PRIMARY':'BACKUP '.$i?></strong>
<input type="hidden" data-vs-name="saved_position" name="links[<?=$i?>][saved_position]" value="<?=(int)$link['position']?>">
<label>ID local du contenu alternatif<input data-vs-name="candidate" name="links[<?=$i?>][candidate]" type="number" min="0" value="<?=(int)($link['candidate_id']??0)?>" list="vs-candidates"></label>
<label>URL alternative (ID = 0)<input data-vs-name="url" type="url" autocomplete="off" name="links[<?=$i?>][url]" value="" placeholder="<?=$link['manual_url']?'URL conservée ; vide pour la garder':'https://…'?>"></label>
<p class="vs-wide"><?=v2_h($link['candidate_id']?($labels[(int)$link['candidate_id']]??'Source catalogue'):sources_redact($link['manual_url']))?></p>
<label>Variante : audio, codec, édition<input data-vs-name="variant_note" name="links[<?=$i?>][variant_note]" maxlength="255" value="<?=v2_h($link['variant_note'])?>"></label>
<label><input data-vs-name="enabled" type="checkbox" name="links[<?=$i?>][enabled]" value="1" <?=$link['enabled']?'checked':''?>> Source activée</label>
<label class="vs-wide"><input data-vs-name="confirmed" type="checkbox" name="links[<?=$i?>][confirmed]" value="1"> Je confirme le même contenu et les différences de langue, codec ou édition pour toute nouvelle alternative.</label>
<div class="vs-wide"><button type="button" data-vs-up>↑</button><button type="button" data-vs-down>↓</button><button type="button" data-vs-remove>Retirer</button></div></div>
<?php endforeach?></div>
<datalist id="vs-candidates"><?php foreach($labels as $sid=>$label):?><option value="<?=(int)$sid?>"><?=v2_h($label)?></option><?php endforeach?></datalist>
<button type="button" id="vs-add">Ajouter un backup</button><input type="hidden" name="form_complete" value="1"><button>Enregistrer les sources</button></form>
<h3><?=count($suggestions)?> suggestion(s) à confirmer</h3><ul><?php foreach($suggestions as $candidate):?><li>#<?=(int)$candidate['stream_id']?> · <?=v2_h($candidate['stream_name'])?> · <?=v2_h($candidate['nom']??'Personnel')?></li><?php endforeach?></ul>
<p>Aucune suggestion n’est présélectionnée. Saisissez l’ID, vérifiez les variantes, cochez la confirmation et enregistrez.</p>
<?php try{$plan=vod_plan($pdo,$id);?><h3>Ordre serveur préparé</h3><ol><?php foreach($plan['rows'] as $source):?><li><?=v2_h($source['_candidate']?'Contenu #'.$source['_candidate']:'URL manuelle masquée')?></li><?php endforeach?></ol><?php if(!$plan['rows']):?><p role="status">Aucune source autorisée dans la politique actuelle.</p><?php endif?><?php }catch(Throwable $e){?><p role="status">Plan indisponible : corriger le PRIMARY, l’identité ou les correspondances.</p><?php }?>
</section><script src="assets/vod-sources.js" defer></script>
<?php endif;v2_end();
