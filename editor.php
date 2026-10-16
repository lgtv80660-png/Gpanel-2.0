<?php
require_once __DIR__ . '/panel_security.php';
panel_require_admin('catalog:edit');
panel_check_csrf();
require 'config.php';


require_once __DIR__ . '/panel_revalidation.php';
function notifyNextJsApp() {
    $ok = panel_revalidate();
    if (!$ok) $_SESSION['panel_notification_failed'] = true;
    return $ok;
}

// --- RÉCUPÉRATION D'UN COMPTE CLIENT POUR LE LECTEUR VIDÉO ---
$stmt_client = $pdo->query("SELECT username, password FROM clients WHERE active = 1 LIMIT 1");
$preview_client = $stmt_client->fetch(PDO::FETCH_ASSOC);
$preview_user = $preview_client ? $preview_client['username'] : '';
$preview_pass = $preview_client ? $preview_client['password'] : '';

// ==========================================
// MOTEUR AJAX (Modifications sans rechargement)
// ==========================================
unset($_SESSION['panel_notification_failed']);
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    
    if ($action === 'toggle_cat') {
        $stmt = $pdo->prepare("UPDATE categories SET visible = NOT visible WHERE category_id = ?");
        $stmt->execute([$_POST['id']]);
        notifyNextJsApp();
        echo json_encode(['success' => true, 'notification_confirmed' => empty($_SESSION['panel_notification_failed'])]); exit;
    }
    if ($action === 'toggle_stream') {
        $stmt = $pdo->prepare("UPDATE streams SET visible = NOT visible WHERE stream_id = ?");
        $stmt->execute([$_POST['id']]);
        notifyNextJsApp();
        echo json_encode(['success' => true, 'notification_confirmed' => empty($_SESSION['panel_notification_failed'])]); exit;
    }
    if ($action === 'bulk_toggle_cats') {
        $ids = json_decode($_POST['ids'] ?? '', true);
        if (!is_array($ids) || !$ids || count($ids) > 1000 || array_filter($ids, function($id) { return !is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1; })) panel_fail(422, 'invalid_request');
        if (!in_array((string)($_POST['visible'] ?? ''), ['0', '1'], true)) panel_fail(422, 'invalid_request');
        $visible = (int)$_POST['visible'];
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE categories SET visible = ? WHERE category_id IN ($inQuery)");
        $stmt->execute(array_merge([$visible], $ids));
        notifyNextJsApp();
        echo json_encode(['success' => true, 'notification_confirmed' => empty($_SESSION['panel_notification_failed'])]); exit;
    }
    if ($action === 'bulk_toggle_streams') {
        $ids = json_decode($_POST['ids'] ?? '', true);
        if (!is_array($ids) || !$ids || count($ids) > 1000 || array_filter($ids, function($id) { return !is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1; })) panel_fail(422, 'invalid_request');
        if (!in_array((string)($_POST['visible'] ?? ''), ['0', '1'], true)) panel_fail(422, 'invalid_request');
        $visible = (int)$_POST['visible'];
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE streams SET visible = ? WHERE stream_id IN ($inQuery)");
        $stmt->execute(array_merge([$visible], $ids));
        notifyNextJsApp();
        echo json_encode(['success' => true, 'notification_confirmed' => empty($_SESSION['panel_notification_failed'])]); exit;
    }
}
// ==========================================

$message = '';
unset($_SESSION['panel_notification_failed']);

// --- ACTIONS CLASSIQUES (Modif / Suppr) ---
if (isset($_POST['update_category'])) {
    $stmt = $pdo->prepare("UPDATE categories SET category_name = ? WHERE category_id = ?");
    if($stmt->execute([$_POST['new_category_name'], $_POST['category_id']])) {
        notifyNextJsApp();
        $message = '<div class="alert alert-success"><i class="fas fa-check"></i> Catégorie mise à jour avec succès.</div>';
    }
}

// MODIFICATION IN-PLACE DES FILMS / ÉPISODES (NOM, CATÉGORIE, POSTER)
if (isset($_POST['update_stream'])) {
    $stmt = $pdo->prepare("UPDATE streams SET stream_name = ?, category_id = ?, stream_icon = ? WHERE stream_id = ?");
    if($stmt->execute([$_POST['new_name'], $_POST['new_category'], $_POST['new_icon'], $_POST['stream_id']])) {
        notifyNextJsApp();
        $message = '<div class="alert alert-success"><i class="fas fa-check"></i> Élément mis à jour avec succès.</div>';
    }
}

