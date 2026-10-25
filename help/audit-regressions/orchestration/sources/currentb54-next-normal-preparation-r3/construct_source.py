"""R105 Source-only new-root/pin DAG successor. Own author tooling only; no target execution."""
from pathlib import Path
import ast,copy,difflib,hashlib,json,os,re,stat
P=Path(__file__).resolve().parent;ORG=P.parent;OLD=ORG/'currentb54-next-normal-preparation-r2/normal-source';OUT=P/'normal-source';AD=ORG/'current41c0-normal-root-admission-r1'
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
 prior_raw=read(OLD/'PINS.json',{'bytes':13692,'sha256':'66b613acf0cd5d98cb4ed956bd5fe0f2b53b78cb5a1f81eb331e0ae5c2d3887a'});prior=json.loads(prior_raw)
 assert len(prior['files'])==33 and len(prior['source_members18'])==18
 originals={name:read(OLD/name,row) for name,row in prior['files'].items()}
 before={**originals,'PINS.json':prior_raw};after=dict(originals)
 # Deliberately never enumerate the R2 root: it also contains failed actual M outputs.
 for name,raw in before.items():
  q=P/'before-source'/name;q.parent.mkdir(parents=True,exist_ok=True)
  if q.exists():assert q.read_bytes()==raw
  else:
   with q.open('xb') as f:assert f.write(raw)==len(raw)
 failed_path=AD/'R105-B54-FAILED-M-ROOT-READBACK.json';failed=obj(failed_path,{'bytes':2607,'sha256':'86c1f99b4a9487e582ef9f7c42a57c09804471fd55ec13c7b944537862ecafa0'})
 output_path=AD/'R105-B54-FAILED-M-PUBLIC-OUTPUTS.json';output_raw=read(output_path,{'bytes':717164,'sha256':'5d3ce24764eac79917c1d611631399d105a824486080cdd2bae2598ebd3208fb'});outputs=json.loads(output_raw)
 env_path=AD/'run-r106-b54-organized-m.py';env_raw=read(env_path)
 assert len(env_raw)==2198 and b"'GIT_CONFIG_COUNT':'1','GIT_CONFIG_KEY_0':'core.longpaths','GIT_CONFIG_VALUE_0':'true'" in env_raw
 assert failed['actual_M_PID']==35032 and failed['native_exit_wait']==1 and failed['native_operations']==241 and failed['preceding_native0_empty_stderr']==240
 assert failed['last_native']['label']=='source-step-14-member-9-after' and failed['last_native']['native_exit']==failed['last_native']['wait_returncode']==128
 assert failed['own_M_complete_outputs']==968 and failed['outer_complete_outputs']==13 and failed['all_public_files']==len(outputs)==982
 assert failed['no_RESULT_or_freeze_or_S_start'] is failed['single_failed_attempt_preserved_no_retry'] is failed['source_environment_failure_not_business_result'] is True
 assert failed['App_DB_HTTP_Docker_Run_or_business_started'] is False and failed['normal_Source_PINS']=={'path':(OLD/'PINS.json').as_posix(),**pin(prior_raw)}
 assert all(set(row)=={'bytes','sha256','named9','fd9','birthtime_ns','EOF','closed'} and Path(path).is_absolute() and isinstance(row['bytes'],int) and re.fullmatch('[a-f0-9]{64}',row['sha256']) and row['EOF'] is row['closed'] is True for path,row in outputs.items())
 # Only these two Root public summaries are read. Do not follow their 982 body pointers.
 (P/'inputs').mkdir()
 for filename,raw in [('R105-FAILED-M-ROOT.json',enc(failed)),('R105-FAILED-M-PUBLIC-OUTPUTS.json',output_raw)]:
  with (P/'inputs'/filename).open('xb') as f:f.write(raw)
 syntax_path=AD/'R103-B54-NORMAL8-R2-ACTUAL-ROOT-READBACK.json';syntax=obj(syntax_path,{'bytes':48845,'sha256':'5d7bcd177fc2b4fb3ea37f4b4400834d3a40d2d0d95f04d3090a1ba2e589a141'})
 syntax_source=Path(syntax['source_PINS']['path']).parent;read(syntax_source/'PINS.json',syntax['source_PINS']);leaves=obj(syntax_source/'LEAVES.json')
 assert syntax['status']=='ROOT_COMPLETE_NORMAL8_PHP82_8CHILDREN_8HOST_40PUBLIC_READBACK0' and syntax['Root_outer_native_exit']==syntax['Root_outer_wait_returncode']==0
 assert (syntax['RESULT_keys'],syntax['host_native'],syntax['complete_PHP_native_wait0'],syntax['total_public_files'])==(18,8,8,40)
 assert len(leaves)==8 and syntax['business_executed'] is syntax['M_S_business_admitted'] is False
 pub0=json.loads(before['validation/PROFILE-PUBLICATION.json']);pub=copy.deepcopy(pub0)
 composite0=copy.deepcopy(pub0['finite_contexts']['current_composite_customer_form_source']);composite=copy.deepcopy(composite0)
 assert composite['profile_interface']['path']==(OLD/'validation/actual-profile/interface-v1.json').as_posix()
 composite['profile_interface']['path']=(OUT/'validation/actual-profile/interface-v1.json').as_posix();pub['finite_contexts']['current_composite_customer_form_source']=composite
 after['validation/PROFILE-PUBLICATION.json']=enc(pub)
 pp=json.loads(before['validation/PROFILE-PINS.json']);pp['members']['PROFILE-PUBLICATION.json']=pin(after['validation/PROFILE-PUBLICATION.json']);after['validation/PROFILE-PINS.json']=enc(pp)
 m=const(before['materializer/prepare.py'].decode(),'CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE',composite)
 s=const(before['validation/run.py'].decode(),'CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE',composite)
 note=b'\n\nR105 Source-only r3: same b54 HEAD/stage25/M37/nativeplan1160; fresh Source root and necessary pin DAG only. R2 M35032 remains failed source-environment preparation at Git native241/128, not App/Run or new Git/whole history. Root alone supplies process-only core.longpaths=true to the dedicated future process tree. All native/authority/business functions and Git argv stay raw. Profile9/eight PHP remain whole raw; existing genuine normal8 body syntax0 may be reused only with a new Root Source binding. Current r3 callbacks/M/S and all future actual identities remain UNBOUND.\n'
 after['validation/DESIGN-TASKS.md']=before['validation/DESIGN-TASKS.md']+note
 s=const(s,'PROFILE_PUBLICATION',pin(after['validation/PROFILE-PINS.json']));after['validation/run.py']=s.encode()
 rootpins=json.loads(before['validation/PINS.json']);rootpins['members']={name:pin(after['validation/'+name]) for name in ('DESIGN-TASKS.md','run.py')};after['validation/PINS.json']=enc(rootpins)
 m_replacements=[("root=Path('"+(OLD/'validation').as_posix()+"')","root=Path('"+(OUT/'validation').as_posix()+"')"),("tool=Path('"+(OLD/'materializer').as_posix()+"')","tool=Path('"+(OUT/'materializer').as_posix()+"')"),("need(pin((root/'PINS.json').read_bytes())=="+repr(pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')","need(pin((root/'PINS.json').read_bytes())=="+repr(pin(after['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')")]
 for was,new in m_replacements:m=once(m,was,new)
 after['materializer/prepare.py']=m.encode();after['materializer/PLAN.md']=before['materializer/PLAN.md']+note
 failure_ref={'path':failed_path.as_posix(),**pin(read(failed_path))};output_ref={'path':output_path.as_posix(),**pin(output_raw)};env_ref={'path':env_path.as_posix(),**pin(env_raw)};syntax_ref={'path':syntax_path.as_posix(),**pin(read(syntax_path))}
 facts={'schema':1,'scope':'R105_SAME_HEAD_NEW_SOURCE_ROOT_PROCESS_ONLY_ENVIRONMENT_NOT_NEW_GIT_OR_WHOLE','engineering_before_R2':{'path':(OLD/'PINS.json').as_posix(),**pin(prior_raw)},'failed_M_Root':failure_ref,'failed_M_public_list':output_ref,'original982_public_bodies_not_reread_or_nested':True,'Root_process_only_environment_Source':env_ref,'M_git_argv_and_functions_changed':False,'new_Git_stage_or_whole_or_Run':False,'prior_exact8PHP_actual_syntax_Root':syntax_ref,'current_r3_callback_M_S_actual':'UNBOUND_NOT_RUN'}
 inv=json.loads(before['materializer/INVERSE.json']);inv['R105_process_environment_Source_successor']=facts;after['materializer/INVERSE.json']=enc(inv)
 launch=before['launcher/launch.py'].decode()
 launch_replacements=[("ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')"),((OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix()),(pin(before['validation/PINS.json'])['sha256'],pin(after['validation/PINS.json'])['sha256'])]
 for was,new in launch_replacements:launch=once(launch,was,new)
 after['launcher/launch.py']=launch.encode()
 module_proof={}
 for name in ('materializer/prepare.py','validation/run.py','launcher/launch.py'):
  old=before[name].decode();new=after[name].decode();od=defs(old);nd=defs(new)
  assert od==nd  # All functions, including entire source_authority and native, not only helpers.
  compile(new,name,'exec');restored=new
  if name!='launcher/launch.py':
   for key in ['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']+(['PROFILE_PUBLICATION'] if name=='validation/run.py' else []):restored=once(restored,statement(new,key),statement(old,key))
  for was,newpart in (m_replacements if name=='materializer/prepare.py' else launch_replacements if name=='launcher/launch.py' else []):restored=once(restored,newpart,was)
  assert restored==old
  module_proof[name]={'before':pin(before[name]),'candidate':pin(after[name]),'all_functions_raw':list(od),'raw_function_count':len(od),'whole_module_inverse_exact_R2':True,'top_level_active_path_or_pin_only':True}
 for name in [x for x in originals if x.startswith('validation/actual-profile/')]:assert after[name]==before[name]
 for name in ['authority-next.py.fragment','current-profile-checks.py.fragment','bafb-failed-finite-checks.py.fragment','source_reader.py','author_support.py','namespace_static.py']:assert after[name]==before[name]
 reused=[];requirements={row['path']:row for row in pub['repository_Source_requirements']}
 for row in leaves:
  body=read(syntax_source/'bundle'/row['bundle_name'],row)
  if row['ordinal']==8:assert body==after['validation/actual-profile/ms4-initialize.php']
  else:
   relative=row['integration_relative'].removeprefix('tracked/');assert pin(body)=={key:requirements[relative][key] for key in ('bytes','sha256')}
  reused.append({'ordinal':row['ordinal'],'bundle_name':row['bundle_name'],**pin(body)})
 proof=json.loads(before['SOURCE-PROOF.json']);proof['R105_new_root_process_only_environment']=facts|{'module_inverse':module_proof,'all_profile9_and_eight_PHP_body_raw':True,'nativeplan1160_same_formula':True,'Root_actual_normal8_identity_rebind_required_not_new_lint':True};after['SOURCE-PROOF.json']=enc(proof)
 inherit=json.loads(before['SOURCE-INHERITANCE.json']);assert inherit['before']==prior['before_source']
 inherit['R105_new_root_Source_only_successor']=facts|{'historical_before_bafbR3_unchanged':inherit['before']};after['SOURCE-INHERITANCE.json']=enc(inherit)
 compared={name:before[name] for name in originals if name not in ('SOURCE-WINDOWS.json','DIFF.patch','REVERSE.patch')};changed={name:after[name] for name in compared}
 fwd=patch(compared,changed);rev=patch(changed,compared);replayed,hf=replay(compared,fwd);restored,hr=replay(changed,rev);assert replayed==changed and restored==compared
 after['DIFF.patch']=fwd;after['REVERSE.patch']=rev
 # The new windows are strictly this constructor's real input map, not a fictional current Core membership map.
 for path in list(READS):read(path)
 for path,row in READS.items():
  now=Path(path).lstat();assert [getattr(now,k) for k in ('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')]==row['named9'] and now.st_birthtime_ns==row['birthtime_ns']
 input_windows=copy.deepcopy(READS)
 after['SOURCE-WINDOWS.json']=enc({'schema':1,'files':input_windows,'all_first_final_own9_FD9_cross4_birth_EOF_closed':True,'original_Run_M_S_private_ENV_body_reads':False,'candidate_import_or_call':False})
 for name,raw in after.items():save(name,raw)
 files={name:{'path':(OUT/name).as_posix(),**pin(after[name])} for name in sorted(after)}
 assert len(files)==33
 fresh=copy.deepcopy(prior);fresh.update(status='R105_R3_NEW_SOURCE_ROOT_PROCESS_ENV_ONLY_NOT_CALLBACK_OR_RUNTIME_ADMITTED',files=files,member_count=33,engineering_before_R2=facts['engineering_before_R2'],R105_failed_M_preserved=failure_ref,R105_process_only_environment_Source=env_ref,new_normal8_syntax='EXACT_PRIOR8PHP_ACTUAL0_REUSE_REQUIRES_ROOT_CURRENT_SOURCE_BINDING',actual_normal8_callbacks_M_S='PRIOR_SAME8PHP_SYNTAX0_CURRENT_R3_CALLBACKS_M_S_UNBOUND')
 save('PINS.json',enc(fresh))
 assert {p.relative_to(OUT).as_posix() for p in OUT.rglob('*') if p.is_file()}==set(files)|{'PINS.json'}
 for name,row in files.items():read(OUT/name,row)
 core_pin=pointer(OUT/'PINS.json')
 summary={'schema':1,'status':'AUTHOR_R3_SOURCE_ONLY_FIXED_ROOT_AND_PIN_DAG_NOT_EXECUTED','Core_PINS':core_pin,'before_R2_Core':facts['engineering_before_R2'],'Core_members_including_self':34,'complete_before_Source_only_files':34,'failed_M_outputs_in_before':0,'engineering_before_R2_not_historical24':True,'historical_SOURCE_INHERITANCE_before_bafb_R3_unchanged':inherit['before'],'whole_module_inverse':module_proof,'all_profile9_and8PHP_raw':True,'profile_ms4_host_entire_raw':True,'M_S_launcher_entire_bytes_changed_only_required_pins_paths':True,'normal8_actual_prior_proof_reusable_at_body_level_only':syntax_ref,'all_source_authority_native_SourceTree_business_functions_raw':True,'full_bidirectional_patch':{'files':sum(compared[n]!=changed[n] for n in compared),'forward_hunks':hf,'reverse_hunks':hr,'all_whole_files_replayed_exact':True},'unchanged_Source_plan':{'HEAD':prior['source_sha'],'parent':prior['parent'],'stages':25,'Mfields':37,'native_expected_not_actual':1160,'required':318,'cumulative':313,'newtracked':266,'tracked':15752,'dual_source_rows':31504,'complete_tracked_bytes':341961431,'whole_histories':11},'Source_input_windows':len(input_windows),'same_five_entry_body_source_pins':{name:files[name] for name in ['source_reader.py','author_support.py','materializer/prepare.py','validation/run.py','validation/PROFILE-PUBLICATION.json']},'actual_r3_normal8_newhost_metadata_callbacks_M_S':'UNBOUND_NOT_RUN','candidate_import_exec_or_targets':False,'old_R2_failed_Source_outputs_W_Val_C_D_Git_modified':False,'independent_review':'PENDING_NONAUTHOR_AND_ROOT'}
 for name,value in [('SOURCE-PROOF.json',summary),('SAME-EIGHT-PHP-SYNTAX-REUSE.json',{'schema':1,'actual_prior_Root':syntax_ref,'same_complete_bodies8':reused,'profile9_raw':True,'no_new_lint_or8host_result_claim':True,'current_Source_identity_joint_Root_binding':'REQUIRED'}),('ROOT-CLI.json',{'schema':1,'Core_PINS':core_pin,'launcher':files['launcher/launch.py'],'original_mode':'materialize','Root_dedicated_outer_Source':env_ref,'process_only_Git_config':{'GIT_CONFIG_COUNT':'1','GIT_CONFIG_KEY_0':'core.longpaths','GIT_CONFIG_VALUE_0':'true'},'outer_and_callback_Source_successors':'OTHER_AUTHORS_PENDING','actual_r3_callback_M_RESULT_freeze_S':'UNBOUND','author_execute_permission':False}),('REPORT.json',summary)]:
  with (P/name).open('xb') as f:f.write(enc(value))
 for path in list(READS):read(path)
 with (P/'INPUT-WINDOWS.json').open('xb') as f:f.write(enc({'files':READS,'all_first_final_whole_EOF_own9_FD9_cross4_birth_closed':True,'no_failed_output_body_follow':True}))
 outer_files={p.relative_to(P).as_posix():pin(p.read_bytes()) for p in sorted(P.rglob('*')) if p.is_file()}
 with (P/'FINAL-PINS.json').open('xb') as f:f.write(enc({'schema':1,'scope':'R105_MINIMAL_R3_SOURCE_ONLY_NEW_ROOT_PIN_DAG_SUCCESSOR','files':outer_files,'member_count':len(outer_files),'namespace_files_including_self':len(outer_files)+1,'runtime':'UNBOUND_NOT_EXECUTED_BY_AUTHOR'}))
 print(json.dumps({'core_PINS':core_pin,'five_ENTRY':summary['same_five_entry_body_source_pins'],'launcher':files['launcher/launch.py'],'outer_PINS':pointer(P/'FINAL-PINS.json'),'REPORT':pointer(P/'REPORT.json'),'Core34':True,'all_functions_profile9_PHP_raw':True,'candidate_or_targets':'NOT_EXECUTED'},ensure_ascii=False))

if __name__=='__main__':main()
