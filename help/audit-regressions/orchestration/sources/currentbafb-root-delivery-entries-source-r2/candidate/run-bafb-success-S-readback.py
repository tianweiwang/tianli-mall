import argparse,hashlib,json,os,re,subprocess,sys,time
from pathlib import Path
ap=argparse.ArgumentParser();ap.add_argument('mode',choices=['root','peer'])
ap.add_argument('--run-id',required=True);ap.add_argument('--outer-pid',required=True,type=int);ap.add_argument('--prepare-pid',required=True,type=int)
ap.add_argument('--context-bytes',required=True,type=int);ap.add_argument('--context-sha256',required=True);ap.add_argument('--authorize-completed-s0',required=True,action='store_true');args=ap.parse_args()
BASE=Path('H:/天俪商城/artifacts/project-organization-20261023');SOURCE=BASE/'currentbafb-complete-normal-S-readers-source-r1';OUT=BASE/'currentbafb-success-S-readers-native-actual-r1';ADM=BASE/'current41c0-normal-root-admission-r1'
def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(x):return (json.dumps(x,ensure_ascii=False,indent=2)+'\n').encode('utf-8')
def put(path,value):
 with path.open('xb') as f:raw=enc(value);assert f.write(raw)==len(raw)
assert not sys.flags.optimize
assert args.authorize_completed_s0 and args.outer_pid>0 and args.prepare_pid>0 and args.context_bytes>0 and args.outer_pid!=args.prepare_pid
assert re.fullmatch(r'[0-9]{14}[a-f0-9]{6}',args.run_id) and re.fullmatch(r'[a-f0-9]{64}',args.context_sha256)
assert args.run_id not in ('20261023124755f45a9e','202610231609185fbd23','20261023182634096f57','2026102321491304a643','2026102402025362a539','2026102403521460e674','2026102406240181bf85','20261024114233c6e65e')
NORMAL=BASE/'currentbafb-next-normal-preparation-r3/normal-source'
# This must precede any Run/context read, output creation and native process.
assert (NORMAL/'launcher/normal-start.terminal.json').is_file(),'Original S is still live or not terminal; reader invocation forbidden'
created=OUT/(args.mode+'-created.json');terminal=OUT/(args.mode+'-terminal.json');stdout=OUT/(args.mode+'-stdout');stderr=OUT/(args.mode+'-stderr');admission=OUT/(args.mode+'-admission.json')
assert not any(p.exists() for p in [created,terminal,stdout,stderr,admission])
actual_out=SOURCE/('actual-root-readback-r1' if args.mode=='root' else 'actual-peer-readback-r1');assert not actual_out.exists()
pins=(SOURCE/'PINS.json').read_bytes();assert fp(pins)=={'bytes':6198,'sha256':'9c642f23008f1ef245b90074ca15fc47e8922a9dd6cfd99138b580e299512a90'};packet=json.loads(pins)
allowed_actual=(SOURCE/'actual-root-readback-r1',SOURCE/'actual-peer-readback-r1')
source_names={p.relative_to(SOURCE).as_posix() for p in SOURCE.rglob('*') if p.is_file() and not any(p.is_relative_to(root) for root in allowed_actual)}
assert len(packet['files'])==41 and source_names==set(packet['files'])|{'PINS.json'}
for name,pin in packet['files'].items():
 assert not any((SOURCE/name).is_relative_to(root) for root in allowed_actual)
 assert fp((SOURCE/name).read_bytes())=={k:pin[k] for k in ('bytes','sha256')}
for root in allowed_actual:
 if root.exists():assert root.is_dir() and {p.relative_to(root).as_posix() for p in root.rglob('*') if p.is_file()}=={'REPORT.json','INPUTS.json'}
source_admission_path=ADM/'R100-SUCCESS-S-READERS-SOURCE-ADMISSION.json';source_admission_raw=source_admission_path.read_bytes()
assert fp(source_admission_raw)=={'bytes':3277,'sha256':'7ea72ea12287770828e25460c65a36ce6610450dccd9652e37130988a1841196'};source_admission=json.loads(source_admission_raw)
assert source_admission['scope']=='ADMIT_COMPLETE_SUCCESS_S_DUAL_READER_SOURCE_ONLY_WAIT_REAL_COMPLETED_S0_BEFORE_ANY_RUNTIME_INVOCATION'
assert source_admission['source_PINS']=={'path':(SOURCE/'PINS.json').as_posix(),**fp(pins)} and source_admission['all_original_full_scopes_and_actual_producer_schemas_preserved'] is True
for name in ('source_REPORT','Root_full_byte_inverse_review','Root_full_semantic_review','fresh_nonAuthor_REPORT','fresh_nonAuthor_PINS'):
 row=source_admission[name];assert fp(Path(row['path']).read_bytes())=={k:row[k] for k in ('bytes','sha256')}
