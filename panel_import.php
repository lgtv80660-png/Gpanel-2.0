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
    $rows = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('streams','categories','import_exclusions','content_settings')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($rows) !== 4 || array_filter($rows, function ($engine) { return strcasecmp($engine, 'InnoDB') !== 0; })) {
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
    $categoryExcluded=panel_import_exclusions($pdo,$fid,$kind.'-category');
    foreach ($remote as $category) {
        if (!is_array($category)) continue;
        $name = trim((string)($category['category_name'] ?? $category['title'] ?? $category['name'] ?? 'Général'));
        $rid = trim((string)($category['category_id'] ?? $category['id'] ?? ''));
        if ($rid === '') $rid = $name;
        if (!in_array($rid, $selected, true) || isset($categoryExcluded[$rid])) continue;
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
// Shared identities: selection and exclusions must match the incremental importer.
function panel_import_identity(array $s, string $type): string {
    if ($type === 'm3u') return 'm3u:'.hash('sha256', (string)($s['url'] ?? ''));
    if ($type === 'stalker') return (string)($s['id'] ?? $s['ch_id'] ?? $s['cmd'] ?? '');
    return (string)($s['stream_id'] ?? $s['series_id'] ?? '');
}
function panel_import_category(array $s, string $type): string {
    if ($type === 'm3u') return (string)($s['group'] ?? 'Général');
    return (string)($s['category_id'] ?? $s['tv_genre_id'] ?? $s['genre_id'] ?? $s['cat_id'] ?? '');
}
function panel_import_container(array $s, string $type): string {
    $ext = normalize_ext($s['container_extension'] ?? $s['container_ext'] ?? $s['ext'] ?? null);
    if (!$ext) $ext = infer_ext_from_url($s['url'] ?? $s['direct_source'] ?? '');
    // A codec label is never a container. Unknown extensions stay unknown.
    return in_array($ext, ['mp4','mkv','avi','ts','m2ts','mov','wmv','flv','webm','m4v','mpg','mpeg','m3u8'], true) ? $ext : 'unknown';
}
function panel_import_quality_details(array $s): array {
    if (!empty($s['_quality'])) return ['value'=>$s['_quality'],'origin'=>$s['_quality_origin']??'manual'];
    $video=$s['video']??$s['info']['video']??[];
    $height=$s['height']??(is_array($video)?($video['height']??null):null);
    if (is_numeric($height) && (int)$height>0) return ['value'=>$height>=2160?'4K':($height>=1080?'1080p':($height>=720?'720p':'SD')),'origin'=>'verified'];
    $resolution=trim((string)($s['resolution']??$s['quality']??''));
    foreach (['4K'=>'/^(?:3840x2160|4096x2160|2160p|4k)$/i','1080p'=>'/^(?:1920x1080|1080p)$/i','720p'=>'/^(?:1280x720|720p)$/i','SD'=>'/^(?:SD|480p|576p|720x480|720x576)$/i'] as $quality=>$pattern) if(preg_match($pattern,$resolution)) return ['value'=>$quality,'origin'=>'verified'];
    $title=(string)($s['name']??$s['title']??$s['stream_name']??'');
    $found=[];
    foreach(['4K'=>'4K|2160p','1080p'=>'1080p','720p'=>'720p','SD'=>'SD|480p|576p'] as $q=>$token) if(preg_match('/(?<![a-z0-9])(?:'.$token.')(?![a-z0-9])/i',$title)) $found[]=$q;
    return count($found)===1?['value'=>$found[0],'origin'=>'estimated']:['value'=>'unknown','origin'=>'unknown'];
}
function panel_import_quality(array $s): string {return panel_import_quality_details($s)['value'];}
function panel_import_exclusions(PDO $pdo, int $fid, string $kind): array {
    $stmt = $pdo->prepare('SELECT remote_stream_id FROM import_exclusions WHERE fournisseur_id = ? AND content_type = ?');
    $stmt->execute([$fid, $kind]);
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}
function panel_import_decisions(PDO $pdo, int $fid, string $kind, array $decisions): void {
    $delete = $pdo->prepare('DELETE FROM import_exclusions WHERE fournisseur_id = ? AND content_type = ? AND remote_stream_id = ?');
    $insert = $pdo->prepare('INSERT INTO import_exclusions (fournisseur_id,content_type,remote_stream_id) VALUES (?,?,?)');
    foreach ($decisions as $rid => $decision) {
        if (!in_array($decision, ['exclude','import'], true) || strlen((string)$rid) > 255 || (string)$rid === '') throw new InvalidArgumentException('Invalid decision');
        $delete->execute([$fid, $kind, (string)$rid]);
        if ($decision === 'exclude') $insert->execute([$fid, $kind, (string)$rid]);
    }
}
function process_and_insert_streams($pdo, $fid, $kind, $type, $items, $catMap) {
    if (empty($items)) return 0;
    
    $known = panel_import_existing($pdo, (int)$fid, $kind);
    $excluded = panel_import_exclusions($pdo, (int)$fid, $kind);
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
        if (isset($excluded[$rid])) continue;
        
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
    
    try { $pdo->query('SELECT 1 FROM content_settings LIMIT 1'); $qualityReady=true; }
    catch (PDOException $e) {if ($e->getCode()!=='42S02' && strpos($e->getMessage(),'no such table')===false)throw $e;$qualityReady=false;}
    if ($qualityReady) {
        require_once __DIR__.'/panel_content.php';
        $ids=panel_import_existing($pdo,(int)$fid,$kind);
        foreach($items as $item){if(!is_array($item))continue;$rid=panel_import_identity($item,$type);if(!isset($ids[$rid])||isset($excluded[$rid])||!isset($seen[$rid]))continue;
            $qd=panel_import_quality_details($item);$current=content_settings($pdo,$ids[$rid]);
            if(($current['quality_origin']??'')!=='manual' && $qd['value']!=='unknown' && !(($current['quality_origin']??'')==='verified'&&$qd['origin']==='estimated')){content_setting($pdo,$ids[$rid],'quality',$qd['value']);content_setting($pdo,$ids[$rid],'quality_origin',$qd['origin']);}
        }
    }
    if ($kind === 'movie') {
        require_once __DIR__.'/panel_movie_groups.php';
        if (movie_groups_ready($pdo)) {
            $identities=panel_import_existing($pdo,(int)$fid,$kind);
            $delete=$pdo->prepare('DELETE FROM movie_identifiers WHERE stream_id=?');
            $insert=$pdo->prepare('INSERT INTO movie_identifiers(stream_id,tmdb_id,imdb_id,release_year) VALUES (?,?,?,?)');
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $rid=(string)($item['stream_id']??$item['id']??'');
                if (!isset($identities[$rid]) || isset($excluded[$rid])) continue;
                $tmdb=(string)($item['tmdb_id']??$item['tmdb']??'');
                $imdb=(string)($item['imdb_id']??'');
                $year=(string)($item['year']??substr((string)($item['releaseDate']??$item['releasedate']??''),0,4));
                $tmdb=preg_match('/^[1-9][0-9]*$/',$tmdb)?$tmdb:null;
                $imdb=preg_match('/^tt[0-9]+$/',$imdb)?$imdb:null;
                $year=preg_match('/^(19|20)[0-9]{2}$/',$year)?$year:null;
                if ($tmdb===null && $imdb===null && $year===null) continue;
                $id=$identities[$rid];
                // Keep previously collected identifiers when a later catalogue omits them.
                $q=$pdo->prepare('SELECT * FROM movie_identifiers WHERE stream_id=?'); $q->execute([$id]); $old=$q->fetch(PDO::FETCH_ASSOC)?:[];
                $delete->execute([$id]); $insert->execute([$id,$tmdb??($old['tmdb_id']??null),$imdb??($old['imdb_id']??null),$year??($old['release_year']??null)]);
            }
        }
    }
    return $count;
}

