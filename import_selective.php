<?php
require_once __DIR__.'/panel_security.php';
panel_require_admin('catalog:import');
panel_check_csrf();
require __DIR__.'/config.php';
require_once __DIR__.'/panel_import.php';
require_once __DIR__.'/panel_import_catalog.php';
require_once __DIR__.'/panel_revalidation.php';
require_once __DIR__.'/stalker.php';
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
$stmt = $pdo->prepare('SELECT * FROM fournisseurs WHERE id = ? AND active = 1'); $stmt->execute([$fid]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);
$providers = $pdo->query('SELECT id,nom FROM fournisseurs WHERE active = 1 ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC);
$message = ''; $snapshot = $_SESSION['selective_import'] ?? null;
$valid = $provider && is_array($snapshot) && $snapshot['fid'] === $fid && $snapshot['kind'] === $kind && $snapshot['expires'] > time();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$provider) panel_fail(422,'invalid_request');
    if (isset($_POST['load'])) {
        try {
            $catalog = panel_import_catalog($provider,$kind,'sel_fetch');
            $snapshot = ['fid'=>$fid,'kind'=>$kind,'expires'=>time()+1800,'token'=>bin2hex(random_bytes(24)),'catalog'=>$catalog];
            $_SESSION['selective_import'] = $snapshot; $valid = true;
        } catch (Throwable $e) { $message = 'Catalogue indisponible ou incomplet. Aucun contenu modifié.'; }
    } else {
        if (!$valid || !is_string($_POST['snapshot'] ?? null) || !hash_equals($snapshot['token'],$_POST['snapshot'])) panel_fail(409,'invalid_request');
        $decisions = []; $selected = []; $cats = [];
        $posted = $_POST['choice'] ?? [];
        $view = panel_import_page($snapshot['catalog']['items'],strtolower(trim($provider['type'])),(string)($_GET['category']??''),(string)($_GET['container']??''),(string)($_GET['quality']??''),(int)($_GET['page']??1));
        if (!is_array($posted)) panel_fail(422,'invalid_request');
        foreach ($posted as $key=>$choice) {
            if ($choice === 'ignore') continue;
            // Keys are opaque hashes, never provider-controlled HTML field names.
            $matched = false;
            foreach ($view['items'] as $rid=>$item) if (hash('sha256',(string)$rid) === (string)$key) {
                if (!in_array($choice,['import','exclude'],true)) panel_fail(422,'invalid_request');
                $decisions[$rid] = $choice; $matched = true;
                if ($choice === 'import') { $selected[] = $item; $cats[] = panel_import_category($item,strtolower(trim($provider['type']))); }
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
            $map = panel_import_categories($pdo,$fid,$kind,$snapshot['catalog']['categories'],array_unique($cats),$provider['nom']);
            $count = process_and_insert_streams($pdo,$fid,$kind,strtolower(trim($provider['type'])),$selected,$map);
            if ($count !== count($selected)) throw new RuntimeException('Selection incomplete');
            $pdo->commit();
            $message = $count.' contenu(s) importé(s)/actualisé(s), '.count(array_filter($decisions,function($d){return $d === 'exclude';})).' exclusion(s) enregistrée(s).';
            if ($count && !panel_revalidate()) $message .= ' Notification du cache non confirmée.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = 'Échec : aucune décision appliquée. Vérifier la migration V2 et le catalogue.';
        } finally { if ($locked) panel_import_lock($pdo,$fid,true); }
    }
}
$type = $provider ? strtolower(trim($provider['type'])) : '';
$view = $valid ? panel_import_page($snapshot['catalog']['items'],$type,(string)($_GET['category']??''),(string)($_GET['container']??''),(string)($_GET['quality']??''),(int)($_GET['page']??1)) : null;
$excluded = [];
if ($valid) try { $excluded = panel_import_exclusions($pdo,$fid,$kind); } catch (Throwable $e) { $message = 'Migration V2 requise pour les exclusions persistantes.'; $view = null; }
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Import sélectif — G-PANEL</title><link rel="stylesheet" href="assets/gpanel.css"><style>main{max-width:1100px;margin:30px auto;padding:20px}select,button{padding:8px;margin:4px}table{width:100%;border-collapse:collapse}td,th{padding:10px;text-align:left;border-bottom:1px solid #64748b}</style></head><body><main>
<h1>Import sélectif individuel</h1><a href="importer.php">Retour à l’import par catégories</a>
<p><?= sel_h($message) ?></p>
<form method="get"><label>Fournisseur <select name="fournisseur_id"><?php foreach($providers as $f): ?><option value="<?= (int)$f['id'] ?>" <?= $fid===$f['id'] || $fid===(int)$f['id'] ? 'selected':'' ?>><?= sel_h($f['nom']) ?></option><?php endforeach ?></select></label>
<label>Type <select name="kind"><?php foreach(['live'=>'Live','movie'=>'Films','series'=>'Séries'] as $k=>$label): ?><option value="<?= $k ?>" <?= $kind===$k?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></label><button>Choisir</button></form>
<?php if($provider): ?><form method="post"><?= panel_csrf_field() ?><button name="load" value="1">Charger / actualiser le catalogue</button></form><?php endif ?>
<p>Seuls les choix explicites de la page sont appliqués. Une exclusion bloque les futurs imports ; elle ne supprime ni ne masque un contenu déjà présent. Choisir « Importer » lève son exclusion. Qualité inconnue si les métadonnées ne donnent aucune résolution. Les choix non validés sont perdus en changeant de page.</p>
<?php if($view): ?>
<form method="get"><input type="hidden" name="fournisseur_id" value="<?= $fid ?>"><input type="hidden" name="kind" value="<?= $kind ?>">
<label>Catégorie <select name="category"><option value="">Toutes</option><?php foreach($snapshot['catalog']['categories'] as $cat): $id=(string)($cat['category_id']??$cat['id']??$cat['category_name']??$cat['title']??''); ?><option value="<?= sel_h($id) ?>" <?= ($_GET['category']??'')===$id?'selected':'' ?>><?= sel_h($cat['category_name']??$cat['title']??$cat['name']??$id) ?></option><?php endforeach ?></select></label>
<label>Conteneur <select name="container"><option value="">Tous</option><?php foreach(['mp4','mkv','avi','ts','m2ts','mov','wmv','flv','webm','m4v','mpg','mpeg','m3u8','unknown'] as $ext): ?><option value="<?= $ext ?>" <?= ($_GET['container']??'')===$ext?'selected':'' ?>><?= $ext==='unknown'?'Inconnu':strtoupper($ext) ?></option><?php endforeach ?></select></label>
<label>Qualité <select name="quality"><option value="">Toutes</option><?php foreach(['720p','1080p','4K','unknown'] as $q): ?><option value="<?= $q ?>" <?= ($_GET['quality']??'')===$q?'selected':'' ?>><?= $q==='unknown'?'Inconnue':$q ?></option><?php endforeach ?></select></label><button>Filtrer</button></form>
<p><?= $view['total'] ?> contenus — page <?= $view['page'] ?>/<?= $view['pages'] ?></p>
<form method="post"><?= panel_csrf_field() ?><input type="hidden" name="snapshot" value="<?= sel_h($snapshot['token']) ?>"><table><tr><th>Contenu</th><th>Conteneur</th><th>Qualité</th><th>Décision</th></tr>
<?php foreach($view['items'] as $rid=>$s): ?><tr><td><?= sel_h($s['name']??$s['title']??$rid) ?><?= isset($excluded[$rid])?' (exclu)':'' ?></td><td><?= sel_h(panel_import_container($s,$type)) ?></td><td><?= sel_h(panel_import_quality($s)) ?></td><td><select name="choice[<?= hash('sha256',(string)$rid) ?>]"><option value="ignore">Aucune modification</option><option value="import">Importer / lever l’exclusion</option><option value="exclude">Exclure des imports</option></select></td></tr><?php endforeach ?></table><button>Appliquer les choix de cette page</button></form>
<?php foreach(['Précédente'=>max(1,$view['page']-1),'Suivante'=>min($view['pages'],$view['page']+1)] as $label=>$p): $query=$_GET; $query['page']=$p; ?><a href="?<?= sel_h(http_build_query($query)) ?>"><?= $label ?></a> <?php endforeach ?>
<?php endif ?></main></body></html>
