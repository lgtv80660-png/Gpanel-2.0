"""Local fixtures only. Usage: python tests/run_regression.py --php /path/to/php --ext /path/to/ext
Requires PHP with PDO SQLite. Never opens production or provider URLs.
"""
import argparse, hashlib, http.cookiejar, json, os, re, shutil, sqlite3, subprocess, tempfile, time
from pathlib import Path
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.error import HTTPError, URLError

ap=argparse.ArgumentParser();ap.add_argument('--php',required=True);ap.add_argument('--ext',required=True);ap.add_argument('--port',type=int,default=18976);ap.add_argument('--keep',action='store_true');ap.add_argument('--ui',action='store_true');args=ap.parse_args()
source=Path(__file__).resolve().parents[1]
tmp=Path(tempfile.mkdtemp(prefix='panel-regression-'));web=tmp/'web';web.mkdir();state=tmp/'state';state.mkdir()
for f in source.glob('*.php'):shutil.copy2(f,web/f.name)
shutil.copytree(source/'assets',web/'assets')
# Replace the provider transport only in the disposable copy; no provider request is made.
importer=(web/'importer.php').read_text(encoding='utf-8')
importer=re.sub(r'function fetch_data_stream\([^\n]*\) \{.*?\n\}', '''function fetch_data_stream($url, $headers = []) {
    if (strpos($url, 'categories') !== false) return '[{"category_id":"remote-10","category_name":"Visible"}]';
    return '[]';
}''',importer,flags=re.S)
(web/'importer.php').write_text(importer,encoding='utf-8')
api=(web/'player_api.php').read_text(encoding='utf-8')
api=re.sub(r'function fetch_data_proxy\([^\n]*\) \{.*?\n\}', '''function fetch_data_proxy($url) {
    file_put_contents(getenv('FIXTURE_UPSTREAM_COUNT'), 'x', FILE_APPEND);
    return file_get_contents(getenv('FIXTURE_UPSTREAM_JSON'));
}''',api,flags=re.S)
(web/'player_api.php').write_text(api,encoding='utf-8')
db=tmp/'fixture.sqlite';conn=sqlite3.connect(db)
conn.executescript('''
CREATE TABLE admins(id INTEGER PRIMARY KEY,username TEXT,password TEXT);
INSERT INTO admins VALUES(1,'fixture-admin','fixture-password'),(2,'fixture-restricted','fixture-password');
CREATE TABLE clients(id INTEGER PRIMARY KEY,username TEXT,password TEXT,active INTEGER DEFAULT 1);
INSERT INTO clients VALUES(1,'Visible','fixture-client',1);
CREATE TABLE fournisseurs(id INTEGER PRIMARY KEY,nom TEXT,type TEXT,url_base TEXT,user TEXT,pass TEXT,mac_address TEXT,proxy TEXT,active INTEGER DEFAULT 1);
INSERT INTO fournisseurs VALUES(1,'Visible','xtream','https://fixture.invalid','fixture','fixture',NULL,NULL,1);
CREATE TABLE categories(category_id INTEGER PRIMARY KEY,category_name TEXT,visible INTEGER,fournisseur_id INTEGER,remote_category_id TEXT,content_type TEXT,parent_id INTEGER);
INSERT INTO categories VALUES(10,'Visible',1,1,'remote-10','movie',0);
CREATE TABLE streams(stream_id INTEGER PRIMARY KEY,stream_name TEXT,stream_icon TEXT,visible INTEGER,fournisseur_id INTEGER,category_id INTEGER,stream_type TEXT,container_extension TEXT,direct_source TEXT,remote_stream_id TEXT);
INSERT INTO streams VALUES(20,'Visible','',1,1,10,'movie','mp4','remote-20','remote-20');
''');conn.commit()
def snapshot():
 return {table:conn.execute('SELECT * FROM '+table+' ORDER BY 1').fetchall() for table in ['clients','fournisseurs','categories','streams']}
initial=snapshot()
for field in ['vod_plot','vod_cast','vod_director','vod_genre','vod_release_date','vod_rating','vod_rating_5based','vod_added','vod_backdrop','vod_trailer','vod_runtime']:
 conn.execute('ALTER TABLE streams ADD COLUMN '+field+' TEXT')
