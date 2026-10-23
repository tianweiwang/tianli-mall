from pathlib import Path
import json,subprocess,time,hashlib,sys
D=Path(__file__).resolve().parent
PY='C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
READER='H:/天俪商城/artifacts/project-organization-20261023/current175f-complete-normal-S-readbacks-source-r1/candidate/readback-current175f-complete-normal-S-root.py'
argv=[PY,'-B','-X','utf8',READER,'--run-id','20261023182634096f57','--outer-pid','35944','--prepare-pid','49064','--authorize-completed-s0']
def put(name,value):
 with (D/name).open('x',encoding='utf8',newline='\n') as f:json.dump(value,f,ensure_ascii=False,indent=2);f.write('\n')
def pointer(path):
 raw=path.read_bytes();return {'path':path.as_posix(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
# Runtime admission guard: Source preparation never executes this block.
if sys.flags.optimize:
 raise RuntimeError('OPTIMIZED_PYTHON_FORBIDDEN_BEFORE_ANY_OUTPUT_OR_CHILD')
_reader_raw=Path(READER).read_bytes()
if len(_reader_raw)!=14136 or hashlib.sha256(_reader_raw).hexdigest()!='9e0475c305cc61c9524291da9f1e27ac160de1f3bd66021c677d8e5556677784':
 raise RuntimeError('EXACT_FULL_PREVIOUSLY_REVIEWED_SUCCESS_READER_REQUIRED')
_s_root=Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1')
_s_created=json.loads((_s_root/'launcher/normal-start.created.json').read_bytes())
_s_terminal=json.loads((_s_root/'launcher/normal-start.terminal.json').read_bytes())
if set(_s_created)!={'pid','argv','started_ns'} or set(_s_terminal)!={'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'}:
 raise RuntimeError('ORIGINAL_S_CREATED_TERMINAL_EXACT_SCHEMA_REQUIRED')
if type(_s_created['pid']) is not int or type(_s_terminal['pid']) is not int or _s_created['pid']!=35944 or _s_terminal['pid']!=35944:
 raise RuntimeError('SAME_GENUINE_ORIGINAL_S_PID35944_REQUIRED')
if type(_s_terminal['native_exit']) is not int or type(_s_terminal['wait_returncode']) is not int or _s_terminal['native_exit']!=0 or _s_terminal['wait_returncode']!=0:
 raise RuntimeError('ORIGINAL_S_NATIVE0_WAIT0_REQUIRED_S1_AND_LIVE_INELIGIBLE')
if type(_s_created['started_ns']) is not int or type(_s_terminal['finished_ns']) is not int or not 0<_s_created['started_ns']<_s_terminal['finished_ns']:
 raise RuntimeError('ORIGINAL_S_NATIVE_TIME_ORDER_REQUIRED')
if _s_created['argv']!=[PY,'-B','-X','utf8',str(_s_root/'validation/run.py'),'--root-source-pins-sha256','0516d5f9eff69c4a36ab8b2a012333fbbcd9c2751614330ee9459cb391fe32a4','--setup-sha256','6449487f1da61266fbdfe76260fced9196b398f1cbb92cc59074924d8e76c8aa']:
 raise RuntimeError('ORIGINAL_S_FROZEN_DRIVER_AND_M_ARGV_REQUIRED')
_context_raw=Path('H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs/20261023182634096f57/actual-run-context.json').read_bytes()
if len(_context_raw)!=12301 or hashlib.sha256(_context_raw).hexdigest()!='c56e609828ba3e51e63f69b83e4af49279bdaebee487116728c383e02dae7959':
 raise RuntimeError('GENUINE_FIXED_COMPLETED_S_CONTEXT_FULL_PIN_REQUIRED')
_context=json.loads(_context_raw)
if _context['run_id']!='20261023182634096f57' or _context['source_sha']!='175f3cd6183894185656a2974579e1be39eed7a7' or _context['freeze_sha256']!='4ac3a0b7b35db7a911e9abb61dfaa4a25b0ffaf3bdc79a7c100b48f0836ce6ed':
 raise RuntimeError('SAME_ORIGINAL_RUN_SOURCE_FREEZE_CONTEXT_REQUIRED')
with (D/'reader.stdout').open('xb') as out,(D/'reader.stderr').open('xb') as err:
 p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=out,stderr=err)
 made={'pid':p.pid,'argv':argv,'started_ns':time.time_ns()};put('reader.created.json',made)
 code=p.wait();waited=p.wait()
ended={**made,'native_exit':code,'wait_returncode':waited,'finished_ns':time.time_ns(),'stdout':pointer(D/'reader.stdout'),'stderr':pointer(D/'reader.stderr')}
put('reader.terminal.json',ended)
put('OUTCOME.json',{'created':pointer(D/'reader.created.json'),'terminal':pointer(D/'reader.terminal.json'),'stdout':pointer(D/'reader.stdout'),'stderr':pointer(D/'reader.stderr'),'native_exit':code,'wait_returncode':waited})
print(json.dumps({'pid':p.pid,'native_exit':code,'wait_returncode':waited,'OUTCOME':pointer(D/'OUTCOME.json'),'stdout':pointer(D/'reader.stdout'),'stderr':pointer(D/'reader.stderr')}),flush=True)
sys.exit(code)
