<?php
// Import helpers have no network, authentication or schema side effects.
function panel_import_lock(PDO $pdo, int $fid, bool $release = false): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    $stmt = $pdo->prepare($release ? 'SELECT RELEASE_LOCK(?)' : 'SELECT GET_LOCK(?, 0)');
    $stmt->execute(['gpanel-import-'.$fid]);
    if (!$release && (int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Provider import already running');
}
function panel_import_require_transactions(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') return;
    $rows = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('streams','categories')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($rows) !== 2 || array_filter($rows, function ($engine) { return strcasecmp($engine, 'InnoDB') !== 0; })) {
        throw new RuntimeException('Run the reviewed V2 migration on a backup before importing.');
    }
}

function panel_import_categories(PDO $pdo, int $fid, string $kind, array $remote, array $selected, string $provider): array {
    $stmt = $pdo->prepare('SELECT category_id, remote_category_id FROM categories WHERE fournisseur_id = ? AND content_type = ? ORDER BY category_id');
    $stmt->execute([$fid, $kind]);
    $known = [];
    foreach ($stmt as $row) {
        $key = (string)$row['remote_category_id'];
        if (!isset($known[$key])) $known[$key] = (int)$row['category_id'];
    }
    $insert = $pdo->prepare('INSERT INTO categories (category_name,parent_id,visible,fournisseur_id,remote_category_id,content_type) VALUES (?,0,1,?,?,?)');
    $map = [];
    $selected = array_map('strval', $selected);
    foreach ($remote as $category) {
        if (!is_array($category)) continue;
        $name = trim((string)($category['category_name'] ?? $category['title'] ?? $category['name'] ?? 'Général'));
        $rid = trim((string)($category['category_id'] ?? $category['id'] ?? ''));
        if ($rid === '') $rid = $name;
        if (!in_array($rid, $selected, true)) continue;
        if (!isset($known[$rid])) {
            $insert->execute([mb_substr('['.$provider.'] '.$name, 0, 100, 'UTF-8'), $fid, $rid, $kind]);
            $known[$rid] = (int)$pdo->lastInsertId();
        }
        $map[$rid] = $known[$rid];
    }
    return $map;
}

function panel_import_existing(PDO $pdo, int $fid, string $kind): array {
    $stmt = $pdo->prepare('SELECT stream_id, remote_stream_id, direct_source FROM streams WHERE fournisseur_id = ? AND stream_type = ? ORDER BY stream_id');
    $stmt->execute([$fid, $kind]);
    $known = [];
    foreach ($stmt as $row) {
        $rid = (string)($row['remote_stream_id'] ?? '');
        if ($rid === '') $rid = (string)$row['direct_source'];
        if ($rid !== '' && !isset($known[$rid])) $known[$rid] = (int)$row['stream_id'];
        // Legacy M3U dumps stored URLs as identity; retain their original IDs.
        $source = (string)$row['direct_source'];
        if ($source !== '' && !isset($known[$source])) $known[$source] = (int)$row['stream_id'];
    }
    return $known;
}

function normalize_ext($ext, $fallback = null) {
    $ext = strtolower(trim((string)$ext));
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    return $ext !== '' ? $ext : $fallback;
}
function infer_ext_from_url($url) {
    $path = parse_url((string)$url, PHP_URL_PATH);
    if (!$path) return null;
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    return normalize_ext($ext, null);
}
function process_and_insert_streams($pdo, $fid, $kind, $type, $items, $catMap) {
    if (empty($items)) return 0;
    
    $known = panel_import_existing($pdo, (int)$fid, $kind);
    $seen = [];
    $refresh = $pdo->prepare('UPDATE streams SET direct_source = ?, remote_stream_id = ? WHERE stream_id = ? AND fournisseur_id = ? AND stream_type = ?');
    $count = 0;
    $batchSize = 500; // Nombre de flux insérés par requête
    $placeholders = [];
    $values = [];
    
    $sqlBase = "INSERT INTO streams (fournisseur_id,stream_name,stream_icon,stream_type,category_id,direct_source,visible,container_extension,remote_stream_id,vod_plot,vod_cast,vod_director,vod_genre,vod_release_date,vod_rating,vod_rating_5based,vod_added,vod_backdrop,vod_trailer,vod_runtime) VALUES ";

    foreach ($items as $s) {
        if (!is_array($s)) continue;
        $plot=$cast=$director=$genre=$release=$rating=$rating5=$added=$backdrop=$trailer=$runtime=null;
        
        if ($type === 'm3u') {
            $name=(string)($s['name']??''); $icon=(string)($s['icon']??''); $source=(string)($s['url']??''); $rid='m3u:'.hash('sha256',$source); $catRid=(string)($s['group']??'Général'); $ext=normalize_ext($s['ext'] ?? null, infer_ext_from_url($source));
            if (isset($known[$source]) && !isset($known[$rid])) $known[$rid] = $known[$source];
        } elseif ($type === 'stalker') {
            $name=(string)($s['name']??$s['title']??''); $icon=(string)($s['logo']??$s['icon']??''); $source=(string)($s['cmd']??$s['url']??''); $rid=(string)($s['id']??$s['ch_id']??$s['cmd']??''); $catRid=(string)($s['category_id']??$s['tv_genre_id']??$s['genre_id']??$s['cat_id']??''); $ext=null;
        } else {
            $name=(string)($s['name']??''); $icon=(string)($s['stream_icon']??$s['cover']??$s['cover_big']??''); $source=(string)($s['stream_id']??$s['series_id']??''); $rid=$source; $catRid=(string)($s['category_id']??''); $ext=normalize_ext($s['container_extension'] ?? $s['container_ext'] ?? null, null); if (!$ext && !empty($s['direct_source'])) $ext=infer_ext_from_url($s['direct_source']);
        }
        
        if (!isset($catMap[$catRid]) || $name==='' || $source==='') continue;
        
        if (isset($seen[$rid])) continue;
        $seen[$rid] = true;
        if (isset($known[$rid])) {
            // Provider transport refresh only: local names, icons, visibility,
            // category, format and metadata always take priority.
            $refresh->execute([$source, $rid, $known[$rid], $fid, $kind]);
            $count++;
            continue;
        }
        if (strlen($rid) > 255) throw new RuntimeException('Remote identity exceeds schema capacity');
        $name = mb_substr($name, 0, 900, 'UTF-8');
        
        // Ajout des valeurs pour le batch
        $placeholders[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        array_push($values, $fid, $name, $icon, $kind, $catMap[$catRid], $source, 1, $ext, $rid, $plot, $cast, $director, $genre, $release, $rating, $rating5, $added, $backdrop, $trailer, $runtime);
        $count++;

        // Si le lot atteint la taille max (500), on exécute l'insertion
        if (count($placeholders) >= $batchSize) {
            $stmt = $pdo->prepare($sqlBase . implode(',', $placeholders));
            $stmt->execute($values);
            // Réinitialisation des tableaux pour le prochain lot
            $placeholders = [];
            $values = [];
        }
    }
    
    // Insertion des éléments restants qui n'auraient pas atteint 500
    if (!empty($placeholders)) {
        $stmt = $pdo->prepare($sqlBase . implode(',', $placeholders));
        $stmt->execute($values);
    }
    
    return $count;
}

