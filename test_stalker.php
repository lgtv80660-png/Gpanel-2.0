<?php
// Force l'affichage des erreurs sur Railway pour le diagnostic
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Désactiver la limite de temps de 30 secondes de PHP
set_time_limit(0);

// Chemin absolu pour éviter les 404 internes
require_once __DIR__ . '/stalker.php';

// Protection contre la redéclaration fatale de fonction
if (!function_exists('h')) {
    function h($v) { 
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); 
    }
}

$result = null;
$host = trim($_POST['host'] ?? '');
$mac = trim($_POST['mac'] ?? '');
$proxy = trim($_POST['proxy'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // On transmet le proxy à la fonction de diagnostic
    $result = stalker_handshake_diagnostics($host, $mac, $proxy);
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>G-PANEL — Test Stalker</title>
<link rel="stylesheet" href="assets/gpanel.css">
<style>
body{min-height:100vh}.st-wrap{max-width:1050px;margin:0 auto;padding:28px 18px}.st-top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:20px}.st-brand{display:flex;align-items:center;gap:12px}.st-brand img{height:38px;width:auto}.st-card{background:var(--gp-panel,rgba(15,27,45,.94));border:1px solid var(--gp-border,#26374d);border-radius:18px;padding:24px;box-shadow:var(--gp-shadow,0 14px 40px rgba(0,0,0,.18));margin-bottom:18px}.st-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}.st-input{width:100%;padding:12px 13px;border-radius:10px;border:1px solid var(--gp-border,#33465e);background:var(--gp-bg,#0b1527);color:var(--gp-text,#eef6ff);box-sizing:border-box}.st-btn{margin-top:16px}.st-success{border-color:#16a34a}.st-error{border-color:#dc2626}.st-table{width:100%;border-collapse:collapse;margin-top:12px;font-size:13px}.st-table th,.st-table td{padding:10px;border-bottom:1px solid var(--gp-border,#26374d);text-align:left;vertical-align:top}.ok{color:#22c55e;font-weight:800}.bad{color:#ef4444;font-weight:800}.muted{color:var(--gp-muted,#8ea0b5)}code{word-break:break-all}@media(max-width:700px){.st-grid{grid-template-columns:1fr}.st-top{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="st-wrap">
  <div class="st-top">
    <div class="st-brand">
      <img src="assets/g-panel-logo-dark.png" alt="G-PANEL">
      <div><strong>Test Stalker / MAG</strong><div class="muted">Diagnostic avant import — aucune donnée n’est modifiée.</div></div>
    </div>
    <div>
      <a class="btn btn-outline-primary" href="admin.php">← Panel</a> 
      <a class="btn btn-outline-secondary" href="importer.php">Importer</a>
    </div>
  </div>
  <div class="st-card">
    <h2>Tester Host + MAC (avec Proxy)</h2>
    <form method="post">
      <div class="st-grid">
        <div><label>Host / Portail</label><input class="st-input" name="host" value="<?= h($host) ?>" placeholder="http://tv4u1.com:8080/c/"></div>
        <div><label>Adresse MAC</label><input class="st-input" name="mac" value="<?= h($mac) ?>" placeholder="00:1A:79:14:E6:B9"></div>
        <div><label>Proxy HTTP (IP:PORT ou IP:PORT:USER:PASS)</label><input class="st-input" name="proxy" value="<?= h($proxy) ?>" placeholder="192.168.1.1:8080"></div>
      </div>
      <button class="btn btn-primary st-btn" type="submit">Tester la connexion Stalker</button>
    </form>
  </div>
<?php if ($result !== null): ?>
  <div class="st-card <?= $result['ok'] ? 'st-success' : 'st-error' ?>">
    <h2><?= $result['ok'] ? '✅ Handshake réussi' : '❌ Handshake échoué' ?></h2>
    <?php if ($result['ok']): ?>
      <p><b>Endpoint :</b> <code><?= h($result['path']) ?></code></p>
      <p><b>Variante :</b> <code><?= h($result['variant']) ?></code></p>
      <p><b>Token :</b> <code><?= h(substr($result['token'], 0, 24)) ?>…</code></p>
      <p class="ok">Le portail est joignable depuis le serveur G-PANEL et accepte ce MAC au handshake.</p>
    <?php else: ?>
      <p><b><?= h($result['error']) ?></b></p>
      <p class="muted">Le diagnostic ci-dessous indique exactement ce que Railway a reçu pour chaque endpoint/variante.</p>
    <?php endif; ?>
    <table class="st-table">
      <thead><tr><th>Endpoint</th><th>Variante</th><th>HTTP</th><th>Token</th><th>Réponse</th></tr></thead>
      <tbody>
      <?php foreach (($result['diagnostics'] ?? []) as $d): ?>
        <tr>
          <td><code><?= h($d['path']) ?></code></td>
          <td><?= h($d['variant']) ?></td>
          <td><?= h($d['http']) ?></td>
          <td><?= !empty($d['token_found']) ? '<span class="ok">OUI</span>' : '<span class="bad">NON</span>' ?></td>
          <td><?= h($d['preview']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
<script src="assets/gpanel-ui.js"></script>
</body>
</html>
