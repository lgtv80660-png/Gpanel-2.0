"""Disposable SQLite/HTTP fixtures only; no production DB or provider requests."""
import argparse, http.cookiejar, json, os, re, shutil, sqlite3, subprocess, tempfile, time
from pathlib import Path
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.parse import urlencode
from urllib.error import HTTPError, URLError

ap=argparse.ArgumentParser();ap.add_argument('--php',required=True);ap.add_argument('--ext',required=True);ap.add_argument('--keep',action='store_true');a=ap.parse_args()
src=Path(__file__).resolve().parents[1]
tmp=Path(tempfile.mkdtemp(prefix='sources-http-',dir=src.parent));web=tmp/'web';web.mkdir()
for f in src.glob('*.php'):shutil.copy2(f,web/f.name)
shutil.copytree(src/'assets',web/'assets')
db=sqlite3.connect(tmp/'fixture.sqlite')
ddl=(src/'sources.sql').read_text();ddl=ddl.replace('CHARACTER SET ascii COLLATE ascii_bin','').replace('ENGINE=InnoDB','');ddl=re.sub(r'UNIQUE KEY \w+ ','UNIQUE ',ddl)
db.executescript(ddl+'''
INSERT INTO source_policy_version VALUES(1,1);
CREATE TABLE admins(id INTEGER PRIMARY KEY,username TEXT,password TEXT);
INSERT INTO admins VALUES(1,'admin','fixture'),(2,'viewer','fixture');
CREATE TABLE panel_admin_roles(admin_id INT,role TEXT,enabled INT);
INSERT INTO panel_admin_roles VALUES(1,'admin',1),(2,'viewer',1);
CREATE TABLE fournisseurs(id INTEGER PRIMARY KEY,nom TEXT,type TEXT,active INT,url_base TEXT,user TEXT,pass TEXT);
INSERT INTO fournisseurs VALUES(1,'First','xtream',1,'https://one.example','HOST_USER_SECRET','HOST_PASS_SECRET'),(2,'Second','xtream',1,'https://two.example','SECRET_2','SECRET_2');
CREATE TABLE categories(category_id INT PRIMARY KEY,visible INT);
INSERT INTO categories VALUES(33,1);
CREATE TABLE streams(stream_id INT PRIMARY KEY,fournisseur_id INT,stream_type TEXT,stream_name TEXT,direct_source TEXT,container_extension TEXT,visible INT,category_id INT);
INSERT INTO streams VALUES(10,1,'live','FR Canal HD','100','m3u8',1,33),(20,2,'live','FR Canal FHD','200','m3u8',1,33);
CREATE TABLE content_settings(stream_id INT PRIMARY KEY,source_override TEXT,source_provider INT,source_remote TEXT);
ALTER TABLE content_settings ADD COLUMN series_parent INT;
CREATE TABLE movie_group_sources(stream_id INT,group_id INT,role TEXT);
CREATE TABLE clients(id INT PRIMARY KEY,username TEXT,password TEXT,active INT);
INSERT INTO clients VALUES(1,'client','fixture-password',1);
CREATE TABLE packs(id INT PRIMARY KEY,name TEXT,level TEXT,active INT,updated_at TEXT);
INSERT INTO packs VALUES(1,'Fixture','BASIC',1,'2026-10-14');
CREATE TABLE pack_rules(pack_id INT,scope TEXT,kind TEXT,reference_id INT);
INSERT INTO pack_rules VALUES(1,'live','all',0);
CREATE TABLE client_packs(client_id INT,pack_id INT,expires_at TEXT);
INSERT INTO client_packs VALUES(1,1,NULL);
''');db.commit()
(web/'config.php').write_text("<?php if(isset($pdo))return;$pdo=new PDO('sqlite:'.getenv('FIXTURE_DB'));$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);")
(web/'__fixture.php').write_text("<?php require __DIR__.'/panel_security.php';$_SESSION['admin_logged']=true;$_SESSION['panel_admin_id']=(string)($_GET['id']??1);echo json_encode(['csrf'=>$_SESSION['panel_csrf']]);")
# Isolate ONLY network transport in disposable fixture; never query a real host.
relay=(web/'panel_source_relay.php').read_text()
relay=re.sub(r'function sources_relay_fetch\(.*?\nfunction sources_relay_dir', '''function sources_relay_fetch(string $url,int $limit,?callable $transport=null,?callable $dns=null): array {
 $manifest=str_ends_with($url,'.m3u8');
 $body=$manifest?"#EXTM3U\\n#EXT-X-TARGETDURATION:6\\n#EXTINF:6,\\nsegment.ts?secret=HOST_PASS_SECRET\\n":'fixture-segment';
 return ['status'=>200,'ok'=>true,'headers'=>[],'body'=>$body,'url'=>$url];
}
function sources_relay_dir''',relay,flags=re.S)
(web/'panel_source_relay.php').write_text(relay)
state=tmp/'private-state';state.mkdir()
env={**os.environ,'FIXTURE_DB':str(tmp/'fixture.sqlite'),'PANEL_SOURCES_LOCAL_HLS':'1','PANEL_SOURCES_STATE_DIR':str(state)}
php=[a.php,'-n','-d','extension_dir='+a.ext,'-d','extension=pdo_sqlite','-d','extension=mbstring']
log=(tmp/'server.log').open('wb');server=subprocess.Popen(php+['-S','127.0.0.1:18979','-t',str(web),str(web/'router.php')],env=env,stdout=log,stderr=log)
base='http://127.0.0.1:18979'
def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,body=None):
 try:
  r=c.open(Request(base+path,data=None if body is None else urlencode(body).encode()),timeout=5);return r.status,r.read().decode()
 except HTTPError as e:return e.code,e.read().decode()
