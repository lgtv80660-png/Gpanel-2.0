"""Concurrent admission at the last free slot; private disposable session state."""
import argparse,json,os,pathlib,shutil,subprocess,tempfile
ap=argparse.ArgumentParser();ap.add_argument('--php',required=True);ap.add_argument('--ext',required=True);args=ap.parse_args()
root=pathlib.Path(__file__).resolve().parents[1]
tmp=pathlib.Path(tempfile.mkdtemp(prefix='gpanel-admission-',dir=root.parent));state=tmp/'sessions';state.mkdir()
try:
 for i in range(999):(state/f'{i:064x}.json').write_text(json.dumps({'expires':4102444800}))
 worker=tmp/'worker.php';worker.write_text("<?php require "+json.dumps(str(root/'panel_source_relay.php').replace('\\','/'))+"; for($i=0;$i<100;$i++){try{sources_relay_session(bin2hex(random_bytes(32)),function(&$s){$s=['expires'=>time()+60];},true);exit(0);}catch(Throwable $e){if($e->getMessage()==='Session busy'){usleep(10000);continue;}exit($e->getMessage()==='Session storage capacity reached'?2:3);}}exit(4);")
 env={**os.environ,'PANEL_SOURCES_STATE_DIR':str(state)}
 workers=[subprocess.Popen([args.php,'-n',str(worker)],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for _ in range(16)]
 codes=[]
 for p in workers:
  out,err=p.communicate(timeout=20);assert not out and not err,'unexpected worker output';codes.append(p.returncode)
 # Admission is deliberately nonblocking: contenders may receive busy or full.
 assert codes.count(0)==1 and all(code in (0,2,4) for code in codes),codes
 assert len(list(state.glob('*.json')))==1000,'capacity exceeded'
 print('PASS 16 concurrent creators: exactly one admitted; 15 refused (busy/full); 999 existing sessions retained')
finally:shutil.rmtree(tmp)