conn.commit();initial=snapshot()
(web/'config.php').write_text("<?php file_put_contents(getenv('FIXTURE_DB_TOUCH'), 'x', FILE_APPEND); $pdo=new PDO('sqlite:'.getenv('FIXTURE_DB')); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);",encoding='utf-8')
# Test-only setup endpoint, created solely in the disposable fixture web root.
(web/'__fixture.php').write_text("""<?php
require __DIR__.'/panel_security.php';
$_SESSION['admin_logged']=true;$_SESSION['panel_admin_id']=(string)($_GET['id']??1);
$_SESSION['panel_language']=panel_load_language($_SESSION['panel_admin_id']);
$_SESSION['panel_last_seen']=isset($_GET['expired'])?time()-1801:time();
echo json_encode(['csrf'=>$_SESSION['panel_csrf'],'lang'=>panel_language()]);
""",encoding='utf-8')
env=os.environ.copy();env.update(PANEL_COOKIE_SECURE='0',PANEL_STATE_DIR=str(state),FIXTURE_DB=str(db),FIXTURE_DB_TOUCH=str(tmp/'db-touch'),PANEL_ADMIN_PERMISSIONS=json.dumps({'1':['panel:read','panel:manage','catalog:edit','catalog:import','cache:revalidate'],'2':['panel:read']}),PANEL_REVALIDATE_URL='',PANEL_REVALIDATE_SECRET='')
env.update(FIXTURE_UPSTREAM_COUNT=str(tmp/'upstream-count'),FIXTURE_UPSTREAM_JSON=str(tmp/'upstream.json'))
(tmp/'upstream.json').write_text(json.dumps({'episodes':{'1':[{'id':'ep-visible','title':'Remote title','container_extension':'mp4'},{'id':'ep-hidden','title':'Hidden remote'},{'id':'ep-new','title':'New episode','container_extension':'mkv'}]},'info':{'name':'Remote series','cover':'Remote cover','plot':'Remote plot'},'seasons':[]}),encoding='utf-8')
php=[str(Path(args.php).resolve()),'-d','extension_dir='+str(Path(args.ext).resolve()),'-d','extension=pdo_sqlite']
base=f'http://127.0.0.1:{args.port}'
log=(tmp/'server.log').open('wb');server=subprocess.Popen(php+['-S',f'127.0.0.1:{args.port}','-t',str(web)],env=env,stdout=log,stderr=log)
def client():return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(c,path,body=None,csrf=None):
 headers={};data=None
 if body is not None:
  from urllib.parse import urlencode
  data=urlencode(body).encode();headers['Content-Type']='application/x-www-form-urlencoded'
 if csrf:headers['X-CSRF-Token']=csrf
 try:
  r=c.open(Request(base+path,data=data,headers=headers),timeout=5)
  return r.status,r.read().decode()
 except HTTPError as e:return e.code,e.read().decode()
def check(condition,name):
 assert condition,name
 print('PASS '+name,flush=True)
