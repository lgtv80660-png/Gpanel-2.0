<?php
require __DIR__.'/../panel_series_cache.php';
function check($ok, $name) { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
$dir = (getenv('GPANEL_TEST_TMP')?:sys_get_temp_dir()).DIRECTORY_SEPARATOR.'gpanel-cache-test-'.bin2hex(random_bytes(8));
mkdir($dir);
putenv('PANEL_STATE_DIR='.$dir);
$calls = 0;
$fetch = function () use (&$calls) { $calls++; return '{"episodes":{"1":[{"id":"remote-1"}]},"info":{"name":"Fixture"}}'; };
try {
    $first = panel_series_metadata('provider-1:series-1:credential-version', $fetch);
    check(panel_series_metadata('provider-1:series-1:credential-version', $fetch) === $first && $calls === 1, 'fresh cache avoids repeated provider call');
    panel_series_metadata('provider-2:series-1:credential-version', $fetch);
    check($calls === 2, 'provider identities isolated');
    panel_series_metadata('provider-1:series-1:new-credentials', $fetch);
    check($calls === 3, 'credential change invalidates cache');
    $path = panel_series_cache_path('provider-1:series-1:credential-version');
    file_put_contents($path, json_encode(['time'=>time()-901,'data'=>$first]));
    check(panel_series_metadata('provider-1:series-1:credential-version', function () { return false; }) === $first,'bounded stale metadata survives provider failure');
    file_put_contents($path, json_encode(['time'=>time()-86401,'data'=>$first]));
    check(panel_series_metadata('provider-1:series-1:credential-version', function () { return false; }) === null,'expired metadata not served indefinitely');
    check(panel_series_metadata('invalid', function () { return '{"error":"failure"}'; }) === null && !is_file(panel_series_cache_path('invalid')), 'invalid responses never cached');
    putenv('PANEL_STATE_DIR='.__DIR__.'/..');
    check(panel_series_cache_path('private') === null,'cache refuses web root storage');
} finally {
    foreach (glob($dir.DIRECTORY_SEPARATOR.'*') as $file) unlink($file);
    rmdir($dir);
}
