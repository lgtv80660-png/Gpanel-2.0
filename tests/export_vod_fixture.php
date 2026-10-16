<?php
if(PHP_SAPI!=='cli' || count($argv)!==2 || file_exists($argv[1]))exit(2);
require __DIR__.'/vod_fixture.php';$db=vod_fixture();
$db->exec("CREATE TABLE admins(id INT PRIMARY KEY,username TEXT,password TEXT);INSERT INTO admins VALUES(1,'admin','fixture'),(2,'viewer','fixture');
CREATE TABLE panel_admin_roles(admin_id INT,role TEXT,enabled INT);INSERT INTO panel_admin_roles VALUES(1,'admin',1),(2,'viewer',1);
CREATE TABLE panel_audit(admin_id INT,event TEXT,target_id INT,created_at TEXT);
CREATE TABLE panel_settings(setting_key TEXT,setting_value TEXT);");
$db->exec('VACUUM INTO '.$db->quote($argv[1]));echo "Synthetic VOD fixture exported\n";
