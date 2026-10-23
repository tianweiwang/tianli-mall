"""Mutable B Source projection only; no target imports or native operations."""
import ast, copy, difflib, hashlib, json, os, re, time
from pathlib import Path

O=Path(__file__).parent;B=O.parent;H=O/'rebind-before-53e'
PREVIOUS=B/'fresh-b-customer-form-external-source-r2'
A=B/'full-a-raw-env-normal-next-source-r1';AP=A/'validation';N=AP/'actual-profile'
HEAD='53e6d521b387b19a4cf370e0e381670b762d325e';PARENT='2fa872e6fda7a8bf46c55f88dd946b972ec79102'
ROWS={};CACHE={};checks=[]
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def n9(s):return [int(getattr(s,k)) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']]
def read(p):
 p=Path(p);key=p.as_posix()
 if key in CACHE:return CACHE[key]
 started=time.time_ns();pb=n9(p.stat())
 with p.open('rb') as f:hb=n9(os.fstat(f.fileno()));raw=f.read();eof=f.read(1);ha=n9(os.fstat(f.fileno()))
 pa=n9(p.stat());assert pb==pa and hb==ha and eof==b'' and len(raw)==hb[6],key
 assert [hb[i] for i in [0,1,6,7]]==[pa[i] for i in [0,1,6,7]],key
 ROWS[key]={'path':key,**pin(raw),'path_before':pb,'path_after':pa,'handle_before':hb,'handle_after':ha,'EOF':True,'closed':True,'started_ns':started,'ended_ns':time.time_ns()};CACHE[key]=raw;return raw
def js(p):return json.loads(read(p))
def compact(v):return {k:v[k] for k in ['bytes','sha256']}
def fixed(p,v):
 raw=read(p);assert pin(raw)==compact(v),(str(p),pin(raw),v);return raw
def pointer(p):return {'path':Path(p).as_posix(),**pin(read(p))}
def check(ok,name):assert ok,name;checks.append(name)
def once(s,a,b):assert s.count(a)==1,(a[:100],s.count(a));return s.replace(a,b,1)
def constants(s):
 d={}
 for n in ast.parse(s).body:
  if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name):
   try:d[n.targets[0].id]=ast.literal_eval(n.value)
   except (ValueError,TypeError):pass
 return d
def constant(s,name,value):
 n=next(n for n in ast.parse(s).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id==name)
 ls=s.splitlines(keepends=True);return ''.join(ls[:n.lineno-1])+name+' = '+repr(value)+'\n'+''.join(ls[n.end_lineno:])
def expression(s,name,expr):
 n=next(n for n in ast.parse(s).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id==name)
 ls=s.splitlines(keepends=True);return ''.join(ls[:n.lineno-1])+name+' = '+expr+'\n'+''.join(ls[n.end_lineno:])
