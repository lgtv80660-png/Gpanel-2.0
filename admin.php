<?php
require_once __DIR__ . '/panel_security.php';
panel_check_csrf();
require 'config.php';
require_once __DIR__.'/panel_packs.php';
require_once __DIR__.'/panel_operations.php';
require_once __DIR__.'/panel_revalidation.php';
require_once __DIR__.'/panel_visual.php';


if (isset($_POST['logout'])) {
    if(!empty($_SESSION['panel_admin_id'])) ops_audit($pdo,'auth.logout');
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

    if ($admin && ops_authorize($pdo,(int)$admin['id'],'panel:read') && (password_verify($pass_input, $admin['password']) || (password_get_info($admin['password'])['algoName'] === 'unknown' && hash_equals($admin['password'], $pass_input)))) {
        session_regenerate_id(true);
        $_SESSION['panel_admin_id'] = (string)$admin['id'];
        $_SESSION['panel_language'] = panel_load_language($admin['id']);
        $_SESSION['panel_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['panel_last_seen'] = time();
        $_SESSION['admin_logged'] = true;
        ops_audit($pdo,'auth.login',null,(int)$admin['id']);
        header("Location: admin.php");
        exit;
    } else {
        ops_audit($pdo,'auth.login_failed',null,0);
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
<?= panel_ui_bootstrap() ?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script><script src="assets/cinema.js" defer></script><noscript><style>body{visibility:visible!important}</style></noscript><link rel="stylesheet" href="assets/cinema.css"></head>
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


if(($_GET['pack_cache']??'')==='pending') $message.='<div class="alert alert-warning">'.htmlspecialchars(panel_t('revalidation_failed'),ENT_QUOTES,'UTF-8').'</div>';

if(isset($_POST['add_client']) || isset($_POST['edit_client_btn']) || isset($_POST['delete_client'])) {
    try {
        if(!packs_ready($pdo)) throw new InvalidArgumentException('Migration Packs requise.');
        $pdo->beginTransaction();$id=0;
        if(!isset($_POST['add_client'])) {
            $id=filter_var($_POST['client_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$id) throw new InvalidArgumentException('Client invalide.');
            $q=$pdo->prepare('SELECT id FROM clients WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn()) throw new InvalidArgumentException('Client introuvable.');
        }
        if(isset($_POST['delete_client'])) {
            $pdo->prepare('DELETE FROM client_packs WHERE client_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);$success='deleted';
        } else {
            $isNew=isset($_POST['add_client']);$username=trim((string)($_POST[$isNew?'username':'edit_client_username']??''));$password=(string)($_POST[$isNew?'password':'edit_client_password']??'');
            if($username==='' || $password==='' || mb_strlen($username)>50 || mb_strlen($password)>50) throw new InvalidArgumentException('Identifiants invalides (50 caractères maximum).');
            if($isNew) {$pdo->prepare('INSERT INTO clients (username,password) VALUES (?,?)')->execute([$username,$password]);$id=(int)$pdo->lastInsertId();$success='1';}
            else {$pdo->prepare('UPDATE clients SET username=?,password=? WHERE id=?')->execute([$username,$password,$id]);$success='updated';}
            packs_assign($pdo,$id,$_POST);
        }
        ops_audit($pdo,isset($_POST['delete_client'])?'client.deleted':(isset($_POST['add_client'])?'client.created':'client.updated'),$id);
        $pdo->commit();$cacheNotified=panel_revalidate();header('Location: admin.php?success='.$success.'&pack_cache='.($cacheNotified?'ok':'pending'));exit;
    } catch(Throwable $e) {if($pdo->inTransaction()) $pdo->rollBack();$message='<div class="alert alert-danger">'.htmlspecialchars($e instanceof InvalidArgumentException?$e->getMessage():'Enregistrement client indisponible.',ENT_QUOTES,'UTF-8').'</div>';if(!($e instanceof InvalidArgumentException)) error_log('Client pack: '.$e->getMessage());}
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
        ops_audit($pdo,'source.created',$new_source_id);
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

        ops_audit($pdo,'source.updated',$source_id);
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
            ops_audit($pdo,'source.deleted',$id);
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

$pack_options=packs_ready($pdo)?$pdo->query('SELECT * FROM packs ORDER BY name')->fetchAll():[];
$total_packs=count($pack_options);
$clients = $pdo->query(packs_ready($pdo)?"SELECT c.*,cp.pack_id,cp.expires_at,p.name AS pack_name,p.level AS pack_level,p.active AS pack_active FROM clients c LEFT JOIN client_packs cp ON cp.client_id=c.id LEFT JOIN packs p ON p.id=cp.pack_id ORDER BY c.id DESC":"SELECT * FROM clients ORDER BY id DESC")->fetchAll();
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
    <link rel="stylesheet" href="assets/packs.css"><script src="assets/packs-translations.js" defer></script>
<?= panel_ui_bootstrap() ?><script src="assets/panel-translations.js"></script><script src="assets/gpanel-ui.js" defer></script><script src="assets/cinema.js" defer></script><noscript><style>body{visibility:visible!important}</style></noscript><link rel="stylesheet" href="assets/cinema.css"></head>
<body class="dashboard-v2 gp-cinema dashboard-view-<?=gp_escape(in_array($_GET['view']??'',['clients','sources'],true)?$_GET['view']:'dashboard')?>">
<?php gp_navigation(); ?>
<script>
(function () {
    try {
        var theme = localStorage.getItem('gpanel.theme');
        if (!theme) {
            theme = 'dark';
            localStorage.setItem('gpanel.theme', theme);
        }
        document.documentElement.dataset.theme = theme === 'dark' ? 'dark' : 'light';
    } catch (error) {
        document.documentElement.dataset.theme = 'dark';
    }
}());
</script>
<form id="panelLogout" method="POST" hidden><?= panel_csrf_field() ?><input name="logout" value="1"></form>
    <main class="main-content">
        <?php gp_header((($_GET['view']??'')==='clients')?'Clients':((($_GET['view']??'')==='sources')?'Sources':'Dashboard')); ?>
        <?= $message ?>
        <?php
        $gp_counts=$pdo->query("SELECT stream_type,COUNT(*) AS total FROM streams GROUP BY stream_type")->fetchAll(PDO::FETCH_KEY_PAIR);
        $gp_latest=$pdo->query("SELECT * FROM streams WHERE stream_type IN ('movie','series') ORDER BY stream_id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
        if($gp_latest)try{$ids=array_column($gp_latest,'stream_id');$q=$pdo->prepare('SELECT stream_id,tmdb_id FROM movie_identifiers WHERE stream_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$map=$q->fetchAll(PDO::FETCH_KEY_PAIR);foreach($gp_latest as &$r)if($r['stream_type']==='movie')$r['tmdb_id']=$map[$r['stream_id']]??null;unset($r);}catch(PDOException $e){}
        $gp_banner_rows=$pdo->query("SELECT * FROM streams WHERE stream_type IN ('movie','series') ORDER BY stream_id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        try{$ids=array_column($gp_banner_rows,'stream_id');if($ids){$q=$pdo->prepare('SELECT stream_id,tmdb_id FROM movie_identifiers WHERE stream_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$map=$q->fetchAll(PDO::FETCH_KEY_PAIR);foreach($gp_banner_rows as &$r)if($r['stream_type']==='movie'&&!empty($map[$r['stream_id']]))$r['tmdb_id']=$map[$r['stream_id']];unset($r);}}catch(PDOException $e){}
        require_once __DIR__.'/dashboard_artwork.php';
        $gp_trending=gp_dashboard_pool();
        $gp_hero=gp_dashboard_hero($gp_trending);
        $gp_collection=gp_dashboard_collection($gp_banner_rows,$gp_trending,$gp_hero);
        ?>
        <div class="gp-dashboard-only">
        <section class="gp-hero gp-dashboard-hero"><?=gp_dashboard_picture($gp_hero,'gp-dashboard-hero-art')?><div class="gp-hero-copy"><p class="gp-eyebrow">Votre univers. Votre contrôle.</p><h2>Le cinéma commence<br>dans votre catalogue.</h2><p>Pilotez vos accès, votre Live TV et vos collections depuis un espace unique.</p><div class="hero-actions"><a href="panel_catalog.php?type=movie" class="btn btn-primary">Explorer les films <?=gp_icon('arrow')?></a><button type="button" onclick="purgeNextJsCache()">Synchroniser G-TV</button></div></div></section>
        <?php gp_kpis([['Clients',$total_clients,'users','blue'],['Contenus',array_sum($gp_counts),'folder','green'],['Films',$gp_counts['movie']??0,'film','violet'],['Séries',$gp_counts['series']??0,'film','gold']]); ?>
        <div class="gp-feature-grid"><a class="gp-feature gp-dashboard-live" href="panel_catalog.php?type=live"><p class="gp-eyebrow">EN DIRECT</p><h2>Live TV</h2><p><?=number_format((int)($gp_counts['live']??0),0,',',' ')?> chaînes · Parcourir les bouquets</p></a><a class="gp-feature" href="panel_catalog.php?type=movie"><?=gp_dashboard_picture($gp_collection,'gp-dashboard-collection-art')?><p class="gp-eyebrow">À LA DEMANDE</p><h2>Films &amp; Séries</h2><p>Vos collections, leurs images et leurs métadonnées</p></a></div>
        <div class="gp-section-heading"><h2>Derniers ajouts</h2><a href="panel_catalog.php?type=all">Tout le catalogue →</a></div><div class="gp-media-rail"><?php foreach($gp_latest as $row) gp_media_card($row); ?></div><?php if(!$gp_latest):?><p class="v2-notice">Le catalogue est vide. Importez vos premiers contenus pour retrouver leurs affiches ici.</p><?php endif?>
        </div>
        <section class="gp-client-summary"><?php
        $gp_active=0;$gp_expired=0;$gp_unassigned=0;foreach($clients as $c){if(empty($c['pack_id']))$gp_unassigned++;elseif(!empty($c['expires_at']) && strtotime($c['expires_at'].' UTC')<=time())$gp_expired++;elseif((int)$c['active'] && (int)($c['pack_active']??0))$gp_active++;}
        gp_kpis([['Clients',$total_clients,'users','blue'],['Accès actifs',$gp_active,'settings','green'],['Expirés',$gp_expired,'monitor','red'],['Sans pack',$gp_unassigned,'pack','gold']]); ?></section>

        <div class="row dashboard-grid">
            <div class="col-xl-8 dashboard-tables">
                <div class="glass-panel" id="gp-client-panel">
                    <div class="card-heading" id="clients"><i class="fas fa-users"></i><div><h5>Clients</h5><p>Accès IPTV créés pour vos abonnés</p></div><button class="btn client-create-button" type="button" data-bs-toggle="modal" data-bs-target="#createClientModal"><i class="fas fa-plus"></i> Ajouter un client</button><button class="btn filter-toggle" type="button" onclick="document.getElementById('client-filter').hidden = !document.getElementById('client-filter').hidden"><i class="fas fa-filter"></i> Filtrer</button></div><div id="client-filter" class="v2-form"><label>Recherche<input id="gp-client-search" type="search" aria-label="Rechercher un client" placeholder="Rechercher un client"></label><label>Pack<select id="gp-client-pack"><option value="">Tous les packs</option><?php foreach($pack_options as $p):?><option value="<?=(int)$p['id']?>"><?=gp_escape($p['name'])?></option><?php endforeach?></select></label><label>Statut<select id="gp-client-state"><option value="">Tous les statuts</option><?php foreach(['Actif','Inactif','Expiré','Sans pack','Pack inactif'] as $state):?><option><?=gp_escape($state)?></option><?php endforeach?></select></label></div>
                    <div class="table-responsive">
                        <table id="clients-table" class="table table-borderless table-hover">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Mot de passe</th>
                                    <th>Statut</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $c): ?>
                                <tr data-pack="<?=(int)($c['pack_id']??0)?>">
                                    <td class="fw-bold" data-gp-skip><i class="fas fa-user-circle me-2 text-muted"></i><?= htmlspecialchars($c['username']) ?><br><?= packs_badge($c['pack_name'] ?? 'Aucun pack', $c['pack_level'] ?? '') ?></td>
                                    <td><span aria-label="Mot de passe masqué">••••••••</span></td>
                                    <td><span class="badge-active"><?= !(int)$c['active'] ? 'Inactif' : (empty($c['pack_id']) ? 'Sans pack' : (!(int)$c['pack_active'] ? 'Pack inactif' : (!empty($c['expires_at']) && strtotime($c['expires_at'].' UTC') <= time() ? 'Expiré' : 'Actif'))) ?></span><?php if(!empty($c['expires_at'])):?><small class="d-block text-muted"><?=htmlspecialchars($c['expires_at'],ENT_QUOTES,'UTF-8')?> UTC</small><?php endif?></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-info-custom me-1" onclick="showCredentials(...<?= htmlspecialchars(json_encode([$c['username'],$c['password']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),ENT_QUOTES,'UTF-8') ?>)">
                                            <i class="fas fa-link"></i> Lien client
                                        </button>
                                        <button class="btn btn-sm btn-outline-info me-1" onclick="editClientModal(...<?= htmlspecialchars(json_encode([$c['id'],$c['username'],$c['password'],$c['pack_id']??0,$c['expires_at']??null],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),ENT_QUOTES,'UTF-8') ?>)">
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

                <div class="glass-panel" id="gp-source-panel">
                    <div class="card-heading" id="sources"><i class="fas fa-server"></i><div><h5>Fournisseurs (Sources)</h5><p>Sources d’import du catalogue</p></div><button type="button" class="btn filter-toggle" data-bs-toggle="modal" data-bs-target="#createSourceModal"><i class="fas fa-plus"></i> Ajouter une source</button></div>
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

<div class="modal fade" id="createClientModal" tabindex="-1" aria-labelledby="createClientTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 id="createClientTitle">Ajouter un client</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div><div class="modal-body"><form method="POST"><?= panel_csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label">Nom d'utilisateur</label>
                            <input type="text" name="username" class="form-control" required placeholder="ex. client01" autocomplete="off">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Mot de passe</label>
                            <input type="password" name="password" class="form-control" required autocomplete="new-password" placeholder="••••••••">
                        </div>
                        <?php packs_client_fields($pack_options); ?>
                        <button type="submit" name="add_client" class="btn btn-primary w-100"><i class="fas fa-plus me-2"></i>Créer l'accès</button>
                    </form></div></div></div></div><div class="modal fade" id="createSourceModal" tabindex="-1" aria-labelledby="createSourceTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 id="createSourceTitle">Ajouter une source</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div><div class="modal-body"><form method="POST"><?= panel_csrf_field() ?>
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
                    </form></div></div></div></div>
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
                <?php packs_client_fields($pack_options,'edit_'); ?>
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

        function editClientModal(id, username, password, packId, expiresAt) {
            document.getElementById('edit_pack_id').value = packId || '0';
            document.getElementById('edit_pack_expires_at').value = expiresAt ? expiresAt.replace(' ', 'T').slice(0,16) : '';
            document.getElementById('edit_pack_expiry_mode').value = packId && !expiresAt ? 'unlimited' : 'default';
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
