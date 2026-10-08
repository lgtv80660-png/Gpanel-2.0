<?php
// Shared authorization. Never performs schema changes or grants implicit access.
function packs_ready(PDO $pdo): bool {
    static $cache=[]; $key=spl_object_id($pdo);
    if (!array_key_exists($key,$cache)) {
        try {foreach(['packs','pack_rules','client_packs'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1"); $cache[$key]=true;}
        catch(PDOException $e) {if($e->getCode()!=='42S02') throw $e; $cache[$key]=false;}
    }
    return $cache[$key];
}
function packs_context(PDO $pdo, int $clientId): array {
    $empty=['version'=>1,'allowed'=>false,'reason'=>'no_pack','pack'=>null,'expires_at'=>null,'rules'=>[]];
    if(!packs_ready($pdo)) {$empty['reason']='migration_required';return $empty;}
    $q=$pdo->prepare('SELECT p.*,cp.expires_at,c.active AS client_active FROM client_packs cp JOIN packs p ON p.id=cp.pack_id JOIN clients c ON c.id=cp.client_id WHERE cp.client_id=?');$q->execute([$clientId]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if(!$p) return $empty;
    $q=$pdo->prepare('SELECT scope,kind,reference_id FROM pack_rules WHERE pack_id=? ORDER BY scope,kind,reference_id');$q->execute([$p['id']]);$rules=$q->fetchAll(PDO::FETCH_ASSOC);
    $allowed=(int)$p['active']===1 && (int)$p['client_active']===1 && (!$p['expires_at'] || strtotime($p['expires_at'].' UTC')>time());
    return ['version'=>1,'allowed'=>$allowed,'reason'=>$allowed?'active':((int)$p['client_active']!==1?'client_disabled':((int)$p['active']!==1?'pack_inactive':'expired')),'pack'=>['id'=>(int)$p['id'],'name'=>$p['name'],'level'=>$p['level'],'active'=>(bool)$p['active'],'updated_at'=>$p['updated_at']],'expires_at'=>$p['expires_at']?str_replace(' ','T',$p['expires_at']).'Z':null,'rules'=>$rules];
}
function packs_scope(array $s): string {
    if((int)($s['fournisseur_id']??-1)===0) return 'personal';
    return ($s['stream_type']??'')==='episode'?'series':(string)($s['stream_type']??'');
}
function packs_rule_allows(array $rules,array $s,array $groups=[]): bool {
    $scope=packs_scope($s);
    foreach($rules as $r) if($r['scope']===$scope && ($r['kind']==='all' || ($r['kind']==='category' && (int)$r['reference_id']===(int)$s['category_id']) || ($r['kind']==='group' && isset($groups[(int)$s['stream_id']]) && (int)$r['reference_id']===$groups[(int)$s['stream_id']]))) return true;
    return false;
}
function packs_catalog(PDO $pdo,array $ctx): array {
    if(!$ctx['allowed']) return [];
    // One bounded catalogue query per request; also respects existing VOD roles.
    static $catalogue=[]; $key=spl_object_id($pdo);
    $rows=$catalogue[$key]??($catalogue[$key]=$pdo->query("SELECT s.stream_id,s.category_id,s.stream_type,s.fournisseur_id,x.series_parent,g.group_id,g.role FROM streams s JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id LEFT JOIN content_settings x ON x.stream_id=s.stream_id LEFT JOIN movie_group_sources g ON g.stream_id=s.stream_id WHERE s.visible=1 AND c.visible=1 AND (f.active=1 OR s.fournisseur_id=0)")->fetchAll(PDO::FETCH_ASSOC));
    $groups=[];foreach($rows as $s) if($s['group_id']) $groups[(int)$s['stream_id']]=(int)$s['group_id'];
    $allowed=[];$episodes=[];
    foreach($rows as $s) {
        if($s['stream_type']==='episode') {$episodes[]=$s;continue;}
        if($s['stream_type']==='movie' && $s['role'] && $s['role']!=='primary') continue;
        if(packs_rule_allows($ctx['rules'],$s,$groups)) $allowed[(int)$s['stream_id']]=$s;
    }
    foreach($episodes as $s) {
        // Episodes inherit their real parent, never an unrelated series/category.
        if($s['series_parent'] ? (isset($allowed[(int)$s['series_parent']]) && $allowed[(int)$s['series_parent']]['stream_type']==='series') : packs_rule_allows($ctx['rules'],$s,$groups)) $allowed[(int)$s['stream_id']]=$s;
    }
    return $allowed;
}
function packs_access(PDO $pdo,int $clientId): array {return packs_catalog($pdo,packs_context($pdo,$clientId));}
function packs_badge($name,$level): string {
    return '<span class="pack-badge '.($level==='PREMIUM+'?'pack-gold':'').'" data-gp-skip>'.htmlspecialchars((string)$name.($level!=='' && $name!==$level?' · '.$level:''),ENT_QUOTES,'UTF-8').'</span>';
}
function packs_assign(PDO $pdo,int $clientId,array $post): void {
    $id=filter_var($post['pack_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($id===false) throw new InvalidArgumentException('Pack invalide.');
    if(!$id) {$pdo->prepare('DELETE FROM client_packs WHERE client_id=?')->execute([$clientId]);return;}
    $q=$pdo->prepare('SELECT * FROM packs WHERE id=? FOR UPDATE');$q->execute([$id]);$pack=$q->fetch(PDO::FETCH_ASSOC);
    if(!$pack) throw new InvalidArgumentException('Pack introuvable.');
    $q=$pdo->prepare('SELECT pack_id,expires_at FROM client_packs WHERE client_id=?');$q->execute([$clientId]);$old=$q->fetch(PDO::FETCH_ASSOC);
    if(!(int)$pack['active'] && (!$old || (int)$old['pack_id']!==$id)) throw new InvalidArgumentException('Ce pack est inactif.');
    $date=trim((string)($post['pack_expires_at']??''));$expires=null;
    if($date!=='') {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date,new DateTimeZone('UTC'));
        if(!$d || $d->format('Y-m-d\TH:i')!==$date) throw new InvalidArgumentException('Expiration invalide.');
        $expires=$d->format('Y-m-d H:i:s');
    } elseif(($post['pack_expiry_mode']??'default')==='default') {
        $expires=$old && (int)$old['pack_id']===$id ? $old['expires_at'] : gmdate('Y-m-d H:i:s',time()+(int)$pack['duration_days']*86400);
    } elseif(($post['pack_expiry_mode']??'')!=='unlimited') throw new InvalidArgumentException('Durée invalide.');
    $pdo->prepare('INSERT INTO client_packs (client_id,pack_id,expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pack_id=VALUES(pack_id),expires_at=VALUES(expires_at)')->execute([$clientId,$id,$expires]);
}
function packs_client_fields(array $packs,string $prefix=''): void { ?>
<div class="mb-3"><label class="form-label" for="<?=$prefix?>pack_id">Pack</label><select class="form-control" name="pack_id" id="<?=$prefix?>pack_id"><option value="0">Aucun pack — accès bloqué</option><?php foreach($packs as $p):?><option value="<?=(int)$p['id']?>" data-duration="<?=(int)$p['duration_days']?>"><?=htmlspecialchars($p['name'].' · '.$p['level'].(!(int)$p['active']?' · Inactif':''),ENT_QUOTES,'UTF-8')?></option><?php endforeach?></select></div>
<div class="mb-3"><label class="form-label" for="<?=$prefix?>pack_expires_at">Expiration (UTC)</label><input type="datetime-local" name="pack_expires_at" id="<?=$prefix?>pack_expires_at" class="form-control"><select class="form-control" name="pack_expiry_mode" id="<?=$prefix?>pack_expiry_mode"><option value="default">Durée du pack (ou expiration actuelle)</option><option value="unlimited">Sans expiration</option></select></div>
<?php }