if (isset($_POST['delete_stream'])) {
    header('Location: content_edit.php?id='.(int)($_POST['stream_id']??0));exit;
}

// --- RÉCUPÉRATION DES FOURNISSEURS POUR LE FILTRE ---
$fournisseurs = $pdo->query("SELECT id, nom FROM fournisseurs ORDER BY nom ASC")->fetchAll();

// --- DÉTECTION DU MODE D'AFFICHAGE ---
$cat_id = $_GET['cat_id'] ?? null;
$mode = $cat_id ? 'streams' : 'categories';

if ($mode === 'streams') {
    $stmt_cat = $pdo->prepare("SELECT category_name FROM categories WHERE category_id = ?");
    $stmt_cat->execute([$cat_id]);
    $current_category_name = $stmt_cat->fetchColumn();

    $stmt_streams = $pdo->prepare("
        SELECT s.*, f.nom as fournisseur_nom 
        FROM streams s 
        LEFT JOIN fournisseurs f ON s.fournisseur_id = f.id 
        WHERE s.category_id = ? 
        ORDER BY s.stream_id DESC
    ");
    $stmt_streams->execute([$cat_id]);
    $items = $stmt_streams->fetchAll();
    
    $all_categories = $pdo->query("SELECT category_id, category_name FROM categories ORDER BY category_name ASC")->fetchAll();
} else {
    $items = $pdo->query("
        SELECT c.category_id, c.category_name, c.visible, 
               COUNT(s.stream_id) as total_items, 
               MAX(s.stream_type) as main_type,
               GROUP_CONCAT(DISTINCT s.fournisseur_id) as fournisseurs_ids
        FROM categories c 
        LEFT JOIN streams s ON c.category_id = s.category_id 
        GROUP BY c.category_id 
        ORDER BY c.category_name ASC
    ")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="<?= panel_language() ?>" dir="<?= panel_language() === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Éditeur de Chaînes - Proxy IPTV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        :root { --bg-dark: #0f1219; --panel-bg: #1a1d26; --accent: #00d2ff; --text-main: #e0e0e0; --border-color: #2d3240; }
        body { background-color: var(--bg-dark); color: var(--text-main); font-family: 'Segoe UI', sans-serif; }
        
        .sidebar { background-color: var(--panel-bg); height: 100vh; position: fixed; width: 260px; border-right: 1px solid var(--border-color); padding-top: 20px; z-index: 1000;}
        .sidebar .brand { color: #fff; font-size: 22px; font-weight: 700; text-align: center; margin-bottom: 40px; }
        .sidebar .brand span { color: var(--accent); }
        .sidebar a { color: #8b92a5; text-decoration: none; display: block; padding: 15px 25px; transition: 0.3s; cursor: pointer; }
        .sidebar a:hover, .sidebar a.active { background: rgba(0, 210, 255, 0.1); color: #fff; border-left: 4px solid var(--accent); }
        .sidebar a i { margin-right: 12px; width: 20px; text-align: center; }

        .main-content { margin-left: 260px; padding: 30px; }
        .glass-panel { background: var(--panel-bg); border-radius: 12px; padding: 25px; border: 1px solid var(--border-color); }
        
        .table { --bs-table-bg: transparent; --bs-table-color: var(--text-main); --bs-table-hover-bg: rgba(0, 210, 255, 0.05); color: var(--text-main); }
        .table th { background-color: var(--bg-dark); color: #8b92a5; border-bottom: 2px solid var(--border-color); font-weight: 600; text-transform: uppercase; font-size: 12px; padding: 15px; }
        .table td { border-bottom: 1px solid var(--border-color); vertical-align: middle; padding: 15px; }
        .table a.folder-link { color: var(--text-main); text-decoration: none; transition: 0.2s; }
        .table a.folder-link:hover { color: var(--accent); }
        
        .badge-type { background: rgba(0, 210, 255, 0.1); color: var(--accent); padding: 5px 10px; border-radius: 6px; font-size: 11px; text-transform: uppercase; }
        .badge-hidden { background: rgba(255, 71, 87, 0.1); color: #ff4757; padding: 5px 10px; border-radius: 6px; font-size: 11px; text-transform: uppercase; border: 1px solid #ff4757; }
        
        .search-bar { background-color: var(--bg-dark); border: 1px solid var(--border-color); color: #fff; border-radius: 8px; padding: 10px 15px; width: 100%; }
        .search-bar:focus { outline: none; border-color: var(--accent); }
        .row-hidden { opacity: 0.4; }
        .folder-icon { font-size: 24px; color: #ffca28; margin-right: 10px; }
        
        .form-check-input { background-color: var(--bg-dark); border-color: var(--border-color); cursor: pointer; }
        .form-check-input:checked { background-color: var(--accent); border-color: var(--accent); }

        #videoPlayer { width: 100%; border-radius: 8px; background: #000; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
    </style>
    <link rel="stylesheet" href="assets/gpanel.css">
<?= panel_ui_bootstrap() ?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script><script src="assets/cinema.js" defer></script><style>html:not(.gp-ready) body{visibility:hidden}</style><noscript><style>body{visibility:visible!important}</style></noscript><link rel="stylesheet" href="assets/cinema.css"></head>
<body>

    <aside class="sidebar">
        <div class="brand"><img class="brand-full" src="assets/g-panel-logo.png" alt="G-PANEL"><img class="brand-mark" src="assets/g-panel-mark.png" alt="G-PANEL"></div><div class="gp-brand-mini">IPTV MANAGEMENT SYSTEM</div>
        <div class="gp-section-label">Navigation</div><a href="admin.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a>
        <div class="gp-section-label">Gestion</div><a href="editor.php" class="active"><i class="fas fa-folder-open"></i> Gestion des Bouquets</a>
        <a href="importer.php"><i class="fas fa-sync-alt"></i> Forcer l'importation</a>
        
        <a href="#" onclick="purgeNextJsCache(); return false;" style="color: #00d2ff;">
            <i class="fas fa-bolt"></i> Synchro App G-TV
        </a>
    </aside>

    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>
                <?php if ($mode === 'streams'): ?>
                    <a href="editor.php" class="text-decoration-none text-info"><i class="fas fa-arrow-left"></i> Retour aux bouquets</a> 
                    <span class="mx-2 text-muted">/</span> 
                    <i class="fas fa-folder-open folder-icon" style="font-size:18px;"></i> <span data-gp-skip><?= htmlspecialchars($current_category_name) ?></span>
                <?php else: ?>
                    Gestion des Bouquets (Catégories)
                <?php endif; ?>
            </h4>
        </div>
        
        <?= $message ?><?php if (!empty($_SESSION['panel_notification_failed'])): ?><div class="alert alert-warning"><?= htmlspecialchars(panel_t('revalidation_failed'), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="glass-panel">
            
            <div class="row mb-4 align-items-center">
                <div class="col-md-8 d-flex gap-2">
                    <input type="text" id="searchInput" class="search-bar flex-grow-1" placeholder="Rechercher..." onkeyup="filterTable()">
                    
                    <select id="typeFilter" class="form-select w-auto bg-dark text-white border-secondary" onchange="filterTable()">
                        <option value="">Tous les types</option>
                        <option value="live">Live (Direct)</option>
                        <option value="movie">Films (VOD)</option>
                        <option value="series">Séries / Épisodes</option>
                    </select>

                    <select id="providerFilter" class="form-select w-auto bg-dark text-white border-secondary" onchange="filterTable()">
                        <option value="">Tous les fournisseurs</option>
                        <?php foreach($fournisseurs as $f): ?>
                            <option data-gp-skip value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-outline-success btn-sm me-2" onclick="bulkAction(true)">
                        <i class="fas fa-eye"></i> Afficher
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="bulkAction(false)">
                        <i class="fas fa-eye-slash"></i> Masquer
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover" id="mainTable">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input class="form-check-input" type="checkbox" id="selectAll" onclick="toggleAllCheckboxes(this)">
                            </th>
                            <th>Statut</th>
                            <?php if ($mode === 'categories'): ?>
                                <th>Bouquet (Catégorie)</th>
                                <th>Contenu</th>
                                <th>Éléments</th>
                            <?php else: ?>
                                <th>Logo</th>
                                <th>Nom du Film / Chaîne</th>
                                <th>Type</th>
                            <?php endif; ?>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        
                        <?php if ($mode === 'categories'): ?>
                            <?php foreach ($items as $c): ?>
                            <tr class="item-row category-row <?= $c['visible'] ? '' : 'row-hidden' ?>" data-type="<?= strtolower($c['main_type'] ?? '') ?>" data-provider="<?= $c['fournisseurs_ids'] ?? '' ?>">
                                <td>
                                    <input class="form-check-input row-checkbox" type="checkbox" value="<?= $c['category_id'] ?>">
                                </td>
                                <td class="badge-cell">
                                    <?php if($c['visible']): ?>
                                        <span class="badge-type"><i class="fas fa-eye"></i> Visible</span>
                                    <?php else: ?>
                                        <span class="badge-hidden"><i class="fas fa-eye-slash"></i> Masqué</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold fs-5">
                                    <a href="editor.php?cat_id=<?= $c['category_id'] ?>" class="folder-link d-flex align-items-center">
                                        <i class="fas fa-folder folder-icon"></i> <span data-gp-skip><?= htmlspecialchars($c['category_name']) ?></span>
                                    </a>
                                </td>
                                <td><span class="badge bg-secondary"><?= strtoupper($c['main_type'] ?? 'VIDE') ?></span></td>
                                <td><span data-gp-skip><?= $c['total_items'] ?></span> <span>liens</span></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-info me-2" onclick="editCategory('<?= $c['category_id'] ?>', '<?= addslashes(htmlspecialchars($c['category_name'])) ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm <?= $c['visible'] ? 'btn-outline-warning' : 'btn-outline-success' ?> toggle-btn me-2" onclick="toggleSingle('<?= $c['category_id'] ?>', 'cat', this)">
                                        <i class="fas <?= $c['visible'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i> 
                                        <span class="btn-text"><?= $c['visible'] ? 'Masquer' : 'Afficher' ?></span>
                                    </button>
                                    <a href="editor.php?cat_id=<?= $c['category_id'] ?>" class="btn btn-sm btn-info text-white">
                                        <i class="fas fa-list"></i> Gérer
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($items as $s): ?>
                            <tr class="item-row <?= $s['visible'] ? '' : 'row-hidden' ?>" data-type="<?= strtolower($s['stream_type'] ?? '') ?>" data-provider="<?= $s['fournisseur_id'] ?? '' ?>">
                                <td>
                                    <input class="form-check-input row-checkbox" type="checkbox" value="<?= $s['stream_id'] ?>">
                                </td>
                                <td class="badge-cell">
                                    <?php if($s['visible']): ?>
                                        <span class="badge-type"><i class="fas fa-eye"></i> Visible</span>
                                    <?php else: ?>
                                        <span class="badge-hidden"><i class="fas fa-eye-slash"></i> Masqué</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($s['stream_icon']): ?>
                                        <img src="<?= htmlspecialchars($s['stream_icon']) ?>" width="40" style="border-radius:4px;">
                                    <?php else: ?>
                                        <i class="fas fa-tv text-muted"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold" data-gp-skip>
                                    <?= htmlspecialchars($s['stream_name']) ?>
                                    <br>
                                    <small class="text-muted"><i class="fas fa-server"></i> <?= htmlspecialchars($s['fournisseur_nom'] ?? 'Inconnu') ?></small>
                                </td>
                                <td><span class="badge-type"><?= $s['stream_type'] ?></span></td>
                                <td class="text-end">
                                    <?php if ($s['stream_type'] === 'live'): ?><a class="btn btn-sm btn-outline-info" href="sources_manage.php?id=<?=(int)$s['stream_id']?>">Sources &amp; Backups</a><?php endif; ?>
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="previewStream('<?= $s['stream_id'] ?>', '<?= $s['stream_type'] ?>', '<?= addslashes(htmlspecialchars($s['stream_name'])) ?>', '<?= htmlspecialchars((string)($s['container_extension'] ?? ''), ENT_QUOTES) ?>')" title="Prévisualiser la chaîne">
                                        <i class="fas fa-play"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm <?= $s['visible'] ? 'btn-outline-warning' : 'btn-outline-success' ?> toggle-btn me-1" onclick="toggleSingle('<?= $s['stream_id'] ?>', 'stream', this)">
                                        <i class="fas <?= $s['visible'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i> 
                                        <span class="btn-text" style="display:none;"><?= $s['visible'] ? 'Masquer' : 'Afficher' ?></span>
                                    </button>
                                    
                                    <button class="btn btn-sm btn-outline-info me-1" onclick="editStream('<?= $s['stream_id'] ?>', '<?= addslashes(htmlspecialchars($s['stream_name'])) ?>', '<?= $s['category_id'] ?>', '<?= addslashes(htmlspecialchars($s['stream_icon'] ?? '')) ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <form method="POST" style="display:inline;" onsubmit="return confirm(GPanelUI.t('Supprimer définitivement ?'));"><?= panel_csrf_field() ?>
                                        <input type="hidden" name="stream_id" value="<?= $s['stream_id'] ?>">
                                        <button type="submit" name="delete_stream" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal Modification Catégorie -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--panel-bg); color: #fff; border: 1px solid var(--border-color);">
          <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
            <h5 class="modal-title">Renommer le bouquet</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST"><?= panel_csrf_field() ?>
              <div class="modal-body">
                <input type="hidden" name="category_id" id="edit_cat_id">
                <div class="mb-3">
                    <label class="form-label text-muted">Nouveau nom de la catégorie</label>
                    <input type="text" name="new_category_name" id="edit_cat_name" class="form-control bg-dark text-white border-secondary" required>
                </div>
              </div>
              <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" name="update_category" class="btn btn-primary" style="background: var(--accent); border: none;">Enregistrer</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Modification Film / Épisode / Chaîne -->
    <?php if ($mode === 'streams'): ?>
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--panel-bg); color: #fff; border: 1px solid var(--border-color);">
          <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
            <h5 class="modal-title">Modifier le Film / la Chaîne</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST"><?= panel_csrf_field() ?>
              <div class="modal-body">
                <input type="hidden" name="stream_id" id="edit_id">
                <div class="mb-3">
                    <label class="form-label text-muted">Nouveau nom du film / épisode</label>
                    <input type="text" name="new_name" id="edit_name" class="form-control bg-dark text-white border-secondary" required>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted">URL Poster / Logo (Optionnel)</label>
                    <input type="text" name="new_icon" id="edit_icon" class="form-control bg-dark text-white border-secondary" placeholder="https://image.tmdb.org/...">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted">Déplacer vers la catégorie</label>
                    <select name="new_category" id="edit_category" class="form-select bg-dark text-white border-secondary">
                        <?php foreach($all_categories as $cat): ?>
                            <option data-gp-skip value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
              </div>
              <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" name="update_stream" class="btn btn-primary" style="background: var(--accent); border: none;">Enregistrer les modifications</button>
              </div>
          </form>
        </div>
      </div>
    </div>
    
    <div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background-color: var(--panel-bg); color: #fff; border: 1px solid var(--border-color);">
          <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
            <h5 class="modal-title"><i class="fas fa-play-circle text-info me-2"></i> <span id="previewTitle" data-gp-skip>Lecteur</span></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopPlayer()"></button>
          </div>
          <div class="modal-body text-center p-3">
            <video id="videoPlayer" controls autoplay></video>
            <div id="playerError" class="alert alert-warning mt-3" style="display: none; text-align: left; font-size: 14px;">
                <i class="fas fa-exclamation-triangle"></i> <b>Attention :</b> Ce format de flux (.ts souvent utilisé pour le direct) n'est pas supporté nativement par les navigateurs web. La chaîne fonctionne, mais vous devez l'ouvrir dans un lecteur comme VLC ou Xtream Player.
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div id="importModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:var(--panel-bg); color:var(--text-main); padding:30px; border-radius:12px; width:450px; text-align:center; border:1px solid var(--border-color); box-shadow:0 8px 25px rgba(0,0,0,0.5);">
            <h3 id="importTitle" style="margin-top:0; color:#fff;">Importation en cours...</h3>
            <p id="importStatus" style="color:#8b92a5; font-size:14px;">Connexion aux fournisseurs et synchronisation des flux en arrière-plan.</p>
            <div style="background:var(--bg-dark); border-radius:6px; overflow:hidden; height:20px; margin:20px 0; border:1px solid var(--border-color);">
                <div id="importProgressBar" style="background:var(--accent); width:20%; height:100%; transition:width 0.4s ease;"></div>
            </div>
            <span id="importPercentage" style="font-weight:bold; color:#fff;">Patientez...</span>
            <div>
                <button id="closeImportBtn" onclick="closeImportModal()" style="display:none; margin-top:20px; background:var(--accent); color:#000; font-weight:bold; border:none; padding:10px 25px; border-radius:6px; cursor:pointer;">Fermer et actualiser</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const currentMode = '<?= $mode ?>';
        const previewUser = encodeURIComponent('<?= addslashes($preview_user) ?>');
        const previewPass = encodeURIComponent('<?= addslashes($preview_pass) ?>');
        let hlsPlayer = null;

        function purgeNextJsCache() {
            if (!confirm(GPanelUI.t("Voulez-vous réinitialiser le cache de l'application G-TV ?"))) return;
            
            fetch('revalidate_proxy.php', {method:'POST', headers:{'X-CSRF-Token':window.PANEL_CONFIG.csrf}})
                .then(res => {
                    if(!res.ok) throw new Error(GPanelUI.t("❌ Erreur de communication avec l'application."));
                    return res.json();
                })
                .then(data => alert(data.message))
                .catch(err => alert(GPanelUI.t("❌ Erreur de communication avec l'application.")));
        }

        function editCategory(id, name) {
            document.getElementById('edit_cat_id').value = id;
            document.getElementById('edit_cat_name').value = name;
            var editCatModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
            editCatModal.show();
        }

        function editStream(id, name, cat_id, icon = '') {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_category').value = cat_id;
            if (document.getElementById('edit_icon')) {
                document.getElementById('edit_icon').value = icon;
            }
            var editModal = new bootstrap.Modal(document.getElementById('editModal'));
            editModal.show();
        }

        function filterTable() {
            let textInput = document.getElementById("searchInput").value.toLowerCase();
            let typeInput = document.getElementById("typeFilter").value.toLowerCase();
            let providerInput = document.getElementById("providerFilter").value;
            
            let rows = document.querySelectorAll("#mainTable tbody .item-row");
            
            rows.forEach(row => {
                let textMatch = row.innerText.toLowerCase().includes(textInput);
                let rowType = row.getAttribute("data-type") || "";
                let typeMatch = (typeInput === "" || rowType === typeInput || (typeInput === 'series' && rowType === 'episode'));
                let rowProvider = row.getAttribute("data-provider") || "";
                let providerMatch = (providerInput === "" || rowProvider.split(',').includes(providerInput));
                
                if (textMatch && typeMatch && providerMatch) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
            document.getElementById('selectAll').checked = false;
        }

        function toggleAllCheckboxes(masterCheckbox) {
            let visibleRows = document.querySelectorAll('#mainTable tbody .item-row:not([style*="display: none"]) .row-checkbox');
            visibleRows.forEach(cb => cb.checked = masterCheckbox.checked);
        }

        async function toggleSingle(id, type, btn) {
            let formData = new FormData();
            formData.append('csrf_token', window.PANEL_CONFIG.csrf);
            formData.append('ajax_action', type === 'cat' ? 'toggle_cat' : 'toggle_stream');
            formData.append('id', id);
            let res = await fetch('editor.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (!res.ok || !data.success) { alert(data.error?.message || GPanelUI.t('Une erreur est survenue lors de la communication avec le serveur.')); return; }
            if (data.success) {
                if (data.notification_confirmed === false) alert(GPanelUI.t('Catalogue enregistré ; notification du cache non confirmée.'));
                updateRowVisuals(btn.closest('tr'), null);
            }
        }

        async function bulkAction(makeVisible) {
            let checkedBoxes = document.querySelectorAll('#mainTable tbody .item-row:not([style*="display: none"]) .row-checkbox:checked');
            let ids = Array.from(checkedBoxes).map(cb => cb.value);
            
            if (ids.length === 0) return alert(GPanelUI.t("Veuillez sélectionner au moins un élément."));

            let formData = new FormData();
            formData.append('csrf_token', window.PANEL_CONFIG.csrf);
            formData.append('ajax_action', currentMode === 'categories' ? 'bulk_toggle_cats' : 'bulk_toggle_streams');
            formData.append('ids', JSON.stringify(ids));
            formData.append('visible', makeVisible ? 1 : 0);

            let res = await fetch('editor.php', { method: 'POST', body: formData });
            let data = await res.json();
            if (!res.ok || !data.success) { alert(data.error?.message || GPanelUI.t('Une erreur est survenue lors de la communication avec le serveur.')); return; }

            if (data.success) {
                if (data.notification_confirmed === false) alert(GPanelUI.t('Catalogue enregistré ; notification du cache non confirmée.'));
                checkedBoxes.forEach(cb => {
                    updateRowVisuals(cb.closest('tr'), makeVisible);
                    cb.checked = false;
                });
                document.getElementById('selectAll').checked = false;
            }
        }

        function updateRowVisuals(row, targetVisible) {
            let isCurrentlyHidden = row.classList.contains('row-hidden');
            let willBeVisible = targetVisible !== null ? targetVisible : isCurrentlyHidden;
            
            let badgeCell = row.querySelector('.badge-cell');
            let btn = row.querySelector('.toggle-btn');
            let btnText = row.querySelector('.btn-text');

            if (willBeVisible) {
                row.classList.remove('row-hidden');
                badgeCell.innerHTML = '<span class="badge-type"><i class="fas fa-eye"></i> Visible</span>';
                btn.className = 'btn btn-sm btn-outline-warning toggle-btn ' + (currentMode === 'categories' ? 'me-2' : 'me-1');
                btn.querySelector('i').className = 'fas fa-eye-slash';
                if(btnText) btnText.innerText = GPanelUI.t('Masquer');
            } else {
                row.classList.add('row-hidden');
                badgeCell.innerHTML = '<span class="badge-hidden"><i class="fas fa-eye-slash"></i> Masqué</span>';
                btn.className = 'btn btn-sm btn-outline-success toggle-btn ' + (currentMode === 'categories' ? 'me-2' : 'me-1');
                btn.querySelector('i').className = 'fas fa-eye';
                if(btnText) btnText.innerText = GPanelUI.t('Afficher');
            }
        }

        function previewStream(streamId, type, name, extension = '') {
            if (!previewUser || !previewPass) {
                alert(GPanelUI.t("Aucun client actif n'a été trouvé dans la base de données. Impossible de lancer la vidéo."));
                return;
            }

            document.getElementById('previewTitle').innerText = name;
            const video = document.getElementById('videoPlayer');
            document.getElementById('playerError').style.display = 'none';

            if (hlsPlayer) {
                hlsPlayer.destroy();
                hlsPlayer = null;
            }

            let url = '';
            if (type === 'live') {
                url = `live.php?username=${encodeURIComponent(previewUser)}&password=${encodeURIComponent(previewPass)}&stream=${encodeURIComponent(streamId)}&extension=m3u8`;
            } else if (type === 'movie') {
                url = `vod.php?username=${encodeURIComponent(previewUser)}&password=${encodeURIComponent(previewPass)}&stream=${encodeURIComponent(streamId)}&extension=${encodeURIComponent(extension || 'mp4')}`;
            } else if (type === 'episode') {
                url = `series.php?username=${encodeURIComponent(previewUser)}&password=${encodeURIComponent(previewPass)}&stream=${encodeURIComponent(streamId)}&extension=${encodeURIComponent(extension || 'mp4')}`;
            } else {
                document.getElementById('playerError').style.display = 'block';
                return;
            }

            const modal = new bootstrap.Modal(document.getElementById('previewModal'));
            modal.show();

            if (type === 'live') {
                if (window.Hls && Hls.isSupported()) {
                    hlsPlayer = new Hls({ debug: false });
                    hlsPlayer.loadSource(url);
                    hlsPlayer.attachMedia(video);
                    hlsPlayer.on(Hls.Events.MANIFEST_PARSED, () => video.play().catch(() => {}));
                    hlsPlayer.on(Hls.Events.ERROR, (event, data) => {
                        if (data.fatal) document.getElementById('playerError').style.display = 'block';
                    });
                } else {
                    video.src = url;
                    video.addEventListener('loadedmetadata', () => video.play().catch(() => {}), { once: true });
                }
            } else {
                video.src = url;
                video.load();
                video.addEventListener('loadedmetadata', () => video.play().catch(() => {}), { once: true });
                video.addEventListener('error', () => {
                    document.getElementById('playerError').style.display = 'block';
                }, { once: true });
            }
        }

        function stopPlayer() {
            let video = document.getElementById('videoPlayer');
            video.pause();
            video.removeAttribute('src');
            video.load();
            if (hlsPlayer) {
                hlsPlayer.destroy();
                hlsPlayer = null;
            }
        }

        if(document.getElementById('previewModal')) {
            document.getElementById('previewModal').addEventListener('hidden.bs.modal', function () {
                stopPlayer();
            });
        }

        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
            location.reload(); 
        }
    </script>

</body>
</html>
