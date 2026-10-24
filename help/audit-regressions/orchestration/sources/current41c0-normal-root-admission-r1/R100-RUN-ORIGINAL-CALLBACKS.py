from pathlib import Path
import argparse,hashlib,json,os,subprocess,time
ap=argparse.ArgumentParser();ap.add_argument('--peer-report',required=True);ap.add_argument('--peer-report-sha256',required=True);a=ap.parse_args()
R=Path('H:/天俪商城/artifacts/project-organization-20261023');O=R/'current41c0-normal-root-admission-r1';E=R/'currentbafb-original-source-callback-entry-preparation-r1';S=R/'currentbafb-next-normal-preparation-r3/normal-source'
def fp(b):return {'bytes':len(b),'sha256':hashlib.sha256(b).hexdigest()}
def enc(x):return (json.dumps(x,ensure_ascii=False,indent=2)+'\n').encode('utf-8')
def put(n,b):
 with (O/n).open('xb') as f:assert f.write(b)==len(b)
refs=[(E/'PINS.json',{'bytes':1067,'sha256':'ac51cac2a1e8c178627adeca961493200767a2dbd3bbc8c979ac61aa2c402963'}),(S/'PINS.json',{'bytes':13638,'sha256':'7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}),(O/'R100-FIXED-R3-ENTRY-UI-ROOT-SOURCE-READBACK.json',{'bytes':77087,'sha256':'95b080cf2e3e0a6e53f4d775a4357180510934fb87b4b5cc31b6ff6f86808a10'}),(O/'R100-EIGHT-PHP-ACTUAL-ROOT-READBACK.json',{'bytes':32965,'sha256':'1fc551dbc152bc197c2f2c83ee420f863fd8bd717e6b98191cf39dc87bd531a7'})]
qualified=[]
for p,pin in refs:
 raw=p.read_bytes();assert fp(raw)==pin;qualified.append({'path':p.as_posix(),**pin})
for root in (E,S):
 raw=(root/'PINS.json').read_bytes();pk=json.loads(raw);assert {p.relative_to(root).as_posix() for p in root.rglob('*') if p.is_file()}==set(pk['files'])|{'PINS.json'}
 for n,pin in pk['files'].items():assert fp((root/n).read_bytes())=={k:pin[k] for k in ('bytes','sha256')}
p=Path(a.peer_report);peer=p.read_bytes();assert fp(peer)['sha256']==a.peer_report_sha256;report=json.loads(peer)
assert p==R/'currentbafb-complete-normal-independent-source-r3/REPORT.json' and fp(peer)=={'bytes':5803,'sha256':'aa72c3a7dfcd8a01c5e9736265facb93bf9783b4f1a3b8fc047b98e23c4937af'}
entry_peer=R/'currentbafb-original-callback-entry-independent-source-r1/REPORT.json';entry_raw=entry_peer.read_bytes();assert fp(entry_raw)=={'bytes':3748,'sha256':'beeb41653276bf728970e4d8add2ddd0c620fea2e1d70dc453cc6e6a766108ee'};entry_report=json.loads(entry_raw)
assert report['finding_count']==entry_report['finding_count']==0 and report['runtime_Source_PINS']==entry_report['runtime_Source_PINS']==qualified[1] and entry_report['source_entry_packet_PINS']==qualified[0]
assert not (R/'currentbafb-original-source-callback-actual-r1').exists()
for n in ['source-callback.created.json','source-callback.terminal.json','source-callback.stdout','source-callback.stderr','R100-SOURCE-CALLBACK-ADMISSION.json']:assert not (O/n).exists()
put('R100-SOURCE-CALLBACK-ADMISSION.json',enc({'schema':1,'scope':'TWO_ORIGINAL_FULL_SOURCE_AUTHORITY_CALLBACKS_ONLY_AFTER_ROOT_AND_INDEPENDENT_SOURCE_REVIEW','Root_fixed_refs':qualified,'independent_reports':[{'path':p.as_posix(),**fp(peer)},{'path':entry_peer.as_posix(),**fp(entry_raw)}],'full_current_runtime_and_entry_PINS_full_bytes_and_names_checked':True,'Root_review':'Fixed r3 F2 source plus narrow original entry with genuine three-field runtime PINS and separately observed native9; original namespace/cache/two full callbacks preserved.','M_body_S_main_App_business_admitted':False}))
argv=['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-I','-B','-X','utf8',str(E/'callbacks.py'),'--authorize-root-reviewed-original-full-source-callbacks-only']
env=dict(os.environ);env.pop('PYTHONOPTIMIZE',None);started=time.time_ns();p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env);made={'pid':p.pid,'argv':argv,'started_ns':started};put('source-callback.created.json',enc(made))
stdout,stderr=p.communicate();waited=p.wait();put('source-callback.stdout',stdout);put('source-callback.stderr',stderr);ended={**made,'native_exit':p.returncode,'wait_returncode':waited,'finished_ns':time.time_ns(),'stdout':fp(stdout),'stderr':fp(stderr)};put('source-callback.terminal.json',enc(ended))
print(json.dumps({'pid':p.pid,'native_exit':p.returncode,'wait_returncode':waited,'stdout':fp(stdout),'stderr':fp(stderr)},ensure_ascii=True));assert p.returncode==waited==0 and stderr==b''
