"""Binary proxy tests: loopback endpoints only; no real providers or database."""
import argparse, http.server, re, threading, tempfile, subprocess, shutil, time
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

ap=argparse.ArgumentParser();ap.add_argument('--php',required=True);ap.add_argument('--ext',required=True);args=ap.parse_args()
hits=[]; payload=b'\x00fixture-video-bytes\xff'
class Upstream(http.server.BaseHTTPRequestHandler):
 def log_message(self,*a):pass
 def do_HEAD(self):
  if self.path in ['/nohead','/emptyhead','/head404']:
   hits.append(self.path)
   if self.path in ['/nohead','/head404']:self.send_response(405 if self.path=='/nohead' else 404);self.end_headers()
   else:self.close_connection=True
   return
  self.do_GET()
 def do_GET(self):
  hits.append(self.path)
  if self.path=='/bad':self.send_response(503);self.end_headers();return
  if self.path=='/redirect':self.send_response(302);self.send_header('Location','http://127.0.0.1:1/credential');self.end_headers();return
  if self.path=='/cdn':self.send_response(302);self.send_header('Location','/ok');self.end_headers();return
  if self.path=='/loop':self.send_response(302);self.send_header('Location','/loop');self.end_headers();return
  if self.path=='/html':self.send_response(200);self.send_header('Content-Type','text/html');self.end_headers();self.wfile.write(b'error');return
  if self.path=='/partial':self.send_response(200);self.send_header('Content-Type','video/mp4');self.send_header('Content-Length','9999');self.end_headers();self.wfile.write(b'v'*1024);return
  data=payload;status=200
  content_range=None
  m=re.fullmatch(r'bytes=(\d+)-(\d+)',self.headers.get('Range',''))
  if m:
   start,end=map(int,m.groups());end=min(end,len(payload)-1);data=payload[start:end+1];status=206;content_range=f'bytes {start}-{end}/{len(payload)}'
  self.send_response(status);self.send_header('Content-Type','video/mp4');self.send_header('Content-Length',str(len(data)))
  if status==206:self.send_header('Content-Range',content_range)
  self.send_header('Location','http://secret:password@fixture.invalid');self.send_header('Set-Cookie','provider=secret');self.end_headers()
  if self.command!='HEAD':self.wfile.write(data)
up=http.server.ThreadingHTTPServer(('127.0.0.1',0),Upstream);threading.Thread(target=up.serve_forever,daemon=True).start()
tmp=Path(tempfile.mkdtemp(prefix='vod-proxy-'));root=Path(__file__).resolve().parents[1]
for name in ['panel_vod_relay.php','panel_source_relay.php','panel_sources.php','panel_state_path.php']:shutil.copy(root/name,tmp)
(tmp/'index.php').write_text("""<?php
require __DIR__.'/panel_vod_relay.php';
$paths = $_GET['mode']==='ok' ? ['/ok'] : [('/'.$_GET['mode']), '/ok'];
if ($_GET['mode']==='allbad') $paths=['/bad','/redirect'];
$rows=array_map(fn($p)=>['type'=>'m3u','direct_source'=>'http://fixture.example'.$p],$paths);
$transport=function($target,$method,$range,$headers,$bytes){$target['url']=str_replace('fixture.example','127.0.0.1:"""+str(up.server_port)+"""',$target['url']);$target['port']="""+str(up.server_port)+""";$target['host']='127.0.0.1';$target['ips']=['127.0.0.1'];return vod_http_hop($target,$method,$range,$headers,$bytes);};
$dns=fn($h)=>['93.184.216.34'];$state=[];
$result=vod_stream_rows($rows,'mp4',$_SERVER['REQUEST_METHOD'],$_SERVER['HTTP_RANGE']??'',$state,$transport,$dns);
if(!$result['started']&&empty($result['ok']))http_response_code($result['status']);
""")
proc=subprocess.Popen([args.php,'-n','-d','extension_dir='+args.ext,'-d','extension=curl','-S','127.0.0.1:18979','-t',str(tmp)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
def request(mode,method='GET',headers=None):
 try:
  with urlopen(Request('http://127.0.0.1:18979/?mode='+mode,method=method,headers=headers or {}),timeout=15) as r:return r.status,r.read(),r.headers
 except HTTPError as e:return e.code,e.read(),e.headers
try:
 for _ in range(30):
  try:request('ok');break
  except URLError:time.sleep(.1)
 for mode in ['ok','bad','redirect','html']:
  hits.clear();status,data,headers=request(mode)
  assert status==200 and data==payload,(mode,status,data)
  assert 'Location' not in headers and 'Set-Cookie' not in headers
  assert hits==(['/ok'] if mode=='ok' else ['/'+mode,'/ok']),hits
  print('PASS binary relay '+mode+'; credentials and redirects suppressed')
 status,data,headers=request('bad',headers={'Range':'bytes=2-5'})
 assert status==502 and not data
 print('PASS nonzero unbound Range never changes file')
 status,data,headers=request('ok',headers={'Range':'bytes=2-5'})
 assert status==206 and data==payload[2:6] and headers['Content-Range']==f'bytes 2-5/{len(payload)}'
 print('PASS validated Range forwarded by real curl')
 hits.clear();status,data,headers=request('cdn');assert status==200 and data==payload and hits==['/cdn','/ok']
 print('PASS upstream redirect stays server-side')
 hits.clear();status,data,headers=request('loop');assert status==200 and data==payload and hits==['/loop','/ok']
 print('PASS upstream redirect loop bounded before backup')
 status,data,headers=request('bad',method='HEAD');assert status==200 and data==b'' and headers['Content-Length']==str(len(payload))
 print('PASS HEAD falls back without response body')
 for mode in ['nohead','emptyhead','head404']:
  hits.clear();status,data,headers=request(mode,method='HEAD')
  assert status==200 and data==b'' and headers['Content-Length']==str(len(payload)) and hits==['/'+mode,'/'+mode],(mode,status,hits)
  print('PASS HEAD uses one-byte GET metadata when gateway '+mode)
 status,data,headers=request('ok',headers={'Range':'bytes=0-'})
 assert status==206 and data==payload and headers['Content-Range']==f'bytes 0-{len(payload)-1}/{len(payload)}'
 print('PASS complete 200 safely satisfies open Range from zero')
 hits.clear()
 from http.client import IncompleteRead
 try:request('partial');raise AssertionError('truncation expected')
 except IncompleteRead as e:assert e.partial==b'v'*1024
 assert hits==['/partial'],hits
 print('PASS failure after first bytes never splices a backup file')
 status,data,headers=request('allbad');assert status==502 and not data
 print('PASS exhausted sources return generic 502 without redirect loop')
finally:
 proc.terminate();proc.wait(timeout=5);up.shutdown();shutil.rmtree(tmp)
