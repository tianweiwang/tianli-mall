"""R103 Source-only F1 exact literal/current path-pin successor. No target execution."""
from pathlib import Path
import ast,copy,difflib,hashlib,json,os,re,stat
P=Path(__file__).resolve().parent;ORG=P.parent;OLD=ORG/'currentb54-next-normal-preparation-r1/normal-source';OUT=P/'normal-source';AD=ORG/'current41c0-normal-root-admission-r1'
READS={}
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def read(p,expected=None):
 p=Path(p);a=p.lstat();keys=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');stamp=lambda s:[getattr(s,k) for k in keys]
 assert stat.S_ISREG(a.st_mode) and not p.is_symlink() and not getattr(a,'st_file_attributes',0)&1024
 with p.open('rb') as f:b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
 z=p.lstat();assert stamp(a)==stamp(z) and stamp(b)==stamp(c) and a.st_birthtime_ns==z.st_birthtime_ns==b.st_birthtime_ns==c.st_birthtime_ns and all(stamp(a)[i]==stamp(b)[i] for i in (0,1,6,7))
 if expected:assert pin(raw)=={k:expected[k] for k in ('bytes','sha256')}
 row={'path':p.as_posix(),**pin(raw),'named9':stamp(z),'fd9':stamp(c),'birthtime_ns':z.st_birthtime_ns,'EOF':True,'closed':True};assert row['path'] not in READS or READS[row['path']]==row;READS[row['path']]=row;return raw
def obj(p,expected=None):return json.loads(read(p,expected))
def pointer(p):return {'path':Path(p).as_posix(),**pin(read(p))}
def save(name,raw):
 p=OUT/name;p.parent.mkdir(parents=True,exist_ok=True)
 with p.open('xb') as f:assert f.write(raw)==len(raw)
 assert p.read_bytes()==raw
def once(s,a,b):assert s.count(a)==1,(a[:100],s.count(a));return s.replace(a,b,1)
def defs(s):return {n.name:ast.get_source_segment(s,n) for n in ast.parse(s).body if isinstance(n,ast.FunctionDef)}
def statement(s,name):return ast.get_source_segment(s,next(n for n in ast.parse(s).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id==name))
def const(s,name,v):return once(s,statement(s,name),name+' = '+repr(v))
def patch(before,after):return ''.join(''.join(difflib.unified_diff(before[n].decode().splitlines(True),after[n].decode().splitlines(True),fromfile='a/'+n,tofile='b/'+n)) for n in before if before[n]!=after[n]).encode()
def replay(before,raw):
 result=dict(before);lines=raw.decode().splitlines(True);i=0;hunks=0
 while i<len(lines):
  assert lines[i].startswith('--- a/') and lines[i+1].startswith('+++ b/');name=lines[i][6:].rstrip('\r\n');i+=2;source=result[name].decode().splitlines(True);out=[];at=0
  while i<len(lines) and lines[i].startswith('@@ '):
   m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\r\n]*\r?\n',lines[i]);assert m
   oldn,newn=int(m[2] or 1),int(m[4] or 1);start=int(m[1])-(oldn!=0);assert start>=at;out.extend(source[at:start]);at=start;i+=1;a=b=0;hunks+=1
   while i<len(lines) and not lines[i].startswith(('@@ ','--- a/')):
    line=lines[i];i+=1;assert line[:1] in (' ','+','-')
    if line[0] in ' -':assert source[at]==line[1:];at+=1;a+=1
    if line[0] in ' +':out.append(line[1:]);b+=1
   assert (a,b)==(oldn,newn)
  out.extend(source[at:]);result[name]=''.join(out).encode()
 return result,hunks
