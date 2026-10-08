<?php
require_once __DIR__.'/panel_operations.php';$pdo=ops_page('Administration système');
$method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true)) panel_fail(405,'method_not_allowed');$error='';$saved=false;
if($method==='POST') {
    panel_check_csrf();$action=(string)($_POST['action']??'');
    if($action==='settings') panel_require_admin('settings:manage');else panel_require_admin('admins:manage');
    try {
        if($action==='settings') {
            $ttl=filter_var($_POST['ttl']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>60,'max_range'=>3600]]);
            $retention=filter_var($_POST['retention']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>7,'max_range'=>365]]);
            if($ttl===false||$retention===false) throw new InvalidArgumentException('TTL 60–3600 secondes ; rétention 7–365 jours.');
            $pdo->beginTransaction();$q=$pdo->prepare('INSERT INTO panel_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach(['monitor_ttl_seconds'=>$ttl,'telemetry_retention_days'=>$retention] as $key=>$value) $q->execute([$key,(string)$value]);
            ops_audit($pdo,'settings.updated');$pdo->commit();
        } elseif(in_array($action,['create','update'],true)) {
            $engine=$pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admins'")->fetchColumn();
            if(strcasecmp((string)$engine,'InnoDB')!==0) throw new InvalidArgumentException('Gestion des comptes : table admins transactionnelle requise.');
            $role=(string)($_POST['role']??'');if(!isset(ops_roles()[$role])) throw new InvalidArgumentException('Rôle invalide.');
            $enabled=isset($_POST['enabled'])?1:0;$password=(string)($_POST['password']??'');
            if(($action==='create'||$password!=='')&&(strlen($password)<12||strlen($password)>72||trim($password)!==$password)) throw new InvalidArgumentException('Mot de passe : 12 à 72 octets, sans espace au début/à la fin.');
            // Serialize all account changes across workers, including last-super-admin checks.
            if((int)$pdo->query("SELECT GET_LOCK('gpanel-admin-management',3)")->fetchColumn()!==1) throw new InvalidArgumentException('Administration occupée, réessayez.');
            try {
                $pdo->beginTransaction();
                if($action==='create') {
                    $username=trim((string)($_POST['username']??''));
                    if(!preg_match('/^[a-zA-Z0-9_.@-]{3,50}$/D',$username)) throw new InvalidArgumentException('Identifiant : 3–50 caractères alphanumériques, . _ @ -');
                    $q=$pdo->prepare('SELECT id FROM admins WHERE username=?');$q->execute([$username]);if($q->fetchColumn())throw new InvalidArgumentException('Identifiant déjà utilisé.');
                    $pdo->prepare('INSERT INTO admins (username,password) VALUES (?,?)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO panel_admin_roles (admin_id,role,enabled) VALUES (?,?,?)')->execute([$id,$role,$enabled]);
                } else {
                    $id=filter_var($_POST['admin_id']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id) throw new InvalidArgumentException('Administrateur invalide.');
                    $q=$pdo->prepare('SELECT r.role,r.enabled FROM panel_admin_roles r JOIN admins a ON a.id=r.admin_id WHERE r.admin_id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();if(!$old)throw new InvalidArgumentException('Administrateur introuvable.');
                    if((int)$_SESSION['panel_admin_id']===$id&&(!$enabled||$role!=='super_admin')) throw new InvalidArgumentException('Le super-administrateur connecté doit conserver son accès.');
                    if($old['role']==='super_admin'&&(int)$old['enabled']&&(!$enabled||$role!=='super_admin')) {
                        $count=(int)$pdo->query("SELECT COUNT(*) FROM panel_admin_roles r JOIN admins a ON a.id=r.admin_id WHERE r.role='super_admin' AND r.enabled=1")->fetchColumn();
                        if($count<=1) throw new InvalidArgumentException('Conservez au moins un super-administrateur actif.');
                    }
                    $pdo->prepare('UPDATE panel_admin_roles SET role=?,enabled=? WHERE admin_id=?')->execute([$role,$enabled,$id]);
                    if($password!=='') $pdo->prepare('UPDATE admins SET password=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
                }
                ops_audit($pdo,'admin.'.$action,$id);$pdo->commit();
            } finally {$pdo->query("SELECT RELEASE_LOCK('gpanel-admin-management')");}
        } else throw new InvalidArgumentException('Action invalide.');
        $saved=true;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof InvalidArgumentException?$e->getMessage():'Enregistrement indisponible.';http_response_code(422);}
}
$canAdmins=ops_authorize($pdo,(int)$_SESSION['panel_admin_id'],'admins:manage');$canSettings=ops_authorize($pdo,(int)$_SESSION['panel_admin_id'],'settings:manage');
$admins=$pdo->query('SELECT a.id,a.username,r.role,r.enabled FROM admins a LEFT JOIN panel_admin_roles r ON r.admin_id=a.id ORDER BY a.id LIMIT 500')->fetchAll();
$before=max(0,(int)($_GET['before']??0));$q=$pdo->prepare('SELECT id,admin_id,event,target_id,created_at FROM panel_audit WHERE (?=0 OR id<?) ORDER BY id DESC LIMIT 101');$q->execute([$before,$before]);$audit=$q->fetchAll();$more=count($audit)>100;$audit=array_slice($audit,0,100);
$adminCount=(int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();$enabledCount=(int)$pdo->query('SELECT COUNT(*) FROM panel_admin_roles r JOIN admins a ON a.id=r.admin_id WHERE r.enabled=1')->fetchColumn();$auditCount=(int)$pdo->query('SELECT COUNT(*) FROM panel_audit')->fetchColumn();
v2_begin('Administration système');gp_kpis([['Administrateurs',$adminCount,'users','blue'],['Comptes activés',$enabledCount,'settings','green'],['Rôles',count(ops_roles()),'settings','gold'],['Événements audit',$auditCount,'chart','violet']]); ?>
<?php if($saved||$error):?><p class="v2-notice"><?=v2_h($error?:'Enregistrement effectué.')?></p><?php endif?>
<section class="glass-panel"><h2>Administrateurs</h2><p class="v2-muted">Comptes existants conservés. Aucun mot de passe ni secret n’est affiché. Désactivation effective sur les prochaines requêtes administratives.</p>
<?php foreach($admins as $a):?><form method="post" class="v2-form"><?=panel_csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="admin_id" value="<?=(int)$a['id']?>"><strong><?=v2_h($a['username'])?></strong><label>Rôle<select name="role" <?=$canAdmins?'':'disabled'?>><?php foreach(ops_roles() as $role=>$perms):?><option value="<?=$role?>" <?=$a['role']===$role?'selected':''?>><?=$role?></option><?php endforeach?></select></label><label>Actif<input type="checkbox" name="enabled" <?=$a['enabled']?'checked':''?> <?=$canAdmins?'':'disabled'?>></label><?php if($canAdmins):?><label>Nouveau mot de passe (facultatif)<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password"></label><button>Enregistrer</button><?php endif?></form><?php endforeach?>
<?php if($canAdmins):?><details><summary>Ajouter un administrateur</summary><form method="post" class="v2-form"><?=panel_csrf_field()?><input type="hidden" name="action" value="create"><label>Identifiant<input name="username" required minlength="3" maxlength="50" autocomplete="off"></label><label>Mot de passe<input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></label><label>Rôle<select name="role"><?php foreach(ops_roles() as $role=>$perms):?><option <?=$role==='viewer'?'selected':''?>><?=$role?></option><?php endforeach?></select></label><label>Actif<input type="checkbox" name="enabled" checked></label><button>Créer</button></form></details><?php endif?></section>
<section class="glass-panel"><h2>Rôles / permissions</h2><div class="v2-table"><table><tr><th>Rôle</th><th>Permissions serveur</th></tr><?php foreach(ops_roles() as $role=>$perms):?><tr><td><?=v2_h($role)?></td><td><?=v2_h(implode(', ',$perms))?></td></tr><?php endforeach?></table></div><p class="v2-muted">La restriction PANEL_ADMIN_PERMISSIONS existante reste un plafond supplémentaire. Seul super_admin peut gérer les comptes.</p></section>
<section class="glass-panel"><h2>Journal d’activité</h2><div class="v2-table"><table><tr><th>Date UTC</th><th>Administrateur</th><th>Événement</th><th>Cible</th></tr><?php foreach($audit as $r):?><tr><td><?=v2_h($r['created_at'])?></td><td><?=v2_h($r['admin_id']??'—')?></td><td><?=v2_h($r['event'])?></td><td><?=v2_h($r['target_id']??'—')?></td></tr><?php endforeach?></table></div><?php if($more):?><a href="?before=<?=(int)end($audit)['id']?>">Événements précédents</a><?php endif?></section>
<section class="glass-panel"><h2>Paramètres G-PANEL / G-TV</h2><form class="v2-form" method="post"><?=panel_csrf_field()?><input type="hidden" name="action" value="settings"><label>TTL monitoring (secondes)<input type="number" name="ttl" min="60" max="3600" value="<?=v2_h(ops_setting($pdo,'monitor_ttl_seconds','300'))?>" required></label><label>Rétention événements (jours)<input type="number" name="retention" min="7" max="365" value="<?=v2_h(ops_setting($pdo,'telemetry_retention_days','90'))?>" required></label><?php if($canSettings):?><button>Enregistrer</button><?php endif?></form><p class="v2-muted">Contrat télémétrie G-TV v1 : POST playback_events.php. Aucune activation implicite côté lecteur. Secrets configurés en environnement :</p><?php foreach(['MYSQLPASSWORD','PANEL_ADMIN_PERMISSIONS','PANEL_REVALIDATE_SECRET'] as $key):?><p><?=v2_h($key)?> : <?=getenv($key)!==false&&getenv($key)!==''?'configuré':'non configuré'?></p><?php endforeach?></section>
<section class="glass-panel"><h2>Sauvegardes / configuration</h2><p class="v2-notice">Aucune sauvegarde créée par cette page. Le dernier dump vérifié est inconnu. Exécutez un dump depuis un opérateur autorisé vers un stockage privé hors webroot ; testez sa restauration dans une base distincte.</p><p>Exemple Linux / Railway avec le client MySQL installé, après configuration d’un login-path dédié :</p><pre style="white-space:pre-wrap">mysqldump --login-path=gpanel-backup --single-transaction --quick --no-tablespaces --hex-blob --result-file=/var/lib/gpanel/backups/gpanel.sql "$MYSQLDATABASE"</pre><p class="v2-muted">Le répertoire doit être privé (0700), le fichier 0600, et le compte limité aux droits de sauvegarde. Ne passez pas de mot de passe en argument. Incluez les configurations non secrètes et un inventaire des variables nécessaires, sans leurs valeurs. Aucune restauration automatique n’est proposée. Consultez OPERATIONS.md pour Railway, WAMP et la rétention.</p></section>
<?php v2_end();
