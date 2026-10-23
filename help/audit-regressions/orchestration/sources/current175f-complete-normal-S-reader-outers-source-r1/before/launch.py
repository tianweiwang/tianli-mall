from pathlib import Path
import json,subprocess,time,hashlib,sys
D=Path(__file__).resolve().parent
PY='C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
READER='H:/天俪商城/artifacts/project-organization-20261023/current175f-actual-materialization-independent-source-r2/candidate/review.py'
argv=[PY,'-B','-X','utf8',READER,'--result-bytes','46750425','--result-sha256','6449487f1da61266fbdfe76260fced9196b398f1cbb92cc59074924d8e76c8aa','--freeze-bytes','10985209','--freeze-sha256','4ac3a0b7b35db7a911e9abb61dfaa4a25b0ffaf3bdc79a7c100b48f0836ce6ed','--outer-pid','11268']
def put(name,value):
 with (D/name).open('x',encoding='utf8',newline='\n') as f:json.dump(value,f,ensure_ascii=False,indent=2);f.write('\n')
def pointer(path):
 raw=path.read_bytes();return {'path':path.as_posix(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
with (D/'reader.stdout').open('xb') as out,(D/'reader.stderr').open('xb') as err:
 p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=out,stderr=err)
 made={'pid':p.pid,'argv':argv,'started_ns':time.time_ns()};put('reader.created.json',made)
 code=p.wait();waited=p.wait()
ended={**made,'native_exit':code,'wait_returncode':waited,'finished_ns':time.time_ns(),'stdout':pointer(D/'reader.stdout'),'stderr':pointer(D/'reader.stderr')}
put('reader.terminal.json',ended)
put('OUTCOME.json',{'created':pointer(D/'reader.created.json'),'terminal':pointer(D/'reader.terminal.json'),'stdout':pointer(D/'reader.stdout'),'stderr':pointer(D/'reader.stderr'),'native_exit':code,'wait_returncode':waited})
print(json.dumps({'pid':p.pid,'native_exit':code,'wait_returncode':waited,'OUTCOME':pointer(D/'OUTCOME.json'),'stdout':pointer(D/'reader.stdout'),'stderr':pointer(D/'reader.stderr')}),flush=True)
sys.exit(code)