count=0
def check(ok,label):
 global count
 assert ok,label
 count+=1;print('PASS '+label,flush=True)
try:
 anon=client()
 for _ in range(50):
  try:req(anon,'/__fixture.php');break
  except URLError:time.sleep(.1)
 anon=client()
 check(req(anon,'/sources_manage.php')[0]==401,'anonymous roles denied')
 check(req(anon,'/sources_manage.php?id=10')[0]==401,'anonymous source secrets denied')
 viewer=client();req(viewer,'/__fixture.php?id=2')
 check(req(viewer,'/sources_manage.php')[0]==403,'viewer cannot edit')
 admin=client();csrf=json.loads(req(admin,'/__fixture.php')[1])['csrf']
 check(req(admin,'/sources_manage.php',{'revision':1})[0]==403,'missing CSRF denied')
 check(req(admin,'/sources_manage.php',{'csrf_token':'wrong','revision':1})[0]==403,'wrong CSRF denied')
 status,html=req(admin,'/sources_manage.php');check(status==200 and 'NEUTRAL' in html,'host role form rendered')
 check('HOST_PASS_SECRET' not in html and 'HOST_USER_SECRET' not in html,'roles never render credentials')
 roles={'csrf_token':csrf,'revision':1,'expected_hosts':2,'hosts[1][live][role]':'primary','hosts[2][live][role]':'backup','hosts[2][live][order]':1}
 for fid in [1,2]:
  for kind in ['movie','series']:roles[f'hosts[{fid}][{kind}][role]']='neutral'
 check(req(admin,'/sources_manage.php',roles)[0]==200,'host role save redirects successfully')
 check(db.execute("SELECT role FROM source_host_roles WHERE fournisseur_id=1 AND kind='live'").fetchone()==('primary',),'role persisted')
 status,html=req(admin,'/sources_manage.php?id=10');check(status==200 and '+ Ajouter backup link' in html and 'Second' in html,'candidate and unlimited add control rendered')
 check('HOST_PASS_SECRET' not in html and 'SECRET_2' not in html,'prepared source plan redacts host secrets')
 post={'csrf_token':csrf,'revision':0,'mode':'custom','expected_links':2,'links[0][candidate]':20,'links[1][candidate]':10}
 status,html=req(admin,'/sources_manage.php?id=10',post);check(status==200 and 'Configuration enregistrée' in html,'custom primary and backup saved')
 check(db.execute('SELECT candidate_id FROM source_channel_links ORDER BY position').fetchall()==[(20,),(10,)],'channel override order persisted')
 req(admin,'/sources_manage.php?id=10',{**post,'revision':1,'links[1][candidate]':20})
 check(db.execute('SELECT candidate_id FROM source_channel_links ORDER BY position').fetchall()==[(20,),(10,)],'duplicate HTTP save preserves previous links')
 _,html=req(admin,'/sources_manage.php?id=10',{'csrf_token':csrf,'revision':1,'mode':'custom','expected_links':1,'links[0][url]':'http://127.0.0.1/private'})
 check('refusée' in html,'private manual URL rejected through form')
 _,html=req(admin,'/sources_manage.php?id=10',{**post,'revision':1,'expected_links':3})
 check('Requête tronquée' in html and db.execute('SELECT COUNT(*) FROM source_channel_links').fetchone()==(2,),'truncated POST cannot partially replace backups')
 db.execute("UPDATE fournisseurs SET nom='<script>alert(1)</script>' WHERE id=2");db.commit()
 _,html=req(admin,'/sources_manage.php?id=10');check('&lt;script&gt;alert(1)&lt;/script&gt;' in html and '<script>alert(1)</script>' not in html,'host names escaped')
 check(db.execute('SELECT stream_id,direct_source FROM streams ORDER BY stream_id').fetchall()==[(10,'100'),(20,'200')],'HTTP saves preserve catalogue IDs and sources')
 playback=client()
 check(req(playback,'/live/client/wrong/10.m3u8')[0]==401,'local relay preserves client authentication')
 status,manifest=req(playback,'/live/client/fixture-password/10.m3u8')
 check(status==200 and '#EXTM3U' in manifest,'stable public Live URL opens local HLS relay')
 check('HOST_PASS_SECRET' not in manifest and 'one.example' not in manifest and 'two.example' not in manifest,'no host credentials or origin sent to player')
 media=next(line for line in manifest.splitlines() if line.startswith('/live_source_media.php'))
 check(req(playback,media)==(200,'fixture-segment'),'opaque resource URL fetches segment server-side')
 check(req(playback,'/live/client/fixture-password/10.ts')[0]==501,'unsupported TS root fails closed without redirect leakage')
 from urllib.parse import parse_qs, urlparse
 query=parse_qs(urlparse(media).query);root='/live_source_media.php?'+urlencode({'s':query['s'][0],'g':0,'r':'root'})
 check(req(playback,root,{'event':'client_black_screen'})[0]==409,'explicit client black-screen signal requests reopen')
 check(req(playback,media)[0]==409,'old-origin segment cannot resume after switch')
 newroot=root.replace('&g=0&','&g=1&')
 check(req(playback,newroot)[0]==503,'reopen observes cooldown')
 time.sleep(3)
 check(req(playback,newroot)[0]==200,'clean session reopens next origin after cooldown')
 db.execute('UPDATE clients SET active=0 WHERE id=1');db.commit()
 check(req(playback,newroot)[0]==403,'session rechecks revoked client access on every request')
 db.execute('UPDATE clients SET active=1 WHERE id=1');db.commit()
 db.executescript("INSERT INTO streams VALUES(70,1,'live','Bein Sport 7 (A) SD','70','ts',1,33),(80,2,'live','|AR| BEIN SPORTS 7 SD','80','ts',1,33);");db.commit()
 status,html=req(admin,'/sources_manage.php?id=70')
 check(status==200 and '|AR| BEIN SPORTS 7 SD' in html and 'suggestion manuelle à confirmer' in html and 'value="80"' in html,'approximate suggestion rendered and selectable with confirmation label')
 check('value="80"  selected' not in html and db.execute('SELECT COUNT(*) FROM source_channel_links WHERE stream_id=70').fetchone()==(0,),'opening suggestion UI creates no association or preselection')
 req(admin,'/sources_manage.php?id=70',{'csrf_token':csrf,'revision':0,'mode':'inherit','expected_links':1,'links[0][candidate]':80})
 check(db.execute('SELECT COUNT(*) FROM source_channel_links WHERE stream_id=70').fetchone()==(0,),'inherited HTTP handler ignores approximate selection')
 status,html=req(admin,'/sources_manage.php?id=70',{'csrf_token':csrf,'revision':1,'mode':'custom','expected_links':2,'links[0][candidate]':70,'links[1][candidate]':80})
 check(status==200 and db.execute('SELECT candidate_id FROM source_channel_links WHERE stream_id=70 ORDER BY position').fetchall()==[(70,),(80,)],'custom HTTP save explicitly confirms manual suggestion')
 db.executescript('DROP TABLE source_channel_links;DROP TABLE source_channel_policy;DROP TABLE source_host_roles;DROP TABLE source_policy_version;');db.commit()
 _,html=req(admin,'/sources_manage.php');check('Migration Sources requise' in html,'missing schema offers migration guidance without auto-DDL')
 check(db.execute('SELECT COUNT(*) FROM streams').fetchone()==(4,),'rollback removes only new settings tables')
 print(f'{count} HTTP checks passed',flush=True)
 if a.keep:
  # Restore source schema for interactive inspection of the disposable fixture.
  db.executescript(ddl+'INSERT INTO source_policy_version VALUES(1,1);');db.commit()
  print('Fixture ready at '+base,flush=True)
  while True:time.sleep(1)
finally:
 server.terminate();server.wait(timeout=10);log.close();db.close();shutil.rmtree(tmp)
