from pathlib import Path
import json,subprocess,time,hashlib,sys
D=Path(__file__).resolve().parent
PY='C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
READER = 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-complete-normal-S-readbacks-source-r1/candidate/readback-current6e3850-complete-normal-S-independent.py'
argv=[PY,'-B','-X','utf8',READER,'--run-id','2026102321491304a643','--outer-pid','2388','--prepare-pid','13036','--authorize-completed-s0']
def put(name,value):
 with (D/name).open('x',encoding='utf8',newline='\n') as f:json.dump(value,f,ensure_ascii=False,indent=2);f.write('\n')
def pointer(path):
 raw=path.read_bytes();return {'path':path.as_posix(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
# Runtime admission guard: Source preparation never executes this block.
if sys.flags.optimize:
 raise RuntimeError('OPTIMIZED_PYTHON_FORBIDDEN_BEFORE_ANY_OUTPUT_OR_CHILD')
_reader_raw=Path(READER).read_bytes()
if len(_reader_raw)!=31219 or hashlib.sha256(_reader_raw).hexdigest()!='49a61c6b373ef8e21defa913bb7e109114b2e6ba77bcf47366b1070aef866357':
 raise RuntimeError('EXACT_FULL_PREVIOUSLY_REVIEWED_SUCCESS_READER_REQUIRED')
_s_root=Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-preflight-diagnostic-current6e3850-normal-next-source-r2')
_s_created=json.loads((_s_root/'launcher/normal-start.created.json').read_bytes())
_s_terminal=json.loads((_s_root/'launcher/normal-start.terminal.json').read_bytes())
if set(_s_created)!={'pid','argv','started_ns'} or set(_s_terminal)!={'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'}:
 raise RuntimeError('ORIGINAL_S_CREATED_TERMINAL_EXACT_SCHEMA_REQUIRED')
if type(_s_created['pid']) is not int or type(_s_terminal['pid']) is not int or _s_created['pid']!=2388 or _s_terminal['pid']!=2388:
 raise RuntimeError('SAME_GENUINE_ORIGINAL_S_PID2388_REQUIRED')
if type(_s_terminal['native_exit']) is not int or type(_s_terminal['wait_returncode']) is not int or _s_terminal['native_exit']!=0 or _s_terminal['wait_returncode']!=0:
 raise RuntimeError('ORIGINAL_S_NATIVE0_WAIT0_REQUIRED_S1_AND_LIVE_INELIGIBLE')
if type(_s_created['started_ns']) is not int or type(_s_terminal['finished_ns']) is not int or not 0<_s_created['started_ns']<_s_terminal['finished_ns']:
 raise RuntimeError('ORIGINAL_S_NATIVE_TIME_ORDER_REQUIRED')
if _s_created['argv']!=[PY,'-B','-X','utf8',str(_s_root/'validation/run.py'),'--root-source-pins-sha256','384be3ed685ea338e377f49285fc15eeca1b2d7b1a5d981591e76055413fae00','--setup-sha256','a017b8c5722d0556659f9d03b264e91340b96a42b36a03f20f0c327525255e02']:
 raise RuntimeError('ORIGINAL_S_FROZEN_DRIVER_AND_M_ARGV_REQUIRED')
_context_raw=Path('H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs/2026102321491304a643/actual-run-context.json').read_bytes()
if len(_context_raw)!=12361 or hashlib.sha256(_context_raw).hexdigest()!='0ae1ef4834aac7856fbda99c6d0d209149012daf068bb4e146af9e26382ae0f9':
 raise RuntimeError('GENUINE_FIXED_COMPLETED_S_CONTEXT_FULL_PIN_REQUIRED')
_context=json.loads(_context_raw)
if _context['run_id']!='2026102321491304a643' or _context['source_sha']!='6e3850fe210b2b1e99b2a33baa68123e34aefab6' or _context['freeze_sha256']!='fc567b79882a2ca6ac80fb7f7d1eafacf1281fe71e88c8ce152e590544611608':
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
