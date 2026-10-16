"""Actual PHP endpoints with disposable SQLite, simulated upstream media, no hosts.
No test setup route or transport replacement is ever written into the project.
"""
import argparse, http.cookiejar, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time
from pathlib import Path
from urllib.request import Request, build_opener, HTTPCookieProcessor, HTTPRedirectHandler
from urllib.parse import urlencode
from urllib.error import HTTPError, URLError

ap=argparse.ArgumentParser();ap.add_argument('--php',required=True);ap.add_argument('--ext',required=True);a=ap.parse_args()
root=Path(__file__).resolve().parents[1];tmp=Path(tempfile.mkdtemp(prefix='gpanel-full-',dir=root.parent));web=tmp/'web';web.mkdir();state=tmp/'sessions';state.mkdir()
for file in root.glob('*.php'):shutil.copy2(file,web/file.name)
shutil.copytree(root/'assets',web/'assets')
php=[a.php,'-n','-d','extension_dir='+a.ext,'-d','extension=pdo_sqlite','-d','extension=mbstring','-d','extension=intl']
subprocess.run(php+[str(root/'tests/export_vod_fixture.php'),str(tmp/'fixture.sqlite')],check=True,capture_output=True)
db=sqlite3.connect(tmp/'fixture.sqlite')
(web/'config.php').write_text("""<?php
if(isset($pdo))return;
$pdo=new PDO('sqlite:'.getenv('FIXTURE_DB'));$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));
""",encoding='utf-8')
(web/'__fixture.php').write_text("""<?php
require __DIR__.'/panel_security.php';$_SESSION['admin_logged']=true;$_SESSION['panel_admin_id']=(int)($_GET['id']??1);$_SESSION['panel_last_seen']=time();echo json_encode(['csrf'=>$_SESSION['panel_csrf']]);
""",encoding='utf-8')
relay=(web/'panel_vod_relay.php').read_text(encoding='utf-8').replace("$transport??='vod_http_hop';","$transport??='fixture_vod_hop';")
relay+='''
function fixture_vod_hop($target,$method,$range,$headers,$bytes){
 file_put_contents(getenv('FIXTURE_HITS'),$target['url']."\\n",FILE_APPEND);
 if($target['host']==='one.example'){$headers(503,[]);return ['status'=>503,'ok'=>false,'headers'=>[]];}
 $payload=str_repeat("\\0fixture-video",128);$status=200;$h=['content-type'=>'video/mp4','location'=>'https://SECRET_HOST','set-cookie'=>'HOST_SECRET'];
 if($range!==''){preg_match('/^bytes=(\\d+)-(\\d*)$/',$range,$m);$start=(int)$m[1];$end=$m[2]===''?strlen($payload)-1:(int)$m[2];$h['content-range']='bytes '.$start.'-'.$end.'/'.strlen($payload);$payload=substr($payload,$start,$end-$start+1);$status=206;}
 $h['content-length']=(string)strlen($payload);$headers($status,$h);if($method!=='HEAD')$bytes($payload);return ['status'=>$status,'ok'=>true,'headers'=>$h];
}
'''
# .example destinations receive synthetic public DNS only in this disposable copy.
relay=relay.replace("$target=sources_url($url,$dns);", "$target=sources_url($url,$dns??fn($h)=>['93.184.216.34']);")
(web/'panel_vod_relay.php').write_text(relay,encoding='utf-8')
with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
base=f'http://127.0.0.1:{port}';env={**os.environ,'FIXTURE_DB':str(tmp/'fixture.sqlite'),'FIXTURE_HITS':str(tmp/'hits'),'PANEL_SOURCES_STATE_DIR':str(state)}
log=(tmp/'server.log').open('wb');server=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','-t',str(web),str(web/'router.php')],env=env,stdout=log,stderr=log)
class NoRedirect(HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
def client(redirect=True):return build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()),*(() if redirect else (NoRedirect(),)))
def req(c,path,body=None,headers=None,method=None):
 try:
  with c.open(Request(base+path,data=None if body is None else urlencode(body).encode(),headers=headers or {},method=method),timeout=10) as r:return r.status,r.read(),r.headers
 except HTTPError as e:return e.code,e.read(),e.headers
