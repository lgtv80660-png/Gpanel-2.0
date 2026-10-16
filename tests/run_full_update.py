"""Local validation only. No real DB, provider or deployment access.

python tests/run_full_update.py --php PATH/php --ext PATH/ext
Writes test-results/{validation.txt,results.json}. HTTP fixtures are disposable.
"""
import argparse, json, os, subprocess, tempfile
from pathlib import Path

ap=argparse.ArgumentParser();ap.add_argument('--php',required=True);ap.add_argument('--ext',required=True);ap.add_argument('--http',action='store_true');args=ap.parse_args()
root=Path(__file__).resolve().parents[1];out=root/'test-results';out.mkdir(exist_ok=True)
tmp=root.parent/'full-test-state';tmp.mkdir(exist_ok=True)
env=os.environ.copy();env.update(GPANEL_TEST_TMP=str(tmp),TMP=str(tmp),TEMP=str(tmp),TMPDIR=str(tmp))
php=[args.php,'-n','-d','extension_dir='+args.ext,'-d','extension=pdo_sqlite','-d','extension=mbstring','-d','extension=intl']
results=[];lines=[]
def run(name,cmd,timeout=90):
    try:
        p=subprocess.run(cmd,cwd=root,env=env,text=True,capture_output=True,timeout=timeout,encoding='utf-8',errors='replace');code=p.returncode;output=p.stdout+p.stderr
    except subprocess.TimeoutExpired as e:code=124;output='TIMEOUT\n'+str(e)
    results.append({'name':name,'exit_code':code,'output':output});lines.append('\n=== '+name+' (exit '+str(code)+') ===\n'+output)
    print(name+': '+('PASS' if code==0 else 'FAIL'),flush=True)

for file in sorted(root.rglob('*.php')):
    if 'test-results' not in file.parts:run('lint '+str(file.relative_to(root)),php+['-l',str(file)])
for file in ['sources_backups.php','sources_suggestions.php','source_relay.php','vod_sources.php','vod_transport.php','movie_groups_smoke.php','content_v2.php','import_v2.php','import_selective.php','series_cache.php','revalidation.php']:
    run(file,php+[str(root/'tests'/file)])
for file in ['sources-backups.js','vod-sources.js']:
    run('JavaScript syntax '+file,['node','--check',str(root/'assets'/file)])
run('sources migration PLAN',php+['migrate_sources.php'])
run('VOD migration PLAN',php+['migrate_vod_sources.php'])
if args.http:
    import sys
    for file in ['sources_http.py','full_update_http.py','vod_proxy.py','run_regression.py']:
        run(file,[sys.executable,str(root/'tests'/file),'--php',args.php,'--ext',args.ext],180)
(out/'validation.txt').write_text('G-PANEL 2.0 FULL UPDATE — local fixtures, no real playback\n'+''.join(lines),encoding='utf-8')
(out/'results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
failed=[r['name'] for r in results if r['exit_code']]
print(f'{len(results)-len(failed)}/{len(results)} commands passed; failures: {failed}',flush=True)
raise SystemExit(bool(failed))
