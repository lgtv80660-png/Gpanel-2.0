"""Local fixtures only. Usage: python tests/run_regression.py --php /path/to/php --ext /path/to/ext
Requires PHP with PDO SQLite. Never opens production or provider URLs.
"""
import argparse, hashlib, http.cookiejar, json, os, re, shutil, sqlite3, subprocess, tempfile, time
from pathlib import Path
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.error import HTTPError, URLError

ap=argparse.ArgumentParser();ap.add_argument('--php',required=True);ap.add_argument('--ext',required=True);ap.add_argument('--port',type=int,default=18976);ap.add_argument('--keep',action='store_true');ap.add_argument('--ui',action='store_true');ap.add_argument('--selective',action='store_true');args=ap.parse_args()
source=Path(__file__).resolve().parents[1]
tmp=Path(tempfile.mkdtemp(prefix='panel-regression-'));web=tmp/'web';web.mkdir();state=tmp/'state';state.mkdir()
for f in source.glob('*.php'):shutil.copy2(f,web/f.name)
shutil.copytree(source/'assets',web/'assets')
# Authentication/catalogue regression isolates transport; actual relay is tested separately.
(web/'panel_vod_relay.php').write_text((source/'panel_vod_relay.php').read_text().split('function vod_relay(')[0] + '''function vod_relay(array $rows, string $extension, string $method, string $range = ''): void {
 echo 'fixture-relay:'.$rows[0]['stream_id'];
}''', encoding='utf-8')
# Replace the provider transport only in the disposable copy; no provider request is made.
importer=(web/'importer.php').read_text(encoding='utf-8')
importer=re.sub(r'function fetch_data_stream\([^\n]*\) \{.*?\n\}', '''function fetch_data_stream($url, $headers = []) {
    if (strpos($url, 'categories') !== false) return '[{"category_id":"remote-10","category_name":"Visible"}]';
    return '[]';
}''',importer,flags=re.S)
(web/'importer.php').write_text(importer,encoding='utf-8')
selective=(web/'import_selective.php').read_text(encoding='utf-8')
selective=re.sub(r'function sel_fetch\([^\n]*\) \{.*?\n\}', '''function sel_fetch($url) {
    if (strpos($url, 'categories') !== false) return '[{"category_id":"remote-10","category_name":"Visible"}]';
    return '[{"stream_id":"remote-20","name":"Provider rename","category_id":"remote-10","container_extension":"mp4","height":1080},{"stream_id":"rejected","name":"Rejected","category_id":"remote-10","container_extension":"mkv"}]';
}''',selective,flags=re.S)
(web/'import_selective.php').write_text(selective,encoding='utf-8')
api=(web/'player_api.php').read_text(encoding='utf-8')
api=re.sub(r'function fetch_data_proxy\([^\n]*\) \{.*?\n\}', '''function fetch_data_proxy($url) {
    file_put_contents(getenv('FIXTURE_UPSTREAM_COUNT'), 'x', FILE_APPEND);
    return file_get_contents(getenv('FIXTURE_UPSTREAM_JSON'));
}''',api,flags=re.S)
(web/'player_api.php').write_text(api,encoding='utf-8')
db=tmp/'fixture.sqlite';conn=sqlite3.connect(db)
conn.executescript('''
CREATE TABLE content_settings(stream_id INTEGER PRIMARY KEY,quality TEXT,quality_origin TEXT,source_override TEXT,source_provider INTEGER,source_remote TEXT,series_parent INTEGER,season_number INTEGER,episode_number INTEGER,production_ref TEXT);
CREATE TABLE admins(id INTEGER PRIMARY KEY,username TEXT,password TEXT);
CREATE TABLE import_exclusions(fournisseur_id INTEGER,content_type TEXT,remote_stream_id TEXT,PRIMARY KEY(fournisseur_id,content_type,remote_stream_id));
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
php=[str(Path(args.php).resolve()),'-n','-d','extension_dir='+str(Path(args.ext).resolve()),'-d','extension=pdo_sqlite','-d','extension=mbstring']
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
 for route in ['content_edit.php','personal_content.php','panel_diagnostics_json.php','editor.php','importer.php','import_selective.php','revalidate_proxy.php','panel_language.php','panel_diagnostics.php']:
  for body in [None,{'ajax_action':'toggle_stream','id':'20'}]:
   status,_=request(anonymous,'/'+route,body);check(status==401,route+' anonymous denied')
 check(not (tmp/'db-touch').exists(),'anonymous denied before database access')
 restricted=client();token=json.loads(request(restricted,'/__fixture.php?id=2')[1])['csrf']
 for route in ['content_edit.php','personal_content.php','editor.php','importer.php','import_selective.php','revalidate_proxy.php']:
  check(request(restricted,'/'+route,{},token)[0]==403,route+' permission denied')
 admin=client();token=json.loads(request(admin,'/__fixture.php')[1])['csrf']
 for route in ['content_edit.php','personal_content.php','editor.php','importer.php','import_selective.php','revalidate_proxy.php','panel_language.php','admin.php']:
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
 if args.selective:
  conn.execute("INSERT INTO streams(stream_id,stream_name,stream_icon,visible,fournisseur_id,category_id,stream_type,container_extension,direct_source,remote_stream_id) VALUES(19,'Disappeared fixture','',1,1,10,'movie','mp4','gone','gone')");conn.commit();initial=snapshot()
  path='/import_selective.php?fournisseur_id=1&kind=movie'
  status,html=request(admin,path,{'load':'1'},token)
  check(status==200 and 'Rejected' in html and '1080p' in html,'selective catalogue renders fixture metadata')
  check('Disparu du catalogue' in html and 'Disappeared fixture' in html,'missing provider item remains manageable and preserved')
  snap=re.search('name="snapshot" value="([a-f0-9]+)"',html).group(1)
  choices={'snapshot':snap,'choice['+hashlib.sha256(b'remote-20').hexdigest()+']':'import','choice['+hashlib.sha256(b'rejected').hexdigest()+']':'exclude'}
  check(request(admin,path,{**choices,'snapshot':'invalid'},token)[0]==409,'stale snapshot rejected')
  check(request(admin,path,{'snapshot':snap,'choice[forged]':'import'},token)[0]==422,'forged selection rejected')
  status,html=request(admin,path,choices,token)
  check(status==200 and 'exclusion(s) enregistrée(s)' in html,'explicit decisions commit')
  check(snapshot()==initial,'selected existing item preserves IDs and customizations')
  check(conn.execute('SELECT remote_stream_id FROM import_exclusions').fetchall()==[('rejected',)],'refusal persisted')
  status,html=request(admin,path+'&container=mp4&quality=1080p')
  check(status==200 and 'Visible' in html and 'Rejected' not in html,'container and quality filters render matching contents')
  check(request(admin,path+'&container=mp4',{'snapshot':snap,'choice['+hashlib.sha256(b'rejected').hexdigest()+']':'import'},token)[0]==422,'filtered-out selection rejected')
  choices={'snapshot':snap,'choice['+hashlib.sha256(b'rejected').hexdigest()+']':'import'}
  request(admin,path,choices,token)
  check(conn.execute('SELECT COUNT(*) FROM import_exclusions').fetchone()[0]==0,'explicit import lifts exclusion')
  check(conn.execute("SELECT COUNT(*) FROM streams WHERE remote_stream_id='rejected'").fetchone()[0]==1,'only explicit new content imported')
  request(admin,path,choices,token)
  check(conn.execute("SELECT COUNT(*) FROM streams WHERE remote_stream_id='rejected'").fetchone()[0]==1,'repeated explicit import preserves identity')
  print('SELECTIVE HTTP FIXTURES COMPLETE',flush=True)
  raise SystemExit(0)
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
 diagnostic=json.loads(request(admin,'/panel_diagnostics_json.php')[1])
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
  if route=='vod.php':check(request(playback,'/'+route+'?'+credentials+'&stream='+stream)[1]=='fixture-relay:20','legacy VOD route reaches authenticated relay')
  else:
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
 # Confirmed multi-provider fixture: one API/M3U card, canonical metadata, explicit backup.
 conn.executescript("""
 UPDATE fournisseurs SET active=1 WHERE id=1;
 UPDATE categories SET visible=1 WHERE category_id=10;
 CREATE TABLE movie_groups(group_id INTEGER PRIMARY KEY,label TEXT);
 CREATE TABLE movie_group_sources(stream_id INTEGER PRIMARY KEY,group_id INTEGER,role TEXT);
 CREATE TABLE movie_identifiers(stream_id INTEGER PRIMARY KEY,tmdb_id TEXT,imdb_id TEXT,release_year TEXT);
 INSERT INTO fournisseurs(id,nom,type,url_base,user,pass,active) VALUES(2,'Synthetic M3U','m3u','https://fixture.invalid','','',1);
 INSERT INTO streams(stream_id,stream_name,visible,fournisseur_id,category_id,stream_type,container_extension,direct_source,remote_stream_id) VALUES(25,'Backup movie',1,2,10,'movie','mkv','https://fixture.invalid/backup.mkv','backup'),(26,'Disabled movie',1,2,10,'movie','mkv','https://fixture.invalid/disabled.mkv','disabled');
 INSERT INTO movie_groups VALUES(1,'Confirmed synthetic movie');
 INSERT INTO movie_group_sources VALUES(20,1,'primary'),(25,1,'backup'),(26,1,'disabled');
 """);conn.commit()
 cards=json.loads(request(anonymous,'/player_api.php?'+credentials+'&action=get_vod_streams')[1])
 check([str(row['stream_id']) for row in cards]==['20'],'confirmed Xtream/M3U group has one VOD card')
 playlist=request(anonymous,'/get.php?'+credentials)[1]
 check('/25.' not in playlist and '/26.' not in playlist,'M3U excludes backup and disabled duplicate cards')
 info=json.loads(request(anonymous,'/player_api.php?'+credentials+'&action=get_vod_info&vod_id=25')[1])
 check(str(info['movie_data']['stream_id'])=='20','backup details resolve canonical primary')
 check(request(playback,'/vod.php?'+credentials+'&stream=20&backup_stream=25')[1]=='fixture-relay:25','explicit M3U backup reaches relay')
 check(request(playback,'/vod.php?'+credentials+'&stream=26')[0]==404,'disabled grouped source refuses playback')
 # Local content lifecycle, with no provider request; existing clients and providers unchanged.
 token=json.loads(request(admin,'/__fixture.php')[1])['csrf']
 personal_ids={};category_ids={}
 for kind in ['live','movie','series']:
  status,body=request(admin,'/personal_content.php',{'op':'create_category','name':'Personal '+kind,'kind':kind},token)
  check(status==200 and 'Catégorie enregistrée' in body,'create personal category '+kind)
  cid=conn.execute('SELECT category_id FROM categories WHERE fournisseur_id=0 AND content_type=?',(kind,)).fetchone()[0];category_ids[kind]=cid
  payload={'name':'Personal '+kind+' 1080p','category':cid,'source':'' if kind=='series' else 'https://fixture.invalid/personal-'+kind+'.mp4','icon':'https://fixture.invalid/poster.jpg','extension':'mp4','quality':'720p','visible':1,'plot':'Personal plot','production_ref':'future-production-1'}
  status,body=request(admin,'/content_edit.php?kind='+kind,payload,token)
  check(status==200 and 'Enregistré' in body,'create personal content '+kind)
  personal_ids[kind]=conn.execute('SELECT stream_id FROM streams WHERE fournisseur_id=0 AND stream_type=?',(kind,)).fetchone()[0]
 request(admin,'/content_edit.php?kind=episode',{'name':'Personal episode','category':category_ids['series'],'source':'https://fixture.invalid/episode.mkv','icon':'','extension':'mkv','parent':personal_ids['series'],'season':2,'episode':3,'visible':1},token)
 eid=conn.execute("SELECT stream_id FROM streams WHERE fournisseur_id=0 AND stream_type='episode'").fetchone()[0]
 count_before=(tmp/'upstream-count').read_text()
 for action,kind,key in [('get_live_streams','live','stream_id'),('get_vod_streams','movie','stream_id'),('get_series','series','series_id')]:
  data=json.loads(request(anonymous,'/player_api.php?'+credentials+'&action='+action)[1]);check(any(int(x[key])==personal_ids[kind] for x in data),'personal published '+action)
 data=json.loads(request(anonymous,'/player_api.php?'+credentials+'&action=get_series_info&series_id='+str(personal_ids['series']))[1]);check(int(data['episodes']['2'][0]['id'])==eid and data['episodes']['2'][0]['episode_num']==3,'personal series and episode metadata with local IDs')
 check((tmp/'upstream-count').read_text()==count_before,'personal catalogue never fetches provider')
 check('Personal live' in request(anonymous,'/get.php?'+credentials)[1] and 'Personal episode' in request(anonymous,'/get.php?'+credentials)[1],'personal M3U live movie episode publication')
 for route,sid in [('live.php',personal_ids['live']),('series.php',eid)]:check(request(playback,'/'+route+'?'+credentials+'&stream='+str(sid))[0]==302,'personal URL playback '+route)
 check(request(playback,'/vod.php?'+credentials+'&stream='+str(personal_ids['movie']))[1]=='fixture-relay:'+str(personal_ids['movie']),'personal movie uses existing authenticated relay')
 request(admin,'/personal_content.php',{'op':'delete_category','id':category_ids['live'],'confirmation':'SUPPRIMER '+str(category_ids['live'])},token)
 check(conn.execute('SELECT COUNT(*) FROM categories WHERE category_id=?',(category_ids['live'],)).fetchone()[0]==1,'nonempty personal category cannot be deleted')
 request(admin,'/content_edit.php?id='+str(personal_ids['live']),{'delete':1,'confirmation':'wrong'},token)
 check(conn.execute('SELECT COUNT(*) FROM streams WHERE stream_id=?',(personal_ids['live'],)).fetchone()[0]==1,'wrong deletion confirmation preserves personal content')
 # Replacement and quality correction stay independent of the importer refresh.
 request(admin,'/content_edit.php?id=20',{'name':'Local title','category':10,'source':'https://fixture.invalid/replacement.mp4','icon':'','extension':'mkv','quality':'4K','visible':1,'plot':'Local plot'},token)
 check(conn.execute('SELECT quality,source_override FROM content_settings WHERE stream_id=20').fetchone()==('4K','https://fixture.invalid/replacement.mp4'),'manual quality and replacement saved separately')
 check(request(playback,'/vod.php?'+credentials+'&stream=20')[1]=='fixture-relay:20','replacement retains existing playback ID')
 php_script=tmp/'reimport.php';php_script.write_text("<?php require "+repr(str(web/'config.php').replace('\\','/'))+"; require "+repr(str(web/'panel_import.php').replace('\\','/'))+"; process_and_insert_streams($pdo,1,'movie','xtream',[['stream_id'=>'remote-20','name'=>'Provider rename','category_id'=>'remote-10','height'=>1080]],['remote-10'=>10]);",encoding='utf-8')
 subprocess.run(php+[str(php_script)],env=env,check=True,capture_output=True)
 check(conn.execute('SELECT stream_name,vod_plot FROM streams WHERE stream_id=20').fetchone()==('Local title','Local plot') and conn.execute('SELECT quality,source_override FROM content_settings WHERE stream_id=20').fetchone()==('4K','https://fixture.invalid/replacement.mp4'),'reimport retains customization manual quality source override')
 check(conn.execute('SELECT COUNT(*) FROM streams WHERE fournisseur_id=0').fetchone()[0]==4,'reimport preserves all personal additions')
 # No provider download: local post-import visibility and exclusions are available immediately.
 path='/import_selective.php?fournisseur_id=1&kind=movie'
 html=request(admin,path)[1];snap=re.search('name="snapshot" value="([a-f0-9]+)"',html).group(1);key='choice['+hashlib.sha256(b'remote-20').hexdigest()+']'
 check('Importé' in html and 'Local title' in html,'local post-import catalogue recognizes existing contents')
 request(admin,path,{'snapshot':snap,key:'deactivate'},token);check(conn.execute('SELECT visible FROM streams WHERE stream_id=20').fetchone()[0]==0,'post-import deactivate preserves ID')
 request(admin,path,{'snapshot':snap,key:'reactivate'},token);request(admin,path,{'snapshot':snap,key:'exclude'},token)
 check(conn.execute('SELECT visible FROM streams WHERE stream_id=20').fetchone()[0]==1 and conn.execute("SELECT COUNT(*) FROM import_exclusions WHERE remote_stream_id='remote-20'").fetchone()[0]==1,'reactivation and future exclusion are independent')
 request(admin,path,{'category_action':'exclude','local_category':10},token)
 check(conn.execute("SELECT COUNT(*) FROM import_exclusions WHERE content_type='movie-category' AND remote_stream_id='remote-10'").fetchone()[0]==1,'category exclusion persisted without content deletion')
 request(admin,path,{'category_action':'import','local_category':10},token)
 request(admin,path,{'category_action':'rename','local_category':10,'category_name':'Renamed category'},token)
 check(conn.execute('SELECT category_name FROM categories WHERE category_id=10').fetchone()[0]=='Renamed category','post-import category rename preserves category ID')
 request(admin,path,{'category_action':'delete','local_category':10,'confirmation':'SUPPRIMER 10'},token)
 check(conn.execute('SELECT COUNT(*) FROM categories WHERE category_id=10').fetchone()[0]==1,'nonempty supplier category cannot be implicitly deleted')
 conn.execute("INSERT INTO categories(category_id,category_name,visible,fournisseur_id,remote_category_id,content_type,parent_id) VALUES(50000,'Supplier series',1,1,'remote-series-cat','series',0)");conn.commit()
 request(admin,'/content_edit.php?id=30',{'name':'Custom series','category':50000,'source':'','icon':'','extension':'mp4','source_provider':1,'source_remote':'replacement-series','visible':1},token)
 check(conn.execute('SELECT source_provider,source_remote FROM content_settings WHERE stream_id=30').fetchone()==(1,'replacement-series'),'series source replacement stored independently of identity')
 data=json.loads(request(anonymous,'/player_api.php?'+credentials+'&action=get_series_info&series_id=30')[1]);check(data['episodes']['1'][0]['id']=='31','series replacement retains local episode IDs with fixture transport')
 # Hide a series and deny its personal episode in metadata, playback and M3U.
 conn.execute('UPDATE streams SET visible=0 WHERE stream_id=?',(personal_ids['series'],));conn.commit()
 check(request(playback,'/series.php?'+credentials+'&stream='+str(eid))[0]==404,'inactive personal parent denies episode playback')
 check('Personal episode' not in request(anonymous,'/get.php?'+credentials)[1],'inactive personal series excludes episode from M3U')
 conn.execute('UPDATE streams SET visible=1 WHERE stream_id=?',(personal_ids['series'],));conn.commit()
 request(admin,'/content_edit.php?id='+str(eid),{'delete':1,'confirmation':'SUPPRIMER '+str(eid)},token)
 check(conn.execute('SELECT COUNT(*) FROM streams WHERE stream_id=?',(eid,)).fetchone()[0]==0,'explicit personal episode deletion')
 status,body=request(admin,'/panel_diagnostics.php');check(status==200 and '<h1>Diagnostic &amp; Santé</h1>' in body and 'content-v2.css' in body,'diagnostic premium HTML')
 if os.environ.get('CONTENT_UI'):
  ui_env={**os.environ,'FIXTURE_BASE':base}
  subprocess.run([os.environ['NODE_EXE'],str(source/'tests/content_ui.cjs')],env=ui_env,check=True)
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
