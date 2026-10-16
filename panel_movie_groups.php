<?php
// Additive movie decisions. Importers never write source roles.
function movie_groups_ready(PDO $pdo): bool {
    static $ready = [];
    $key = spl_object_id($pdo);
    if (!array_key_exists($key, $ready)) {
        try { foreach (['movie_groups','movie_group_sources','movie_identifiers'] as $table) $pdo->query("SELECT 1 FROM $table LIMIT 1"); $ready[$key] = true; }
        catch (PDOException $e) { if ($e->getCode() !== '42S02' && strpos($e->getMessage(),'no such table') === false) throw $e; $ready[$key] = false; }
    }
    return $ready[$key];
}
function movie_source_allowed(PDO $pdo, $id): bool {
    if (!movie_groups_ready($pdo)) return true;
    $q = $pdo->prepare('SELECT role FROM movie_group_sources WHERE stream_id=?'); $q->execute([$id]);
    return $q->fetchColumn() !== 'disabled';
}
function movie_resolve(PDO $pdo, $id, $backup = null) {
    if (!movie_source_allowed($pdo, $id)) return false;
    $anchor=$pdo->prepare("SELECT s.stream_id FROM streams s JOIN categories c ON c.category_id=s.category_id LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id WHERE s.stream_id=? AND s.stream_type='movie' AND s.visible=1 AND c.visible=1 AND (f.active=1 OR s.fournisseur_id=0)");
    $anchor->execute([$id]); if (!$anchor->fetchColumn()) return false;
    if ($backup !== null) {
        if (!movie_groups_ready($pdo)) return false;
        // Only an explicitly requested backup in the same confirmed group.
        $q=$pdo->prepare("SELECT b.stream_id FROM movie_group_sources a JOIN movie_group_sources b ON a.group_id=b.group_id WHERE a.stream_id=? AND a.role='primary' AND b.stream_id=? AND b.role='backup'");
        $q->execute([$id,$backup]); if (!$q->fetchColumn()) return false;
        $id=$backup;
    } elseif (movie_groups_ready($pdo)) {
        $q=$pdo->prepare("SELECT b.stream_id FROM movie_group_sources a JOIN movie_group_sources b ON a.group_id=b.group_id WHERE a.stream_id=? AND b.role='primary'");
        $q->execute([$id]); $primary=$q->fetchColumn(); if ($primary !== false) $id=$primary;
    }
    $q=$pdo->prepare("SELECT s.*, COALESCE(f.type,'m3u') AS type, f.url_base, f.user, f.pass FROM streams s LEFT JOIN fournisseurs f ON f.id=s.fournisseur_id JOIN categories c ON c.category_id=s.category_id WHERE s.stream_id=? AND s.stream_type='movie' AND s.visible=1 AND c.visible=1 AND (f.active=1 OR s.fournisseur_id=0)");
    $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC); if ($row) {require_once __DIR__.'/panel_content.php'; try{$row=content_playback($pdo,$row);}catch(Throwable $e){return false;}} return $row;
}
function movie_confirm(PDO $pdo, array $roles): void {
    if (count($roles)<2 || count(array_filter($roles,fn($r)=>$r==='primary'))!==1) throw new RuntimeException('Choisir au moins deux films et exactement un principal.');
    foreach ($roles as $id=>$role) if (!ctype_digit((string)$id) || !in_array($role,['primary','backup','disabled'],true)) throw new RuntimeException('Choix invalide.');
    $pdo->beginTransaction();
    try {
        // Serialize administrative changes; unique membership prevents concurrent regrouping.
        $q=$pdo->prepare("SELECT stream_id,stream_name,fournisseur_id FROM streams WHERE stream_type='movie' AND stream_id IN (".implode(',',array_fill(0,count($roles),'?')).') ORDER BY stream_id'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute(array_keys($roles)); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)!==count($roles) || count(array_unique(array_column($rows,'fournisseur_id')))<2) throw new RuntimeException('Films de plusieurs fournisseurs requis.');
        $q=$pdo->prepare('SELECT DISTINCT group_id FROM movie_group_sources WHERE stream_id IN ('.implode(',',array_fill(0,count($roles),'?')).')'); $q->execute(array_keys($roles)); $groups=$q->fetchAll(PDO::FETCH_COLUMN);
        if (count($groups)>1) throw new RuntimeException('Fusion de groupes existants interdite.');
        if ($groups) {
            $gid=$groups[0]; $q=$pdo->prepare('SELECT stream_id FROM movie_group_sources WHERE group_id=?'); $q->execute([$gid]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $existing) if (!isset($roles[$existing])) throw new RuntimeException('Inclure toutes les sources du groupe existant.');
            $primaryQ=$pdo->prepare("SELECT stream_id FROM movie_group_sources WHERE group_id=? AND role='primary'");$primaryQ->execute([$gid]);$oldPrimary=$primaryQ->fetchColumn();
            if($oldPrimary!==false && ($roles[$oldPrimary]??'')!=='primary')throw new RuntimeException('ID public du groupe conservé : changer la priorité de lecture dans Sources & Backups.');
        } else { $q=$pdo->prepare('INSERT INTO movie_groups(label) VALUES (?)'); $q->execute([$rows[0]['stream_name']]); $gid=$pdo->lastInsertId(); }
        $del=$pdo->prepare('DELETE FROM movie_group_sources WHERE group_id=?'); $del->execute([$gid]);
        $ins=$pdo->prepare('INSERT INTO movie_group_sources(stream_id,group_id,role) VALUES (?,?,?)'); foreach($roles as $id=>$role) $ins->execute([$id,$gid,$role]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
function movie_candidate_keys(array $row): array {
    $keys=[];
    if (preg_match('/^[1-9][0-9]*$/',(string)($row['tmdb_id']??''))) $keys[]='tmdb:'.$row['tmdb_id'];
    if (preg_match('/^tt[0-9]+$/',(string)($row['imdb_id']??''))) $keys[]='imdb:'.$row['imdb_id'];
    $title=mb_strtolower(trim((string)$row['stream_name']),'UTF-8');
    $year=(string)($row['release_year']??'');
    if (!preg_match('/^(19|20)[0-9]{2}$/',$year)) {
        if (preg_match('/^(19|20)[0-9]{2}/',(string)($row['vod_release_date']??''),$m)) $year=$m[0];
        elseif(preg_match('/[\(\[]((?:19|20)[0-9]{2})[\)\]]/',$title,$m)) $year=$m[1];
    }
    $title=preg_replace('/[\(\[](?:19|20)[0-9]{2}[\)\]]/u',' ',$title);
    $title=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$title));
    if ($title!=='' && preg_match('/^(19|20)[0-9]{2}$/',$year)) $keys[]='titre+année:'.$title.':'.$year;
    return $keys;
}

// Catalogue identity: only the confirmed primary is listed. Backup URLs remain usable.
function movie_catalog_visible(PDO $pdo, $id): bool {
    if (!movie_groups_ready($pdo)) return true;
    $q=$pdo->prepare('SELECT role FROM movie_group_sources WHERE stream_id=?');
    $q->execute([$id]); $role=$q->fetchColumn();
    return $role === false || $role === 'primary';
}