count=0
def check(ok,label):
 global count
 assert ok,label
 count+=1;print('PASS '+label,flush=True)
def policy(sid,candidates,revision=0):
 d={'csrf_token':csrf,'action':'policy','mode':'custom','revision':revision,'expected_links':len(candidates),'form_complete':1}
 for i,c in enumerate(candidates):d.update({f'links[{i}][candidate]':c,f'links[{i}][enabled]':1,f'links[{i}][confirmed]':1})
 return req(admin,f'/sources_manage.php?id={sid}',d)
try:
 for _ in range(50):
  try:req(client(),'/__fixture.php');break
  except URLError:time.sleep(.1)
 anon=client();check(req(anon,'/sources_manage.php?id=100')[0]==401,'anonymous denied shared VOD administration')
 viewer=client();req(viewer,'/__fixture.php?id=2');check(req(viewer,'/sources_manage.php?id=1101')[0]==403,'viewer denied episode administration')
 admin=client();csrf=json.loads(req(admin,'/__fixture.php')[1])['csrf']
 check(req(admin,'/sources_manage.php?id=100',{'action':'policy'})[0]==403,'VOD CSRF enforced by existing guard')
 check(req(anon,'/panel_vod_manage.php')[0]==404,'included UI cannot bypass shared controller')
 for kind in ['live','movie','series','episode']:check(req(admin,'/sources_manage.php?browse='+kind)[0]==200,'unified catalogue renders '+kind)
 status,html,_=req(admin,'/sources_manage.php?id=100');check(status==200 and b'HOST_SECRET' not in html and b'SECOND_SECRET' not in html,'VOD interface hides provider credentials')
 check(db.execute('SELECT COUNT(*) FROM source_vod_links').fetchone()==(0,),'opening VOD UI creates no configuration')
 req(admin,'/movie_duplicates.php',{'csrf_token':csrf,'roles[100]':'primary','roles[200]':'backup','expected_members':2,'form_complete':1})
 check(db.execute('SELECT stream_id,role FROM movie_group_sources ORDER BY stream_id').fetchall()==[(100,'primary'),(200,'backup')],'existing film duplicate confirmation UI reused')
 req(admin,'/movie_duplicates.php',{'csrf_token':csrf,'roles[100]':'backup','roles[200]':'primary','expected_members':2,'form_complete':1})
 check(db.execute("SELECT stream_id FROM movie_group_sources WHERE role='primary'").fetchall()==[(100,)],'group catalogue primary cannot change public identity')
 check(policy(100,[100,200])[0]==200 and db.execute('SELECT candidate_id FROM source_vod_links WHERE stream_id=100 ORDER BY position').fetchall()==[(100,),(200,)],'HTTP film confirmation and order saved')
 # Store a manual URL, then ensure its complete secret is absent from HTML.
 d={'csrf_token':csrf,'action':'policy','mode':'custom','revision':1,'expected_links':3,'form_complete':1}
 for i,c in enumerate([100,200]):d.update({f'links[{i}][candidate]':c,f'links[{i}][enabled]':1,f'links[{i}][confirmed]':1})
 d.update({'links[2][candidate]':0,'links[2][url]':'https://93.184.216.34/private/MANUAL_SECRET.mp4','links[2][enabled]':1,'links[2][confirmed]':1})
 status,html,_=req(admin,'/sources_manage.php?id=100',d)
 check(status==200 and b'MANUAL_SECRET' not in html and db.execute('SELECT COUNT(*) FROM source_vod_links WHERE stream_id=100').fetchone()==(3,),'manual backup saved with secret masked on redisplay')
 nofollow=client(False);status,_,headers=req(nofollow,'/movie/client/CLIENT_SECRET/100.mp4');cap=headers.get('Location','')
 check(status==307 and re.fullmatch(r'/vod_source_media.php\?s=[a-f0-9]{64}',cap) is not None,'stable movie URL issues only opaque capability')
 check(req(anon,'/movie/client/WRONG/100.mp4')[0]==401,'existing client authentication retained')
 status,payload,headers=req(anon,cap);check(status==200 and payload==b'\0fixture-video'*128,'movie PRIMARY failure falls back before media bytes')
 check('Location' not in headers and 'Set-Cookie' not in headers and b'HOST_SECRET' not in payload,'upstream credentials and headers never exposed')
 before=(tmp/'hits').read_text();status,chunk,headers=req(anon,cap,headers={'Range':'bytes=64-127'})
 check(status==206 and chunk==payload[64:128] and headers['Content-Range']==f'bytes 64-127/{len(payload)}','Range seeks same opaque movie session')
 check((tmp/'hits').read_text()[len(before):].count('two.example')==1 and 'one.example' not in (tmp/'hits').read_text()[len(before):],'seek stays pinned to selected backup file')
 check(req(nofollow,'/movie/client/CLIENT_SECRET/100.mp4',headers={'Range':'bytes=64-127'})[0]==409,'new unbound seek cannot silently select another file')
 status,_,head=req(anon,cap,method='HEAD');check(status==200 and head['Content-Length']==str(len(payload)),'HEAD preserves pinned file metadata')
 db.execute('UPDATE packs SET active=0');db.commit();check(req(anon,cap)[0]==403,'capability rechecks pack revocation');db.execute('UPDATE packs SET active=1');db.commit()
 db.execute("UPDATE clients SET password='ROTATED'");db.commit();check(req(anon,cap)[0]==403,'capability invalidated by client credential change');db.execute("UPDATE clients SET password='CLIENT_SECRET'");db.commit()
 policy(100,[200,100],2);check(req(anon,cap)[0]==403,'policy change invalidates old movie capability')
 check(req(anon,'/vod_source_media.php?s=../evil')[0]==403,'capability path traversal denied')
 policy(1101,[1101,2101]);check(db.execute('SELECT COUNT(*) FROM source_vod_policy WHERE stream_id=1101').fetchone()==(0,),'episode pairing required by HTTP handler')
 req(admin,'/sources_manage.php?id=10',{'csrf_token':csrf,'action':'series_match','other_series':20,'confirmed':1,'form_complete':1})
 check(db.execute('SELECT * FROM source_series_matches').fetchall()==[(10,20)],'series correspondence confirmed through shared UI')
 policy(1101,[1101,2102]);check(db.execute('SELECT COUNT(*) FROM source_vod_policy WHERE stream_id=1101').fetchone()==(0,),'wrong episode number refused through UI')
 policy(1101,[1101,2101]);check(db.execute('SELECT candidate_id FROM source_vod_links WHERE stream_id=1101 ORDER BY position').fetchall()==[(1101,),(2101,)],'episode backup confirmed without changing public ID')
 status,_,headers=req(nofollow,'/series/client/CLIENT_SECRET/1101.mp4');epcap=headers.get('Location','');check(status==307 and 'SECRET' not in epcap,'episode public URL uses opaque server transport')
 status,data,_=req(anon,epcap);check(status==200 and data==payload,'episode failover returns backup bytes')
 check('/series/SECOND_USER/SECOND_SECRET/9988.mkv' in (tmp/'hits').read_text(),'server uses mapped host-specific episode ID and extension')
 req(admin,'/sources_manage.php?id=10',{'csrf_token':csrf,'action':'series_match','other_series':20,'confirmed':1,'remove':1,'form_complete':1})
 check(req(anon,epcap)[0]==403,'removing series match revokes dependent capability')
 db.execute('DROP TABLE source_vod_links');db.commit();status,html,_=req(admin,'/sources_manage.php?id=100')
 check(status==200 and 'Migration VOD requise' in html.decode(),'missing VOD schema gives guidance without auto-migration')
 check(db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='source_vod_links'").fetchone()==(0,),'HTTP never recreates a missing configuration table')
 print(f'{count} full-update HTTP checks passed',flush=True)
finally:
 server.terminate();server.wait(timeout=10);log.close();db.close()
 if os.environ.get('GPANEL_KEEP_FAILED_FIXTURES'):print('Fixture retained: '+str(tmp))
 else:shutil.rmtree(tmp)