review_path=Path(source_admission['fresh_nonAuthor_REPORT']['path']);review_raw=review_path.read_bytes();review=json.loads(review_raw);assert review['findings']==[]
cli=json.loads((SOURCE/'CLI.json').read_bytes());argv=cli['Root_argv' if args.mode=='root' else 'peer_argv'][:];entry=Path(argv[5]);assert entry==SOURCE/'candidate'/('root-reader.py' if args.mode=='root' else 'peer-reader.py')
assert argv[:5]==['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-I','-B','-X','utf8']
assert argv[6:]==['--run-id','<NEW_GENUINE_COMPLETED_S0_RUN>','--outer-pid','<ORIGINAL_S_PID>','--prepare-pid','<ORIGINAL_DISTINCT_PREPARE_PID>','--context-bytes','<REAL_CONTEXT_BYTES>','--context-sha256','<REAL_CONTEXT_SHA256>','--authorize-completed-s0']
for option,value in [('--run-id',args.run_id),('--outer-pid',str(args.outer_pid)),('--prepare-pid',str(args.prepare_pid)),('--context-bytes',str(args.context_bytes)),('--context-sha256',args.context_sha256)]:argv[argv.index(option)+1]=value
entry_pin={'path':entry.as_posix(),**fp(entry.read_bytes())};assert entry_pin==source_admission['Root_source' if args.mode=='root' else 'peer_source']
helper=SOURCE/'candidate/finite-helper.py';assert {'path':helper.as_posix(),**fp(helper.read_bytes())}==source_admission['finite_helper']
original_created=json.loads((NORMAL/'launcher/normal-start.created.json').read_bytes());original_terminal=json.loads((NORMAL/'launcher/normal-start.terminal.json').read_bytes())
assert set(original_created)=={'pid','argv','started_ns'} and set(original_terminal)=={'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'}
assert original_created['pid']==original_terminal['pid']==args.outer_pid and original_terminal['native_exit']==original_terminal['wait_returncode']==0
assert 0<original_created['started_ns']<original_terminal['finished_ns']
assert original_created['argv']==['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-B','-X','utf8',str(NORMAL/'validation/run.py'),'--root-source-pins-sha256','e40e95ba18acbdd3d42843129535be813f471b5bb1b45e018ba3f453466cd920','--setup-sha256','06291afb846c2043a4ab425a5184c962e3e2fc5e180f5c369bfbd075ca2e9e0a']
for stream in ('stdout','stderr'):
 row=original_terminal[stream];assert set(row)=={'path','bytes','sha256'} and Path(row['path'])==NORMAL/('launcher/normal-start.'+stream) and type(row['bytes']) is int and row['bytes']>=0 and re.fullmatch('[a-f0-9]{64}',row['sha256'])
assert original_terminal['stderr']['bytes']==0 and original_terminal['stderr']['sha256']==hashlib.sha256(b'').hexdigest()
prepare_created=json.loads((NORMAL/'validation/native-normal-start-assets-r1/001-prepare.created.json').read_bytes());prepare_terminal=json.loads((NORMAL/'validation/native-normal-start-assets-r1/001-prepare.terminal.json').read_bytes())
assert set(prepare_created)=={'argv','pid'} and set(prepare_terminal)=={'pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256'}
assert type(prepare_created['pid']) is int and type(prepare_terminal['pid']) is int and prepare_created['pid']==prepare_terminal['pid']==args.prepare_pid
assert type(prepare_terminal['native_exit']) is int and type(prepare_terminal['wait_returncode']) is int and prepare_terminal['native_exit']==prepare_terminal['wait_returncode']==0
prepare_argv=prepare_created['argv'];assert type(prepare_argv) is list and len(prepare_argv)==10
assert Path(prepare_argv[0])==Path(sys.executable)==Path('C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe') and prepare_argv[1:4]==['-B','-X','utf8']
assert Path(prepare_argv[4])==NORMAL/'validation/actual-profile/ms4-host.py' and prepare_argv[5:7]==['prepare','--binding']
assert Path(prepare_argv[7])==NORMAL/'validation/materialize-freeze-assets-r1/freeze-binding.json' and prepare_argv[8]=='--run-root' and Path(prepare_argv[9])==BASE.parents[1]/'artifacts/ms4-aftersale-review-normal-runs'
for stream in ('stdout','stderr'):
 count=prepare_terminal[stream+'_bytes'];digest=prepare_terminal[stream+'_sha256'];assert type(count) is int and count>=0 and type(digest) is str and re.fullmatch('[a-f0-9]{64}',digest)
 if count==0:assert digest==hashlib.sha256(b'').hexdigest()
