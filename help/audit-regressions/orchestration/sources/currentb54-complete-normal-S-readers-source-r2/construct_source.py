from pathlib import Path
import ast,difflib,hashlib,json,time
ROOT=Path('H:/天俪商城');ORG=ROOT/'artifacts/project-organization-20261023'
BEFORE=ORG/'currentb54-complete-normal-S-readers-source-r1';OUT=ORG/'currentb54-complete-normal-S-readers-source-r2'
CORE=ORG/'currentb54-next-normal-preparation-r3/normal-source';ADM=ORG/'current41c0-normal-root-admission-r1'
inputs={}
def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def read(p):
 p=Path(p);a=p.stat();raw=p.read_bytes();b=p.stat()
 assert (a.st_dev,a.st_ino,a.st_size,a.st_mtime_ns,a.st_ctime_ns)==(b.st_dev,b.st_ino,b.st_size,b.st_mtime_ns,b.st_ctime_ns)
 inputs[p.as_posix()]={'path':p.as_posix(),**fp(raw)};return raw
def put(p,raw):
 p=Path(p);p.parent.mkdir(parents=True,exist_ok=True)
 if isinstance(raw,str):raw=raw.encode('utf-8')
 if p.exists():
  if p.read_bytes()!=raw:
   assert not (OUT/'PINS.json').exists() and p.is_relative_to(OUT)
   assert p.parent.name=='candidate' or p.name.endswith('.diff') or p.name in ['SOURCE-INPUTS.json','SOURCE-REVIEW.json']
   p.write_bytes(raw)
 else:
  with p.open('xb')as f:f.write(raw)