def functions(s):return {n.name:ast.get_source_segment(s,n) for n in ast.parse(s).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def editfunc(s,name,change):return once(s,functions(s)[name],change(functions(s)[name]))
def rebind_line(s,before,after):
 ls=s.splitlines(keepends=True);count=0
 for i,line in enumerate(ls):
  if before['sha256'] in line:
   assert line.count(before['sha256'])==line.count(str(before['bytes']))==1
   ls[i]=line.replace(before['sha256'],after['sha256']).replace(str(before['bytes']),str(after['bytes']),1);count+=1
 assert count>0
 return ''.join(ls),count
def write(p,raw,exclusive=False):
 p=Path(p);p.parent.mkdir(parents=True,exist_ok=True)
 with p.open('xb' if exclusive else 'wb') as f:f.write(raw)
def patch(a,b,name):return ''.join(difflib.unified_diff(a.decode().splitlines(keepends=True),b.decode().splitlines(keepends=True),fromfile='before/'+name,tofile='candidate/'+name)).encode()
def replay(raw,delta):
 lines=delta.decode().splitlines(keepends=True);ls=raw.decode().splitlines(keepends=True);i=2;cursor=0;out=[]
 while i<len(lines):
  match=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n?',lines[i]);assert match
  oldn=int(match[2] or 1);newn=int(match[4] or 1);start=int(match[1])-1 if oldn else int(match[1]);assert start>=cursor
  out.extend(ls[cursor:start]);cursor=start;i+=1;used=made=0
  while i<len(lines) and not lines[i].startswith('@@ '):
   line=lines[i];assert line[0] in ' +-'
   if line[0] in ' -':assert ls[cursor]==line[1:];cursor+=1;used+=1
   if line[0] in ' +':out.append(line[1:]);made+=1
   i+=1
  assert (used,made)==(oldn,newn)
 out.extend(ls[cursor:]);return ''.join(out).encode()
def proof(name,a,b,bp,ap):
 if a==b:return {'name':name,'before':{'path':bp.as_posix(),**pin(a)},'after':{'path':ap.as_posix(),**pin(b)},'stages':[],'unchanged_chunks':[{'before_utf8_offset':0,'after_utf8_offset':0,**pin(a)}]}
 stages=[];chunks=[];raw=a;left=a.decode().splitlines(keepends=True);right=b.decode().splitlines(keepends=True);offset=0
 for tag,aa,ab,ba,bb in difflib.SequenceMatcher(None,left,right,autojunk=False).get_opcodes():
  old=''.join(left[aa:ab]).encode();new=''.join(right[ba:bb]).encode()
  if tag=='equal':
   if old:chunks.append({'before_utf8_offset':len(''.join(left[:aa]).encode()),'after_utf8_offset':len(''.join(right[:ba]).encode()),**pin(old)})
  else:
   at=len(''.join(left[:aa]).encode())+offset;assert raw[at:at+len(old)]==old;after=raw[:at]+new+raw[at+len(old):]
   stages.append({'stage_utf8_offset':at,'before_utf8':old.decode(),'after_utf8':new.decode(),'whole_before':pin(raw),'whole_after':pin(after)});raw=after;offset+=len(new)-len(old)
 assert raw==b
 for seam in reversed(stages):
  at=seam['stage_utf8_offset'];old=seam['before_utf8'].encode();new=seam['after_utf8'].encode();assert raw[at:at+len(new)]==new;raw=raw[:at]+old+raw[at+len(new):]
 assert raw==a
 return {'name':name,'before':{'path':bp.as_posix(),**pin(a)},'after':{'path':ap.as_posix(),**pin(b)},'stages':stages,'unchanged_chunks':chunks}

def main():
 assert not (O/'PINS.json').exists() and not (O/'REPORT.json').exists(),'B_FINAL_PUBLICATION_MUST_REMAIN_ABSENT'
 binder_pointer=pointer(Path(__file__))
 # Preserve the complete previous mutable projection once; all later reads use it.
 if not (H/'HISTORY.json').exists():
  cp=js(PREVIOUS/'candidate/PINS.json');bp=js(PREVIOUS/'browser-v1/PINS.json');rp=js(PREVIOUS/'root-source/PINS.json')
  names=['author.py','root-current-adapter.fragment.py','CURRENT-A-SOURCE-BINDING.json','CURRENT-M-SOURCE-CONTRACT.json','CURRENT-SETS.json','DESIGN-TASKS.md','DRAFT-CHECKS.json','DRAFT-STATE.json','DRAFT-M-METADATA-PROOF.json','SOURCE-QUALIFICATION.json','SOURCE-TRANSFORMS.json','FUNCTION-PRESERVATION.json','DIFF.patch','REVERSE.patch','INITIALIZER-MEMBERSHIP-SOURCE-PROOF.json','INITIALIZER-MEMBERSHIP-DIFF.patch','INITIALIZER-MEMBERSHIP-REVERSE.patch','optimization-before-current2fa/ms4-initialize.php','current-core-aux/CORE-AUX-AUTHORITY.json']
  names += ['candidate/'+n for n in cp['members']]+['candidate/PINS.json']+['browser-v1/'+n for n in bp['members']]+['browser-v1/PINS.json']+['root-source/'+n for n in rp['members']]+['root-source/PINS.json']
  values={name:read(PREVIOUS/name) for name in names}
  for name,raw in values.items():write(H/name,raw,True)
  write(H/'HISTORY.json',enc({'schema':1,'status':'EXACT_PRIOR_CURRENT2FA_MUTABLE_SOURCE_AND_ACTUAL_M0_HISTORY_NOT_CURRENT53E_ADMISSION','source_sha':PARENT,'files':{name:pin(raw) for name,raw in values.items()},'old_A_failed_and_retired':True}),True)
  for row in json.loads(values['SOURCE-TRANSFORMS.json']):
   raw=fixed(PREVIOUS/'before'/row['path'],row['before']);write(O/'before'/row['path'],raw,True)
 hist=js(H/'HISTORY.json')
 def prior(name):return fixed(H/name,hist['files'][name])
 oldcontract=json.loads(prior('CURRENT-M-SOURCE-CONTRACT.json'));sets=json.loads(prior('CURRENT-SETS.json'))
 check(oldcontract['source_sha']==sets['source_sha']==PARENT and oldcontract['M_result']['sha256']=='1a7955e1aebe6a156ae22ee7d8e6b2912f1779d891b4498eae88029437968665','EXACT_PRIOR2FA_SOURCE_AND_COMPLETED_M0_RETAINED_HISTORY')
 design=prior('DESIGN-TASKS.md')+b'\n\n## Current53e Source and completed M0 projection\n\nOnly the shared raw-environment preservation library and two status documents changed in the actual eleventh commit, parent2fa. Preserve the complete previous2fa B Source/M0 projection in rebind-before-53e; it is history. Use the exact current normal N9/composite and its two already present membership seams, unchanged UI8/Core3/browser8/material5, strict UI12/ENV9 roles and all original business guards. Join actual current M30/11steps/277 native metadata and true launcher nested descriptors to the new frozen A Source. Bind the full actual customer-prepare retirement as a fourth distinct historical failure object; original S remains UNKNOWN and failed resume1/HTTP0 are not success. Reuse Root completed full277 readback; do not repeat those raw streams or old31008 physical bodies. All A/Workerclose/independentzero/postA/final-eligible gates remain None, Root sealedfalse and four entry guards UNSEALED. No final outer PINS/REPORT, B/READY/GUI/DOM/device/finance/release acceptance. No target/resource/native or active Run/WVal reads. Regenerate the complete31 transforms,26 byte-context inverses and internal selected17/nonself16/browser8/CoreAux/Root4 DAG. This continues the sole canonical plan.\n'
 apinsraw=fixed(A/'PINS.json',{'bytes':52743,'sha256':'acf10b9cff79d943ef8c5ef04ea00d7462c8c8914b12db63a85ceded6b47687f'});apins=json.loads(apinsraw)
 def ar(name):return fixed(A/name,apins['files'][name])
 normalpubraw=ar('validation/PROFILE-PUBLICATION.json');normalpub=json.loads(normalpubraw);normalrootraw=ar('validation/PINS.json');normalroot=json.loads(normalrootraw);normalpinsraw=ar('validation/PROFILE-PINS.json');mraw=ar('materializer/prepare.py');sraw=ar('validation/run.py');mv=constants(mraw.decode())
 chain=mv['COMMIT_CHAIN'];cumulative=mv['COMMITTED_PATHS'];requirements=normalpub['repository_Source_requirements']
 check(len(chain)==11 and chain[:10]==oldcontract['commit_chain'] and normalpub['source_sha']==HEAD and normalpub['parent']==PARENT and len(cumulative)==62 and len(requirements)==68,'ACTUAL53E_ELEVEN_CHAIN_N9_62_68_SOURCE')
 authority=js(A/'CURRENT-ELEVENTH-COMMIT-AUTHORITY.json');actual=json.loads(fixed(authority['commit_record']['path'],authority['commit_record']))
 adoption={'path':authority['source_manifest']['path'],**compact(authority['source_manifest']),'commit':authority['commit_record']}
 committed=[{'path':r['path'],**r['after']} for r in chain[10]['files']]
 check(actual['head']==HEAD and actual['parent']==PARENT and actual['Main_Val_clean_and_same_head'] is True and len(committed)==3 and set(r['path'] for r in committed)==set(actual['files']),'ACTUAL_ELEVENTH_EXACT3_COMMIT_NO_NEW_PATHS')
 requirement_map={r['path']:r for r in requirements};oldrequirements={r['path']:r for r in oldcontract['source_requirements68']}
 check(set(requirement_map)==set(oldrequirements) and [p for p in requirement_map if requirement_map[p]!=oldrequirements[p]]==[r['path'] for r in committed if r['path'] in requirement_map],'CURRENT_REQUIREMENTS_EXACT_THREE_ADOPTED_DESCRIPTOR_CHANGES')
 selected=[r['path'] for r in json.loads(prior('candidate/PUBLICATION.json'))['selected17']];out={f:prior('candidate/'+f) for f in selected}
 normalinterface=json.loads(ar('validation/actual-profile/interface-v1.json'));currentcu=normalinterface['customer_form_current_profile']['php_sources'];oldcu=sets['CU7'];lib='help/audit-regressions/isolated/lib/ms4-customer-http-normal.php';oldrow=next(r for r in oldcu if r['path']==lib);newrow=next(r for r in currentcu if r['path']==lib)
 check(currentcu==[newrow if r['path']==lib else r for r in oldcu] and newrow==requirement_map[lib],'CU7_ONLY_SHARED_RAW_LIBRARY_DESCRIPTOR_CHANGED')
 for f,count in [('ms4-host.py',1),('verify-zero-ms4-fulfillment.py',2),('ms4-initialize.php',1)]:
  txt,n=rebind_line(out[f].decode(),oldrow,newrow);check(n==count,'EXACT_CURRENT_SHARED_LIB_LITERAL_SITES_'+f);out[f]=txt.encode()
 ui_vector=sets['source_vector8'];core=json.loads(prior('current-core-aux/CORE-AUX-AUTHORITY.json'));core['source_sha']=HEAD;core['browser_manifest']['path']=(O/'browser-v1/PINS.json').as_posix();coreraw=enc(core);coreptr={'path':(O/'current-core-aux/CORE-AUX-AUTHORITY.json').as_posix(),**pin(coreraw)}
 source_rows=dict(requirement_map)
 for path in [r['path'] for r in core['core_sources']]:
  check(path not in source_rows or source_rows[path]==ui_vector[path],'CORE3_EXACT_CURRENT_REQUIREMENT_JOIN_'+path);source_rows[path]=ui_vector[path]
 check(len(source_rows)==69 and len(core['core_sources'])==3,'SOURCE69_PRESERVES_NORMAL68_PLUS_SINGLE_BACKEND_ENTRY')

 # Real completed M0 binding. Root independently read all277 raw receipts; do not replay them here.
 mout=AP/'materialize-freeze-assets-r1';cmraw=fixed(mout/'RESULT.json',{'bytes':1871656,'sha256':'fa7efc593311852a85751201256d95be64eac605c8e7dc6ea09298ad773e58b9'});cm=json.loads(cmraw);cmptr=pointer(mout/'RESULT.json')
 readbackpath=B/'full-a-raw-env-normal-materialization-root-readback-r1/REPORT.json';rbraw=fixed(readbackpath,{'bytes':1033,'sha256':'7671b773d16286e7b292e3a5e080cdec388b57320a2b2a3816557b09733c3100'});rb=json.loads(rbraw)
 check(len(cm)==30 and cm['source_sha']==HEAD and cm['parent']==PARENT and cm['commit_chain']==chain and cm['selected9']==normalpub['selected9'] and cm['Run_created'] is False and cm['App_DB_HTTP_business']=='NOT RUN','ACTUAL_CURRENT_M30_SAME_HEAD_ONLY_SOURCE_SUCCESS')
 check(rb['RESULT']==cmptr and rb['outer_pid']==23920 and rb['outer_native_exit']==0 and rb['native_count']==277 and rb['all_created_terminal_same_pid_argv_both_wait0_and_full_streams'] is True and rb['all_windows_ended'] is True,'ROOT_COMPLETE_277_RAW_READBACK_REUSED_WITH_EXACT_RESULT')
 launcher=A/'launcher';made=json.loads(read(launcher/'materialize.created.json'));ended=json.loads(read(launcher/'materialize.terminal.json'))
 check(set(made)=={'pid','argv','started_ns'} and set(ended)=={'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'} and made['pid']==ended['pid']==23920 and ended['native_exit']==ended['wait_returncode']==0 and len(made['argv'])==5 and made['argv'][:4]==['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-B','-X','utf8'] and Path(made['argv'][4])==A/'materializer/prepare.py','ACTUAL_CURRENT_M_OUTER_CLOSED_NESTED_SCHEMA_PID_AND_ARGV')
 for suffix in ['stdout','stderr']:
  descriptor=ended[suffix];raw=fixed(descriptor['path'],descriptor);check(descriptor['path']==(launcher/('materialize.'+suffix)).as_posix(),'ACTUAL_M_NESTED_'+suffix.upper()+'_PATH')
  if suffix=='stderr':check(raw==b'','ACTUAL_CURRENT_M_STDERR_EMPTY')
  else:check(json.loads(raw)=={'status':cm['status'],'result':cmptr,'freeze':{'path':cm['freeze'],'bytes':cm['freeze_bytes'],'sha256':cm['freeze_sha256']},'native':277,'unique_source_files':10886,'Run_created':False,'App_DB_HTTP_business':'NOT RUN'},'ACTUAL_CURRENT_M_STDOUT_EXACT_RESULT_AND_FREEZE')
 outcome={'created':pointer(launcher/'materialize.created.json'),'terminal':pointer(launcher/'materialize.terminal.json'),'stdout':ended['stdout'],'stderr':ended['stderr'],'created_object':made,'terminal_object':ended}
 native_count=6+sum(2+2*len(step['files']) for step in chain)+1+len(cumulative)+2+3+1+4
 check(native_count==len(cm['native_operations'])==277 and cm['source_counts']==oldcontract['source_counts'] and cm['unique_source_files_actual_read']==10886,'DERIVED_CURRENT277_AND_SIX_ROLE_UNION_COUNTS_PRESERVED')
 freeze= json.loads(fixed(cm['freeze'],{'bytes':cm['freeze_bytes'],'sha256':cm['freeze_sha256']}))
 check(freeze['source_sha']==HEAD and freeze['artifact_files']==cm['selected9'] and freeze['proof_php_ini']==cm['proof_php_ini'] and cm['freeze_sha256']=='5961d32e44fa12424c6503b0510221b28b84f43e6074416f11de69df714bf626','ACTUAL_CURRENT_FREEZE_N9_INI_IDENTITY')
 # No complete tracked snapshot/native replay: exact paths unchanged by the actual three existing-file commit.
 check(len(oldcontract['complete_tracked_paths15504'])==15504 and all(p in oldcontract['complete_tracked_paths15504'] for p in actual['files']),'EXACT_PRIOR_TRACKED_NAMES_INHERITED_THREE_EXISTING_PATHS_ONLY')
 rootmeta=json.loads(fixed(cm['root_source_snapshot']['path'],cm['root_source_snapshot']));check(len(rootmeta)==14,'ACTUAL_CURRENT_ROOT14_METADATA_DESCRIPTOR_JOIN')
 check(pin(fixed(cm['materialize_source']['path'],cm['materialize_source']))==pin(mraw),'ACTUAL_CURRENT_COPIED_M_SOURCE_BYTES')
 authoritysnapshot=pointer(mout/'AUTHORITY-INPUT-SNAPSHOT.json')
 contract=copy.deepcopy(oldcontract);contract.update(source_sha=HEAD,parent=PARENT,source_patch_base=cm['source_patch_base'],commit_chain=chain,committed_paths62=cumulative,source_requirements68=requirements,native_commands=[{'label':r['label'],'argv':r['argv']} for r in cm['native_operations']],derived_native_count=277,selected9=normalpub['selected9'],A_source_PINS={'path':(A/'PINS.json').as_posix(),**pin(apinsraw)},M_Source={'path':(A/'materializer/prepare.py').as_posix(),**pin(mraw)},M_result=cmptr,M_materialize_source_copy=cm['materialize_source'],M_native_operations=cm['native_operations'],M_root_snapshot=cm['root_source_snapshot'],M_complete_snapshot=cm['complete_source_snapshot'],M_authority_input_snapshot=authoritysnapshot,M_freeze={'path':cm['freeze'],'bytes':cm['freeze_bytes'],'sha256':cm['freeze_sha256']},M_outcome=outcome,M_launcher_argv=made['argv'],M_Root_raw_readback={'path':readbackpath.as_posix(),**pin(rbraw)},pinned_candidate_A_Source_status='CURRENT53E_ACTUAL_M0_BOUND_A_POSTA_AND_FINAL_QUALIFICATION_UNBOUND')
 contract.pop('result_fields_exact29');contract['result_fields_exact30']=list(cm)
 contract['historical_2fa_source_projection']={'manifest':pointer(H/'HISTORY.json'),'contract':pointer(H/'CURRENT-M-SOURCE-CONTRACT.json'),'A_source_binding':pointer(H/'CURRENT-A-SOURCE-BINDING.json'),'actual_M0':oldcontract['M_result'],'scope':'EXACT_HISTORICAL_SOURCE_AND_M0_NEVER_CURRENT53E_ADMISSION'}
 retireptr=mv['CURRENT_CUSTOMER_PREPARE_FAILURE_RETIREMENT'];retire=json.loads(fixed(retireptr['path'],retireptr))
 check(len(retire)==22 and cm['current_customer_prepare_failure_retirement']==retire and retire['original_failure_truth']['failed_outer_native_exit']=='UNKNOWN','CURRENT_M_FOURTH_DISTINCT_RETIREMENT_ACTUAL22_FIELDS')
 for key,source in [('prior_retirement','prior_retirement_expected'),('current_driver_failure_retirement','driver_failure_retirement_expected'),('current_early_failure_retirement','early_failure_retirement_expected')]:check(cm[key]==oldcontract[source],'ORIGINAL_DISTINCT_RETIREMENT_UNCHANGED_'+key)
 contract['customer_prepare_failure_retirement_expected']=retire;contract['customer_prepare_failure_retirement_source']=retireptr
 contract['historical_retirement_names_remain_distinct']=oldcontract['historical_retirement_names_remain_distinct']+['current_customer_prepare_failure_retirement']
 for key in ['A_outcome','A_run_context','A_outer_session','A_worker_close','A_independent_zero','post_A_history','final_eligible_A_Source']:check(contract[key] is None,'CURRENT_A_POSTA_FINAL_GATE_ABSENT_'+key)
 contractraw=enc(contract);contractptr={'path':(O/'CURRENT-M-SOURCE-CONTRACT.json').as_posix(),**pin(contractraw)}
 binding={'schema':1,'scope':'ACTUAL_FROZEN_CURRENT53E_A_SOURCE_AND_M0_ONLY_NOT_A_POSTA_SUCCESS','root':A.as_posix(),'normal_profile_candidate':N.as_posix(),'Source_PINS':pin(apinsraw),'publication':pin(normalpubraw),'M_Source':pin(mraw),'S_Source':pin(sraw),'Root_PINS':pin(normalrootraw),'M0':cmptr,'M_A_postA_success':None,'previous2fa':pointer(H/'CURRENT-A-SOURCE-BINDING.json')}

 interface=normalinterface;oldinterface=json.loads(prior('candidate/interface-v1.json'));record=copy.deepcopy(oldinterface['fresh_B_current_UI_profile_source'])
 record.update(source_sha=HEAD,parent=PARENT,actual_adoption_authority=adoption,customer_form_sources7=currentcu,CoreAux_authority=coreptr)
 record['browser8_manifest']['path']=(O/'browser-v1/PINS.json').as_posix()
 record['historical_previous2fa_UI_projection']=pointer(H/'candidate/interface-v1.json')
 record['material_identity_layers']['current_source_sha']=HEAD;record['material_identity_layers']['current_exact3path_adoption']=adoption
 record['material_identity_layers']['no_frontend_or_public_path_modified_in_current_commit']=all(not p.startswith(('template/','crmeb/public/')) for p in actual['files'])
 check(record['material_identity_layers']['build_source_sha']=='eadf74f8924a5ed5b801043030b579dffe5d1702' and record['material_identity_layers']['producer_source_sha']=='4e2723c2b96f4e0265b9fc75483e307820f3fe79','ORIGINAL_BUILD_EADF_PRODUCER4E_IDENTITIES_NOT_REWRITTEN')
 interface['fresh_B_current_UI_profile_source']=record;out['interface-v1.json']=enc(interface);ip=pin(out['interface-v1.json'])
 out['ms4-host.py']=constant(out['ms4-host.py'].decode(),'INTERFACE_SHA256',ip['sha256']).encode();hp=pin(out['ms4-host.py'])
 c=out['normal-close-ms4-fulfillment.py'].decode()
 for k in ['HOST_SHA256','UI_HOST_SHA256']:c=constant(c,k,hp['sha256'])
 out['normal-close-ms4-fulfillment.py']=c.encode();cp=pin(out['normal-close-ms4-fulfillment.py']);z=out['verify-zero-ms4-fulfillment.py'].decode()
 for k,v in [('HOST_SHA256',hp['sha256']),('UI_HOST_SHA256',hp['sha256']),('CLOSE_SHA256',cp['sha256']),('UI_CLOSE_SHA256',cp['sha256']),('INTERFACE_SHA256',ip['sha256']),('UI_INTERFACE_SHA256',ip['sha256']),('INTERFACE_BYTES',ip['bytes']),('UI_INTERFACE_BYTES',ip['bytes'])]:z=constant(z,k,v)
 out['verify-zero-ms4-fulfillment.py']=z.encode();out['README.md']+=('\n\nCurrent53e unsealed Source projection with real same-HEAD M0 only. Exact shared raw-environment library delta, N9, M30/11steps/277, historical2fa retained. Full A/postA/final qualification absent; no B/READY/GUI/business acceptance.\n').encode()
 d=json.loads(out['source-derivation.json']);d['historical_previous2fa_UI_projection']=pointer(H/'candidate/source-derivation.json');d['fresh_B_customer_form_external_current_source'].update(record);d['fresh_B_customer_form_external_current_source']['normal_source_profile']={'root':N.as_posix(),'PROFILE_PUBLICATION':{'path':(AP/'PROFILE-PUBLICATION.json').as_posix(),**pin(normalpubraw)}};d['source_revision']+=1;d['current_nonself_artifacts']=[{'path':f,**pin(out[f])} for f in selected if f!='source-derivation.json'];out['source-derivation.json']=enc(d)
 newsets=copy.deepcopy(sets);newsets.update(source_sha=HEAD,CU7=currentcu)
 browsernames=list(json.loads(prior('browser-v1/PINS.json'))['members']);browser={f:prior('browser-v1/'+f) for f in browsernames};browsermanifest=prior('browser-v1/PINS.json')
 proofs=[proof(f,read(O/'before'/f),out[f],O/'before'/f,O/'candidate'/f) for f in selected]+[proof('browser-v1/'+f,read(O/'before/browser-v1'/f),browser[f],O/'before/browser-v1'/f,O/'browser-v1'/f) for f in browsernames]+[proof('browser-v1/PINS.json',read(O/'before/browser-v1/PINS.json'),browsermanifest,O/'before/browser-v1/PINS.json',O/'browser-v1/PINS.json')]
 pub=json.loads(prior('candidate/PUBLICATION.json'));pub.update(source_sha=HEAD,parent=PARENT,selected17=[{'path':f,**pin(out[f])} for f in selected],repository_Source_requirements=requirements)
 normalauthority={'root':AP.as_posix(),'profile_root':N.as_posix(),'PINS':{'path':(AP/'PROFILE-PINS.json').as_posix(),**pin(normalpinsraw)},'PUBLICATION':{'path':(AP/'PROFILE-PUBLICATION.json').as_posix(),**pin(normalpubraw)}}
 pub['finite_contexts'].update(repository_committed_files=committed,current_core_aux_authority=coreptr,SourceRows69=list(source_rows.values()),normal9_authority={'root':AP.as_posix(),'profile_root':N.as_posix(),'PROFILE_PINS':normalauthority['PINS'],'PROFILE_PUBLICATION':normalauthority['PUBLICATION']},whole_forward_inverse_files=proofs,current_M_source_contract=contractptr)
 pub['finite_contexts']['browser_manifest']['path']=(O/'browser-v1/PINS.json').as_posix()
 profilepins=json.loads(prior('candidate/PINS.json'));profilepins['members']={**{f:pin(out[f]) for f in selected},'DESIGN.md':pin(design),'PUBLICATION.json':pin(enc(pub))}
 ri=json.loads(prior('root-source/INPUTS.json'));ri.update(head=HEAD,parent=PARENT,committed_files=committed,normal_profile_publication=normalauthority,current_core_aux_authority=coreptr)
 ri['normal_root'].update(root=AP.as_posix(),PINS={'path':(AP/'PINS.json').as_posix(),**pin(normalrootraw)},source_members=normalroot['members'],materialization_result=cmptr,materialization_protocol={'current_source_contract':contractptr,'actual_result':cmptr,'old_r40_not_reattributed':True})
 ri['ui_profile_publication']['PINS']={'path':(O/'candidate/PINS.json').as_posix(),**pin(enc(profilepins))};ri['ui_profile_publication']['PUBLICATION']={'path':(O/'candidate/PUBLICATION.json').as_posix(),**pin(enc(pub))}
 ri['ui_profile_publication']['root']=(O/'candidate').as_posix()
 ri['body_preservation']['historical2fa_projection']=pointer(H/'root-source/INPUTS.json');ri['body_preservation']['current_source_status']='ACTUAL_CURRENT53E_M0_BOUND_ONLY; A_POSTA_FINAL_QUALIFICATION_ABSENT'
 check(ri['sealed'] is False and ri['normal_root']['known_A_identity'] is None and all(ri[k] is None for k in ['A_QUALIFICATION','A_OUTER_SESSION','source_review_authority','post_a_history_contract']),'ROOT_SEALED_FALSE_ALL_CURRENT_A_ADMISSIONS_ABSENT')
 fragment=prior('root-current-adapter.fragment.py').decode().replace("contract['result_fields_exact29']","contract['result_fields_exact30']").replace('len(setup) == 29','len(setup) == 30').replace("len(setup['commit_chain']) == 10","len(setup['commit_chain']) == 11").replace("contract['derived_native_count'] == 269","contract['derived_native_count'] == 277").replace('len(COMMITTED_FILES)==5','len(COMMITTED_FILES)==3')
 guard="    assert json.loads(file_pin(Path(contract['early_failure_retirement_source']['path']),contract['early_failure_retirement_source'])) == contract['early_failure_retirement_expected']"
 fragment=once(fragment,guard,guard+"\n    assert setup['current_customer_prepare_failure_retirement'] == contract['customer_prepare_failure_retirement_expected']\n    assert json.loads(file_pin(Path(contract['customer_prepare_failure_retirement_source']['path']),contract['customer_prepare_failure_retirement_source'])) == contract['customer_prepare_failure_retirement_expected']")
 fragment=fragment.replace('These remain three distinct','These remain four distinct')
 rm=prior('root-source/root-ui-materialize-freeze.py').decode();rs=prior('root-source/root-ui-start-to-ready.py').decode()
 rm=once(rm,prior('root-current-adapter.fragment.py').decode(),fragment)
 for k,v in [('HEAD',HEAD),('PREVIOUS',HEAD),('COMMIT_PARENT',PARENT),('COMMITTED_FILES',committed),('COMMITTED_PATHS',set(r['path'] for r in committed)),('SOURCE_ROWS',source_rows),('NORMAL_SOURCE_PINS',normalroot['members']),('ROOT_INPUTS_PIN',pin(enc(ri))),('CURRENT_M_SOURCE_CONTRACT',contract),('OLD_CLI_PINS',{r['path']:r['sha256'] for r in normalpub['selected9'] if r['path'] in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']}),('OLD_COORDINATOR_SHA',pin(sraw)['sha256'])]:rm=constant(rm,k,v)
 rm=expression(rm,'NORMAL_SOURCE','Path('+repr(AP.as_posix())+')');rm=expression(rm,'PROFILE_SOURCE','Path('+repr((O/'candidate').as_posix())+')');rm=expression(rm,'RUNTIME','Path('+repr((O/'runtime').as_posix())+')');rm=editfunc(rm,'main',lambda t:once(t,'len(committed) == len(COMMITTED_PATHS) == 5','len(committed) == len(COMMITTED_PATHS) == 3'))
 for k,v in [('SOURCE',HEAD),('ROOT_INPUTS_PIN',pin(enc(ri))),('CURRENT_CORE_AUX_AUTHORITY_PIN',coreptr),('PROFILE_ARTIFACTS',[{'artifact':f,**pin(out[f])} for f in selected]),('PINS',{f:pin(out[f])['sha256'] for f in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']}),('SOURCE_BYTES',{f:len(out[f]) for f in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']})]:rs=constant(rs,k,v)
 for name in ['current_core_aux_authority','main']:
  rs=editfunc(rs,name,lambda t:t.replace("'1b819d0f7b9e7c6e58c9847a2868b696d9070151'",repr(PARENT)).replace("Path('"+str(B/'full-a-initializer-customer-form-mount-next-source-r1/validation').replace('\\','/')+"')",'Path('+repr(AP.as_posix())+')').replace("len(inputs['committed_files']) == 5","len(inputs['committed_files']) == 3"))
 rs=expression(rs,'RUNTIME','Path('+repr((O/'runtime').as_posix())+')')
 root={'DESIGN-TASKS.md':prior('root-source/DESIGN-TASKS.md')+b'\n\nCurrent53e Source and actual M0 only:30 fields/11steps/277; historical2fa retained; A/postA/final qualification absent. No current B admission.\n','INPUTS.json':enc(ri),'root-ui-materialize-freeze.py':rm.encode(),'root-ui-start-to-ready.py':rs.encode()}
 rootpins=json.loads(prior('root-source/PINS.json'));rootpins['members']={f:pin(raw) for f,raw in root.items()}

 # Reuse the exact two membership seams; only the signed current library row changes.
 preopt,_=rebind_line(prior('optimization-before-current2fa/ms4-initialize.php').decode(),oldrow,newrow);op=json.loads(prior('INITIALIZER-MEMBERSHIP-SOURCE-PROOF.json'));rollback=out['ms4-initialize.php'].decode()
 for seam in reversed(op['seams']):rollback=once(rollback,seam['replacement'],seam['original'])
 check(rollback==preopt,'SAME_TWO_MEMBERSHIP_SEAMS_ONLY_CURRENT_LIB_LITERAL_SUCCESSOR')
 op.update(before=pin(preopt.encode()),candidate=pin(out['ms4-initialize.php']),checkpoint=(O/'optimization-before-current53e/ms4-initialize.php').as_posix(),historical_previous2fa_proof=pointer(H/'INITIALIZER-MEMBERSHIP-SOURCE-PROOF.json'),current53e_initializer_PHP82_syntax='NOT RUN_B_UI_DESCRIPTOR_SUCCESSOR',A_postA_final_seal=None)
 op['models']=[]
 for leftkey,rightkey in [('customer_source_files','fulfillment_source_files'),('fulfillment_source_files','aftersale_review_source_files')]:
  left=freeze[leftkey];right=freeze[rightkey];lookup={'path:'+r['path']:True for r in left};filtered=[r for r in right if type(r['path']) is str and 'path:'+r['path'] in lookup]
  check(all(type(r['path']) is str for r in left+right) and filtered==left and len(lookup)==len(left),'CURRENT_REAL_M_METADATA_COMPLETE_MEMBERSHIP_ORDER_'+leftkey)
  op['models'].append({'left_group':leftkey,'right_group':rightkey,'left_count':len(left),'right_count':len(right),'original_result':pin(enc(left)),'lookup_model_result':pin(enc(filtered)),'complete_order_and_all_descriptors_equal':True,'fixed_prefix':'path:','proof_kind':'CURRENT_COMPLETED_M_METADATA_STRING_PATHS_AND_EXACT_SAME_TWO_SOURCE_SEAMS_NOT_PHP_MANIFEST_EXECUTION'})
 od=patch(preopt.encode(),out['ms4-initialize.php'],'ms4-initialize.php');orr=patch(out['ms4-initialize.php'],preopt.encode(),'ms4-initialize.php');check(replay(preopt.encode(),od)==out['ms4-initialize.php'] and replay(out['ms4-initialize.php'],orr)==preopt.encode(),'CURRENT_INITIALIZER_MEMBERSHIP_FULL_FORWARD_REVERSE');op.update(delta_forward=pin(od),delta_reverse=pin(orr))

 preservation={}
 for f in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
  olddefs=functions(prior('candidate/'+f).decode());newdefs=functions(out[f].decode());changed=[name for name in olddefs if olddefs[name]!=newdefs[name]]
  check(changed==(['ui_fixed_sources'] if f=='verify-zero-ms4-fulfillment.py' else []),'ONLY_NECESSARY_ZERO_SHARED_LIB_LITERAL_FUNCTION_DELTA_'+f);preservation[f]={'changed_functions':changed,'all_other_original_functions_exact':True}
 for name in ['known_normal_root','consume_materialization','verify_sources']:
  check(functions(prior('root-source/root-ui-materialize-freeze.py').decode())[name]==functions(rm)[name],'R40_HISTORY_FUNCTION_BYTE_EXACT_'+name)
 marker="    require(stage('db-up')";oldrs=prior('root-source/root-ui-start-to-ready.py').decode()
 if marker in oldrs:check(rs[rs.index(marker):]==oldrs[oldrs.index(marker):],'ROOT_S_ORIGINAL_DBUP_TO_READY_SUFFIX_BYTE_EXACT')
 for text in [rm,rs,fragment,out['ms4-host.py'].decode(),out['normal-close-ms4-fulfillment.py'].decode(),out['verify-zero-ms4-fulfillment.py'].decode()]:ast.parse(text)
 check(constants(rm)['CURRENT_M_SOURCE_CONTRACT']==contract and "result_fields_exact30" in fragment and "len(setup['commit_chain']) == 11" in fragment and 'derived_native_count\'] == 277' in fragment,'ROOT_M30_11_277_ADAPTER_ACTUAL_CONTRACT_BOUND')
 for f in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
  check(constants(out[f].decode())['UI17_SOURCE_SEAL']=='UNSEALED_PENDING_JOINT_FULL17_SOURCE_QUALIFICATION','EXACT_UNSEALED_ENTRY_'+f)
 check("const M4I_UI17_SOURCE_SEAL = 'UNSEALED_PENDING_JOINT_FULL17_SOURCE_QUALIFICATION';" in out['ms4-initialize.php'].decode(),'EXACT_UNSEALED_INITIALIZER_ENTRY')
 rmolddefs=functions(prior('root-source/root-ui-materialize-freeze.py').decode());rmnewdefs=functions(rm)
 check([name for name in rmolddefs if rmolddefs[name]!=rmnewdefs[name]]==['main','current_known_normal_root','current_consume_materialization','current_verify_sources'],'ROOT_M_ONLY_FOUR_NECESSARY_CURRENT_SOURCE_FUNCTIONS_CHANGED')
 check(constants(out['ms4-host.py'].decode())['CUSTOMER_FORM_SOURCE_FILES']==constants(out['verify-zero-ms4-fulfillment.py'].decode())['CUSTOMER_FORM_SOURCE_FILES']==currentcu,'HOST_ZERO_CURRENT_CU7_TABLES')
 check(constants(out['ms4-host.py'].decode())['INTERFACE_SHA256']==pin(out['interface-v1.json'])['sha256'],'HOST_INTERFACE_CURRENT_DAG')
 for f in ['normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
  cv=constants(out[f].decode())
  for key in ['HOST_SHA256','UI_HOST_SHA256']:check(cv[key]==pin(out['ms4-host.py'])['sha256'],'CURRENT_HOST_DAG_'+f+'_'+key)
 cv=constants(out['verify-zero-ms4-fulfillment.py'].decode())
 check(all(cv[key]==pin(out['normal-close-ms4-fulfillment.py'])['sha256'] for key in ['CLOSE_SHA256','UI_CLOSE_SHA256']) and all(cv[key]==pin(out['interface-v1.json'])['sha256'] for key in ['INTERFACE_SHA256','UI_INTERFACE_SHA256']) and all(cv[key]==len(out['interface-v1.json']) for key in ['INTERFACE_BYTES','UI_INTERFACE_BYTES']),'ZERO_CLOSE_INTERFACE_HASH_AND_BYTES_DAG')
 check(ri['ui_profile_publication']['root']==(O/'candidate').as_posix() and pub['finite_contexts']['browser_manifest']['path']==core['browser_manifest']['path']==record['browser8_manifest']['path']==(O/'browser-v1/PINS.json').as_posix(),'ACTIVE_UI_BROWSER_ROOT_POINTERS_ALL_CURRENT_R3')
 check(d['current_nonself_artifacts']==[{'path':f,**pin(out[f])} for f in selected if f!='source-derivation.json'] and profilepins['members']['PUBLICATION.json']==pin(enc(pub)) and rootpins['members']=={f:pin(raw) for f,raw in root.items()},'ACTUAL_NONSELF16_PROFILE19_ROOT4_COMPLETE_DAG')
 for f in ['ms4-order.png','manifests/ui-h5-dist.json','manifests/ui-merchant-dist.json','manifests/ui-admin-dist.json','manifests/ui-public-copy-profile.json']:check(out[f]==prior('candidate/'+f),'ORIGINAL_STATIC_MATERIAL_BYTES_UNCHANGED_'+f)
 pairs=[(f,read(O/'before'/f),out[f]) for f in selected]+[('browser-v1/'+f,read(O/'before/browser-v1'/f),browser[f]) for f in browsernames]+[('browser-v1/PINS.json',read(O/'before/browser-v1/PINS.json'),browsermanifest),('current-core-aux/CORE-AUX-AUTHORITY.json',read(O/'before/current-core-aux/CORE-AUX-AUTHORITY.json'),coreraw)]+[('root-source/'+f,read(O/'before/root-source'/f),raw) for f,raw in root.items()]
 diffs=[];reverses=[];transforms=[]
 for name,a,b in pairs:
  if a!=b:
   forward=patch(a,b,name);reverse=patch(b,a,name);check(replay(a,forward)==b and replay(b,reverse)==a,'FULL_PAIR_FORWARD_REVERSE_'+name);diffs.append(forward);reverses.append(reverse)
  transforms.append({'path':name,'before':pin(a),'candidate':pin(b),'changed':a!=b,'whole_forward_reverse':True})
 check(len(pairs)==31 and len(proofs)==26 and len(d['current_nonself_artifacts'])==16 and len(selected)==17 and len(browser)==8,'FULL31_CONTEXT26_NONSELF16_SELECTED17_BROWSER8_PRESERVED')
 emitted={'CURRENT-A-SOURCE-BINDING.json':enc(binding),'CURRENT-M-SOURCE-CONTRACT.json':contractraw,'CURRENT-SETS.json':enc(newsets),'DESIGN-TASKS.md':design,'root-current-adapter.fragment.py':fragment.encode(),'current-core-aux/CORE-AUX-AUTHORITY.json':coreraw,'candidate/DESIGN.md':design,'candidate/PUBLICATION.json':enc(pub),'candidate/PINS.json':enc(profilepins),'root-source/PINS.json':enc(rootpins),'optimization-before-current53e/ms4-initialize.php':preopt.encode(),'INITIALIZER-MEMBERSHIP-SOURCE-PROOF.json':enc(op),'INITIALIZER-MEMBERSHIP-DIFF.patch':od,'INITIALIZER-MEMBERSHIP-REVERSE.patch':orr,'DIFF.patch':b''.join(diffs),'REVERSE.patch':b''.join(reverses),'SOURCE-TRANSFORMS.json':enc(transforms),'FUNCTION-PRESERVATION.json':enc({'previous2fa':pointer(H/'FUNCTION-PRESERVATION.json'),'current53e_delta':preservation,'Root_old_history_functions':'EXACT','Root_S_business_suffix':'EXACT'})}
 emitted.update({'candidate/'+f:raw for f,raw in out.items()});emitted.update({'root-source/'+f:raw for f,raw in root.items()})
 emitted.update({'browser-v1/'+f:raw for f,raw in browser.items()});emitted['browser-v1/PINS.json']=browsermanifest
 metadata={'scope':'CURRENT53E_ACTUAL_M0_METADATA_ROOT277_READBACK_REUSED_SOURCE_PROJECTION_ONLY','current_M_result':cmptr,'current_M_outcome':outcome,'current_M_Root_raw_readback':contract['M_Root_raw_readback'],'previous2fa_projection':pointer(H/'HISTORY.json'),'actual_current_Source_adoption':adoption,'current_Source_requirements68':68,'current_UI_Source69':69,'normal_union':10886,'tracked_paths15504':'Same complete names as historical2fa; exact actual three existing paths and complete current M snapshot descriptor retained. Root full current M qualification reused; no repeated31008 row audit.','complete_current_snapshot':cm['complete_source_snapshot'],'native277_raw':'NOT REPEATED; exact completed Root readback referenced','current_WVal_Run_context':'NOT READ','current_A_postA_final':None}
 emitted['DRAFT-M-METADATA-PROOF.json']=enc(metadata)
 emitted['SOURCE-QUALIFICATION.json']=enc({'schema':1,'status':'MUTABLE_CURRENT53E_SOURCE_AND_ACTUAL_M0_NOT_FINAL_A_ADMISSION','checks':checks,'current_A_Source':binding,'current_M0':cmptr,'current_M_fields':30,'current_M_steps':11,'native_M_budget':277,'historical2fa_projection':pointer(H/'HISTORY.json'),'all_current_A_postA_final_values':None,'runtime_eligible':False,'PHP_B_initializer_syntax_manifest_performance':'NOT RUN','target_native_operations':0})
 emitted['DRAFT-STATE.json']=enc({'status':'MUTABLE_R2_CURRENT53E_M0_ONLY_NOT_RUNTIME','source_sha':HEAD,'current_M0':cmptr,'historical2fa_M0':oldcontract['M_result'],'A_and_postA':None,'all_four_UI_entry_guards':'UNSEALED','Root_inputs_sealed':False,'final_outer_PINS_REPORT':'NOT CREATED'})
 emitted['AUTHOR-STAGE.json']=enc({'status':'MUTABLE_CURRENT53E_SOURCE_AND_M0_BINDING_COMPLETE_A_POSTA_ABSENT','source_sha':HEAD,'runtime':False,'A_postA_final':None})
 # The old full author is preserved in history. This current entry is intentionally a narrow binder.
 emitted['author.py']=("\"\"\"Current mutable B Source binder; complete former2fa author retained in rebind-before-53e.\"\"\"\nfrom pathlib import Path\nimport runpy\nif __name__ == '__main__':\n    runpy.run_path(str(Path(__file__).parent/'rebind_current53e.py'),run_name='__main__')\n").encode()
 for name,raw in emitted.items():write(O/name,raw)
 for name,raw in emitted.items():
  check(read(O/name)==raw,'ACTUAL_EMITTED_FULL_BYTES_'+name)
 for name,expected in hist['files'].items():
  check(pin(read(PREVIOUS/name))==expected,'PRIOR_R2_NAMED_SOURCE_STILL_EXACT_'+name)
 ended_ns=time.time_ns();assert all(v['closed'] and v['EOF'] and v['ended_ns']<=ended_ns for v in ROWS.values())
 draftmembers=[{'path':name,**pin(raw)} for name,raw in emitted.items()]+[{'path':'rebind_current53e.py',**compact(binder_pointer)}]
 for name in ['candidate/PINS.json','browser-v1/PINS.json','root-source/PINS.json']:
  check(name in [r['path'] for r in draftmembers],'CURRENT_NESTED_PIN_COVERAGE_'+name)
 draftmembers.append({'path':'before/browser-v1/PINS.json',**pin(read(O/'before/browser-v1/PINS.json'))})
 check(len(draftmembers)==len({row['path'] for row in draftmembers}),'DRAFT_CURRENT_GENERATED_MEMBER_NAMES_UNIQUE')
 ended_ns=time.time_ns()
 write(O/'DRAFT-FINITE-WINDOWS.json',enc({'status':'DRAFT_FULL_EOF_NATIVE9_WINDOWS_CLOSED_NOT_FINAL_SEAL','input_count':len(ROWS),'ended_ns':ended_ns,'all_input_windows_ended':True,'inputs':ROWS}))
 result={'status':'MUTABLE_CURRENT53E_SOURCE_AND_REAL_M0_BOUND_UNSEALED','checks':checks,'check_count':len(checks),'current_generated_members_not_final_outer_inventory':draftmembers,'Source_binder':binder_pointer,'history2fa_projection':pointer(H/'HISTORY.json'),'metadata_proof':metadata,'A_and_postA':None,'final_eligible_A_Source':None,'outer_final_PINS_REPORT_created':False,'all_draft_read_windows_ended':True,'input_count':len(ROWS),'ended_ns':ended_ns,'pending':['actual same-head full A0/Workerclose0/independentzero0/postA0','final eligible Source qualification and one-time full DAG rebind/seal review','necessary B PHP/JS syntax','actual fresh B and complete UI20/20ACK/42frames/three widths','independent H5 nine scenes/history37-fourCLI/media-device/finance-release-manual']}
 write(O/'DRAFT-CHECKS.json',enc(result))
 print(json.dumps({'DRAFT_CHECKS':pin((O/'DRAFT-CHECKS.json').read_bytes()),'contract':pin(contractraw),'initializer':pin(out['ms4-initialize.php']),'checks':len(checks),'inputs':len(ROWS),'ended_ns':ended_ns,'A_postA_final':None,'final_PINS_REPORT':'NOT CREATED'},ensure_ascii=False))

if __name__=='__main__':main()