try:
 anonymous=client()
 for _ in range(50):
  try:request(anonymous,'/__fixture.php');break
  except URLError:time.sleep(.1)
 anonymous=client()
 for route in ['editor.php','importer.php','revalidate_proxy.php','panel_language.php','panel_diagnostics.php']:
  for body in [None,{'ajax_action':'toggle_stream','id':'20'}]:
   status,_=request(anonymous,'/'+route,body);check(status==401,route+' anonymous denied')
 check(not (tmp/'db-touch').exists(),'anonymous denied before database access')
 restricted=client();token=json.loads(request(restricted,'/__fixture.php?id=2')[1])['csrf']
 for route in ['editor.php','importer.php','revalidate_proxy.php']:
  check(request(restricted,'/'+route,{},token)[0]==403,route+' permission denied')
 admin=client();token=json.loads(request(admin,'/__fixture.php')[1])['csrf']
 for route in ['editor.php','importer.php','revalidate_proxy.php','panel_language.php','admin.php']:
  for bad in [None,'wrong']:
   check(request(admin,'/'+route,{'ajax_action':'toggle_stream','id':'20'},bad)[0]==403,route+' invalid CSRF denied')
 check(not (tmp/'db-touch').exists(),'invalid permissions and CSRF denied before database access')
 check(snapshot()==initial,'negative requests preserve catalogue and clients')
 for language in ['en','ar','fr']:
  check(request(admin,'/panel_language.php',{'language':language},token)[0]==200,'save language '+language)
  fresh=client();data=json.loads(request(fresh,'/__fixture.php')[1]);check(data['lang']==language,'language survives fresh session '+language)
 check(json.loads(request(restricted,'/__fixture.php?id=2')[1])['lang']=='fr','language isolated by admin identity')
 check(request(admin,'/panel_language.php',{'language':'ru'},token)[0]==422,'unsupported language denied')
 check(request(admin,'/revalidate_proxy.php')[0]==405,'revalidation GET denied')
 check(request(admin,'/revalidate_proxy.php',{},token)[0]==502,'unconfigured revalidation reports failure')
 check(request(admin,'/editor.php',{'ajax_action':'bulk_toggle_streams','ids':'[]','visible':'1'},token)[0]==422,'empty bulk rejected')
 result=json.loads(request(admin,'/editor.php',{'ajax_action':'toggle_stream','id':'20'},token)[1])
 check(result['success'] and not result['notification_confirmed'],'valid edit succeeds and reports unconfirmed notification')
 check(conn.execute('SELECT visible FROM streams WHERE stream_id=20').fetchone()[0]==0,'target stream toggled')
 current=snapshot();check(current['clients']==initial['clients'] and current['fournisseurs']==initial['fournisseurs'] and current['categories']==initial['categories'],'edit preserves unrelated entities')
 request(admin,'/editor.php',{'ajax_action':'toggle_stream','id':'20'},token)
 check(snapshot()==initial,'second toggle restores fixture without changing IDs')
 status,html=request(admin,'/importer.php',{'step':'3','fournisseur_id':'1','selected_cats[movie][]':'remote-10'},token)
 check(status==200 and 'Importation annulée' in html,'empty provider import reports failure')
 check(snapshot()==initial,'empty provider import rolls back catalogue and category replacement')
 expired=client();request(expired,'/__fixture.php?expired=1');check(request(expired,'/editor.php')[0]==401,'expired session denied')
 for route in ['admin.php','editor.php','editor.php?cat_id=10','importer.php']:
  status,html=request(admin,'/'+route);check(status==200 and 'PANEL_CONFIG' in html and 'csrf_token' in html,route+' renders with CSRF and language config')
 # Real login flow uses fixture admins, never the production database.
 login=client();status,html=request(login,'/admin.php');csrf=re.search('name="csrf_token" value="([a-f0-9]+)"',html).group(1)
 status,html=request(login,'/admin.php',{'login_btn':'1','username':'fixture-admin','password':'fixture-password','csrf_token':csrf})
 check(status==200 and 'panelLogout' in html and 'Gestion Clients' in html,'legacy admin login remains compatible')
 check(request(login,'/editor.php')[0]==200,'login identity permits editor')
 check(snapshot()==initial,'render and login preserve all fixture data')
 diagnostic=json.loads(request(admin,'/panel_diagnostics.php')[1])
 check(diagnostic['orphan_streams']==0 and diagnostic['catalogue'][0]['total']==1,'diagnostic reports fixture catalogue without secrets')
 # Client API and playback: fixture transport, no provider network requests.
 conn.execute("INSERT INTO streams(stream_id,stream_name,stream_icon,visible,fournisseur_id,category_id,stream_type,container_extension,direct_source,remote_stream_id,vod_plot) VALUES(30,'Custom series','Custom cover',1,1,10,'series',NULL,'series-remote','series-remote','Custom plot')")
 conn.execute("INSERT INTO streams(stream_id,stream_name,stream_icon,visible,fournisseur_id,category_id,stream_type,container_extension,direct_source,remote_stream_id) VALUES(31,'Custom episode','Custom episode icon',1,1,10,'episode','mkv','ep-visible','ep-visible'),(32,'Hidden episode','',0,1,10,'episode','mp4','ep-hidden','ep-hidden'),(40,'Live fixture','',1,1,10,'live','ts','live-remote','live-remote')")
 conn.commit()
 credentials='username=visible&password=fixture-client'
 status,body=request(anonymous,'/player_api.php?username=visible&password=wrong&action=get_series_info&series_id=30')
 check(json.loads(body)['user_info']['auth']==0 and not (tmp/'upstream-count').exists(),'invalid client cannot fetch upstream metadata')
 status,body=request(anonymous,'/player_api.php?'+credentials+'&action=get_series_info&series_id=30');data=json.loads(body)
 check(status==200 and len(data['episodes']['1'])==2,'on-demand series returns visible episodes only')
 check(data['episodes']['1'][0]['id']=='31' and data['episodes']['1'][0]['title']=='Custom episode' and data['episodes']['1'][0]['container_extension']=='mkv','episode ID, title and format preserved')
 check(data['info']['name']=='Custom series' and data['info']['cover']=='Custom cover','series custom name and icon win')
 check(conn.execute('SELECT vod_plot FROM streams WHERE stream_id=30').fetchone()[0]=='Custom plot','series custom metadata preserved')
 request(anonymous,'/player_api.php?'+credentials+'&action=get_series_info&series_id=30')
 check((tmp/'upstream-count').read_text()=='x','second series request uses cache')
 check(conn.execute("SELECT COUNT(*) FROM streams WHERE direct_source='ep-new'").fetchone()[0]==1,'cached series request does not duplicate episode')
 from urllib.request import HTTPRedirectHandler
 class NoRedirect(HTTPRedirectHandler):
  def redirect_request(self,*args,**kwargs):return None
 playback=build_opener(NoRedirect())
 for route,stream,expected in [('live.php','40','live/fixture/fixture/live-remote.ts'),('vod.php','20','movie/fixture/fixture/remote-20.mp4'),('series.php','31','series/fixture/fixture/ep-visible.mkv')]:
  try:playback.open(base+'/'+route+'?'+credentials+'&stream='+stream)
  except HTTPError as response:check(response.code==302 and response.headers['Location'].endswith(expected),route+' fixture playback redirect')
  check(request(anonymous,'/'+route+'?username=visible&password=wrong&stream='+stream)[0]==401,route+' rejects invalid client')
 conn.execute('UPDATE categories SET visible=0 WHERE category_id=10');conn.commit()
 for route,stream in [('live.php','40'),('vod.php','20'),('series.php','31')]:check(request(anonymous,'/'+route+'?'+credentials+'&stream='+stream)[0]==404,route+' hidden category denied')
 check(json.loads(request(anonymous,'/player_api.php?'+credentials+'&action=get_series_info&series_id=30')[1])['episodes']==[],'hidden series category denied before cached lookup')
 check(json.loads(request(anonymous,'/player_api.php?'+credentials+'&action=get_vod_info&vod_id=20')[1])['movie_data']==[],'hidden VOD detail denied')
 check(request(anonymous,'/get.php?'+credentials)[1].count('#EXTINF')==0,'hidden category excluded from M3U playlist')
 conn.execute('UPDATE categories SET visible=1 WHERE category_id=10');conn.execute('UPDATE fournisseurs SET active=0 WHERE id=1');conn.commit()
 check(json.loads(request(anonymous,'/player_api.php?'+credentials+'&action=get_series_info&series_id=30')[1])['episodes']==[],'disabled provider denied before cached lookup')
 for action in ['get_live_streams','get_vod_streams','get_series','get_live_categories','get_vod_categories','get_series_categories']:
  check(json.loads(request(anonymous,'/player_api.php?'+credentials+'&action='+action)[1])==[],'disabled provider excluded from '+action)
 # Missing config never opens a production connection, and leaks no details.
 proc=subprocess.run(php+[str(source/'config.php')],env={k:v for k,v in env.items() if not k.startswith('MYSQL')},capture_output=True,text=True)
 check(proc.stdout=='Database unavailable.','missing database config fails generically')
 if args.ui:subprocess.run(['node',str(source/'tests/ui-regression.cjs')],check=True)
 print('FIXTURE '+str(tmp),flush=True)
 if args.keep:
  print('SERVER '+str(server.pid),flush=True)
  while True:time.sleep(1)
finally:
 server.terminate();server.wait(timeout=10);log.close();conn.close()
 if not args.keep:shutil.rmtree(tmp)