assert prepare_terminal['stderr_bytes']==0 and prepare_terminal['stderr_sha256']==hashlib.sha256(b'').hexdigest()
run=BASE.parents[1]/'artifacts/ms4-aftersale-review-normal-runs'/args.run_id;context_path=run/'actual-run-context.json';context_raw=context_path.read_bytes()
assert fp(context_raw)=={'bytes':args.context_bytes,'sha256':args.context_sha256};context=json.loads(context_raw)
assert context['run_id']==args.run_id and context['source_sha']=='bafb6305062aeb6b6e945c21da572863062ca480' and context['freeze_sha256']=='7d4a8c79a358009aff3716ea305d34ea69e8e79de17bb2972973ee384c2c2bb1'
assert Path(context['run_dir'])==run and Path(context['prep_dir'])==NORMAL/'validation/actual-profile' and Path(context['repo_dir'])==BASE.parents[1]/'worktrees/ms4-aftersale-normal-check'
OUT.mkdir(exist_ok=True)
put(admission,{'scope':'ONE_ORIGINAL_COMPLETE_SUCCESS_S_'+args.mode.upper()+'_READBACK_ONLY','source_PINS':{'path':(SOURCE/'PINS.json').as_posix(),**fp(pins)},'Root_fixed_byte_and_semantic_Source0':True,'Root_Source_admission':{'path':source_admission_path.as_posix(),**fp(source_admission_raw)},'fresh_nonAuthor_report':{'path':review_path.as_posix(),**fp(review_raw)},'entry':entry_pin,'original_S_PID':args.outer_pid,'original_prepare_PID':args.prepare_pid,'original_S_natural_native_wait':0,'actual_context':{'path':context_path.as_posix(),**fp(context_raw)},'actual_reader_output':actual_out.as_posix(),'Source_namespace_exclusions_only':[p.as_posix() for p in allowed_actual],'full_business_UI_A0_release_admitted':False})
started=time.time_ns();env=dict(os.environ);env.pop('PYTHONOPTIMIZE',None)
with stdout.open('xb') as out,stderr.open('xb') as err:
 proc=subprocess.Popen(argv,cwd='H:/天俪商城',stdin=subprocess.DEVNULL,stdout=out,stderr=err,env=env);put(created,{'pid':proc.pid,'argv':argv,'started_ns':started,'source':entry_pin});result=proc.wait()
ended={'pid':proc.pid,'native_exit':result,'wait_returncode':proc.returncode,'finished_ns':time.time_ns(),'stdout':{'path':stdout.as_posix(),**fp(stdout.read_bytes())},'stderr':{'path':stderr.as_posix(),**fp(stderr.read_bytes())}}
readback_outputs={};readback_error=None
try:
 if result==0:
  assert actual_out.is_dir() and {p.relative_to(actual_out).as_posix() for p in actual_out.rglob('*') if p.is_file()}=={'REPORT.json','INPUTS.json'}
  for name in ('REPORT.json','INPUTS.json'):
   path=actual_out/name
   before=path.stat();assert path.is_file() and not path.is_symlink()
   with path.open('rb') as handle:raw=handle.read();assert handle.read(1)==b''
   after=path.stat();assert (before.st_mode,before.st_ino,before.st_dev,before.st_size,before.st_mtime_ns,before.st_ctime_ns)==(after.st_mode,after.st_ino,after.st_dev,after.st_size,after.st_mtime_ns,after.st_ctime_ns) and len(raw)==before.st_size
   readback_outputs[name]={'path':path.as_posix(),**fp(raw),'EOF':True}
except (OSError,ValueError,AssertionError) as error:readback_error=type(error).__name__
ended['actual_readback_outputs']=readback_outputs;ended['actual_readback_output_error']=readback_error
put(terminal,ended);assert {'path':entry.as_posix(),**fp(entry.read_bytes())}==entry_pin
if result==0:assert readback_error is None and set(readback_outputs)=={'REPORT.json','INPUTS.json'} and ended['stderr']['bytes']==0
print(json.dumps({'mode':args.mode,'terminal':ended},ensure_ascii=True));raise SystemExit(result)
