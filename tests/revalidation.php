<?php
// Run with php -n tests/revalidation.php: curl is mocked; no network is opened.
if (extension_loaded('curl')) exit("Run this test with php -n (no curl extension).\n");
foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT',
    'CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_PROTOCOLS','CURLOPT_HTTPHEADER','CURLPROTO_HTTPS','CURLINFO_HTTP_CODE'] as $i => $name) define($name, $i + 1);
$GLOBALS['fixture_status'] = 200;
$GLOBALS['fixture_body'] = '{"revalidated":true}';
function curl_init($url) { $GLOBALS['fixture_url'] = $url; return new stdClass(); }
function curl_setopt_array($ch, $options) { $GLOBALS['fixture_options'] = $options; return true; }
function curl_exec($ch) { return $GLOBALS['fixture_body']; }
function curl_getinfo($ch, $option) { return $GLOBALS['fixture_status']; }
function curl_close($ch) {}
function check($ok, $name) { if (!$ok) throw new RuntimeException($name); echo 'PASS ' . $name . "\n"; }
require __DIR__ . '/../panel_revalidation.php';
putenv('PANEL_REVALIDATE_URL=https://cache.fixture.invalid/api/revalidate');
putenv('PANEL_REVALIDATE_SECRET=fixture-only');
putenv('PANEL_REVALIDATE_AUTH=header');
check(panel_revalidate(), 'valid receiver acknowledgement accepted');
$options = $GLOBALS['fixture_options'];
check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS peer and hostname verified');
check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_TIMEOUT] === 5, 'redirects disabled and request bounded');
check(strpos($GLOBALS['fixture_url'], '?') === false && in_array('Authorization: Bearer fixture-only', $options[CURLOPT_HTTPHEADER], true), 'default secret travels in header');
foreach ([302,401,403,500] as $status) { $GLOBALS['fixture_status'] = $status; check(!panel_revalidate(), 'receiver HTTP ' . $status . ' refused'); }
$GLOBALS['fixture_status'] = 200;
foreach ([false, '{}', '{"revalidated":false}', 'invalid-json'] as $body) { $GLOBALS['fixture_body'] = $body; check(!panel_revalidate(), 'missing or failed acknowledgement refused'); }
foreach (['http://cache.fixture.invalid','https://user:pass@cache.fixture.invalid','https://cache.fixture.invalid?secret=x','https://cache.fixture.invalid#fragment'] as $url) {
    putenv('PANEL_REVALIDATE_URL=' . $url); check(!panel_revalidate(), 'unsafe configured URL rejected');
}
putenv('PANEL_REVALIDATE_URL=https://cache.fixture.invalid/api/revalidate');
putenv('PANEL_REVALIDATE_AUTH=legacy-query');
$GLOBALS['fixture_body'] = '{"success":true}';
check(panel_revalidate() && strpos($GLOBALS['fixture_url'], '?secret=') !== false, 'legacy consumer compatibility requires explicit mode');
putenv('PANEL_REVALIDATE_SECRET');
check(!panel_revalidate(), 'missing secret never falls back');
