<?php
require_once __DIR__ . '/panel_security.php';
panel_require_admin('catalog:import');
panel_check_csrf();
require_once __DIR__ . '/panel_revalidation.php';
require_once __DIR__ . '/panel_import.php';
// V16.1 - Importation AJAX + Détection des filtres + Noms personnalisés + BULK INSERT (Optimisation Vitesse)
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) { @ob_end_flush(); }
ob_implicit_flush(1);

require 'config.php';

require_once 'stalker.php';


$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(3600);
ini_set('memory_limit', '2048M');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fetch_data_stream($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'IPTV-Panel/16.0',
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// =========================================================
// NOUVELLE FONCTION OPTIMISÉE AVEC BULK INSERT (BATCH 500)
// =========================================================
// ==========================================
// MOTEUR AJAX (Compteurs Anti-Ban)
// ==========================================
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_count') {
    error_reporting(0); 
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');
    
    $fid = (int)($_POST['fournisseur_id'] ?? 0);
    $kind = $_POST['kind'] ?? '';
    $cat_id = $_POST['cat_id'] ?? '';
    $token = $_POST['token'] ?? '';
    $path = $_POST['path'] ?? '/c/';

    $stmt = $pdo->prepare("SELECT * FROM fournisseurs WHERE id = ?");
    $stmt->execute([$fid]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $count = 0;
    if ($f && strtolower(trim($f['type'])) === 'stalker') {
        $portal = stalker_normalize_portal($f['url_base']);
        $mac = stalker_normalize_mac($f['mac_address']);
        $proxy = $f['proxy'] ?? '';
        
        if (empty($token)) {
            $hs = stalker_handshake($portal, $mac, $proxy);
            if ($hs['ok']) {
                $token = $hs['token'];
                $path = $hs['path'];
            }
        }
        
        if (!empty($token)) {
            if ($kind === 'live') {
                $res = stalker_load($portal, $mac, $token, 'itv', 'get_all_channels', [], $path, $proxy);
                if ($res['ok']) {
                    $items = stalker_js_list($res['data']);
                    if (is_array($items)) {
                        foreach($items as $i) {
                            if (($i['tv_genre_id'] ?? $i['category_id'] ?? '') == $cat_id) $count++;
                        }
                    }
                }
            } else {
                $st_type = ($kind === 'movie') ? 'vod' : 'series';
                $res = stalker_load($portal, $mac, $token, $st_type, 'get_ordered_list', ['category' => $cat_id, 'p'=>1], $path, $proxy);
                
                if ($res['ok']) {
                    $raw = json_decode((string)$res['data'], true);
                    if (isset($raw['js']['total_items'])) {
                        $count = (int)$raw['js']['total_items'];
                    } else {
                        $items = stalker_js_list($res['data']);
                        $count = is_array($items) ? count($items) : 0;
                    }
                    
                    if ($count === 0 && $kind === 'series') {
                        $res2 = stalker_load($portal, $mac, $token, 'series', 'get_series', ['category' => $cat_id, 'p'=>1], $path, $proxy);
                        if ($res2['ok']) {
                            $raw2 = json_decode((string)$res2['data'], true);
                            if (isset($raw2['js']['total_items'])) {
                                $count = (int)$raw2['js']['total_items'];
                            } else {
                                $items = stalker_js_list($res2['data']);
                                $count = is_array($items) ? count($items) : 0;
                            }
                        }
                    }
                }
            }
        }
    }
    echo json_encode(['count' => $count]);
    exit;
}

$fournisseurs = $pdo->query("SELECT * FROM fournisseurs WHERE active = 1 ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
$selectedId = (int)($_GET['fournisseur_id'] ?? $_POST['fournisseur_id'] ?? 0);
$step = ($selectedId > 0 && !isset($_POST['step'])) ? 2 : (int)($_POST['step'] ?? 1);
$selectedProvider = null;

if ($selectedId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM fournisseurs WHERE id = ? AND active = 1 LIMIT 1");
    $stmt->execute([$selectedId]);
    $selectedProvider = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ==========================================
// ÉTAPE 1 : CHOIX DU FOURNISSEUR
// ==========================================
if (!$selectedProvider):
?>
<!DOCTYPE html><html lang="<?= panel_language() ?>" dir="<?= panel_language() === 'ar' ? 'rtl' : 'ltr' ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G-PANEL — Importation</title><link rel="stylesheet" href="assets/gpanel.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.gp-import-shell{max-width:1180px;margin:28px auto;padding:0 18px}
.gp-import-top{position:sticky;top:0;z-index:1000;display:flex;justify-content:space-between;align-items:center;gap:18px;padding:12px 18px;margin:-24px -24px 24px;border-bottom:1px solid rgba(127,157,190,.18);backdrop-filter:blur(14px)}
.gp-import-brand{display:flex;align-items:center;gap:12px}
.gp-import-brand img{height:34px;width:auto}
.gp-import-title{font-weight:800;font-size:20px;color:var(--gp-text,#eef6ff)}
.gp-import-card{background:var(--gp-panel,rgba(15,27,45,.92));border:1px solid var(--gp-border,#26374d);border-radius:16px;padding:30px;box-shadow:0 14px 40px rgba(0,0,0,.18); max-width: 700px; margin: 0 auto;}
.import-logo{width:180px;display:block;margin:0 auto 15px;}
</style>
<style>html:not(.gp-ready) body{visibility:hidden}</style><noscript><style>body{visibility:visible!important}</style></noscript><?= panel_ui_bootstrap() ?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script></head><body>
<div class="gp-import-shell">
  <div class="gp-import-top">
    <div class="gp-import-brand">
      <img src="assets/g-panel-logo.png" alt="G-PANEL">
      <div><div class="gp-import-title">Importation Interactive (V16.1)</div></div>
    </div>
    <a class="btn btn-outline-light" href="admin.php"><i class="fas fa-arrow-left"></i> Retour</a>
  </div>
  <div class="gp-import-card">
    <img src="assets/g-panel-logo.png" class="import-logo" alt="G-PANEL">
    <h3 class="text-center mb-4">Choisir une source</h3>
    <form method="POST"><?= panel_csrf_field() ?>
        <input type="hidden" name="step" value="2">
        <select name="fournisseur_id" class="form-select bg-dark text-white border-secondary mb-4" required>
            <option value="">-- Sélectionner un fournisseur --</option>
            <?php foreach($fournisseurs as $f): ?>
                <option data-gp-skip value="<?= (int)$f['id'] ?>"><?= h($f['nom']) ?> — <?= h(strtoupper($f['type'])) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary w-100 py-2"><i class="fas fa-bolt me-2"></i>Scanner les catégories</button>
    </form>
  </div>
</div>
</body></html>
<?php exit; endif; 

$f = $selectedProvider; $fid = (int)$f['id']; $nom = $f['nom']; $type = strtolower(trim($f['type']));
$remoteCats = ['live'=>[], 'movie'=>[], 'series'=>[]];
$catCounts = ['live'=>[], 'movie'=>[], 'series'=>[]];
$js_token = ''; $js_path = '/c/';

// ==========================================
// ÉTAPE 2 : AFFICHAGE & AJAX COUNTING
// ==========================================
if ($step === 2) {
    $stmtExisting = $pdo->prepare("SELECT remote_category_id, category_name, content_type FROM categories WHERE fournisseur_id = ? AND visible = 1");
    $stmtExisting->execute([$fid]);
    $alreadyImported = ['live'=>[], 'movie'=>[], 'series'=>[]];
    $customNames = ['live'=>[], 'movie'=>[], 'series'=>[]];

    foreach ($stmtExisting->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $alreadyImported[$row['content_type']][] = (string)$row['remote_category_id'];
        $customNames[$row['content_type']][(string)$row['remote_category_id']] = $row['category_name'];
    }

    if ($type === 'xtream') {
        $base = rtrim((string)$f['url_base'], '/');
        $api = $base.'/player_api.php?username='.rawurlencode((string)$f['user']).'&password='.rawurlencode((string)$f['pass']);
        
        foreach (['get_live_categories'=>'live','get_vod_categories'=>'movie','get_series_categories'=>'series'] as $action=>$kind) {
            $resp = fetch_data_stream($api.'&action='.$action);
            if ($resp) { $dec = json_decode($resp, true); if(is_array($dec)) $remoteCats[$kind] = $dec; }
        }
        foreach (['get_live_streams'=>'live','get_vod_streams'=>'movie','get_series'=>'series'] as $action=>$kind) {
            $resp = fetch_data_stream($api.'&action='.$action);
            if ($resp) { 
                $items = json_decode($resp, true); 
                if(is_array($items)){
                    foreach($items as $s) {
                        $c = (string)($s['category_id'] ?? '');
                        if(!isset($catCounts[$kind][$c])) $catCounts[$kind][$c]=0;
                        $catCounts[$kind][$c]++;
                    }
                }
            }
        }
    } elseif ($type === 'stalker') {
        $portal = stalker_normalize_portal($f['url_base']);
        $mac = stalker_normalize_mac($f['mac_address'] ?? '');
        $proxy = $f['proxy'] ?? ''; 
        if ($mac === '') panel_fail(422, 'mac_missing');
        $hs = stalker_handshake($portal, $mac, $proxy);
        if (!$hs['ok']) panel_fail(502, 'import_failed');
        
        $js_token = $hs['token'];
        $js_path = $hs['path'];
        
        $res = stalker_load($portal, $mac, $js_token, 'itv', 'get_genres', [], $js_path, $proxy);
        if ($res['ok']) $remoteCats['live'] = stalker_js_list($res['data']);
        $res = stalker_load($portal, $mac, $js_token, 'vod', 'get_categories', [], $js_path, $proxy);
        if ($res['ok']) $remoteCats['movie'] = stalker_js_list($res['data']);
        $res = stalker_load($portal, $mac, $js_token, 'series', 'get_categories', [], $js_path, $proxy);
        if ($res['ok']) $remoteCats['series'] = stalker_js_list($res['data']);
        
    } elseif ($type === 'm3u') {
        $resp = fetch_data_stream($f['url_base']);
        if ($resp) {
            $lines = preg_split('/\r\n|\r|\n/', (string)$resp);
            foreach ($lines as $line) {
                if (stripos(trim($line), '#EXTINF:') === 0) {
                    $group = 'Général';
                    if (preg_match('/group-title="([^"]*)"/i',$line,$m)) $group = $m[1] ?: 'Général';
                    $remoteCats['live'][$group] = ['id'=>$group,'title'=>$group];
                    if(!isset($catCounts['live'][$group])) $catCounts['live'][$group]=0;
                    $catCounts['live'][$group]++;
                }
            }
            $remoteCats['live'] = array_values($remoteCats['live']);
        }
    }
?>
<!DOCTYPE html><html lang="<?= panel_language() ?>" dir="<?= panel_language() === 'ar' ? 'rtl' : 'ltr' ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>G-PANEL — Sélection</title><link rel="stylesheet" href="assets/gpanel.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body{background:#0f1219;color:#eef6ff;}
.top-bar{background:#1a1d26;padding:15px 30px;border-bottom:1px solid #2d3240;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:100;}
.cat-box{background:#1a1d26;border:1px solid #2d3240;border-radius:10px;padding:20px;margin-bottom:20px;}
.cat-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px;margin-top:15px;max-height:500px;overflow-y:auto;padding-right:10px;}
.cat-item{background:#0b1527;border:1px solid #33465e;padding:10px;border-radius:6px;display:flex;align-items:center;gap:10px; cursor:pointer; transition:0.2s;}
.cat-item:hover{border-color:#00d2ff;}
.cat-item input{cursor:pointer; width: 18px; height: 18px; accent-color: #00d2ff;}
.badge-count{background:rgba(0,210,255,0.1);color:#00d2ff;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:bold;margin-left:auto;}
.badge-empty{background:rgba(255,71,87,0.1);color:#ff4757;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:bold;margin-left:auto; opacity:0.6;}
.loading-badge{color:#8ea0b5; font-size:14px; margin-left:auto;}
.disabled-item{opacity:0.5; pointer-events:none;}
.badge-imported{background:rgba(22, 163, 74, 0.2); color:#16a34a; border: 1px solid #16a34a;}
</style>
<style>html:not(.gp-ready) body{visibility:hidden}</style><noscript><style>body{visibility:visible!important}</style></noscript><?= panel_ui_bootstrap() ?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script></head><body>
<form method="POST" id="importForm" action="importer.php"><?= panel_csrf_field() ?>
    <input type="hidden" name="fournisseur_id" value="<?= $fid ?>">
    <input type="hidden" name="step" value="3">
    
    <div class="top-bar">
        <div>
            <h4 style="margin:0;"><i class="fas fa-filter text-info me-2"></i> <span>Sélection des bouquets :</span> <span data-gp-skip><?= h($nom) ?></span></h4>
            <small class="text-muted" id="loading-status">Les catégories actives de votre éditeur sont pré-cochées.</small>
        </div>
        <div>
            <a href="admin.php" class="btn btn-outline-secondary me-2">Annuler</a>
            <button type="submit" class="btn btn-primary px-4"><i class="fas fa-download me-2"></i> Valider et Importer</button>
        </div>
    </div>

    <div style="padding: 30px;">
        <?php foreach (['live'=>'Direct (Live)', 'movie'=>'Films (VOD)', 'series'=>'Séries'] as $kind => $label): 
            if (empty($remoteCats[$kind])) continue;
        ?>
        <div class="cat-box">
            <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2">
                <h5 style="margin:0;color:#00d2ff;"><i class="fas fa-folder-open me-2"></i> <?= $label ?></h5>
                <button type="button" class="btn btn-sm btn-outline-light" onclick="toggleAll('<?= $kind ?>')">Tout (dé)cocher</button>
            </div>
            <div class="cat-list" id="list_<?= $kind ?>">
                <?php foreach ($remoteCats[$kind] as $c): 
                    $rid = trim((string)($c['category_id'] ?? $c['id'] ?? ''));
                    $name = trim((string)($c['category_name'] ?? $c['title'] ?? $c['name'] ?? 'Général'));
                    if ($rid === '') $rid = $name;
                    
                    $isStalker = ($type === 'stalker');
                    $count = $catCounts[$kind][$rid] ?? 0;
                    
                    $isAlreadyImported = in_array($rid, $alreadyImported[$kind]);
                    $checkedState = $isAlreadyImported ? 'checked' : '';
                    $myCustomName = $customNames[$kind][$rid] ?? null;
                ?>
                <label class="cat-item <?= ($isStalker) ? 'disabled-item ajax-cat' : (($count == 0 && !$isAlreadyImported) ? 'disabled-item' : '') ?>" 
                       data-kind="<?= $kind ?>" data-id="<?= h($rid) ?>">
                    <input type="checkbox" name="selected_cats[<?= $kind ?>][]" value="<?= h($rid) ?>" <?= ($isStalker || ($count == 0 && !$isAlreadyImported)) ? 'disabled' : '' ?> <?= $checkedState ?>>
                    
                    <div style="font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex-grow:1;">
                        <div style="font-weight:bold; color:#fff;" title="<?= h($name) ?>" data-gp-skip>
                            <?= h($name) ?>
                        </div>
                        <?php if ($myCustomName): ?>
                            <small style="color:#00d2ff; font-size:11px;">
                                <i class="fas fa-pen"></i> <span>Ton nom :</span> <span data-gp-skip><?= h($myCustomName) ?></span>
                            </small>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($isAlreadyImported && !$isStalker): ?>
                         <span class="badge-count badge-imported" title="Actif dans l'éditeur"><i class="fas fa-check"></i> <?= $count ?></span>
                    <?php elseif ($isStalker): ?>
                        <span class="loading-badge"><i class="fas fa-spinner fa-spin"></i></span>
                    <?php else: ?>
                        <span class="<?= ($count > 0) ? 'badge-count' : 'badge-empty' ?>"><?= $count ?></span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</form>

<script>
function toggleAll(kind) {
    let checkboxes = document.querySelectorAll('#list_' + kind + ' input[type="checkbox"]:not(:disabled)');
    let allChecked = Array.from(checkboxes).every(c => c.checked);
    checkboxes.forEach(c => c.checked = !allChecked);
}

document.addEventListener('DOMContentLoaded', async function() {
    const ajaxItems = document.querySelectorAll('.ajax-cat');
    if (ajaxItems.length === 0) return;
    
    document.getElementById('loading-status').innerHTML = "<i class='fas fa-sync fa-spin text-warning'></i> Calcul des flux en cours...";
    
    const stalkerToken = "<?= h($js_token) ?>";
    const stalkerPath = "<?= h($js_path) ?>";
    const alreadyImportedJS = <?= json_encode($alreadyImported) ?>;
    
    for (let i = 0; i < ajaxItems.length; i++) {
        let item = ajaxItems[i];
        let kind = item.getAttribute('data-kind');
        let catId = item.getAttribute('data-id');
        let badge = item.querySelector('.loading-badge');
        let checkbox = item.querySelector('input');
        
        let isImported = alreadyImportedJS[kind] && alreadyImportedJS[kind].includes(catId);
        
        try {
            let formData = new FormData();
            formData.append('csrf_token', window.PANEL_CONFIG.csrf);
            formData.append('ajax_action', 'get_count');
            formData.append('fournisseur_id', '<?= $fid ?>');
            formData.append('kind', kind);
            formData.append('cat_id', catId);
            formData.append('token', stalkerToken);
            formData.append('path', stalkerPath);
            
            let response = await fetch('importer.php', { method: 'POST', body: formData });
            let data = await response.json();
            if (!response.ok || data.error) throw new Error('request failed');
            
            item.classList.remove('disabled-item');
            
            if (isImported) {
                badge.className = 'badge-count badge-imported';
                badge.innerHTML = '<i class="fas fa-check"></i> ' + data.count;
            } else {
                badge.className = (data.count > 0) ? 'badge-count' : 'badge-empty';
                badge.innerText = data.count;
            }
            
            if (data.count > 0 || isImported) {
                checkbox.disabled = false;
            }
        } catch (e) {
            badge.className = 'badge-empty';
            badge.innerText = GPanelUI.t('Err');
        }
    }
    document.getElementById('loading-status').innerHTML = "<i class='fas fa-check text-success'></i> <span>Calcul terminé.</span>";
});
</script>
</body></html>
<?php exit; 
}

// ==========================================
// ÉTAPE 3 : IMPORTATION SQL CIBLÉE
// ==========================================
if ($step === 3):
    $importSucceeded = true;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') panel_fail(405, 'invalid_request');
    $selectedCats = $_POST['selected_cats'] ?? [];
    if (!is_array($selectedCats)) panel_fail(422, 'invalid_request');
    foreach ($selectedCats as $kind => $ids) {
        if (!in_array($kind, ['live','movie','series'], true) || !is_array($ids) || array_filter($ids, function ($id) { return !is_scalar($id); })) panel_fail(422, 'invalid_request');
    }
    
    if (empty($selectedCats['live']) && empty($selectedCats['movie']) && empty($selectedCats['series'])) {
        panel_fail(422, 'import_empty');
    }

    echo '<!DOCTYPE html><html lang="' . panel_language() . '" dir="' . (panel_language() === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="UTF-8"><title>G-PANEL — Import SQL</title><link rel="stylesheet" href="assets/gpanel.css">' . panel_ui_bootstrap() . '<script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script></head><body style="background:#0f1219;color:#fff;padding:40px;font-family:monospace;">';
    echo '<h2><i class="fas fa-database text-info"></i> <span>'.h(panel_t('import_started')).'</span> <span data-gp-skip>'.h($nom).'</span></h2><hr style="border-color:#2d3240;">'; flush();

    $insertCat = $pdo->prepare("INSERT INTO categories (category_name,parent_id,visible,fournisseur_id,remote_category_id,content_type) VALUES (?,0,1,?,?,?)");
    
    $remoteCats = ['live'=>[], 'movie'=>[], 'series'=>[]];
    $token = ''; $hs = []; $portal = ''; $mac = ''; $proxy = ''; $api = '';

    if ($type === 'xtream') {
        $base = rtrim((string)$f['url_base'], '/');
        $api = $base.'/player_api.php?username='.rawurlencode((string)$f['user']).'&password='.rawurlencode((string)$f['pass']);
        foreach (['get_live_categories'=>'live','get_vod_categories'=>'movie','get_series_categories'=>'series'] as $action=>$kind) {
            $resp = fetch_data_stream($api.'&action='.$action);
            if ($resp) { $dec = json_decode($resp, true); if(is_array($dec)) $remoteCats[$kind] = $dec; }
        }
    } elseif ($type === 'stalker') {
        $portal = stalker_normalize_portal($f['url_base']);
        $mac = stalker_normalize_mac($f['mac_address'] ?? '');
        $proxy = $f['proxy'] ?? ''; 
        $hs = stalker_handshake($portal, $mac, $proxy);
        if ($hs['ok']) {
            $token = $hs['token'];
            $res = stalker_load($portal, $mac, $token, 'itv', 'get_genres', [], $hs['path'], $proxy);
            if ($res['ok']) $remoteCats['live'] = stalker_js_list($res['data']);
            $res = stalker_load($portal, $mac, $token, 'vod', 'get_categories', [], $hs['path'], $proxy);
            if ($res['ok']) $remoteCats['movie'] = stalker_js_list($res['data']);
            $res = stalker_load($portal, $mac, $token, 'series', 'get_categories', [], $hs['path'], $proxy);
            if ($res['ok']) $remoteCats['series'] = stalker_js_list($res['data']);
        }
    } elseif ($type === 'm3u') {
        $resp = fetch_data_stream($f['url_base']);
        $m3u_lines = $resp ? preg_split('/\r\n|\r|\n/', (string)$resp) : [];
        foreach ($m3u_lines as $line) {
            if (stripos(trim($line), '#EXTINF:') === 0) {
                $group = 'Général';
                if (preg_match('/group-title="([^"]*)"/i',$line,$m)) $group = $m[1] ?: 'Général';
                $remoteCats['live'][$group] = ['id'=>$group,'title'=>$group];
            }
        }
        $remoteCats['live'] = array_values($remoteCats['live']);
    }

    foreach (['live', 'movie', 'series'] as $kind) {
        if (empty($selectedCats[$kind])) continue;

        echo "<h3 style='color:#00d2ff; margin-top:30px;'><span>".h(panel_t('processing'))."</span> <span>{$kind}</span>…</h3>"; flush();
        
        try {
            if (empty($remoteCats[$kind])) throw new RuntimeException('Invalid categories');
            panel_import_lock($pdo, $fid);
            panel_import_require_transactions($pdo);
            $pdo->beginTransaction();

            $catMap = panel_import_categories($pdo, $fid, $kind, $remoteCats[$kind], $selectedCats[$kind], $nom);

            $total_inserted = 0;

            // Appel de la fonction sans passer $insertStream
            if ($type === 'xtream') {
                $action = ($kind==='live') ? 'get_live_streams' : (($kind==='movie') ? 'get_vod_streams' : 'get_series');
                echo "<p style='color:#8b92a5;'>".h(panel_t('xtream_download'))."</p>"; flush();
                $resp = fetch_data_stream($api.'&action='.$action);
                if ($resp) {
                    $items = json_decode($resp, true);
                    if (is_array($items)) {
                        $total_inserted += process_and_insert_streams($pdo, $fid, $kind, $type, $items, $catMap);
                    }
                }
            } elseif ($type === 'm3u') {
                echo "<p style='color:#8b92a5;'>".h(panel_t('m3u_parse'))."</p>"; flush();
                $items = []; $current = null;
                foreach ($m3u_lines as $line) {
                    if (trim($line) === '') continue;
                    if (stripos($line, '#EXTINF:') === 0) {
                        $current = ['name'=>'','icon'=>'','group'=>'Général','url'=>'','ext'=>null];
                        if (preg_match('/tvg-logo="([^"]*)"/i',$line,$m)) $current['icon']=$m[1];
                        if (preg_match('/group-title="([^"]*)"/i',$line,$m)) $current['group']=$m[1] ?: 'Général';
                        $pos = strrpos($line, ',');
                        if ($pos !== false) $current['name']=trim(substr($line,$pos+1));
                    } elseif ($current && $line[0] !== '#') {
                        $current['url']=$line; $items[] = $current; $current=null;
                    }
                }
                $total_inserted += process_and_insert_streams($pdo, $fid, $kind, $type, $items, $catMap);
                
            } elseif ($type === 'stalker') {
                if ($kind === 'live') {
                    $res = stalker_load($portal, $mac, $token, 'itv', 'get_all_channels', [], $hs['path'], $proxy);
                    if ($res['ok']) {
                        $total_inserted += process_and_insert_streams($pdo, $fid, $kind, $type, stalker_js_list($res['data']), $catMap);
                    }
                } else {
                    $stalker_type = ($kind === 'movie') ? 'vod' : 'series';
                    foreach ($selectedCats[$kind] as $cat_id) {
                        echo "<p style='margin:2px 0; color:#8ea0b5;'><span>".h(panel_t('group_id'))."</span> <span data-gp-skip>".h($cat_id)."</span>...</p>"; flush();
                        
                        $p = 1;
                        while(true) {
                            $res = stalker_load($portal, $mac, $token, $stalker_type, 'get_ordered_list', ['category' => $cat_id, 'p' => $p], $hs['path'], $proxy);
                            $items = stalker_js_list($res['data']);
                            
                            if (empty($items) && $kind === 'series') {
                                $res = stalker_load($portal, $mac, $token, 'series', 'get_series', ['category' => $cat_id, 'p' => $p], $hs['path'], $proxy);
                                $items = stalker_js_list($res['data']);
                            }
                            
                            if (empty($items)) break;
                            
                            $inserted = process_and_insert_streams($pdo, $fid, $kind, $type, $items, $catMap);
                            $total_inserted += $inserted;
                            echo "<script>window.scrollTo(0,document.body.scrollHeight);</script>"; flush();
                            
                            if (count($items) < 14) break;
                            $p++;
                        }
                    }
                }
            }

            if ($total_inserted < 1) throw new RuntimeException('Empty import');
            $pdo->commit();
            echo "<p style='color:#16a34a; font-weight:bold;'>✔ <span>".h(panel_t('kind_'.$kind))."</span> : <span data-gp-skip>$total_inserted</span> <span>".h(panel_t('items_inserted'))."</span></p>"; flush();
        } catch(Throwable $e) {
            $importSucceeded = false;
            if($pdo->inTransaction()) $pdo->rollBack();
            echo "<p style='color:#dc2626;'><span>".h(panel_t('sql_error'))."</span> ".h(panel_t('import_failed'))."</p>"; flush();
        } finally {
            panel_import_lock($pdo, $fid, true);
        }
    }

    $notificationConfirmed = $importSucceeded && panel_revalidate();

    echo '<div style="margin-top:40px; padding:20px; background:#16a34a; color:#fff; border-radius:8px; text-align:center;">';
    echo '<h3>' . h(panel_t($importSucceeded ? 'import_ok' : 'import_failed')) . '</h3>';
    echo '<p>' . h(panel_t($notificationConfirmed ? 'revalidation_ok' : 'revalidation_failed')) . '</p>';
    echo '<a href="admin.php" style="display:inline-block; margin-top:15px; padding:10px 20px; background:#fff; color:#000; text-decoration:none; border-radius:5px; font-weight:bold;">'.h(panel_t('back_panel')).'</a>';
    echo '</div></body></html>';
endif; 
?>
