<?php
require_once __DIR__.'/panel_security.php';panel_require_admin('catalog:edit');panel_check_csrf();
require_once __DIR__.'/config.php';require_once __DIR__.'/panel_sources.php';require_once __DIR__.'/panel_layout.php';
header('Referrer-Policy: no-referrer');
$id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($id===false)panel_fail(422,'invalid_request');
$ready=sources_ready($pdo);$notice='';$row=null;
if($id){try{$row=sources_row($pdo,$id);if($row['stream_type']!=='live')panel_fail(422,'invalid_request');}catch(RuntimeException $e){panel_fail(404,'invalid_request');}}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 try{
  if(!$ready)throw new RuntimeException('Migration Sources requise.');
  if($id){
   if(($_POST['mode']??'')==='custom' && (int)($_POST['expected_links']??-1)!==count($_POST['links']??[]))throw new RuntimeException('Requête tronquée : aucun lien enregistré.');
   sources_save_channel($pdo,$id,$_POST);
  } else {
   $known=$pdo->query("SELECT id FROM fournisseurs WHERE type='xtream'")->fetchAll(PDO::FETCH_COLUMN);
   if((int)($_POST['expected_hosts']??-1)!==count($known))throw new RuntimeException('La liste des hosts a changé. Rechargez la page.');
   foreach($known as $fid)foreach(['live','movie','series'] as $kind)if(!isset($_POST['hosts'][$fid][$kind]['role']))throw new RuntimeException('Requête incomplète : aucun rôle enregistré.');
   sources_save_hosts($pdo,$_POST['hosts']??[],(int)($_POST['revision']??-1));
  }
  require_once __DIR__.'/panel_operations.php';ops_audit($pdo,$id?'sources.channel.updated':'sources.hosts.updated');
  header('Location: sources_manage.php'.($id?'?id='.$id.'&saved=1':'?saved=1'));exit;
 }catch(Throwable $e){$notice=$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Échec de sauvegarde : aucune modification appliquée.';}
}
v2_begin($id?'Sources & Backups · '.$row['stream_name']:'Sources · rôles des hosts');
?>
<style>.sb-grid{display:grid;gap:16px}.sb-link{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;padding:16px;border:1px solid var(--gp-border);border-radius:10px}.sb-link input,.sb-link select{width:100%}.sb-roles td{padding:12px}.sb-note{color:var(--gp-muted);line-height:1.7}.sb-list{display:grid;gap:12px;margin:20px 0}input,select{min-height:44px} .sb-wide{grid-column:1/-1}@media(max-width:700px){.sb-link{grid-template-columns:1fr}.sb-roles{display:block;overflow:auto}}</style>
<p><a href="admin.php?view=sources">Gérer / importer les hosts</a> · <a href="sources_manage.php">Rôles PRIMARY / BACKUP</a><?php if($id):?> · <a href="editor.php?cat_id=<?=(int)$row['category_id']?>">Retour aux chaînes</a><?php endif?></p>
<?php if($notice||isset($_GET['saved'])):?><p role="<?=$notice?'alert':'status'?>" class="v2-notice"><?=v2_h($notice?:'Configuration enregistrée.')?></p><?php endif?>
<p class="sb-note">Cette configuration s’applique au relais HLS local lorsqu’il est activé. Un changement d’origine exige une réouverture de session par le lecteur. Les autres lecteurs conservent leur comportement actuel.</p>
<?php if(!$ready):?><p role="alert">Migration Sources requise sur une copie locale de la base. Aucun schéma n’est créé depuis cette page.</p><?php v2_end();return;endif?>
<?php if(!$id):
$hosts=$pdo->query("SELECT id,nom,active FROM fournisseurs WHERE type='xtream' ORDER BY nom,id")->fetchAll(PDO::FETCH_ASSOC);$roles=[];
foreach($pdo->query('SELECT * FROM source_host_roles')->fetchAll(PDO::FETCH_ASSOC) as $r)$roles[$r['fournisseur_id']][$r['kind']]=$r;
$revision=(int)$pdo->query('SELECT revision FROM source_policy_version WHERE id=1')->fetchColumn();
?>
<section class="glass-panel"><h2>Choix manuel par host</h2><p class="sb-note">Chaque nouveau host reste NEUTRAL. Choisissez un seul PRIMARY par type. Les BACKUP ont un ordre unique positif : 1, 2, 3… Aucun média Film ou Série n’est fusionné ni basculé par ces rôles.</p>
<form method="post"><?=panel_csrf_field()?><input type="hidden" name="revision" value="<?=$revision?>"><input type="hidden" name="expected_hosts" value="<?=count($hosts)?>"><table class="sb-roles"><thead><tr><th>Host</th><th>LIVE</th><th>FILMS</th><th>SÉRIES</th></tr></thead><tbody>
<?php foreach($hosts as $h):?><tr><th><?=v2_h($h['nom'])?><?=$h['active']?'':' · inactif'?></th><?php foreach(['live','movie','series'] as $kind):$r=$roles[$h['id']][$kind]??['role'=>'neutral','backup_order'=>''];?><td><label>Rôle <?=v2_h($kind)?> pour <?=v2_h($h['nom'])?><select name="hosts[<?=$h['id']?>][<?=$kind?>][role]"><?php foreach(['neutral','primary','backup'] as $role):?><option value="<?=$role?>" <?=$r['role']===$role?'selected':''?>><?=strtoupper($role)?></option><?php endforeach?></select></label><label>Ordre BACKUP<input type="number" min="1" name="hosts[<?=$h['id']?>][<?=$kind?>][order]" value="<?=v2_h($r['backup_order'])?>"></label></td><?php endforeach?></tr><?php endforeach?>
</tbody></table><button type="submit">Enregistrer les rôles</button></form></section>
<?php else:
$policy=sources_policy($pdo,$id);$links=sources_links($pdo,$id);$candidates=sources_suggestions($pdo,$row);
$displayRows=[$id=>$row];foreach($candidates as $c)$displayRows[$c['stream_id']]=$c;
foreach($links as $l)if($l['candidate_id']&&!isset($displayRows[$l['candidate_id']])){try{$displayRows[$l['candidate_id']]=sources_row($pdo,(int)$l['candidate_id']);}catch(RuntimeException $e){$displayRows[$l['candidate_id']]=['stream_name'=>'Source absente #'.$l['candidate_id'],'nom'=>'Indisponible'];}}
$settingsQ=$pdo->prepare('SELECT source_override,source_provider FROM content_settings WHERE stream_id=?');$settingsQ->execute([$id]);$legacy=$settingsQ->fetch(PDO::FETCH_ASSOC)?:[];
?>
<section class="glass-panel"><h2><?=v2_h($row['stream_name'])?></h2><p>Source catalogue : <?=v2_h($row['nom']??'Personnel')?> · <?=v2_h(sources_redact($row['url_base']??$row['direct_source']??''))?></p>
<?php if(!empty($legacy['source_override'])||!empty($legacy['source_provider'])):?><p role="status">Un remplacement existe dans l’éditeur actuel : <?=v2_h(!empty($legacy['source_override'])?sources_redact($legacy['source_override']):'Host #'.$legacy['source_provider'])?>. Il reste actif dans le lecteur existant ; la nouvelle configuration préparée est indépendante.</p><?php endif?>
<p class="sb-note">ID public conservé : #<?=$id?>. Les correspondances sont proposées après normalisation du nom. Pays, langue, +1 et numéros restent significatifs. La qualité indiquée est estimée depuis le titre, sans analyse vidéo. Plusieurs variantes d’un host nécessitent votre choix ; aucune sélection automatique ambiguë.</p>
<form method="post" id="sb-form"><?=panel_csrf_field()?><input type="hidden" name="revision" value="<?=(int)$policy['revision']?>"><input type="hidden" id="sb-count" name="expected_links" value="<?=max(1,count($links))?>">
<label>Priorités de la chaîne<select name="mode" id="sb-mode"><option value="inherit" <?=$policy['mode']==='inherit'?'selected':''?>>Hériter des rôles des hosts</option><option value="custom" <?=$policy['mode']==='custom'?'selected':''?>>Remplacement et backups personnalisés</option></select></label>
<p class="sb-note">En mode personnalisé, la première ligne est PRIMARY et les suivantes BACKUP dans l’ordre affiché. Ajouter autant de liens que nécessaire ; les limites techniques de requête du serveur s’appliquent. Choisir une source détectée OU saisir un lien manuel.</p>
<div id="sb-links" class="sb-list">
<?php if(!$links)$links=[['candidate_id'=>$id,'manual_url'=>null]];
foreach($links as $i=>$l):?>
<div class="sb-link"><strong class="sb-wide sb-rank"><?=$i===0?'PRIMARY':'BACKUP '.$i?></strong><label>Source enregistrée<select name="links[<?=$i?>][candidate]"><option value="0">Lien manuel</option><?php foreach($displayRows as $sid=>$c):?><option value="<?=(int)$sid?>"  <?=((int)($l['candidate_id']??0)===(int)$sid)?'selected':''?>><?=v2_h(($c['nom']??'Host').' · '.$c['stream_name'].' · '.($c['quality']??sources_name($c['stream_name'])['quality']).(!empty($c['manual_confirmation'])?' · suggestion manuelle à confirmer':''))?></option><?php endforeach?></select></label><label>Lien manuel<input type="url" name="links[<?=$i?>][url]" value="<?=v2_h($l['manual_url']??'')?>" autocomplete="off" placeholder="https://…"></label><div><button type="button" data-sb-up>↑</button><button type="button" data-sb-down>↓</button><button type="button" data-sb-remove>Retirer</button></div></div>
<?php endforeach?></div>
<button type="button" id="sb-add">+ Ajouter backup link</button><button type="submit">Enregistrer la configuration</button>
</form>
<h3><?=count($candidates)?> correspondance(s) / suggestion(s) à examiner</h3><p class="sb-note">Les suggestions manuelles peuvent ignorer un marqueur de langue et la variante Sport / Sports. Vérifiez la langue et le contenu, choisissez explicitement la source en mode personnalisé puis enregistrez pour confirmer. Les rôles hérités utilisent uniquement les correspondances strictes.</p>
<ul><?php foreach($candidates as $c):?><li><?=v2_h($c['nom'].' · '.$c['stream_name'].' · '.$c['quality'].(!empty($c['manual_confirmation'])?' · suggestion manuelle à confirmer':' · correspondance stricte'))?><?=$c['quality_diff']?' · qualité différente / à vérifier':''?></li><?php endforeach?></ul>
<?php try{$plan=sources_plan($pdo,$id);?><h3>Ordre serveur préparé</h3><ol><?php foreach($plan['sources'] as $s):?><li><?=v2_h(sources_redact($s['url']))?></li><?php endforeach?></ol><?php foreach($plan['warnings'] as $warning):?><p role="status"><?=v2_h($warning)?></p><?php endforeach?><?php }catch(Throwable $e){?><p role="status">Plan indisponible : source principale inactive, masquée ou absente.</p><?php }?>
</section>
<script src="assets/sources-backups.js" defer></script>
<?php endif;v2_end();
