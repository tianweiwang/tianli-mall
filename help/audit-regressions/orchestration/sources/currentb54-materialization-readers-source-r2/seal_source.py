"""Author-only static final seal, never executes either complete reader."""
from pathlib import Path
import ast,hashlib,json,os,stat
P=Path(__file__).resolve().parent;O=P.parent;OLD=O/'currentb54-materialization-readers-source-r1'
AUDIT=O/'currentb54-materialization-readers-independent-source-r1';READS={}
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def read(path,expected=None):
 path=Path(path);a=path.lstat();ks=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');nine=lambda x:[getattr(x,k) for k in ks]
 assert stat.S_ISREG(a.st_mode) and not path.is_symlink() and not getattr(a,'st_file_attributes',0)&1024
 with path.open('rb') as f:b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
 z=path.lstat();assert nine(a)==nine(z) and nine(b)==nine(c) and a.st_birthtime_ns==z.st_birthtime_ns==b.st_birthtime_ns==c.st_birthtime_ns and all(nine(a)[i]==nine(b)[i] for i in (0,1,6,7))
 if expected:assert pin(raw)=={k:expected[k] for k in ('bytes','sha256')}
 row={'path':path.as_posix(),**pin(raw),'named9':nine(z),'fd9':nine(c),'birthtime_ns':z.st_birthtime_ns,'EOF':True,'closed':True}
 assert path.as_posix() not in READS or READS[path.as_posix()]==row;READS[path.as_posix()]=row;return raw
def save(name,raw):
 q=P/name;q.parent.mkdir(parents=True,exist_ok=True)
 with q.open('xb') as f:assert f.write(raw)==len(raw)
def defs(raw):
 ls=raw.splitlines(True);return {n.name:b''.join(ls[n.lineno-1:n.end_lineno]) for n in ast.parse(raw).body if isinstance(n,ast.FunctionDef)}