def jput(p,obj):put(p,json.dumps(obj,ensure_ascii=False,indent=2)+'\n')
def funcs(text):
 lines=text.splitlines(keepends=True)
 return {n.name:''.join(lines[n.lineno-1:n.end_lineno])for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def literals(text):
 result={}
 for node in ast.parse(text).body:
  if isinstance(node,ast.Assign)and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
   try:result[node.targets[0].id]=ast.literal_eval(node.value)
   except (ValueError,TypeError):pass
 return result
assert not (OUT/'PINS.json').exists()
core_raw=read(CORE/'PINS.json');core=json.loads(core_raw)
script_pins={name:{k:core['files']['validation/actual-profile/'+name][k]for k in ['bytes','sha256']}for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']}
for name,pin in script_pins.items():assert fp(read(CORE/'validation/actual-profile'/name))==pin
def rebound_scripts(text):
 lines=text.splitlines(keepends=True)
 nodes=[n for n in ast.parse(text).body if isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and n.targets[0].id=='scripts']
 assert len(nodes)==1;node=nodes[0];prior=ast.literal_eval(node.value);assert set(prior)==set(script_pins)
 before_segment=''.join(lines[node.lineno-1:node.end_lineno]);segment=before_segment
 for name,pin in script_pins.items():
  assert prior[name]['bytes']==pin['bytes'] and prior[name]['sha256']!=pin['sha256']
  old_sha=repr(prior[name]['sha256']);new_sha=repr(pin['sha256']);assert segment.count(old_sha)==1
  segment=segment.replace(old_sha,new_sha)
 assert len(segment.encode())==len(before_segment.encode())
 return ''.join(lines[:node.lineno-1])+segment+''.join(lines[node.end_lineno:])
old={name:read(BEFORE/'candidate'/name)for name in ['root-reader.py','peer-reader.py','finite-helper.py']}
old_pin=read(BEFORE/'PINS.json');assert fp(old_pin)=={'bytes':2470,'sha256':'7271b438c9c41151adce351d51b7c40593f8c03f1ba1e50a5b4333aad2ac21ee'}
for name,raw in old.items():put(OUT/'before'/name,raw)
old_line="assert tree['requested_repo_source'] == freeze['requested_repo_source'] == 'H:/天俪商城/worktrees/ms4-aftersale-normal-check'"
new_line="assert tree['requested_repo_source'] == freeze['requested_repo_source'] == 'H:/天俪商城/worktrees/ms4-language-cache-normal-check'"
root_old=old['root-reader.py'].decode('utf-8');assert root_old.count(old_line)==1
root_new=rebound_scripts(root_old.replace(old_line,new_line));root_pin=fp(root_new.encode('utf-8'))
peer_old=old['peer-reader.py'].decode('utf-8')
old_pid_line="assert peer_M['actual_outer_pid'] == m_readback['outer_pid'] == 22536"
new_pid_line="assert peer_M['actual_outer_pid'] == m_readback['outer_pid'] == 16792"
assert peer_old.count(old_pid_line)==1
peer_before='r.read(Path('+repr((BEFORE/'candidate/root-reader.py').as_posix())+'), '+repr(fp(old['root-reader.py']))+')'
peer_after='r.read(Path('+repr((OUT/'candidate/root-reader.py').as_posix())+'), '+repr(root_pin)+')'
assert peer_old.count(peer_before)==1;peer_new=rebound_scripts(peer_old.replace(peer_before,peer_after).replace(old_pid_line,new_pid_line))
checks={}
for name,text in [('root-reader.py',root_new),('peer-reader.py',peer_new)]:
 prior=old[name].decode('utf-8');ast.parse(text);assert funcs(text)==funcs(prior)
 before_lines=prior.splitlines(keepends=True);after_lines=text.splitlines(keepends=True)
 changes=[row for row in difflib.SequenceMatcher(a=before_lines,b=after_lines,autojunk=False).get_opcodes()if row[0]!='equal']
 assert len(changes)==(2 if name=='root-reader.py' else 3)
 forward=list(before_lines);reverse=list(after_lines)
 for _,a,b,c,d in reversed(changes):
  assert b-a==d-c and forward[a:b]==before_lines[a:b] and reverse[c:d]==after_lines[c:d]
  forward[a:b]=after_lines[c:d];reverse[c:d]=before_lines[a:b]
 assert ''.join(forward)==text and ''.join(reverse)==prior
 put(OUT/'candidate'/name,text)
 put(OUT/(name+'.FORWARD.diff'),''.join(difflib.unified_diff(before_lines,after_lines,fromfile='before/'+name,tofile='candidate/'+name)))
 put(OUT/(name+'.REVERSE.diff'),''.join(difflib.unified_diff(after_lines,before_lines,fromfile='candidate/'+name,tofile='before/'+name)))
 checks[name]={'before':fp(old[name]),'candidate':fp(text.encode('utf-8')),'diff_splices':len(changes),'only_F1_F2_F3_and_peer_Root_Source_identity':True,'exact_module_forward_reverse':True,'all_functions_and_classes_raw':list(funcs(text))}
put(OUT/'candidate/finite-helper.py',old['finite-helper.py'])
root_report=ROOT/'artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r2/REPORT.json'
actual_root=json.loads(read(root_report));actual_peer=json.loads(read(ORG/'currentb54-complete-materialization-independent-readback-r2/REPORT.json'))
accepted=json.loads(read(ADM/'R108-B54-M-DUAL-READERS-ACTUAL-ROOT-ACCEPTANCE.json'))
callback=json.loads(read(ADM/'R106-B54-CALLBACK-ACTUAL-ROOT-READBACK.json'))
origin=actual_root['complete_tracked_source_tree_verified']['Val_origin']
assert actual_peer['source_tree_complete_freeze_plan']['requested_repo_source']==origin==(ROOT/'worktrees/ms4-language-cache-normal-check').as_posix()
assert origin!=(ROOT/'worktrees/ms4-aftersale-normal-check').as_posix()
assert actual_peer['actual_outer_pid']==actual_root['outer_pid']==accepted['actual_M_PID']==16792
assert old_pid_line not in peer_new and new_pid_line in peer_new
for name,text in [('root-reader.py',root_new),('peer-reader.py',peer_new)]:
 const=literals(text)
 assert const['SOURCE_PINS']==fp(core_raw)
 assert const['ROOT_SOURCE_PINS']=={k:core['files']['validation/PINS.json'][k]for k in ['bytes','sha256']}
 assert const['M_RESULT']=={k:accepted['actual_RESULT'][k]for k in ['bytes','sha256']}
 assert const['FREEZE']=={k:accepted['actual_freeze'][k]for k in ['bytes','sha256']}
 assert const['M_ROOT_READBACK']==fp(read(root_report))
 assert const['driver_pin']=={k:core['files']['validation/run.py'][k]for k in ['bytes','sha256']}
 assert const['scripts']==script_pins
 assert const['CALLBACK_ROOT_EXPECTED']==callback
 for leaf,pin in const['PROFILE_SOURCE_PINS9'].items():assert pin=={k:core['files']['validation/actual-profile/'+leaf][k]for k in ['bytes','sha256']}
 matches=[n.test.comparators[0]for n in ast.parse(text).body if isinstance(n,ast.Assert)and isinstance(n.test,ast.Compare)and isinstance(n.test.left,ast.Name)and n.test.left.id=='dual_M_qualification']
 assert len(matches)==1 and ast.literal_eval(matches[0])==accepted
 assert "'current25_b54_profile9_bafb_finite420_and_Source33_input48_complete': True" in text
jput(OUT/'SOURCE-INPUTS.json',{'scope':'r1_Sources_and_existing_actual_M_Root_public_producers_only','files':list(inputs.values()),'candidate_or_target_execution':False})
jput(OUT/'SOURCE-REVIEW.json',{'status':'AUTHOR_COMPLETE_SOURCE_STATIC_F1_F2_F3_GREEN_PENDING_ROOT_AND_FRESH_R2_REVIEW','programs':checks,'helper_raw':fp(old['finite-helper.py']),'actual_Val_origin':origin,'r1_F1_wrong_Val_predicate':False,'r2_same_strict_tree_equals_freeze_equals_Val_predicate':True,'r2_F2_same_three_script_bytes_and_actual_Core_profile9_file_SHA':script_pins,'r2_F3_same_strict_M_PID_conjunction_matches_actual_Root_peer_and_acceptance':16792,'actual_Core_M_Result_freeze_profile9_driver_callback_Root_acceptance_literal_connections':True,'actual_output_namespace_derived_from_new___file__':True,'r1_sealed17_preserved':True,'readers_or_targets_imported_executed':False,'S_Runtime_admission':'NOT_CREATED','created_ns':time.time_ns()})
readme=OUT/'README.md';text=readme.read_text('utf-8');text=text.replace('SOURCE_WORK_IN_PROGRESS_UNSEALED','AUTHOR_SOURCE_COMPLETE_STATIC_ONLY_PENDING_ROOT_AND_FRESH_R2_REVIEW')
text+='\n最终Source记录：F1/F2/F3均已按真实公开producer/当前三整profile文件作静态红绿连接，Root/peer模块精确正逆复原、全部16/24函数类与helper逐字保持。原三profile读取和全业务断言未改；没有候选导入/执行或S读回准入。Root与fresh对r2的只读审查仍待，PINS仅封Source不授运行。\n'
readme.write_text(text,'utf-8',newline='\n')
for name,pin in json.loads(old_pin)['files'].items():assert fp((BEFORE/name).read_bytes())==pin
members=sorted(p for p in OUT.rglob('*')if p.is_file())
jput(OUT/'PINS.json',{'schema':1,'status':'SOURCE_ONLY_PENDING_ROOT_AND_FRESH_R2_REVIEW_NOT_RUNTIME_ADMISSION','files':{p.relative_to(OUT).as_posix():fp(p.read_bytes())for p in members},'member_count':len(members),'actual_readers':'NOT_RUN'})
print(json.dumps({'out':OUT.as_posix(),'programs':checks,'helper':fp(old['finite-helper.py']),'PINS_exists':(OUT/'PINS.json').exists(),'current_files':len([p for p in OUT.rglob('*')if p.is_file()]),'F1_actual_producer_static':'RED1_TO_GREEN0'},ensure_ascii=False))
