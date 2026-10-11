<?php
// No cURL extension loaded: synthetic transport only, no network access.
foreach (['CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_PROTOCOLS','CURLOPT_HTTPHEADER','CURLPROTO_HTTPS','CURLINFO_HTTP_CODE'] as $i => $name) define($name, $i + 1);
$opts = []; $fixtureStatus = 200; $fixtureBody = '{"revalidated":true}'; $fixtureErrno = 0;
function curl_init($url) { return 'fixture'; }
function curl_setopt($ch, $key, $value) { $GLOBALS['opts'][$key]=$value; return true; }
function curl_setopt_array($ch, $opts) { $GLOBALS['opts'] += $opts; return true; }
function curl_exec($ch) { return $GLOBALS['fixtureBody']; }
function curl_getinfo($ch, $key) { return $GLOBALS['fixtureStatus']; }
function curl_errno($ch) { return $GLOBALS['fixtureErrno']; }
function curl_close($ch) {}
function check($ok, $message) { if (!$ok) throw new Exception($message); echo $message, "\n"; }
require __DIR__ . '/../panel_revalidation.php';
check(!panel_revalidate(), 'Missing config rejected without transport');
putenv('PANEL_REVALIDATE_URL=https://backend.example/api/revalidate');
putenv('PANEL_REVALIDATE_SECRET=synthetic-test-secret');
check(panel_revalidate(), 'Synthetic backend success accepted');
check(($opts[CURLOPT_POST] ?? false) === true, 'Bearer contract uses POST');
check(in_array('Authorization: Bearer synthetic-test-secret', $opts[CURLOPT_HTTPHEADER], true), 'Bearer header sent');
$fixtureStatus=401;check(!panel_revalidate() && $GLOBALS['panel_revalidation_error']==='sync_auth_rejected','Auth rejection classified for proxy 502');
$fixtureStatus=503;check(!panel_revalidate() && $GLOBALS['panel_revalidation_error']==='sync_backend_failed','Backend failure classified for proxy 502');
$fixtureStatus=200;$fixtureBody='{}';check(!panel_revalidate() && $GLOBALS['panel_revalidation_error']==='sync_response_invalid','Invalid success response rejected');
$fixtureErrno=28;check(!panel_revalidate() && $GLOBALS['panel_revalidation_error']==='sync_transport_failed','Timeout classified without leaking URL or secret');
