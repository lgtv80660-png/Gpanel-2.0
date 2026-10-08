<?php
// Dashboard-only artwork. No catalogue writes or browser-side TMDB requests.
require_once __DIR__.'/tmdb_public.php';

function gp_dashboard_pool(): array {
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'gpanel-dashboard-'.substr(hash('sha256',__DIR__),0,12);
    $file=$dir.DIRECTORY_SEPARATOR.'trending.json';
    $cached=is_file($file)?json_decode((string)@file_get_contents($file),true):[];
    $cached=is_array($cached)?$cached:[];
    $age=time()-(int)($cached['checked_at']??0);
    if($age<(!empty($cached['pool'])?1800:300))return $cached['pool']??[];
    // One upstream call, bounded by panel_tmdb's four-second timeout.
    $data=panel_tmdb('trending/all/day');
    $groups=['movie'=>[],'tv'=>[]];
    foreach(($data['results']??[]) as $item){
        $type=$item['media_type']??'';
        $path=$item['backdrop_path']??'';
        if(!isset($groups[$type])||!empty($item['adult'])||!is_string($path)||!preg_match('#^/[A-Za-z0-9_.-]+$#D',$path))continue;
        if(count($groups[$type])>=6)continue;
        $groups[$type][]=['backdrop'=>'https://image.tmdb.org/t/p/w1280'.$path,'type'=>$type];
    }
    $pool=[];
    for($i=0;$i<6;$i++)foreach(['movie','tv'] as $type)if(isset($groups[$type][$i]))$pool[]=$groups[$type][$i];
    // Retain a last successful pool for at most one day during an outage.
    $updated=$pool?time():(int)($cached['updated_at']??0);
    if(!$pool&&$updated>time()-86400)$pool=$cached['pool']??[];
    if(!is_dir($dir))@mkdir($dir,0700,true);
    if(is_dir($dir)){
        $tmp=@tempnam($dir,'pool');
        if($tmp!==false){
            if(@file_put_contents($tmp,panel_tmdb_json(['checked_at'=>time(),'updated_at'=>$updated,'pool'=>$pool]),LOCK_EX)!==false)@rename($tmp,$file);
            if(is_file($tmp))@unlink($tmp);
        }
    }
    return $pool;
}

function gp_dashboard_hero(array $pool): string {
    $previous=$_SESSION['gp_dashboard_artwork']??[];
    $choices=array_values(array_filter($pool,fn($art)=>$art['backdrop']!==($previous['backdrop']??'')&&$art['type']!==($previous['type']??'')));
    if(!$choices)$choices=array_values(array_filter($pool,fn($art)=>$art['backdrop']!==($previous['backdrop']??'')));
    if(!$choices)$choices=$pool;
    if(!$choices)return '';
    $selected=$choices[random_int(0,count($choices)-1)];
    $_SESSION['gp_dashboard_artwork']=$selected;
    return $selected['backdrop'];
}

function gp_dashboard_same_image(string $a,string $b): bool {
    if(parse_url($a,PHP_URL_HOST)==='image.tmdb.org'&&parse_url($b,PHP_URL_HOST)==='image.tmdb.org')return basename((string)parse_url($a,PHP_URL_PATH))===basename((string)parse_url($b,PHP_URL_PATH));
    return $a!==''&&$b!==''&&parse_url($a,PHP_URL_PATH)===parse_url($b,PHP_URL_PATH);
}

function gp_dashboard_collection(array $rows,array $pool,string $hero): string {
    foreach($rows as $row){
        $src=gp_media($row)['backdrop'];
        if($src&&!gp_dashboard_same_image($src,$hero))return $src;
    }
    // Another TMDB backdrop when the catalogue has none; never reuse the hero.
    foreach($pool as $art)if(!gp_dashboard_same_image($art['backdrop'],$hero))return $art['backdrop'];
    foreach($rows as $row){$src=gp_media($row)['poster'];if($src&&!gp_dashboard_same_image($src,$hero))return $src;}
    return '';
}

function gp_dashboard_picture(string $src,string $class): string {
    // No shared banner fallback or enrichment attributes: failed images reveal
    // the distinct CSS background of their own dashboard section.
    return $src!==''?'<span class="gp-picture '.gp_escape($class).'" aria-hidden="true"><img src="'.gp_escape($src).'" alt="" loading="eager" decoding="async" referrerpolicy="no-referrer"></span>':'';
}
