<?php
require_once __DIR__.'/panel_security.php';
panel_require_admin('catalog:import');
panel_check_csrf();
require __DIR__.'/config.php';
require_once __DIR__.'/panel_import.php';
require_once __DIR__.'/panel_import_catalog.php';
require_once __DIR__.'/panel_revalidation.php';
require_once __DIR__.'/stalker.php';
require_once __DIR__.'/panel_content.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function sel_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function sel_fetch($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>120,CURLOPT_CONNECTTIMEOUT=>20]);
    $body = curl_exec($ch); $status = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($body === false || $status >= 400) throw new RuntimeException('Provider download failed');
    return $body;
}
$fid = (int)($_GET['fournisseur_id'] ?? 0);
$kind = (string)($_GET['kind'] ?? 'live');
if (!in_array($kind,['live','movie','series'],true)) panel_fail(422,'invalid_request');
$stmt = $pdo->prepare('SELECT * FROM fournisseurs WHERE id = ?'); $stmt->execute([$fid]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);
$providers = $pdo->query('SELECT id,nom FROM fournisseurs ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC);
$message = ''; $snapshot = $_SESSION['selective_import'] ?? null;
$valid = $provider && is_array($snapshot) && $snapshot['fid'] === $fid && $snapshot['kind'] === $kind && $snapshot['expires'] > time();
function sel_known(PDO $pdo,int $fid,string $kind): array {
    $q=$pdo->prepare('SELECT s.*,f.type AS provider_type,c.remote_category_id,x.quality AS saved_quality,x.quality_origin AS saved_origin FROM streams s LEFT JOIN categories c ON c.category_id=s.category_id LEFT JOIN content_settings x ON x.stream_id=s.stream_id JOIN fournisseurs f ON f.id=s.fournisseur_id WHERE s.fournisseur_id=? AND s.stream_type=? ORDER BY s.stream_id');$q->execute([$fid,$kind]);$known=[];
    foreach($q as $row){$rid=(string)($row['remote_stream_id']?:$row['direct_source']);if($row['provider_type']==='m3u')$rid='m3u:'.hash('sha256',(string)$row['direct_source']);if($rid!==''&&!isset($known[$rid]))$known[$rid]=$row;}
    return $known;
}
function sel_catalog(PDO $pdo,array $catalog,array $known,string $type,bool $hasRemote): array {
    $remote=$catalog['items'];
    foreach($known as $rid=>$row){
        $settings=['quality'=>$row['saved_quality'],'quality_origin'=>$row['saved_origin']];
        if(!isset($catalog['items'][$rid])) {
            $item=['name'=>$row['stream_name'],'category_id'=>$row['remote_category_id'],'group'=>$row['remote_category_id'],'container_extension'=>$row['container_extension'],'_missing'=>$hasRemote];
            $catalog['items'][$rid]=$item;
        }
        $catalog['items'][$rid]['_local']=$row;
        if(!empty($settings['quality'])){$catalog['items'][$rid]['_quality']=$settings['quality'];$catalog['items'][$rid]['_quality_origin']=$settings['quality_origin'];}
    }
    return $catalog;
}
$known=$provider?sel_known($pdo,$fid,$kind):[];
$localCats=[];
if($provider){$q=$pdo->prepare('SELECT category_id,category_name,remote_category_id FROM categories WHERE fournisseur_id=? AND content_type=?');$q->execute([$fid,$kind]);foreach($q as $cat)$localCats[]=['category_id'=>(string)$cat['remote_category_id'],'category_name'=>$cat['category_name'].' · déjà en base #'.$cat['category_id']];}
// A local snapshot allows edits and decisions without downloading a provider catalogue.
if($provider&&!$valid){$snapshot=['fid'=>$fid,'kind'=>$kind,'expires'=>time()+1800,'token'=>bin2hex(random_bytes(24)),'catalog'=>['categories'=>$localCats,'items'=>[]],'remote'=>false];$_SESSION['selective_import']=$snapshot;$valid=true;}
$snapshotCatalog=$valid?sel_catalog($pdo,$snapshot['catalog'],$known,strtolower(trim($provider['type'])),!empty($snapshot['remote'])):null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$provider) panel_fail(422,'invalid_request');
    if(isset($_POST['category_action'])) {
        $cid=(int)($_POST['local_category']??0);$q=$pdo->prepare('SELECT * FROM categories WHERE category_id=? AND fournisseur_id=? AND content_type=?');$q->execute([$cid,$fid,$kind]);$cat=$q->fetch(PDO::FETCH_ASSOC);
        if(!$cat)panel_fail(422,'invalid_request');$action=$_POST['category_action'];$locked=false;
        try{
            panel_import_require_transactions($pdo);panel_import_lock($pdo,$fid);$locked=true;$pdo->beginTransaction();
            if(in_array($action,['exclude','import'],true))panel_import_decisions($pdo,$fid,$kind.'-category',[(string)$cat['remote_category_id']=>$action]);
            elseif(in_array($action,['deactivate','reactivate','rename','delete'],true)){
                panel_require_admin('catalog:edit');
                if($action==='rename'){$name=trim((string)($_POST['category_name']??''));if($name===''||mb_strlen($name)>100)throw new RuntimeException('Nom de catégorie invalide.');$pdo->prepare('UPDATE categories SET category_name=? WHERE category_id=?')->execute([$name,$cid]);}
                elseif($action==='delete'){$q=$pdo->prepare('SELECT COUNT(*) FROM streams WHERE category_id=?');$q->execute([$cid]);if($q->fetchColumn())throw new RuntimeException('Déplacer ou supprimer explicitement les contenus avant la catégorie.');if(($_POST['confirmation']??'')!=='SUPPRIMER '.$cid)throw new RuntimeException('Saisir SUPPRIMER '.$cid);panel_import_decisions($pdo,$fid,$kind.'-category',[(string)$cat['remote_category_id']=>'exclude']);$pdo->prepare('DELETE FROM categories WHERE category_id=?')->execute([$cid]);}
                else $pdo->prepare('UPDATE categories SET visible=? WHERE category_id=?')->execute([$action==='reactivate'?1:0,$cid]);
            }else throw new RuntimeException('Action invalide.');
            $pdo->commit();panel_revalidate();$message='Décision de catégorie enregistrée.';
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$message=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Échec : catégorie inchangée.';}finally{if($locked)panel_import_lock($pdo,$fid,true);}
    } elseif (isset($_POST['load'])) {
        try {
            if (!(int)$provider['active']) throw new RuntimeException('Provider inactive');
            $catalog = panel_import_catalog($provider,$kind,'sel_fetch');
            $snapshot = ['fid'=>$fid,'kind'=>$kind,'expires'=>time()+1800,'token'=>bin2hex(random_bytes(24)),'catalog'=>$catalog,'remote'=>true];
            $_SESSION['selective_import'] = $snapshot; $valid = true;
        } catch (Throwable $e) { $message = 'Catalogue indisponible ou incomplet. Aucun contenu modifié.'; }
    } else {
        if (!$valid || !is_string($_POST['snapshot'] ?? null) || !hash_equals($snapshot['token'],$_POST['snapshot'])) panel_fail(409,'invalid_request');
        $decisions = []; $selected = []; $cats = []; $visibility=[];
        $posted = $_POST['choice'] ?? [];
        $view = panel_import_page($snapshotCatalog['items'],strtolower(trim($provider['type'])),(string)($_GET['category']??''),(string)($_GET['container']??''),(string)($_GET['quality']??''),(int)($_GET['page']??1));
        if (!is_array($posted)) panel_fail(422,'invalid_request');
        foreach ($posted as $key=>$choice) {
            if ($choice === 'ignore') continue;
            // Keys are opaque hashes, never provider-controlled HTML field names.
            $matched = false;
            foreach ($view['items'] as $rid=>$item) if (hash('sha256',(string)$rid) === (string)$key) {
                if (!in_array($choice,['import','exclude','deactivate','reactivate'],true)) panel_fail(422,'invalid_request');
                if(in_array($choice,['import','exclude'],true)) $decisions[$rid] = $choice; else {if(!isset($known[$rid]))panel_fail(422,'invalid_request');panel_require_admin('catalog:edit');$visibility[$rid]=$choice==='reactivate'?1:0;} $matched = true;
                if ($choice === 'import' && !isset($known[$rid])) { if(empty($snapshot['remote'])||!empty($item['_missing']))panel_fail(422,'invalid_request'); $selected[] = $item; $cats[] = panel_import_category($item,strtolower(trim($provider['type']))); }
                break;
            }
            if (!$matched) panel_fail(422,'invalid_request');
        }
        $locked = false;
        try {
            panel_import_require_transactions($pdo);
            panel_import_lock($pdo,$fid); $locked = true;
            $pdo->beginTransaction();
            panel_import_decisions($pdo,$fid,$kind,$decisions);
            $up=$pdo->prepare('UPDATE streams SET visible=? WHERE stream_id=? AND fournisseur_id=? AND stream_type=?');foreach($visibility as $rid=>$visible)$up->execute([$visible,$known[$rid]['stream_id'],$fid,$kind]);
            $map = panel_import_categories($pdo,$fid,$kind,$snapshot['catalog']['categories'],array_unique($cats),$provider['nom']);
            $count = process_and_insert_streams($pdo,$fid,$kind,strtolower(trim($provider['type'])),$selected,$map);
            if ($count !== count($selected)) throw new RuntimeException('Selection incomplete');
            $pdo->commit();
            $message = $count.' contenu(s) importé(s)/actualisé(s), '.count(array_filter($decisions,function($d){return $d === 'exclude';})).' exclusion(s) enregistrée(s), '.count($visibility).' état(s) modifié(s).';
            if (($count || $decisions || $visibility) && !panel_revalidate()) $message .= ' Notification du cache non confirmée.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = 'Échec : aucune décision appliquée. Vérifier la migration V2 et le catalogue.';
        } finally { if ($locked) panel_import_lock($pdo,$fid,true); }
    }
}
$type = $provider ? strtolower(trim($provider['type'])) : '';
$known=$provider?sel_known($pdo,$fid,$kind):[];
if($valid){$snapshotCatalog=sel_catalog($pdo,$snapshot['catalog'],$known,$type,!empty($snapshot['remote']));$present=array_column($snapshotCatalog['categories'],'category_id');foreach($localCats as $cat)if(!in_array($cat['category_id'],$present,true))$snapshotCatalog['categories'][]=$cat;}
$view = $valid ? panel_import_page($snapshotCatalog['items'],$type,(string)($_GET['category']??''),(string)($_GET['container']??''),(string)($_GET['quality']??''),(int)($_GET['page']??1)) : null;
$excluded = [];
if ($valid) try { $excluded = panel_import_exclusions($pdo,$fid,$kind); } catch (Throwable $e) { $message = 'Migration V2 requise pour les exclusions persistantes.'; $view = null; }
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Import & gestion fournisseur — G-PANEL V2</title><link rel="stylesheet" href="assets/gpanel.css"><link rel="stylesheet" href="assets/content-v2.css"><style>main{max-width:1100px;margin:30px auto;padding:20px}select,button{padding:8px;margin:4px}table{width:100%;border-collapse:collapse}td,th{padding:10px;text-align:left;border-bottom:1px solid #64748b}</style></head><body><aside class="sidebar"><div class="brand"><img class="brand-full" src="assets/g-panel-logo-dark.png" alt="G-PANEL V2"></div><div class="gp-section-label">Administration V2</div><a href="admin.php">Tableau de bord</a><a href="editor.php">Gestion des Bouquets</a><a href="importer.php">Import par catégories</a><a href="import_selective.php">Imports sélectifs</a><a href="personal_content.php">Contenus personnels</a><a href="panel_diagnostics.php">Diagnostic &amp; Santé</a><a href="movie_duplicates.php">Regroupement VOD</a></aside><main class="main-content gp-workspace">
<h1>Import & gestion fournisseur</h1><a href="importer.php">Retour à l’import par catégories</a>
<p><?= sel_h($message) ?></p>
<form method="get"><label>Fournisseur <select name="fournisseur_id"><?php foreach($providers as $f): ?><option value="<?= (int)$f['id'] ?>" <?= $fid===$f['id'] || $fid===(int)$f['id'] ? 'selected':'' ?>><?= sel_h($f['nom']) ?></option><?php endforeach ?></select></label>
<label>Type <select name="kind"><?php foreach(['live'=>'Live','movie'=>'Films','series'=>'Séries'] as $k=>$label): ?><option value="<?= $k ?>" <?= $kind===$k?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></label><button>Choisir</button></form>
<?php if($provider): ?><form method="post"><?= panel_csrf_field() ?><button name="load" value="1">Charger / actualiser le catalogue</button></form><?php endif ?>
<p>Seuls les choix explicites de la page sont appliqués. Une exclusion bloque les futurs imports ; elle ne supprime ni ne masque un contenu déjà présent. Choisir « Importer » lève son exclusion. Qualité vérifiée par métadonnées, estimée depuis un titre explicite ou corrigée manuellement. Les éléments disparus du catalogue restent conservés. Gestion locale disponible sans recharger le fournisseur. Les choix non validés sont perdus en changeant de page.</p>
<?php if($provider):$q=$pdo->prepare('SELECT * FROM categories WHERE fournisseur_id=? AND content_type=? ORDER BY category_name');$q->execute([$fid,$kind]);$managedCats=$q->fetchAll(PDO::FETCH_ASSOC);$catExcluded=panel_import_exclusions($pdo,$fid,$kind.'-category');?>
<details class="glass-panel"><summary>Gérer les catégories déjà importées (<?=count($managedCats)?>)</summary><form method="post" class="v2-form"><?=panel_csrf_field()?><label>Catégorie locale<select name="local_category" required><?php foreach($managedCats as $cat):?><option value="<?=(int)$cat['category_id']?>"><?=sel_h($cat['category_name'])?> · #<?=(int)$cat['category_id']?> · <?=$cat['visible']?'Active':'Inactive'?><?=isset($catExcluded[$cat['remote_category_id']])?' · Exclue des futurs imports':''?></option><?php endforeach?></select></label><label>Action<select name="category_action"><option value="exclude">Exclure la catégorie des futurs imports</option><option value="import">Lever l’exclusion de catégorie</option><option value="deactivate">Désactiver la catégorie</option><option value="reactivate">Réactiver la catégorie</option><option value="rename">Renommer</option><option value="delete">Supprimer si vide (confirmation requise)</option></select></label><label>Nouveau nom (renommer)<input name="category_name" maxlength="100"></label><label>Suppression : SUPPRIMER suivi de l’ID<input name="confirmation"></label><button>Appliquer à cette catégorie</button></form><p>Exclure bloque aussi les nouveaux éléments de cette catégorie. Les contenus existants restent conservés.</p></details>
<?php endif?>
<?php if($view): ?>
<form method="get"><input type="hidden" name="fournisseur_id" value="<?= $fid ?>"><input type="hidden" name="kind" value="<?= $kind ?>">
<label>Catégorie <select name="category"><option value="">Toutes</option><?php foreach($snapshotCatalog['categories'] as $cat): $id=(string)($cat['category_id']??$cat['id']??$cat['category_name']??$cat['title']??''); ?><option value="<?= sel_h($id) ?>" <?= ($_GET['category']??'')===$id?'selected':'' ?>><?= sel_h($cat['category_name']??$cat['title']??$cat['name']??$id) ?></option><?php endforeach ?></select></label>
<label>Conteneur <select name="container"><option value="">Tous</option><?php foreach(['mp4','mkv','avi','ts','m2ts','mov','wmv','flv','webm','m4v','mpg','mpeg','m3u8','unknown'] as $ext): ?><option value="<?= $ext ?>" <?= ($_GET['container']??'')===$ext?'selected':'' ?>><?= $ext==='unknown'?'Inconnu':strtoupper($ext) ?></option><?php endforeach ?></select></label>
<label>Qualité <select name="quality"><option value="">Toutes</option><?php foreach(['SD','720p','1080p','4K','unknown'] as $q): ?><option value="<?= $q ?>" <?= ($_GET['quality']??'')===$q?'selected':'' ?>><?= $q==='unknown'?'Inconnue':$q ?></option><?php endforeach ?></select></label><button>Filtrer</button></form>
<p><?= $view['total'] ?> contenus — page <?= $view['page'] ?>/<?= $view['pages'] ?></p>
<form method="post"><?= panel_csrf_field() ?><input type="hidden" name="snapshot" value="<?= sel_h($snapshot['token']) ?>"><div class="v2-table"><table><tr><th>Contenu / état</th><th>Conteneur</th><th>Qualité</th><th>Décision / gestion</th></tr>
<?php foreach($view['items'] as $rid=>$s): $local=$s['_local']??null;$qd=panel_import_quality_details($s);?><tr><td><?=sel_h($local['stream_name']??$s['name']??$s['title']??$rid)?><small class="v2-badge"><?=$local?((int)$local['visible']?'Importé':'Inactif'):'Nouveau'?><?=isset($excluded[$rid])?' · Exclu':''?><?=!empty($s['_missing'])?' · Disparu du catalogue':''?></small><?php if($local):?><small>#<?=(int)$local['stream_id']?> · catégorie locale #<?=(int)$local['category_id']?></small><?php endif?></td><td><?=sel_h(panel_import_container($s,$type))?></td><td><?=sel_h($qd['value'])?><small><?=sel_h(['verified'=>'Vérifiée (métadonnées)','estimated'=>'Estimée (titre)','manual'=>'Correction manuelle','unknown'=>'Inconnue'][$qd['origin']]??$qd['origin'])?></small></td><td><select name="choice[<?=hash('sha256',(string)$rid)?>]"><option value="ignore">Aucune modification</option><option value="import"><?=$local?'Lever l’exclusion':'Importer'?></option><option value="exclude">Exclure des futurs imports</option><?php if($local):?><option value="deactivate">Désactiver</option><option value="reactivate">Réactiver</option><?php endif?></select><?php if($local):?><br><a href="content_edit.php?id=<?=(int)$local['stream_id']?>">Modifier / remplacer source / supprimer</a><?php endif?></td></tr><?php endforeach?></table></div><button>Appliquer les choix de cette page</button></form>
<?php foreach(['Précédente'=>max(1,$view['page']-1),'Suivante'=>min($view['pages'],$view['page']+1)] as $label=>$p): $query=$_GET; $query['page']=$p; ?><a href="?<?= sel_h(http_build_query($query)) ?>"><?= $label ?></a> <?php endforeach ?>
<?php endif ?></main>

<?= panel_ui_bootstrap() ?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script>
</body></html>
