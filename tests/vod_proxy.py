"""Binary proxy tests: loopback endpoints only; no real providers or database."""
import argparse, http.server, threading, tempfile, subprocess, shutil, time
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

ap=argparse.ArgumentParser();ap.add_argument('--php',required=True);ap.add_argument('--ext',required=True);args=ap.parse_args()
hits=[]; payload=b'\x00fixture-video-bytes\xff'
class Upstream(http.server.BaseHTTPRequestHandler):
 def log_message(self,*a):pass
 def do_HEAD(self):self.do_GET()
 def do_GET(self):
  hits.append(self.path)
  if self.path=='/bad':self.send_response(503);self.end_headers();return
  if self.path=='/redirect':self.send_response(302);self.send_header('Location','http://127.0.0.1:1/credential');self.end_headers();return
  if self.path=='/cdn':self.send_response(302);self.send_header('Location','/ok');self.end_headers();return
  if self.path=='/loop':self.send_response(302);self.send_header('Location','/loop');self.end_headers();return
  if self.path=='/html':self.send_response(200);self.send_header('Content-Type','text/html');self.end_headers();self.wfile.write(b'error');return
  if self.path=='/partial':self.send_response(200);self.send_header('Content-Type','video/mp4');self.send_header('Content-Length','100');self.end_headers();self.wfile.write(b'first');return
  data=payload;status=200
  if self.headers.get('Range')=='bytes=2-5':data=payload[2:6];status=206
  self.send_response(status);self.send_header('Content-Type','video/mp4');self.send_header('Content-Length',str(len(data)))
  if status==206:self.send_header('Content-Range',f'bytes 2-5/{len(payload)}')
  self.send_header('Location','http://secret:password@fixture.invalid');self.send_header('Set-Cookie','provider=secret');self.end_headers()
  if self.command!='HEAD':self.wfile.write(data)
up=http.server.ThreadingHTTPServer(('127.0.0.1',0),Upstream);threading.Thread(target=up.serve_forever,daemon=True).start()
tmp=Path(tempfile.mkdtemp(prefix='vod-proxy-'));root=Path(__file__).resolve().parents[1]
shutil.copy(root/'panel_vod_relay.php',tmp)
(tmp/'index.php').write_text("""<?php
require __DIR__.'/panel_vod_relay.php';
$paths = $_GET['mode']==='ok' ? ['/ok'] : [('/'.$_GET['mode']), '/ok'];
if ($_GET['mode']==='allbad') $paths=['/bad','/redirect'];
$rows=array_map(fn($p)=>['type'=>'m3u','direct_source'=>'http://127.0.0.1:"""+str(up.server_port)+"""'.$p],$paths);
vod_relay($rows,'mp4',$_SERVER['REQUEST_METHOD'],$_SERVER['HTTP_RANGE']??'');
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
 assert status==206 and data==payload[2:6] and headers['Content-Range']==f'bytes 2-5/{len(payload)}'
 print('PASS Range survives primary failure')
 hits.clear();status,data,headers=request('cdn');assert status==200 and data==payload and hits==['/cdn','/ok']
 print('PASS upstream redirect stays server-side')
 hits.clear();status,data,headers=request('loop');assert status==200 and data==payload and len(hits)==5
 print('PASS upstream redirect loop bounded before backup')
 status,data,headers=request('bad',method='HEAD');assert status==200 and data==b'' and headers['Content-Length']==str(len(payload))
 print('PASS HEAD falls back without response body')
 hits.clear()
 from http.client import IncompleteRead
 try:request('partial');raise AssertionError('truncation expected')
 except IncompleteRead as e:assert e.partial==b'first'
 assert hits==['/partial'],hits
 print('PASS failure after first bytes never splices a backup file')
 status,data,headers=request('allbad');assert status==502 and not data
 print('PASS exhausted sources return generic 502 without redirect loop')
finally:
 proc.terminate();proc.wait(timeout=5);up.shutdown();shutil.rmtree(tmp)
