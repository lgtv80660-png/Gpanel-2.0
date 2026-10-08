<?php
require_once __DIR__.'/panel_security.php';
panel_require_admin('panel:read');panel_check_csrf();
require __DIR__.'/config.php';require_once __DIR__.'/panel_revalidation.php';require_once __DIR__.'/panel_packs.php';require_once __DIR__.'/panel_layout.php';
if(!packs_ready($pdo)) {http_response_code(503);v2_begin('Gestion des Packs');echo '<div class="v2-notice">Migration Packs requise : exécuter la migration V2 ou migrate_packs.php avant utilisation.</div>';v2_end();exit;}
$error='';$editing=(int)($_GET['edit']??0);
if($_SERVER['REQUEST_METHOD']==='POST') {
    panel_require_admin('panel:manage');
    try {
        $pdo->beginTransaction();$id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($id===false) throw new InvalidArgumentException('Pack invalide.');
        $q=$pdo->prepare('SELECT * FROM packs WHERE id=? FOR UPDATE');$q->execute([$id]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($id && !$existing) throw new InvalidArgumentException('Pack introuvable.');
        $op=$_POST['operation']??'';
        if($op==='save') {
            $name=trim((string)($_POST['name']??''));$description=trim((string)($_POST['description']??''));$level=$_POST['level']??'';
            $days=filter_var($_POST['duration_days']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>36500]]);
            if($name==='' || mb_strlen($name)>120 || mb_strlen($description)>10000 || !in_array($level,['BASIC','PREMIUM','PREMIUM+'],true) || $days===false) throw new InvalidArgumentException('Nom, niveau ou durée invalide.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM client_packs WHERE pack_id=?');$q->execute([$id]);
            if($id && (int)$q->fetchColumn()>0 && empty($_POST['confirm_impact'])) throw new InvalidArgumentException('Confirmez la modification des droits des clients affectés.');
            $rules=[];
            foreach(['live','movie','series','personal'] as $scope) {
                if(!empty($_POST['all'][$scope])) {$rules[]=[$scope,'all',0];continue;}
                $ids=$_POST['categories'][$scope]??[];
                if(!is_array($ids)) throw new InvalidArgumentException('Catégories invalides.');
                foreach(array_unique($ids) as $ref) {
                    if(!ctype_digit((string)$ref) || (int)$ref<1) throw new InvalidArgumentException('Catégorie invalide.');
                    $q=$pdo->prepare("SELECT 1 FROM categories c JOIN streams s ON s.category_id=c.category_id WHERE c.category_id=? AND ".($scope==='personal'?"s.fournisseur_id=0":"s.fournisseur_id<>0 AND s.stream_type=?").' LIMIT 1');
                    $q->execute($scope==='personal'?[$ref]:[$ref,$scope]);if(!$q->fetchColumn()) throw new InvalidArgumentException('Catégorie absente du catalogue.');
                    $rules[]=[$scope,'category',(int)$ref];
                }
            }
            $groups=$_POST['groups']??[];if(!is_array($groups)) throw new InvalidArgumentException('Regroupements invalides.');
            if(empty($_POST['all']['movie'])) foreach(array_unique($groups) as $ref) {
                if(!ctype_digit((string)$ref)) throw new InvalidArgumentException('Regroupement invalide.');
                $q=$pdo->prepare("SELECT 1 FROM movie_groups g JOIN movie_group_sources m ON m.group_id=g.group_id JOIN streams s ON s.stream_id=m.stream_id WHERE g.group_id=? AND m.role='primary' AND s.fournisseur_id<>0 LIMIT 1");$q->execute([$ref]);
                if(!$q->fetchColumn()) throw new InvalidArgumentException('Regroupement absent.');$rules[]=['movie','group',(int)$ref];
            }
            $values=[$name,$description,$level,isset($_POST['active'])?1:0,$days];
            if($id) {$pdo->prepare('UPDATE packs SET name=?,description=?,level=?,active=?,duration_days=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute(array_merge($values,[$id]));}
            else {$pdo->prepare('INSERT INTO packs (name,description,level,active,duration_days) VALUES (?,?,?,?,?)')->execute($values);$id=(int)$pdo->lastInsertId();}
            $pdo->prepare('DELETE FROM pack_rules WHERE pack_id=?')->execute([$id]);$q=$pdo->prepare('INSERT INTO pack_rules (pack_id,scope,kind,reference_id) VALUES (?,?,?,?)');foreach($rules as $r) $q->execute(array_merge([$id],$r));
        } elseif($op==='duplicate' && $existing) {
            $pdo->prepare('INSERT INTO packs (name,description,level,active,duration_days) VALUES (?,?,?,0,?)')->execute([mb_substr($existing['name'],0,110).' (copie)',$existing['description'],$existing['level'],$existing['duration_days']]);$copy=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO pack_rules (pack_id,scope,kind,reference_id) SELECT ?,scope,kind,reference_id FROM pack_rules WHERE pack_id=?')->execute([$copy,$id]);
        } elseif($op==='toggle' && $existing) {$pdo->prepare('UPDATE packs SET active=1-active WHERE id=?')->execute([$id]);}
        elseif($op==='delete' && $existing) {
            $q=$pdo->prepare('SELECT COUNT(*) FROM client_packs WHERE pack_id=?');$q->execute([$id]);if($q->fetchColumn()) throw new InvalidArgumentException('Retirez ou changez le pack des clients affectés avant suppression.');
            $pdo->prepare('DELETE FROM packs WHERE id=?')->execute([$id]);
        } else throw new InvalidArgumentException('Action invalide.');
        $pdo->commit();$notified=panel_revalidate();header('Location: packs.php?saved=1&cache='.($notified?'ok':'pending'));exit;
    } catch(Throwable $e) {if($pdo->inTransaction()) $pdo->rollBack();$error=$e instanceof InvalidArgumentException?$e->getMessage():'Enregistrement indisponible.';if(!($e instanceof InvalidArgumentException)) error_log('Packs: '.$e->getMessage());$editing=(int)($_POST['id']??0);}
}
$packs=$pdo->query('SELECT p.*,(SELECT COUNT(*) FROM client_packs cp WHERE cp.pack_id=p.id) AS clients FROM packs p ORDER BY p.id DESC')->fetchAll(PDO::FETCH_ASSOC);
$rules=$pdo->query('SELECT * FROM pack_rules')->fetchAll(PDO::FETCH_ASSOC);$byPack=[];foreach($rules as $r) $byPack[(int)$r['pack_id']][]=$r;
$categories=$pdo->query("SELECT c.category_id,c.category_name,CASE WHEN s.fournisseur_id=0 THEN 'personal' ELSE s.stream_type END AS scope,COUNT(*) AS contents FROM categories c JOIN streams s ON s.category_id=c.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id LEFT JOIN movie_group_sources g ON g.stream_id=s.stream_id WHERE s.stream_type IN ('live','movie','series') AND (s.stream_type<>'movie' OR g.role IS NULL OR g.role='primary') AND c.visible=1 AND s.visible=1 AND (f.active=1 OR s.fournisseur_id=0) GROUP BY c.category_id,c.category_name,scope ORDER BY c.category_name")->fetchAll(PDO::FETCH_ASSOC);
$groups=$pdo->query("SELECT g.group_id,g.label FROM movie_groups g JOIN movie_group_sources m ON m.group_id=g.group_id JOIN streams s ON s.stream_id=m.stream_id WHERE m.role='primary' AND s.fournisseur_id<>0 ORDER BY g.label")->fetchAll(PDO::FETCH_ASSOC);
$form=null;foreach($packs as $p) if((int)$p['id']===$editing) $form=$p;
$showForm=$editing>0 || isset($_GET['create']) || $error!=='';
if($editing && !$form) {http_response_code(404);$error='Pack introuvable.';$showForm=false;}
$selected=$byPack[$editing]??[];
if($error!=='' && ($_POST['operation']??'')==='save') {
    $form=array_merge($form??['id'=>0,'clients'=>0],['name'=>(string)($_POST['name']??''),'description'=>(string)($_POST['description']??''),'level'=>(string)($_POST['level']??'BASIC'),'duration_days'=>(int)($_POST['duration_days']??30),'active'=>isset($_POST['active'])?1:0]);
    $selected=[];
    foreach(['live','movie','series','personal'] as $scope) {
        if(!empty($_POST['all'][$scope])) $selected[]=['scope'=>$scope,'kind'=>'all','reference_id'=>0];
        foreach(is_array($_POST['categories'][$scope]??null)?$_POST['categories'][$scope]:[] as $ref) $selected[]=['scope'=>$scope,'kind'=>'category','reference_id'=>(int)$ref];
    }
    foreach(is_array($_POST['groups']??null)?$_POST['groups']:[] as $ref) $selected[]=['scope'=>'movie','kind'=>'group','reference_id'=>(int)$ref];
}
$has=function($scope,$kind,$ref=0) use($selected) {foreach($selected as $r) if($r['scope']===$scope && $r['kind']===$kind && (int)$r['reference_id']===$ref) return true;return false;};
v2_begin('Gestion des Packs');
?>
<link rel="stylesheet" href="assets/packs.css">
<script src="assets/packs-translations.js" defer></script>
<div class="packs-heading"><div><p class="v2-muted">Catalogue · Bouquets · Packs · Clients · G-TV</p><p>Composez les accès de vos abonnés à partir du catalogue existant.</p></div><a class="pack-button" href="packs.php?create=1"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Créer un pack</a></div>
<?php if($error):?><div class="v2-notice v2-danger" role="alert"><?=v2_h($error)?></div><?php endif?>
<?php if(isset($_GET['saved'])):?><div class="v2-notice" role="status">Pack enregistré. Les droits sont appliqués aux prochaines requêtes.</div><?php endif?>
<?php if($showForm):$form=$form??['id'=>0,'name'=>'','description'=>'','level'=>'BASIC','duration_days'=>30,'active'=>1,'clients'=>0];?>
<section class="v2-card"><h2><?=$editing?'Modifier le pack':'Créer un pack'?></h2><form method="post" id="pack-form"><?=panel_csrf_field()?><input type="hidden" name="operation" value="save"><input type="hidden" name="id" value="<?=(int)$form['id']?>">
<div class="v2-form"><label>Nom<input name="name" maxlength="120" required value="<?=v2_h($form['name'])?>"></label><label>Niveau<select name="level"><?php foreach(['BASIC','PREMIUM','PREMIUM+'] as $l):?><option <?=$form['level']===$l?'selected':''?>><?=v2_h($l)?></option><?php endforeach?></select></label><label>Durée par défaut (jours)<input name="duration_days" type="number" min="1" max="36500" required value="<?=(int)$form['duration_days']?>"></label><label>Description<textarea name="description" maxlength="10000" rows="2"><?=v2_h($form['description'])?></textarea></label><label><span><input type="checkbox" name="active" <?=$form['active']?'checked':''?>> Actif</span></label></div>
<div class="pack-rights"><?php foreach(['live'=>'LIVE','movie'=>'FILMS','series'=>'SÉRIES','personal'=>'CONTENUS PERSONNELS'] as $scope=>$label):?>
<fieldset><legend><?=$label?></legend><label class="pack-choice"><input type="checkbox" name="all[<?=$scope?>]" value="1" <?=$has($scope,'all')?'checked':''?>> Tout autoriser <small>(inclut les ajouts futurs)</small></label><div class="pack-category-list"><?php foreach($categories as $c):if($c['scope']!==$scope)continue;?><label class="pack-choice"><input type="checkbox" name="categories[<?=$scope?>][]" value="<?=(int)$c['category_id']?>" <?=$has($scope,'category',(int)$c['category_id'])?'checked':''?>><span data-gp-skip><?=v2_h($c['category_name'])?></span><small><?=(int)$c['contents']?></small></label><?php endforeach?><?php if($scope==='movie' && $groups):?><h4>Regroupement VOD</h4><?php foreach($groups as $g):?><label class="pack-choice"><input type="checkbox" name="groups[]" value="<?=(int)$g['group_id']?>" <?=$has('movie','group',(int)$g['group_id'])?'checked':''?>><span data-gp-skip><?=v2_h($g['label'])?></span></label><?php endforeach;endif?></div></fieldset><?php endforeach?></div>
<p class="v2-muted">Les contenus personnels ont leurs propres droits. Les catégories incluent leurs futurs contenus ; les épisodes héritent de leur série.</p>
<p id="pack-preview" class="v2-notice" aria-live="polite"></p>
<?php if($form['clients']):?><div class="v2-notice"><strong><?=(int)$form['clients']?> clients impactés.</strong><label class="pack-choice"><input type="checkbox" name="confirm_impact" value="1" required> Je confirme l’application des nouveaux droits à ces clients.</label></div><?php endif?>
<button type="submit">Enregistrer</button> <a href="packs.php">Annuler</a></form></section>
<?php endif?>
<?php if(($_GET['cache']??'')==='pending'):?><div class="v2-notice"><?=v2_h(panel_t('revalidation_failed'))?></div><?php endif?>
<div class="v2-form"><label>Rechercher un pack<input id="pack-search" type="search" placeholder="Rechercher..."></label><label>Statut<select id="pack-status"><option value="">Tous les statuts</option><option value="1">Actif</option><option value="0">Inactif</option></select></label><label>Niveau<select id="pack-level"><option value="">Tous les niveaux</option><option>BASIC</option><option>PREMIUM</option><option>PREMIUM+</option></select></label></div>
<div class="packs-grid"><?php foreach($packs as $p):$catalog=packs_catalog($pdo,['allowed'=>true,'rules'=>$byPack[(int)$p['id']]??[]]);$counts=['live'=>0,'movie'=>0,'series'=>0];foreach($catalog as $s) if(isset($counts[$s['stream_type']])) $counts[$s['stream_type']]++;?>
<article class="v2-card pack-card <?=$p['level']==='PREMIUM+'?'pack-card-gold':''?>" data-name="<?=v2_h($p['name'])?>" data-level="<?=v2_h($p['level'])?>" data-active="<?=(int)$p['active']?>"><div class="pack-card-top"><svg class="pack-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 9 5v8l-9 5-9-5V8l9-5Zm0 10 9-5M12 13 3 8m9 5v8"/></svg><?=packs_badge($p['level'],$p['level'])?><span class="v2-muted"><?=$p['active']?'Actif':'Inactif'?></span></div><h2 data-gp-skip><?=v2_h($p['name'])?></h2><p class="v2-muted" data-gp-skip><?=v2_h($p['description'])?></p><div class="pack-counts"><span><b><?=$counts['live']?></b>Live</span><span><b><?=$counts['movie']?></b>Films</span><span><b><?=$counts['series']?></b>Séries</span></div><p><strong><?=(int)$p['clients']?></strong> clients · <strong><?=(int)$p['duration_days']?></strong> jours</p><div class="pack-actions"><a class="pack-button" href="packs.php?edit=<?=(int)$p['id']?>">Modifier</a><?php foreach(['duplicate'=>'Dupliquer','toggle'=>$p['active']?'Désactiver':'Activer','delete'=>'Supprimer'] as $op=>$label):?><form method="post" <?=$op==='delete'?'data-confirm="Supprimer ce pack ?"':($op==='toggle'?'data-confirm="Modifier le statut du pack pour tous ses clients ?"':'')?>><?=panel_csrf_field()?><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button name="operation" value="<?=$op?>" <?=$op==='delete' && $p['clients']?'disabled title="Clients affectés : suppression bloquée"':''?>><?=$label?></button></form><?php endforeach?></div></article>
<?php endforeach?></div><?php if(!$packs):?><section class="v2-card"><h2>Votre premier pack</h2><p>Choisissez un nom libre et un niveau BASIC, PREMIUM ou PREMIUM+ GOLD, puis sélectionnez les bouquets autorisés.</p><a href="packs.php?create=1">Créer un pack</a></section><?php endif?>
<?php
// Preview data contains only catalogue identifiers, never source URLs or credentials.
$preview=$pdo->query("SELECT s.stream_id,s.category_id,s.stream_type,s.fournisseur_id,g.group_id FROM streams s JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id LEFT JOIN movie_group_sources g ON g.stream_id=s.stream_id WHERE s.stream_type IN ('live','movie','series') AND s.visible=1 AND c.visible=1 AND (f.active=1 OR s.fournisseur_id=0) AND (s.stream_type<>'movie' OR g.role IS NULL OR g.role='primary')")->fetchAll(PDO::FETCH_ASSOC);
?><script type="application/json" id="pack-catalog"><?=json_encode($preview,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script><script src="assets/packs.js" defer></script><?php v2_end(); ?>
