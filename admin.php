<?php
require_once __DIR__ . '/panel_security.php';
panel_check_csrf();
require 'config.php';


if (isset($_POST['logout'])) {
    $_SESSION = [];
    session_regenerate_id(true);
    header("Location: admin.php");
    exit;
}

$login_error = '';
if (isset($_POST['login_btn'])) {
    $user_input = trim($_POST['username'] ?? '');
    $pass_input = trim($_POST['password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$user_input]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && (password_verify($pass_input, $admin['password']) || (password_get_info($admin['password'])['algoName'] === 'unknown' && hash_equals($admin['password'], $pass_input)))) {
        session_regenerate_id(true);
        $_SESSION['panel_admin_id'] = (string)$admin['id'];
        $_SESSION['panel_language'] = panel_load_language($admin['id']);
        $_SESSION['panel_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['panel_last_seen'] = time();
        $_SESSION['admin_logged'] = true;
        header("Location: admin.php");
        exit;
    } else {
        $login_error = "Identifiant ou mot de passe incorrect.";
    }
}

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true):
?>
<!DOCTYPE html>
<html lang="<?= panel_language() ?>" dir="<?= panel_language() === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - IPTV Proxy Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bg-dark: #0f1219; --panel-bg: #1a1d26; --accent: #00d2ff; --text-main: #e0e0e0; --border-color: #2d3240; }
        body { background-color: var(--bg-dark); color: var(--text-main); font-family: 'Segoe UI', sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-panel { background: var(--panel-bg); padding: 40px; border-radius: 12px; border: 1px solid var(--border-color); width: 100%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .form-control { background-color: var(--bg-dark); border: 1px solid var(--border-color); color: #fff; padding: 12px; }
        .form-control:focus { background-color: var(--bg-dark); color: #fff; border-color: var(--accent); box-shadow: none; }
        .btn-primary { background: var(--accent); border: none; padding: 12px; font-weight: bold; color: #000; }
        .btn-primary:hover { opacity: 0.9; }
    </style>
    <link rel="stylesheet" href="assets/gpanel.css">
<?= panel_ui_bootstrap() ?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script><noscript><style>body{visibility:visible!important}</style></noscript></head>
<body>
<form id="panelLogout" method="POST" hidden><?= panel_csrf_field() ?><input name="logout" value="1"></form>
    <div class="login-panel">
        <div class="text-center mb-4">
            <img src="assets/g-panel-logo.png" alt="G-PANEL" style="width:260px;max-width:100%;height:auto;display:block;margin:0 auto 14px;">
            <p class="text-muted" style="font-size:14px;">Espace Administrateur sécurisé</p>
        </div>
        <?php if($login_error): ?>
            <div class="alert alert-danger py-2" style="font-size:13px;"><?= $login_error ?></div>
        <?php endif; ?>
        <form method="POST"><?= panel_csrf_field() ?>
            <div class="mb-3">
                <label class="form-label text-muted" style="font-size:12px; text-transform:uppercase;">Utilisateur</label>
                <input type="text" name="username" class="form-control" required autocomplete="off">
            </div>
            <div class="mb-4">
                <label class="form-label text-muted" style="font-size:12px; text-transform:uppercase;">Mot de passe</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" name="login_btn" class="btn-primary w-100">Se connecter</button>
        </form>
    </div>

</body>
</html>
<?php 
exit; 
endif; 

panel_require_admin('panel:read');
if ($_SERVER['REQUEST_METHOD'] === 'POST') panel_require_admin('panel:manage');

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? "https" : "http";
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_dir = dirname($_SERVER['PHP_SELF']);
if ($base_dir === '\\' || $base_dir === '/') $base_dir = '';
$server_url = $protocol . "://" . $host . $base_dir;

$message = '';
if (isset($_GET['success'])) {
    if ($_GET['success'] == '1') $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Ajout effectué avec succès !</div>';
    if ($_GET['success'] == 'updated') $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Élément mis à jour !</div>';
    if ($_GET['success'] == 'deleted') $message = '<div class="alert alert-warning"><i class="fas fa-trash"></i> Élément supprimé !</div>';
}

if (isset($_POST['add_client'])) {
    $stmt = $pdo->prepare("INSERT INTO clients (username, password) VALUES (?, ?)");
    if($stmt->execute([$_POST['username'], $_POST['password']])) {
        header("Location: admin.php?success=1"); exit;
    }
}

if (isset($_POST['edit_client_btn'])) {
    $stmt = $pdo->prepare("UPDATE clients SET username = ?, password = ? WHERE id = ?");
    if($stmt->execute([$_POST['edit_client_username'], $_POST['edit_client_password'], $_POST['client_id']])) {
        header("Location: admin.php?success=updated"); exit;
    }
}

if (isset($_POST['delete_client'])) {
    $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
    if($stmt->execute([$_POST['client_id']])) {
        header("Location: admin.php?success=deleted"); exit;
    }
}

if (isset($_POST['add_source'])) {
    $nom = $_POST['nom'];
    $type = $_POST['type'];
    $url_base = $_POST['url_base'];
    $user = $_POST['user'] ?? null;
    $pass = $_POST['pass'] ?? null;
    $mac_address = $_POST['mac_address'] ?? null;
    $proxy = $_POST['proxy'] ?? null;

    $stmt = $pdo->prepare("INSERT INTO fournisseurs (nom, type, url_base, user, pass, mac_address, proxy) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if($stmt->execute([$nom, $type, $url_base, $user, $pass, $mac_address, $proxy])) {
        $new_source_id = (int)$pdo->lastInsertId();
        header("Location: importer.php?fournisseur_id=" . $new_source_id);
        exit;
    }
}

if (isset($_POST['edit_source_btn'])) {
    $source_id = (int)($_POST['source_id'] ?? 0);
    $new_nom   = trim($_POST['edit_nom'] ?? '');

    $stmtOld = $pdo->prepare("SELECT nom FROM fournisseurs WHERE id = ?");
    $stmtOld->execute([$source_id]);
    $old_nom = $stmtOld->fetchColumn();

    $stmt = $pdo->prepare("UPDATE fournisseurs SET nom = ?, url_base = ?, user = ?, pass = ?, mac_address = ?, proxy = ? WHERE id = ?");
    if($stmt->execute([$new_nom, $_POST['edit_url'], $_POST['edit_user'], $_POST['edit_pass'], $_POST['edit_mac_address'] ?? null, $_POST['edit_proxy'] ?? null, $source_id])) {
        
        if ($old_nom && $old_nom !== $new_nom) {
            $oldTag = '[' . $old_nom . ']';
            $newTag = '[' . $new_nom . ']';
            $stmtCat = $pdo->prepare("UPDATE categories SET category_name = REPLACE(category_name, ?, ?) WHERE fournisseur_id = ?");
            $stmtCat->execute([$oldTag, $newTag, $source_id]);
        }

        header("Location: admin.php?success=updated"); exit;
    }
}

if (isset($_POST['delete_source'])) {
    $id = (int)($_POST['source_id'] ?? 0);
    if ($id > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM streams WHERE fournisseur_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM categories WHERE fournisseur_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM fournisseurs WHERE id = ?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    header("Location: admin.php?success=deleted"); exit;
}

$total_clients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$total_sources = $pdo->query("SELECT COUNT(*) FROM fournisseurs")->fetchColumn();

$clients = $pdo->query("SELECT * FROM clients ORDER BY id DESC")->fetchAll();
$fournisseurs = $pdo->query("SELECT * FROM fournisseurs ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= panel_language() ?>" dir="<?= panel_language() === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPTV Proxy Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bg-dark: #0f1219; --panel-bg: #1a1d26; --accent: #00d2ff; --accent-hover: #3a7bd5; --text-main: #e0e0e0; --text-muted: #8b92a5; --border-color: #2d3240; }
        body { background-color: var(--bg-dark); color: var(--text-main); font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        .sidebar { background-color: var(--panel-bg); height: 100vh; position: fixed; width: 260px; border-right: 1px solid var(--border-color); padding-top: 20px; z-index: 1000; }
        .sidebar .brand { color: #fff; font-size: 22px; font-weight: 700; text-align: center; margin-bottom: 40px; letter-spacing: 1px; }
        .sidebar .brand span { color: var(--accent); }
        .sidebar a { color: var(--text-muted); text-decoration: none; display: block; padding: 15px 25px; font-size: 15px; transition: 0.3s; border-left: 4px solid transparent; }
        .sidebar a:hover, .sidebar a.active { background: linear-gradient(90deg, rgba(0, 210, 255, 0.1), transparent); color: #fff; border-left: 4px solid var(--accent); }
        .sidebar a i { margin-right: 12px; width: 20px; text-align: center; }
        .main-content { margin-left: 260px; padding: 30px; }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
        .user-profile { display: flex; align-items: center; gap: 15px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), var(--accent-hover)); display: flex; align-items: center; justify-content: center; font-weight: bold; color: #fff; }
        .glass-panel { background: var(--panel-bg); border-radius: 12px; padding: 25px; margin-bottom: 25px; border: 1px solid var(--border-color); box-shadow: 0 8px 16px rgba(0,0,0,0.2); }
        .stat-card { display: flex; align-items: center; gap: 20px; }
        .stat-card .icon { font-size: 40px; color: var(--accent); background: rgba(0, 210, 255, 0.1); padding: 15px; border-radius: 12px; }
        .stat-card h3 { margin: 0; font-size: 28px; color: #fff; font-weight: 700; }
        .stat-card p { margin: 0; color: var(--text-muted); font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        .form-control, .form-select { background-color: var(--bg-dark); border: 1px solid var(--border-color); color: #fff; padding: 12px 15px; }
        .form-control:focus, .form-select:focus { background-color: var(--bg-dark); color: #fff; border-color: var(--accent); box-shadow: 0 0 0 0.25rem rgba(0, 210, 255, 0.25); }
        .form-label { color: var(--text-muted); font-size: 13px; text-transform: uppercase; }
        .btn-primary { background: linear-gradient(90deg, var(--accent-hover), var(--accent)); border: none; padding: 10px 25px; font-weight: 600; letter-spacing: 0.5px; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-info-custom { background: rgba(0, 210, 255, 0.1); border: 1px solid var(--accent); color: var(--accent); padding: 5px 12px; border-radius: 6px; font-size: 13px; transition: 0.3s; }
        .btn-info-custom:hover { background: var(--accent); color: #fff; }
        .table { color: var(--text-main); margin-top: 15px; }
        .table th { background-color: var(--bg-dark); color: var(--text-muted); border-bottom: 2px solid var(--border-color); font-weight: 600; text-transform: uppercase; font-size: 12px; padding: 15px; }
        .table td { background-color: transparent; border-bottom: 1px solid var(--border-color); vertical-align: middle; padding: 15px; color: #fff;}
        .badge-active { background: rgba(40, 167, 69, 0.2); color: #28a745; padding: 5px 10px; border-radius: 6px; font-size: 12px; }
        .type-badge { background: rgba(0, 210, 255, 0.1); color: var(--accent); padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .modal-content { background-color: var(--panel-bg); border: 1px solid var(--border-color); color: #fff; }
        .modal-header { border-bottom: 1px solid var(--border-color); }
        .modal-footer { border-top: 1px solid var(--border-color); }
        .copy-box { background: var(--bg-dark); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); font-family: monospace; font-size: 13px; margin-bottom: 15px; word-break: break-all; }
        .copy-box span { color: var(--accent); }
    </style>
    <link rel="stylesheet" href="assets/gpanel.css">
<?= panel_ui_bootstrap() ?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script><noscript><style>body{visibility:visible!important}</style></noscript></head>
<body>
<form id="panelLogout" method="POST" hidden><?= panel_csrf_field() ?><input name="logout" value="1"></form>
    <aside class="sidebar">
        <div class="brand"><img class="brand-full" src="assets/g-panel-logo.png" alt="G-PANEL"><img class="brand-mark" src="assets/g-panel-mark.png" alt="G-PANEL"></div><div class="gp-brand-mini">IPTV MANAGEMENT SYSTEM</div>
        <div class="gp-section-label">Navigation</div><a href="admin.php" class="active"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a>
        <a href="editor.php"><i class="fas fa-folder-open"></i> Gestion des Bouquets</a>
        <div class="gp-section-label">Gestion</div><a href="#clients"><i class="fas fa-users"></i> Gestion Clients</a>
        <a href="#sources"><i class="fas fa-server"></i> Fournisseurs (Sources)</a>
        <a href="movie_duplicates.php"><i class="fas fa-layer-group"></i> Regroupement VOD</a>
        <a href="import_selective.php"><i class="fas fa-filter"></i> Imports sélectifs</a>
        <a href="importer.php"><i class="fas fa-sync-alt"></i> Forcer l'importation</a>
        <a href="panel_diagnostics.php" target="_blank" rel="noopener"><i class="fas fa-stethoscope"></i> Diagnostic catalogue V2</a>

        <a href="#" onclick="purgeNextJsCache(); return false;" style="color: #00d2ff;">
            <i class="fas fa-bolt"></i> Synchro App G-TV
        </a>

        <a href="#" onclick="event.preventDefault();document.getElementById('panelLogout').submit()" style="color: #ff4757; margin-top: 30px;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div class="gp-page-title"><div><div class="eyebrow">G-PANEL V2 / Administration</div><h1>Tableau de bord</h1></div><span class="gp-chip"><i class="fas fa-shield-halved"></i> Catalogue administré</span></div>
            <div class="user-profile">
                <div class="avatar">Z</div>
                <div>
                    <div style="font-weight: 600; color: #fff;">Admin</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Administrateur</div>
                </div>
            </div>
        </header>

        <?= $message ?>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="glass-panel stat-card">
                    <div class="icon"><i class="fas fa-users"></i></div>
                    <div>
                        <h3><?= $total_clients ?></h3>
                        <p>Clients Actifs</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="glass-panel stat-card">
                    <div class="icon"><i class="fas fa-satellite-dish"></i></div>
                    <div>
                        <h3><?= $total_sources ?></h3>
                        <p>Sources Connectées</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-4">
                <div class="glass-panel" id="clients">
                    <h5 class="mb-4"><i class="fas fa-user-plus text-primary me-2"></i> Nouveau Client</h5>
                    <form method="POST"><?= panel_csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label">Nom d'utilisateur</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Mot de passe</label>
                            <input type="text" name="password" class="form-control" required>
                        </div>
                        <button type="submit" name="add_client" class="btn btn-primary w-100"><i class="fas fa-plus me-2"></i>Créer l'accès</button>
                    </form>
                </div>

                <div class="glass-panel" id="sources">
                    <h5 class="mb-4"><i class="fas fa-link text-primary me-2"></i> Ajouter une Source</h5>
                    <form method="POST"><?= panel_csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label">Nom d'identification</label>
                            <input type="text" name="nom" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type de source</label>
                            <select name="type" id="source_type" class="form-select" onchange="toggleFields()">
                                <option value="xtream">API Xtream Codes</option>
                                <option value="m3u">Lien M3U Direct</option>
                                <option value="stalker">Portail Stalker (MAC)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL de base / Host</label>
                            <input type="url" name="url_base" class="form-control" required placeholder="http://server.com:8080">
                        </div>
                        
                        <div class="row" id="auth_fields_xtream">
                            <div class="col-6 mb-3">
                                <label class="form-label">Utilisateur</label>
                                <input type="text" name="user" class="form-control">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Mot de passe</label>
                                <input type="text" name="pass" class="form-control">
                            </div>
                        </div>

                        <div id="auth_fields_stalker" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Adresse MAC</label>
                                <input type="text" name="mac_address" class="form-control" placeholder="00:1A:79:XX:XX:XX">
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-warning"><i class="fas fa-shield-alt"></i> Proxy HTTP (Optionnel)</label>
                                <input type="text" name="proxy" class="form-control" placeholder="IP:PORT ou IP:PORT:USER:PASS">
                                <div class="form-text text-muted">Contourne le blocage IP des fournisseurs Stalker.</div>
                            </div>
                        </div>

                        <button type="submit" name="add_source" class="btn btn-primary w-100"><i class="fas fa-save me-2"></i>Enregistrer la source</button>
                    </form>
                </div>
            </div>

            <div class="col-xl-8">
                <div class="glass-panel">
                    <h5 class="mb-4">Liste des Clients</h5>
                    <div class="table-responsive">
                        <table class="table table-borderless table-hover">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Password</th>
                                    <th>Statut</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $c): ?>
                                <tr>
                                    <td class="fw-bold" data-gp-skip><i class="fas fa-user-circle me-2 text-muted"></i><?= htmlspecialchars($c['username']) ?></td>
                                    <td><span aria-label="Mot de passe masqué">••••••••</span></td>
                                    <td><span class="badge-active"><i class="fas fa-circle me-1" style="font-size:8px;"></i> Actif</span></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-info-custom me-1" onclick="showCredentials('<?= htmlspecialchars($c['username']) ?>', '<?= htmlspecialchars($c['password']) ?>')">
                                            <i class="fas fa-link"></i> Lien
                                        </button>
                                        <button class="btn btn-sm btn-outline-info me-1" onclick="editClientModal('<?= $c['id'] ?>', '<?= addslashes(htmlspecialchars($c['username'])) ?>', '<?= addslashes(htmlspecialchars($c['password'])) ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm(GPanelUI.t('Supprimer ce client ?'));"><?= panel_csrf_field() ?>
                                            <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
                                            <button type="submit" name="delete_client" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="glass-panel">
                    <h5 class="mb-4">Sources (Fournisseurs)</h5>
                    <div class="table-responsive">
                        <table class="table table-borderless table-hover">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Type</th>
                                    <th>URL / Host</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fournisseurs as $f): ?>
                                <tr>
                                    <td class="fw-bold" data-gp-skip><?= htmlspecialchars($f['nom']) ?></td>
                                    <td><span class="type-badge"><?= strtoupper($f['type']) ?></span></td>
                                    <td style="max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <a href="<?= htmlspecialchars($f['url_base']) ?>" target="_blank" class="text-muted"><?= htmlspecialchars($f['url_base']) ?></a>
                                        <?php if (strtolower($f['type']) === 'stalker' && !empty($f['mac_address'])): ?>
                                            <div><small class="text-info"><i class="fas fa-network-wired me-1"></i><?= htmlspecialchars($f['mac_address']) ?></small></div>
                                        <?php endif; ?>
                                        <?php if (!empty($f['proxy'])): ?>
                                            <div><small class="text-warning"><i class="fas fa-shield-alt me-1"></i>Proxy Activé</small></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="importer.php?fournisseur_id=<?= (int)$f['id'] ?>" class="btn btn-sm btn-outline-success me-2" target="_self" title="Importer / actualiser uniquement cette source">
                                            <i class="fas fa-sync-alt"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-info me-2" onclick="editSourceModal('<?= $f['id'] ?>', '<?= addslashes(htmlspecialchars($f['nom'])) ?>', '<?= addslashes(htmlspecialchars($f['url_base'])) ?>', '<?= addslashes(htmlspecialchars($f['user'])) ?>', '<?= addslashes(htmlspecialchars($f['pass'])) ?>', '<?= addslashes(htmlspecialchars($f['mac_address'] ?? '')) ?>', '<?= addslashes(htmlspecialchars($f['proxy'] ?? '')) ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm(GPanelUI.t('⚠️ ATTENTION : Cela supprimera définitivement ce fournisseur ET TOUTES ses chaînes de votre base de données. Continuer ?'));"><?= panel_csrf_field() ?>
                                            <input type="hidden" name="source_id" value="<?= $f['id'] ?>">
                                            <button type="submit" name="delete_source" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-center" style="color:#536a83;font-size:11px;padding:8px 0 4px;letter-spacing:.4px;">G-PANEL • IPTV MANAGEMENT SYSTEM</div>
    </main>

    <div class="modal fade" id="credentialsModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-link text-primary me-2"></i> Lien M3U & API Xtream</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="text-muted mb-3" style="font-size:13px;">Copiez et envoyez ce lien directement au client :</p>
            <div class="copy-box" id="modal-link-box"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="editClientModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-user-edit text-primary me-2"></i> Modifier le client</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST"><?= panel_csrf_field() ?>
              <div class="modal-body">
                <input type="hidden" name="client_id" id="edit_client_id">
                <div class="mb-3">
                    <label class="form-label">Nom d'utilisateur</label>
                    <input type="text" name="edit_client_username" id="edit_client_username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Mot de passe</label>
                    <input type="text" name="edit_client_password" id="edit_client_password" class="form-control" required>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" name="edit_client_btn" class="btn-primary">Enregistrer</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="editSourceModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-edit text-primary me-2"></i> Modifier le fournisseur</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST"><?= panel_csrf_field() ?>
              <div class="modal-body">
                <input type="hidden" name="source_id" id="edit_source_id">
                <div class="mb-3">
                    <label class="form-label">Nom d'identification</label>
                    <input type="text" name="edit_nom" id="edit_source_nom" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">URL de base / Host</label>
                    <input type="url" name="edit_url" id="edit_source_url" class="form-control" required>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Utilisateur</label>
                        <input type="text" name="edit_user" id="edit_source_user" class="form-control">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Mot de passe</label>
                        <input type="text" name="edit_source_pass" class="form-control" id="edit_source_pass">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Adresse MAC Stalker</label>
                    <input type="text" name="edit_mac_address" id="edit_source_mac" class="form-control" placeholder="00:1A:79:XX:XX:XX">
                </div>
                <div class="mb-3">
                    <label class="form-label text-warning"><i class="fas fa-shield-alt"></i> Proxy HTTP (Optionnel)</label>
                    <input type="text" name="edit_proxy" id="edit_source_proxy" class="form-control" placeholder="IP:PORT ou IP:PORT:USER:PASS">
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" name="edit_source_btn" class="btn-primary">Enregistrer</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const currentPath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
        const serverBaseUrl = window.location.origin + currentPath;

        function toggleFields() {
            const type = document.getElementById('source_type').value;
            const authXtream = document.getElementById('auth_fields_xtream');
            const authStalker = document.getElementById('auth_fields_stalker');

            if (type === 'xtream') {
                authXtream.style.display = 'flex';
                authStalker.style.display = 'none';
            } else if (type === 'm3u') {
                authXtream.style.display = 'none';
                authStalker.style.display = 'none';
            } else if (type === 'stalker') {
                authXtream.style.display = 'none';
                authStalker.style.display = 'block';
            }
        }
        toggleFields();

        function showCredentials(username, password) {
            let m3uLink = `${serverBaseUrl}/get.php?username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&type=m3u_plus`;
            
            const box = document.getElementById('modal-link-box');
            if (box) box.innerText = m3uLink;

            document.querySelectorAll('.copy-box, .modal-body code').forEach(el => {
                el.innerText = m3uLink;
            });

            const modalElement = document.getElementById('credentialsModal');
            if (modalElement) {
                new bootstrap.Modal(modalElement).show();
            }
        }
        window.showCredentials = showCredentials;

        function editClientModal(id, username, password) {
            document.getElementById('edit_client_id').value = id;
            document.getElementById('edit_client_username').value = username;
            document.getElementById('edit_client_password').value = password;
            new bootstrap.Modal(document.getElementById('editClientModal')).show();
        }

        function editSourceModal(id, nom, url, user, pass, mac, proxy) {
            document.getElementById('edit_source_id').value = id;
            document.getElementById('edit_source_nom').value = nom;
            document.getElementById('edit_source_url').value = url;
            document.getElementById('edit_source_user').value = user;
            document.getElementById('edit_source_pass').value = pass;
            document.getElementById('edit_source_mac').value = mac || '';
            document.getElementById('edit_source_proxy').value = proxy || '';
            new bootstrap.Modal(document.getElementById('editSourceModal')).show();
        }

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
    </script>

</body>
</html>