def main():
 assert not (OUT/'PINS.json').exists()
 design_path=AD/'R103-B54-R2-SOURCE-F1-DESIGN-ADOPTION.json';design=obj(design_path,{'bytes':2434,'sha256':'f732bc3e19c04aeb67db2b96fa5f797f5159a516cfee370559178660b136060a'})
 finding_path=Path(design['F1']['path']);finding=obj(finding_path,design['F1'])
 independent_path=ORG/'currentb54-complete-normal-independent-source-r1/REPORT.json';independent=obj(independent_path,{'bytes':7522,'sha256':'45d18d99dfb62ef5e2e2cb249f1a09ca5ca3f8209feca71ec51c229db65b5f9c'})
 stage=obj(Path(design['actual_producer']['path']),design['actual_producer'])
 correct=design['correct_exact_scope'];wrong=finding['expected_literal_in_candidate'];assert stage['scope']==finding['actual_producer_literal']==correct and wrong!=correct
 prior=obj(OLD/'PINS.json',design['before_R1_core']);assert prior['source_sha']==design['HEAD'] and prior['parent']==design['PARENT']
 originals={n:read(OLD/n,row) for n,row in prior['files'].items()};before={**originals,'PINS.json':read(OLD/'PINS.json')};after=dict(originals)
 outer_before=obj(Path(design['before_R1_outer']['path']),design['before_R1_outer'])
 assert set(p.relative_to(OLD.parent).as_posix() for p in OLD.parent.rglob('*') if p.is_file())==set(outer_before['files'])|{'FINAL-PINS.json'}
 for n,row in outer_before['files'].items():read(OLD.parent/n,row)
 for name,raw in before.items():
  q=P/'before-source'/name;q.parent.mkdir(parents=True,exist_ok=True)
  with q.open('xb') as f:f.write(raw)
 for name,path in [('R103-DESIGN-ADOPTION.json',design_path),('R103-F1-ROOT.json',finding_path),('R1-INDEPENDENT-REPORT.json',independent_path)]:save('inputs/'+name,read(path))
 # These are the sole current composite path and exact scope semantic changes. Old24 literals/11whole remain untouched.
 pub0=json.loads(before['validation/PROFILE-PUBLICATION.json']);pub=copy.deepcopy(pub0)
 composite0=copy.deepcopy(pub0['finite_contexts']['current_composite_customer_form_source']);composite=copy.deepcopy(composite0)
 assert composite['profile_interface']['path']==(OLD/'validation/actual-profile/interface-v1.json').as_posix()
 composite['profile_interface']['path']=(OUT/'validation/actual-profile/interface-v1.json').as_posix();pub['finite_contexts']['current_composite_customer_form_source']=composite
 after['validation/PROFILE-PUBLICATION.json']=enc(pub)
 pp=json.loads(before['validation/PROFILE-PINS.json']);pp['members']['PROFILE-PUBLICATION.json']=pin(after['validation/PROFILE-PUBLICATION.json']);after['validation/PROFILE-PINS.json']=enc(pp)
 new_scope_comparison="stage['scope']=="+repr(correct);old_scope_comparison="stage['scope']=="+repr(wrong)
 for name in ['authority-next.py.fragment','materializer/prepare.py','validation/run.py']:
  raw=after[name].decode();raw=once(raw,old_scope_comparison,new_scope_comparison);after[name]=raw.encode()
 m=after['materializer/prepare.py'].decode();s=after['validation/run.py'].decode()
 for label in ['M','S']:
  value=m if label=='M' else s;value=const(value,'CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE',composite)
  if label=='M':m=value
  else:s=value
 note=('\n\nR103 Source-only r2: the one outer current stage.scope literal now exactly equals the unchanged actual b54 Git exporter scope '+correct+'. R1 is a static failed Source attempt, not a new Git stage/whole/Run. All25 stages/M37/plan1160/fulltracked scope and original24/20literal/nested23/19literal/11whole/finite420 remain. Only necessary current root/publication/profile/validation/launcher identities follow this new Source directory. Profile9 and8PHP whole raw; normal18/full8PHP+8host/40 actual syntax, original callbacks/M/S all UNBOUND_NOT_RUN.\n').encode()
 after['validation/DESIGN-TASKS.md']=before['validation/DESIGN-TASKS.md']+note
 s=const(s,'PROFILE_PUBLICATION',pin(after['validation/PROFILE-PINS.json']));after['validation/run.py']=s.encode()
 rootpins=json.loads(before['validation/PINS.json']);rootpins['members']={n:pin(after['validation/'+n]) for n in ('DESIGN-TASKS.md','run.py')};after['validation/PINS.json']=enc(rootpins)
 for a,b in [("root=Path('"+(OLD/'validation').as_posix()+"')","root=Path('"+(OUT/'validation').as_posix()+"')"),("tool=Path('"+(OLD/'materializer').as_posix()+"')","tool=Path('"+(OUT/'materializer').as_posix()+"')"),("need(pin((root/'PINS.json').read_bytes())=="+repr(pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')","need(pin((root/'PINS.json').read_bytes())=="+repr(pin(after['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')")]:m=once(m,a,b)
 after['materializer/prepare.py']=m.encode();after['materializer/PLAN.md']=before['materializer/PLAN.md']+note
 inv=json.loads(before['materializer/INVERSE.json']);inv['R103_Source_F1_attempt_successor']={'before_static_failed_R1':design['before_R1_core'],'finding':design['F1'],'Root_design':pointer(design_path),'R1_independent_review':pointer(independent_path),'one_exact_scope':{'before':wrong,'after':correct},'Git_stage_added':False,'whole_history_added':False,'future_normal8_callbacks_M_S':'UNBOUND_NOT_RUN'};after['materializer/INVERSE.json']=enc(inv)
 launch=before['launcher/launch.py'].decode()
 for a,b in [("ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')"),((OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix()),(pin(before['validation/PINS.json'])['sha256'],pin(after['validation/PINS.json'])['sha256'])]:launch=once(launch,a,b)
 after['launcher/launch.py']=launch.encode()
 scope_proof={}
 for name in ['materializer/prepare.py','validation/run.py']:
  old=before[name].decode();new=after[name].decode();od=defs(old);nd=defs(new);assert list(od)==list(nd)
  assert {k:v for k,v in od.items() if k!='source_authority'}=={k:v for k,v in nd.items() if k!='source_authority'}
  assert once(nd['source_authority'],new_scope_comparison,old_scope_comparison)==od['source_authority']
  assert od['validate_complete_source_tree']==nd['validate_complete_source_tree'];compile(new,name,'exec')
  node=next(n for n in ast.parse(nd['source_authority']).body[0].body if isinstance(n,ast.FunctionDef) and n.name=='historical_twentyfour')
  oldnode=next(n for n in ast.parse(od['source_authority']).body[0].body if isinstance(n,ast.FunctionDef) and n.name=='historical_twentyfour')
  assert ast.get_source_segment(nd['source_authority'],node)==ast.get_source_segment(od['source_authority'],oldnode)
  # This independent inversion explicitly covers all nonfunction current identity seams as well.
  restored=new
  for key in ['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']+(['PROFILE_PUBLICATION'] if name=='validation/run.py' else []):restored=once(restored,statement(new,key),statement(old,key))
  restored=once(restored,new_scope_comparison,old_scope_comparison)
  if name=='materializer/prepare.py':
   for a,b in [("root=Path('"+(OUT/'validation').as_posix()+"')","root=Path('"+(OLD/'validation').as_posix()+"')"),("tool=Path('"+(OUT/'materializer').as_posix()+"')","tool=Path('"+(OLD/'materializer').as_posix()+"')"),("need(pin((root/'PINS.json').read_bytes())=="+repr(pin(after['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')","need(pin((root/'PINS.json').read_bytes())=="+repr(pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')")]:restored=once(restored,a,b)
  assert restored==old
  # Pure literal inspection is independent of calling the consumer and catches the original F1.
  comparisons=[n for n in ast.walk(ast.parse(nd['source_authority'])) if isinstance(n,ast.Compare) and isinstance(n.left,ast.Subscript) and ast.get_source_segment(nd['source_authority'],n.left)=="stage['scope']"]
  matches=[ast.literal_eval(n.comparators[0]) for n in comparisons if isinstance(n.comparators[0],ast.Constant)]
  assert correct in matches and wrong not in matches
  scope_proof[name]={'full_module_inverse_to_R1':True,'only_function_change':'source_authority one exact scope literal','historical24_whole_raw':True,'non_authority_functions_raw':len(od)-1,'original_profile9_and_SourceTree_raw':True,'exact_current_consumer_scope_equals_true_producer':correct}
 assert defs(launch)==defs(before['launcher/launch.py'].decode())
 for name in [n for n in before if n.startswith('validation/actual-profile/')]:assert after[name]==before[name]
 for name in ['current-profile-checks.py.fragment','bafb-failed-finite-checks.py.fragment','source_reader.py','author_support.py','namespace_static.py']:assert after[name]==before[name]
 for name,raw in after.items():
  if name not in ('SOURCE-PROOF.json','SOURCE-INHERITANCE.json','SOURCE-WINDOWS.json'):save(name,raw)
 # Keep all counters/historical fields as r1 Source facts, append only current static F1 identity evidence.
 proof=json.loads(before['SOURCE-PROOF.json']);proof['R103_F1_static_correction']={'before_Source':design['before_R1_core'],'Root_finding':design['F1'],'producer':design['actual_producer'],'one_scope':correct,'module_inverse':scope_proof,'R1_source_only_not_nested_as_whole':True};save('SOURCE-PROOF.json',enc(proof))
 inherit=json.loads(before['SOURCE-INHERITANCE.json']);inherit['R103_F1_Source_only_successor']={'engineering_before':design['before_R1_core'],'historical24_before_unchanged':inherit['before'],'newGit_or_whole':False,'actual_qualification':'UNBOUND_NOT_RUN'};save('SOURCE-INHERITANCE.json',enc(inherit))
 # Exact all-member patch includes the F1 fragment and all transitive metadata, but not dynamic windows/PINS.
 compared={n:before[n] for n in before if n not in ('PINS.json','SOURCE-PROOF.json','SOURCE-INHERITANCE.json','SOURCE-WINDOWS.json','DIFF.patch','REVERSE.patch')}
 candidates={n:after[n] for n in compared};forward=patch(compared,candidates);reverse=patch(candidates,compared)
 assert replay(compared,forward)[0]==candidates and replay(candidates,reverse)[0]==compared
 # Replace only the new packet's inherited patch leaves, before they are sealed as current.
 (OUT/'DIFF.patch').write_bytes(forward);(OUT/'REVERSE.patch').write_bytes(reverse)
 for path in list(READS):read(path)
 save('SOURCE-WINDOWS.json',enc({'schema':1,'files':READS,'all_first_final_own9_FD9_cross4_birth_EOF_closed':True,'original_Run_M_S_private_ENV_body_reads':False,'candidate_import_or_call':False}))
 facts={'schema':1,'status':'R103_F1_EXACT_SCOPE_SOURCE_SUCCESSOR_NOT_CALLBACK_OR_RUNTIME_ADMITTED','before_R1':design['before_R1_core'],'Root_F1':design['F1'],'actual_producer':design['actual_producer'],'correct_scope':correct,'source_sha':design['HEAD'],'parent':design['PARENT'],'all25_chain_counters_M37_1160_unchanged':True,'all_profile9_8PHP_raw':True,'full_module_inverse':scope_proof,'whole_nested24_20literal_23_19literal_11whole_and_finite420_raw':True,'no26thGit_or12thwhole':True,'R1_independent_report':pointer(independent_path),'current_paths_only_pub_profile_validation_launcher_pin_DAG':True,'actual_normal8_callbacks_M_S':'UNBOUND_NOT_RUN'}
 with (P/'SOURCE-PROOF.json').open('xb') as f:f.write(enc(facts))
 files={p.relative_to(OUT).as_posix():{'path':p.as_posix(),**pin(read(p))} for p in sorted(OUT.rglob('*')) if p.is_file()}
 fresh=copy.deepcopy(prior);fresh.update(status=facts['status'],files=files,member_count=len(files),engineering_before_R1=design['before_R1_core'],historical24_before_unchanged=prior['before_source'],R103_F1_scope_correction=design['F1'],actual_normal8_callbacks_M_S='UNBOUND_NOT_RUN')
 save('PINS.json',enc(fresh))
 assert {p.relative_to(OUT).as_posix() for p in OUT.rglob('*') if p.is_file()}==set(files)|{'PINS.json'}
 for n,row in files.items():read(OUT/n,row)
 print(json.dumps({'core_PINS':pointer(OUT/'PINS.json'),'core_members':len(files)+1,'M':pin(after['materializer/prepare.py']),'S':pin(after['validation/run.py']),'launcher':pin(after['launcher/launch.py']),'Source_proof':pointer(P/'SOURCE-PROOF.json'),'runtime_targets':'NOT_RUN'},ensure_ascii=False))
if __name__=='__main__':main()