def main():
 assert not (P/'PINS.json').exists()
 report_raw=read(AUDIT/'REPORT.json',{'bytes':4679,'sha256':'86db838ff40474949d99943a836b7e965c51e3a8b0672ed3af3d33667dd3ae5a'});report=json.loads(report_raw)
 assert report['finding_count']==len(report['findings'])==1 and report['findings'][0]['id']=='F1'
 auditrefs={}
 for name,expected in [('REPORT.json',pin(report_raw)),('PINS.json',{'bytes':424,'sha256':'f18ccee6366bc9cd916acf052bd675bc7dbd239912653e779c4c197dcb6a3123'}),('READ-RECORDS.json',{'bytes':81574,'sha256':'654963e302759cf0553bf3bfd15955b28e1a2be93a7c1d311834b6f117a627a8'})]:
  raw=read(AUDIT/name,expected);save('inputs/R1-FINAL-INDEPENDENT-'+name,raw);auditrefs[name]={'path':(AUDIT/name).as_posix(),**pin(raw)}
 oldpin=read(OLD/'PINS.json',report['PINS']);old=json.loads(oldpin)
 assert {q.relative_to(OLD).as_posix() for q in OLD.rglob('*') if q.is_file()}==set(old['files'])|{'PINS.json'}
 for name,row in old['files'].items():read(OLD/name,row)
 checks=json.loads(read(P/'CANDIDATE-CHECKS.json'));modules=checks['programs']
 for name in ['root-reader.py','peer-review.py']:
  before=read(P/'before'/name,modules[name]['before']);after=read(P/name,modules[name]['candidate']);compile(ast.parse(after),name,'exec')
  olddefs=defs(before);newdefs=defs(after)
  if name=='root-reader.py':assert olddefs==newdefs
  else:
   assert all(newdefs[k]==v for k,v in olddefs.items() if k!='main')
   fixed=report['findings'][0];was=("callback_root['status']=="+repr(fixed['consumer_requires'])).encode();new=("callback_root['status']=="+repr(fixed['actual_fixed_producer_and_expected_object'])).encode()
   assert newdefs['main'].count(new)==1 and newdefs['main'].replace(new,was)==olddefs['main'] and was not in after
   expected=next(n.value for n in ast.parse(after).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id=='CALLBACK_ROOT_EXPECTED')
   assert ast.literal_eval(expected)['status']==fixed['actual_fixed_producer_and_expected_object']
 for q in (P/'author-fragments').rglob('*'):
  if q.is_file():assert read(q)==read(OLD/q.relative_to(P))
 for q in (P/'inputs').iterdir():
  if q.is_file() and not q.name.startswith('R1-FINAL-INDEPENDENT-'):assert read(q)==read(OLD/q.relative_to(P))
 design=json.loads((P/'DESIGN.json').read_bytes());design.update(status='R108_EXACT_F1_SOURCE_SUCCESSOR_COMPLETE_NOT_EXECUTED',final_R1_audit=auditrefs['REPORT.json'],final_R1_only_F1=True)
 (P/'DESIGN.json').write_bytes(enc(design))
 (P/'DESIGN.md').write_bytes(b'R108 complete-reader Source successor. Final independent R1 audit has exactly F1; the real B54 status is now required in peer main while its whole-object equality stays intact. Only two exclusive r2 output names also change. All complete current/historical scope, every Root function and29 peer nonmain functions remain raw. No candidate or target executed; new peer/Root actual reader results and S/business remain UNBOUND.\n')
 final={'schema':1,'status':'AUTHOR_R2_EXACT_F1_SOURCE_SUCCESSOR_NOT_EXECUTED_PENDING_NONAUTHOR','F1':report['findings'][0],'actual_R1_independent_final':auditrefs,'before_R1_PINS':{'path':(OLD/'PINS.json').as_posix(),**pin(oldpin)},'modules':modules,'Root_all15_functions_and_peer29_nonmain_raw':True,'peer_main_only_exact_F1_status_reverse_is_R1':True,'both_outputs_only_r2_exclusive_identity':True,'all_current_helpers_and_fixed_input_bodies_raw':True,'historic24_23_22_21_current25_M37_1160_11whole_Tree_all_intersections_retained':True,'actual_M_Core_or_Git_reexecuted':False,'candidate_or_helper_or_targets_executed':False,'actual_root_peer_reader_outputs_PIDs_native_wait':'UNBOUND_NOT_RUN','S_business_admitted':False,'new_nonauthor_r2_review':'PENDING'}
 save('SOURCE-PROOF.json',enc(final));save('REPORT.json',enc(final))
 for q in sorted(P.rglob('*')):
  if q.is_file():read(q)
 for path in list(READS):read(path)
 save('FINAL-INPUT-WINDOWS.json',enc({'files':READS,'all_full_EOF_own_named9_FD9_cross4_birth_first_final':True,'Run_private_M_RESULT_freeze_or_raw_stdio_read':False}))
 current={q.relative_to(P).as_posix():pin(q.read_bytes()) for q in sorted(P.rglob('*')) if q.is_file() and q.relative_to(P).as_posix() not in ('SOURCE-PINS.json','PINS.json')}
 save('SOURCE-PINS.json',enc({'schema':1,'scope':'COMPLETE_FIXED_B54_R2_M_READER_SOURCE_EXACT_F1_ONLY_NOT_RUNTIME_RESULT','files':current}))
 allfiles={q.relative_to(P).as_posix():pin(q.read_bytes()) for q in sorted(P.rglob('*')) if q.is_file()}
 save('PINS.json',enc({'schema':1,'scope':'B54_COMPLETE_M_READER_SOURCE_R2_EXACT_F1_AND_OUTPUT_IDENTITIES','files':allfiles,'member_count':len(allfiles),'namespace_files_including_self':len(allfiles)+1,'runtime':'UNBOUND_NOT_EXECUTED_BY_AUTHOR'}))
 assert {q.relative_to(P).as_posix() for q in P.rglob('*') if q.is_file()}==set(allfiles)|{'PINS.json'}
 print(json.dumps({'root':pin((P/'root-reader.py').read_bytes()),'peer':pin((P/'peer-review.py').read_bytes()),'PINS':pin((P/'PINS.json').read_bytes()),'SOURCE_PINS':pin((P/'SOURCE-PINS.json').read_bytes()),'REPORT':pin((P/'REPORT.json').read_bytes()),'members_with_self':len(allfiles)+1,'Source_inputs':len(READS),'actual_targets':'NOT_EXECUTED'},ensure_ascii=False))
if __name__=='__main__':main()
