"""Test Railway with synthetic accounts; never follows upstream/media redirects.
Set TEST_BASE_URL, TEST_ADMIN_PASSWORD and TEST_CLIENT_PASSWORD in the environment.
"""
import http.cookiejar,json,os,re,sys
from urllib.request import Request,build_opener,HTTPCookieProcessor,HTTPRedirectHandler
from urllib.parse import urlencode,quote
from urllib.error import HTTPError,URLError

class NoRedirect(HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
base=os.environ['TEST_BASE_URL'].rstrip('/')
admin_password=os.environ['TEST_ADMIN_PASSWORD'];client_password=os.environ['TEST_CLIENT_PASSWORD']
client=build_opener(NoRedirect(),HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(path,body=None):
 data=urlencode(body).encode() if body is not None else None
 try:
  with client.open(Request(base+path,data=data),timeout=20) as response:return response.status,response.read().decode(),dict(response.headers)
 except HTTPError as error:return error.code,error.read().decode(),dict(error.headers)
 except URLError:raise RuntimeError('Connection failed; credentials suppressed') from None
def check(value,name):
 if not value:raise RuntimeError(name)
 print('PASS '+name,flush=True)
try:
 status,body,_=request('/health.php');check(status==200 and json.loads(body)['environment']=='test','PHP/Apache/MySQL readiness after migrations')
 for path in ['/mysql.sql','/.git/config','/config.php','/migrate_v2.php','/deploy/initialize-test.php','/__test_provider/player_api.php']:
  check(request(path)[0] in [403,404], 'private route denied '+path)
 check(request('/panel_diagnostics.php')[0]==401,'admin diagnostic requires login')
 status,body,_=request('/player_api.php?username=unknown&password=invalid');check(json.loads(body)['user_info']['auth']==0,'Xtream invalid authentication refused')
 status,body,_=request('/admin.php');check(status==200,'admin login route renders')
 token=re.search(r'name="csrf_token" value="([a-f0-9]+)"',body).group(1)
 status,body,_=request('/admin.php',{'csrf_token':token,'login_btn':'1','username':'gpanel-test-admin','password':admin_password})
 check(status==302,'admin login redirects after authentication')
 status,body,_=request('/admin.php')
 check(status==200 and 'Gestion Clients' in body,'synthetic admin login succeeds')
 status,body,_=request('/panel_diagnostics.php');check(status==200 and json.loads(body)['orphan_streams']==0,'authenticated diagnostic and MySQL catalogue')
 credentials=urlencode({'username':'gpanel-test-client','password':client_password})
 for action in ['get_live_categories','get_vod_categories','get_series_categories','get_live_streams','get_vod_streams','get_series']:
  status,body,_=request('/player_api.php?'+credentials+'&action='+action)
  check(status==200 and len(json.loads(body))==1,'Xtream fixture '+action)
 status,body,_=request('/player_api.php?'+credentials+'&action=get_vod_info&vod_id=2')
 check(status==200 and json.loads(body)['movie_data'],'VOD fixture metadata via loopback only')
 series=[]
 for _ in range(2):
  status,body,_=request('/player_api.php?'+credentials+'&action=get_series_info&series_id=3');data=json.loads(body)
  series.append(data['episodes']['1'][0]['id'])
 check(series==['4','4'],'on-demand series cached with stable episode ID')
 status,body,_=request('/get.php?'+credentials)
 check(status==200 and body.startswith('#EXTM3U') and body.count('#EXTINF')==3,'synthetic M3U playlist')
 for kind,stream,ext in [('live','1','ts'),('movie','2','mp4'),('series','4','mkv')]:
  path='/'+kind+'/gpanel-test-client/'+quote(client_password,safe='')+'/'+stream+'.'+ext
  status,body,headers=request(path)
  location=next((value for key,value in headers.items() if key.lower()=='location'),'')
  check(status==302 and location.startswith('http://127.0.0.1:'),'Apache rewrite and '+kind+' fixture redirect, not followed (status '+str(status)+')')
 print('PASS deployment smoke tests; real media playback NOT tested')
except Exception as error:
 print('FAIL '+(str(error) if isinstance(error,RuntimeError) else 'Unexpected response; credentials suppressed'),file=sys.stderr)
 sys.exit(1)
