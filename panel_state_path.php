<?php
// Windows ACLs can permit writes while PHP is_writable(directory) reports false.
// Only a caller-validated private directory may reach this probe.
function panel_state_writable(string $dir): bool {
    if(is_writable($dir))return true;
    if(PHP_OS_FAMILY!=='Windows')return false;
    $path=$dir.DIRECTORY_SEPARATOR.'.write-probe-'.bin2hex(random_bytes(16));
    $file=@fopen($path,'x');if(!$file)return false;
    fclose($file);return unlink($path);
}
function panel_state_outside(string $dir,string $web): bool {
    $d=rtrim(str_replace('\\','/',$dir),'/');$w=rtrim(str_replace('\\','/',$web),'/');
    if(PHP_OS_FAMILY==='Windows'){$d=strtolower($d);$w=strtolower($w);}
    return $d!==$w && !str_starts_with($d,$w.'/');
}
