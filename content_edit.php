<?php
require_once __DIR__.'/panel_security.php';panel_require_admin('catalog:edit');panel_check_csrf();
require __DIR__.'/config.php';require_once __DIR__.'/panel_content.php';require_once __DIR__.'/panel_layout.php';require_once __DIR__.'/panel_revalidation.php';require_once __DIR__.'/panel_import.php';
$id=(int)($_GET['id']??0);$kind=(string)($_GET['kind']??'live');$row=null;$notice='';
if($id){$q=$pdo->prepare('SELECT * FROM streams WHERE stream_id=?');$q->execute([$id]);$row=$q->fetch();if(!$row) panel_fail(404,'invalid_request');$kind=$row['stream_type'];}
if(!in_array($kind,['live','movie','series','episode'],true)) panel_fail(422,'invalid_request');
$fid=(int)($row['fournisseur_id']??0);$personal=$fid===0;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 $locked=false;
 try {
  panel_import_require_transactions($pdo);if($fid){panel_import_lock($pdo,$fid);$locked=true;}$pdo->beginTransaction();
  if(isset($_POST['delete'])){if(!$row)throw new RuntimeException('Contenu absent.');content_delete($pdo,$row,(string)($_POST['confirmation']??''));$pdo->commit();panel_revalidate();header('Location: '.($personal?'personal_content.php':'import_selective.php?fournisseur_id='.$fid.'&kind='.$kind));exit;}
  $id=content_save($pdo,$_POST+['kind'=>$kind],$row);$pdo->commit();panel_revalidate();header('Location: content_edit.php?id='.$id.'&saved=1');exit;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$notice=$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'Échec de sauvegarde : aucune modification appliquée.';}finally{if($locked)panel_import_lock($pdo,$fid,true);}
}
$settings=$row?content_settings($pdo,$id):[];
$q=$pdo->prepare('SELECT * FROM categories WHERE COALESCE(fournisseur_id,0)=? AND content_type=? ORDER BY category_name');$q->execute([$fid,$kind==='episode'?'series':$kind]);$cats=$q->fetchAll();
$sourceProviders=$personal?[]:$pdo->query('SELECT id,nom,type FROM fournisseurs WHERE active=1 ORDER BY nom')->fetchAll();
$parents=$personal?$pdo->query("SELECT stream_id,stream_name FROM streams WHERE fournisseur_id=0 AND stream_type='series' ORDER BY stream_name")->fetchAll():[];
v2_begin(($row?'Modifier':'Créer').' · '.($personal?'contenu personnel':'contenu fournisseur')); ?>
<a href="<?=$personal?'personal_content.php':'import_selective.php?fournisseur_id='.$fid.'&kind='.$kind?>">Retour à la gestion</a>
<?php if($notice || isset($_GET['saved'])):?><p class="v2-notice"><?=v2_h($notice?:'Enregistré. ID et identité du contenu conservés.')?></p><?php endif?>
<section class="glass-panel"><p>Type : <?=v2_h($kind)?><?=$id?' · ID '.$id:''?></p><form method="post" class="v2-form"><?=panel_csrf_field()?>
<?php foreach(['name'=>'Titre','icon'=>'Affiche / logo (URL)','source'=>$personal?'URL du flux (vide pour une fiche série)':'URL de remplacement (vide = source fournisseur)','extension'=>'Conteneur (mp4, mkv, ts, m3u8…)'] as $field=>$label):$value=['name'=>$row['stream_name']??'','icon'=>$row['stream_icon']??'','source'=>$personal?($row['direct_source']??''):($settings['source_override']??''),'extension'=>$row['container_extension']??''][$field];?><label><?=v2_h($label)?><input name="<?=$field?>" value="<?=v2_h($value)?>" <?=$field==='name'?'required maxlength="900"':''?>></label><?php endforeach?>
<label>Catégorie<select name="category" required><option value="">Choisir</option><?php foreach($cats as $cat):?><option value="<?=(int)$cat['category_id']?>" <?=($row['category_id']??'')==$cat['category_id']?'selected':''?>><?=v2_h($cat['category_name'])?></option><?php endforeach?></select></label>
<?php if(!$personal):?><label>Fournisseur de remplacement<select name="source_provider"><option value="0">Source originale / URL directe</option><?php foreach($sourceProviders as $f):?><option value="<?=(int)$f['id']?>" <?=($settings['source_provider']??0)==$f['id']?'selected':''?>><?=v2_h($f['nom'].' · '.$f['type'])?></option><?php endforeach?></select></label><label>ID distant / URL M3U du remplacement<input name="source_remote" value="<?=v2_h($settings['source_remote']??'')?>" maxlength="255"></label><?php endif?>
<label>Qualité manuelle<select name="quality"><?php foreach([''=>'Automatique','unknown'=>'Inconnue','SD'=>'SD','720p'=>'720p','1080p'=>'1080p','4K'=>'4K'] as $q=>$label):?><option value="<?=$q?>" <?=($settings['quality']??'')===$q?'selected':''?>><?=$label?></option><?php endforeach?></select></label>
<label>Visible<input type="checkbox" name="visible" value="1" <?=!$row||$row['visible']?'checked':''?>></label>
<?php foreach(['plot'=>'Synopsis','cast'=>'Distribution','director'=>'Réalisation','genre'=>'Genre','release_date'=>'Date de sortie','rating'=>'Note','runtime'=>'Durée'] as $field=>$label):?><label><?=$label?><input name="<?=$field?>" value="<?=v2_h($row['vod_'.$field]??'')?>"></label><?php endforeach?>
<?php if($personal):?><label>Référence de production personnelle (facultative)<input name="production_ref" maxlength="128" value="<?=v2_h($settings['production_ref']??'')?>"></label><?php endif?>
<?php if($personal&&$kind==='episode'):?><label>Série personnelle<select name="parent" required><?php foreach($parents as $parent):?><option value="<?=(int)$parent['stream_id']?>" <?=($settings['series_parent']??0)==$parent['stream_id']?'selected':''?>><?=v2_h($parent['stream_name'])?></option><?php endforeach?></select></label><label>Saison<input name="season" type="number" min="1" value="<?=(int)($settings['season_number']??1)?>" required></label><label>Épisode<input name="episode" type="number" min="1" value="<?=(int)($settings['episode_number']??1)?>" required></label><?php endif?>
<button name="save" value="1">Enregistrer</button></form></section>
<?php if($row):?><details class="glass-panel"><summary class="v2-danger">Suppression explicite</summary><p>Suppression définitive du contenu #<?=$id?>. Pour un fournisseur, une exclusion bloque sa recréation au prochain import. Les autres contenus restent conservés.</p><form method="post" class="v2-form"><?=panel_csrf_field()?><label>Saisir SUPPRIMER <?=$id?><input name="confirmation" required autocomplete="off"></label><button name="delete" value="1">Supprimer définitivement</button></form></details><?php endif?>
<?php v2_end();
