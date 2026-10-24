import argparse,hashlib,json,os,subprocess,sys,time
from pathlib import Path
ap=argparse.ArgumentParser();ap.add_argument('mode',choices=['root','peer']);ap.add_argument('--review-report',required=True);ap.add_argument('--review-sha256',required=True);args=ap.parse_args()
BASE=Path('H:/天俪商城/artifacts/project-organization-20261023');SOURCE=BASE/'currentbafb-materialization-readers-source-r1';OUT=BASE/'currentbafb-m-readers-actual-r1';ADM=BASE/'current41c0-normal-root-admission-r1'
def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(x):return (json.dumps(x,ensure_ascii=False,indent=2)+'\n').encode('utf-8')
def put(path,value):
 with path.open('xb') as f:raw=enc(value);assert f.write(raw)==len(raw)
assert not sys.flags.optimize
pins=(SOURCE/'PINS.json').read_bytes();assert fp(pins)=={'bytes':11849,'sha256':'2bfdc7899cc64b078521d5ec68a90f41ca4fc4f7dd9854f6768fb6439fb72ce0'};packet=json.loads(pins)
assert {p.relative_to(SOURCE).as_posix() for p in SOURCE.rglob('*') if p.is_file()}==set(packet['files'])|{'PINS.json'}
for name,pin in packet['files'].items():assert fp((SOURCE/name).read_bytes())=={k:pin[k] for k in ('bytes','sha256')}
for name,size,sha in [('R100-DUAL-M-READERS-ROOT-SOURCE-BYTE-REVIEW.json',31157,'13c2db36b7f2a3a60c34dbe3cfbb462c8d40d286d03289fe7d603ecb0b33248d'),('R100-DUAL-M-READERS-ROOT-SEMANTIC-REVIEW.json',2211,'f2fd25de4db84b1b9c1fd76cd0a3caf8767a9e75ee0bb332a2e9fe16b07523ee')]:assert fp((ADM/name).read_bytes())=={'bytes':size,'sha256':sha}
review_path=Path(args.review_report);review_raw=review_path.read_bytes();assert fp(review_raw)['sha256']==args.review_sha256;review=json.loads(review_raw)
assert review.get('findings')==[] or review.get('finding_count')==0
cli=json.loads((SOURCE/'CLI.json').read_bytes());argv=cli['Root_argv' if args.mode=='root' else 'peer_argv'];entry=Path(argv[5]);assert entry==SOURCE/('root-reader.py' if args.mode=='root' else 'peer-review.py')
assert argv[argv.index('--outer-pid')+1]=='22536' and argv[argv.index('--result-sha256')+1]=='06291afb846c2043a4ab425a5184c962e3e2fc5e180f5c369bfbd075ca2e9e0a' and argv[argv.index('--freeze-sha256')+1]=='7d4a8c79a358009aff3716ea305d34ea69e8e79de17bb2972973ee384c2c2bb1'
entry_pin={'path':entry.as_posix(),**fp(entry.read_bytes())};OUT.mkdir(exist_ok=True);created=OUT/(args.mode+'-created.json');terminal=OUT/(args.mode+'-terminal.json');stdout=OUT/(args.mode+'-stdout');stderr=OUT/(args.mode+'-stderr');admission=OUT/(args.mode+'-admission.json')
assert not any(p.exists() for p in [created,terminal,stdout,stderr,admission]);actual_out=Path(cli['Root_output' if args.mode=='root' else 'peer_output']);assert not actual_out.exists()
put(admission,{'scope':'ONE_ORIGINAL_COMPLETE_M_'+args.mode.upper()+'_READBACK_ONLY','source_PINS':{'path':(SOURCE/'PINS.json').as_posix(),**fp(pins)},'Root_fixed_byte_and_semantic_Source0':True,'fresh_nonAuthor_report':{'path':review_path.as_posix(),**fp(review_raw)},'entry':entry_pin,'actual_M_PID':22536,'S_or_business_admitted':False})
started=time.time_ns();env=dict(os.environ);env.pop('PYTHONOPTIMIZE',None)
with stdout.open('xb') as out,stderr.open('xb') as err:
 proc=subprocess.Popen(argv,cwd='H:/天俪商城',stdin=subprocess.DEVNULL,stdout=out,stderr=err,env=env);put(created,{'pid':proc.pid,'argv':argv,'started_ns':started,'source':entry_pin});result=proc.wait()
ended={'pid':proc.pid,'native_exit':result,'wait_returncode':proc.returncode,'finished_ns':time.time_ns(),'stdout':{'path':stdout.as_posix(),**fp(stdout.read_bytes())},'stderr':{'path':stderr.as_posix(),**fp(stderr.read_bytes())}};put(terminal,ended);assert {'path':entry.as_posix(),**fp(entry.read_bytes())}==entry_pin
print(json.dumps({'mode':args.mode,'terminal':ended},ensure_ascii=True));raise SystemExit(result)
