<?php
// Authenticated, bounded TMDB enrichment. Returns only image URLs and display metadata.
require_once __DIR__.'/panel_security.php';panel_require_admin('panel:read');
require_once __DIR__.'/panel_artwork_cache.php';
header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')panel_fail(405,'method_not_allowed');
$id=(string)($_GET['id']??'');$type=(string)($_GET['type']??'');
if(!preg_match('/^[1-9][0-9]{0,9}$/D',$id)||!in_array($type,['movie','tv'],true)){http_response_code(422);exit('{}');}
// Release session before slow upstream work so concurrent admin requests stay usable.
session_write_close();
echo panel_tmdb_json(gp_artwork($type,$id));
