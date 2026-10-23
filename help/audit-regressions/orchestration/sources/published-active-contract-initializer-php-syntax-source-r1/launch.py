from pathlib import Path
import subprocess,json,time,hashlib,sys
SOURCE_ROOT=Path(__file__).resolve().parent
OUT=SOURCE_ROOT.parent/'published-active-contract-initializer-php-syntax-actual-r1'
OUT.mkdir()
PY='C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
def put(name,value):
 with (OUT/name).open('x',encoding='utf-8',newline='\n') as f:json.dump(value,f,ensure_ascii=False,indent=2);f.write('\n')
argv=[PY,'-B','-X','utf8',str(SOURCE_ROOT/'run.py')]
with (OUT/'outer.stdout').open('xb') as out,(OUT/'outer.stderr').open('xb') as err:
 p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=out,stderr=err)
 made={'pid':p.pid,'argv':argv,'started_ns':time.time_ns()};put('outer.created.json',made)
 rc=p.wait();waited=p.wait()
terminal={**made,'native_exit':rc,'wait_returncode':waited,'finished_ns':time.time_ns()}
for name in ['stdout','stderr']:
 raw=(OUT/('outer.'+name)).read_bytes();terminal[name+'_bytes']=len(raw);terminal[name+'_sha256']=hashlib.sha256(raw).hexdigest()
put('outer.terminal.json',terminal)
print(json.dumps(terminal));sys.exit(rc)
