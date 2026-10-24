"""Own nine-profile Source preparation only; never run/import a target or a native tool."""
from pathlib import Path
import ast,copy,difflib,hashlib,json,os,re
O=Path(__file__).resolve().parent;ORG=O.parent.parent
N=ORG/'currentd0a5-next-normal-preparation-r1/normal-source'
HEAD='bafb6305062aeb6b6e945c21da572863062ca480';PARENT='d0a5d5398546e223122b5c2a2f115e1908f78ced'
AR='help/audit-regressions/isolated/ms4-aftersale-review-normal.php';DOC='help/audit-regressions/isolated/ms4-aftersale-warm-failure-context.md'
READS={}
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def read(path,expected=None):
 path=Path(path);a=path.lstat();assert path.is_file() and not path.is_symlink()
 with path.open('rb') as f:
  b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
 d=path.lstat()
 for x,y in [(a,d),(b,c)]:assert all(getattr(x,k)==getattr(y,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns','st_birthtime_ns'])
 assert all(getattr(a,k)==getattr(b,k) for k in ['st_dev','st_ino','st_size','st_mtime_ns','st_birthtime_ns'])
 row={'path':path.as_posix(),**pin(raw)}
 if expected:assert all(row[k]==expected[k] for k in ['bytes','sha256'])
 if row['path'] in READS:assert READS[row['path']]==row
 READS[row['path']]=row;return raw
def obj(path,expected=None):return json.loads(read(path,expected))
def pointer(path):return {'path':Path(path).as_posix(),**pin(read(path))}
def save(name,raw):
 p=O/name;p.parent.mkdir(parents=True,exist_ok=True)
 with p.open('xb') as f:assert f.write(raw)==len(raw)
 assert read(p)==raw
def put(name,v):save(name,enc(v))
def defs(raw):return {n.name:ast.get_source_segment(raw.decode(),n) for n in ast.parse(raw).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def constants(raw):
 out={}
 for n in ast.parse(raw).body:
  if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name):
   try:out[n.targets[0].id]=ast.literal_eval(n.value)
   except (ValueError,TypeError):pass
 return out
def const(raw,key,value):
 text=raw.decode();nodes=[n for n in ast.parse(raw).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id==key];assert len(nodes)==1
 old=ast.get_source_segment(text,nodes[0].value);assert text.count(old)>=1
 lines=text.splitlines(True);node=nodes[0];assert node.lineno==node.end_lineno
 line=lines[node.lineno-1];offset=node.value.col_offset;end=node.value.end_col_offset
 assert line[offset:end]==old;lines[node.lineno-1]=line[:offset]+repr(value)+line[end:];return ''.join(lines).encode()
def patch(before,after):
 return ''.join(''.join(difflib.unified_diff(before[n].decode().splitlines(True),after[n].decode().splitlines(True),fromfile='before/'+n,tofile='candidate/'+n)) for n in before if before[n]!=after[n]).encode()
def replay_files(before,patchraw):
 result=dict(before);lines=patchraw.decode().splitlines(True);i=0
 while i<len(lines):
  assert lines[i].startswith('--- ');name=lines[i][4:].strip().split('/',1)[1];assert lines[i+1].startswith('+++ ');i+=2;source=result[name].decode().splitlines(True);out=[];pos=0
  while i<len(lines) and lines[i].startswith('@@ '):
   m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\r\n]*\r?\n',lines[i]);assert m
   start=int(m[1])-1;assert start>=pos;out.extend(source[pos:start]);pos=start;i+=1;old=new=0
   while i<len(lines) and not lines[i].startswith(('@@ ','--- ')):
    line=lines[i];assert line[0] in ' +-'
    if line[0] in ' -':assert source[pos]==line[1:];pos+=1;old+=1
    if line[0] in ' +':out.append(line[1:]);new+=1
    i+=1
   assert (old,new)==(int(m[2] or 1),int(m[4] or 1))
  out.extend(source[pos:]);result[name]=''.join(out).encode()
 return result

def main():
 assert not (O/'PINS.json').exists()
 design=read(O/'inputs/ROOT-DESIGN.md',{'bytes':1218073,'sha256':'7959f35b8be6393568fdc8d7cecd2c1f59a477dbc8a12f7296aaf0103f1a1d9c'})
 lp=ORG/'currentd0a5-warm-failure-context-local-commit-actual-r2/REPORT.json';vp=ORG/'currentbafb-val-update-actual-r1/REPORT.json'
 local=obj(lp,{'bytes':16331,'sha256':'af507526be5a252b7005e259a626b93806bf9798ef77e3cdc98662f3ff497059'});val=obj(vp,{'bytes':4316,'sha256':'ca9d7d83d54e1b1485a39ad6ea902fe20c36917312bf5aff2216d1c6afe764d0'})
 assert local['head']==val['new_head']==HEAD and local['parent']==val['old_head']==PARENT and local['status']=='LOCAL_SCOPED_COMMIT_COMPLETE_NOT_PUSHED'
 assert local['paths']==val['changed_paths_from_parent']==[AR,DOC] and local['only_exact_two_paths_committed'] is local['committed_PHP_equals_reviewed_and_syntax_checked_candidate'] is local['worktree_index_clean'] is True and local['push_deploy_production']=='NOT_RUN'
 assert val['status']=='CLEAN_VALIDATION_WORKTREE_ALIGNED_TO_LOCAL_REVIEWED_COMMIT' and len(val['operations'])==6 and all(x['native_exit']==x['wait_returncode']==0 for x in val['operations'])
 save('inputs/ROOT-LOCAL-COMMIT.json',read(lp));save('inputs/ROOT-VAL-UPDATE.json',read(vp))
 packet=obj(N/'PINS.json',{'bytes':87279,'sha256':'d1ee9ba1981bdc56bf5c694478f455797a810d9635e5053b320cebd30759e6ae'});pub=obj(N/'validation/PROFILE-PUBLICATION.json',packet['files']['validation/PROFILE-PUBLICATION.json']);assert pub['source_sha']==PARENT
 order=[r['path'] for r in pub['selected9']];assert len(order)==9
 before={r['path']:read(N/'validation/actual-profile'/r['path'],r) for r in pub['selected9']};after=dict(before)
 for name,body in before.items():save('before/profile/'+name,body)
 save('inputs/PRIOR-SOURCE-PINS.json',read(N/'PINS.json'));save('inputs/PRIOR-PROFILE-PINS.json',read(N/'validation/PROFILE-PINS.json'))
 oldar={'bytes':239862,'sha256':'6734e3916c3bde501fb57e4113e33ddb8ca697ec24d17ce617df2527652cab93'};newar={'bytes':244912,'sha256':'a1258f537a3aa2de776d5a64afa5621141df0173e6d602feb168bd1f0c12ea0c'};doc={'bytes':1781,'sha256':'b3092b34dbae1e421072f21e1217d6f36b0cd175b1b997391bd2b1998f0c301b'}
 for label,root in [('W',Path('H:/天俪商城/worktrees/ms4-asset-consumers')),('Val',Path('H:/天俪商城/worktrees/ms4-aftersale-normal-check'))]:
  for relative,wanted in [(AR,newar),(DOC,doc)]:
   body=read(root/relative,wanted)
   if label=='W':save('inputs/current/'+relative,body)
 text=before['ms4-initialize.php'].decode();seams=[]
 for old,new in [("'bytes' => 239862, 'sha256' => '"+oldar['sha256']+"'","'bytes' => 244912, 'sha256' => '"+newar['sha256']+"'"),("[239862, '"+oldar['sha256']+"']","[244912, '"+newar['sha256']+"']")]:
  assert text.count(old)==1;text=text.replace(old,new,1);seams.append({'before':old,'after':new})
 after['ms4-initialize.php']=text.encode();restored=text
 for row in reversed(seams):assert restored.count(row['after'])==1;restored=restored.replace(row['after'],row['before'],1)
 assert restored.encode()==before['ms4-initialize.php']
 interface=json.loads(before['interface-v1.json']);oldseven=copy.deepcopy(interface['customer_form_current_profile']['php_sources']);seven=[{'path':r['path'],**newar} if r['path']==AR else r for r in oldseven];assert len(seven)==7 and sum(a!=b for a,b in zip(oldseven,seven))==1
 entry=interface['aftersale_review_normal_successor'];assert (entry['entry_bytes'],entry['entry_sha256'])==(oldar['bytes'],oldar['sha256']);entry['entry_bytes']=newar['bytes'];entry['entry_sha256']=newar['sha256'];interface['customer_form_current_profile']['php_sources']=seven
 tree=interface['source_tree_runtime_profile']['actual_candidate_tracked_sources'];assert tree[0]=={'path':AR,**oldar};tree[0]={'path':AR,**newar};after['interface-v1.json']=enc(interface)
 allowed={'ms4-host.py':{'CUSTOMER_FORM_SOURCE_FILES','AFTERSALE_REVIEW_ENTRY_BYTES','AFTERSALE_REVIEW_ENTRY_SHA256','INTERFACE_SHA256'},'normal-close-ms4-fulfillment.py':{'HOST_SHA256'},'verify-zero-ms4-fulfillment.py':{'CUSTOMER_FORM_SOURCE_FILES','AFTERSALE_REVIEW_ENTRY_BYTES','AFTERSALE_REVIEW_ENTRY_SHA256','INTERFACE_SHA256','INTERFACE_BYTES','HOST_SHA256','CLOSE_SHA256'}}
 for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
  assert list(constants(before[name])['CUSTOMER_FORM_SOURCE_FILES'])==oldseven
  for key,value in [('CUSTOMER_FORM_SOURCE_FILES',tuple(seven)),('AFTERSALE_REVIEW_ENTRY_BYTES',newar['bytes']),('AFTERSALE_REVIEW_ENTRY_SHA256',newar['sha256'])]:after[name]=const(after[name],key,value)
 after['ms4-host.py']=const(after['ms4-host.py'],'INTERFACE_SHA256',pin(after['interface-v1.json'])['sha256']);after['normal-close-ms4-fulfillment.py']=const(after['normal-close-ms4-fulfillment.py'],'HOST_SHA256',pin(after['ms4-host.py'])['sha256'])
 for key,value in [('INTERFACE_SHA256',pin(after['interface-v1.json'])['sha256']),('INTERFACE_BYTES',len(after['interface-v1.json'])),('HOST_SHA256',pin(after['ms4-host.py'])['sha256']),('CLOSE_SHA256',pin(after['normal-close-ms4-fulfillment.py'])['sha256'])]:after['verify-zero-ms4-fulfillment.py']=const(after['verify-zero-ms4-fulfillment.py'],key,value)
 deriv=json.loads(before['source-derivation.json']);prior_entry=copy.deepcopy(deriv['new_business_entry']);assert {k:prior_entry[k] for k in ['path','bytes','sha256']}=={'path':AR,**oldar};deriv['new_business_entry'].update(newar);deriv['new_business_entry']['status']='ACTUAL_LOCAL_COMMITTED_BYTES_NORMAL_NOT_EXECUTED';deriv['new_business_entry']['source']='Actual bafb local commit and clean Main/Val; final push and complete new normal execution UNBOUND';node='bafb_warm_failure_context_source_successor';assert node not in deriv
 deriv[node]={'schema':1,'scope':'SOURCE_ONLY_LOCAL_BAFB_WARM_FAILURE_DIAGNOSTIC_PROFILE_PIN_REFRESH','source_sha':HEAD,'parent':PARENT,'source_admission':'LOCAL_ONLY_PENDING_FINAL_PUSH','remote_qualification':'UNBOUND','actual_local_commit':pointer(lp),'actual_validation_update':pointer(vp),'frozen_Root_design':pointer(O/'inputs/ROOT-DESIGN.md'),'current_seven_PHP':seven,'new_tracked_document':{'path':DOC,**doc},'initializer_two_identity_seams':seams,'current_initializer':pin(after['ms4-initialize.php']),'same_three_read_owner_helper':constants(before['ms4-host.py'])['THREE_READ_SOURCE_FILES'],'all_original_Python_functions':'RAW_IDENTICAL','all_other_initializer_bytes':'RAW_IDENTICAL','normal_success_result_keys':29,'diagnostic_keys':4,'failure_only_optional_top_level':'warm_failure_context','safe_context_fields':['ordinal','logical_name','expected_null','expected_wire_bytes','expected_wire_sha256','raw_type','raw_bytes','raw_sha256'],'future_failure_projection_qualification':'Exact new producer and new Run/source/freeze/CID/nonzero terminal, original phase/code and same original four-field fingerprint; strict typed eight-field/fixed40 ordinal-name whitelist; no values/trace/new reads/writes; old d0a5 projection is not new evidence.','new_initializer_actual_PHP82_syntax':'UNBOUND_NOT_RUN','actual_export_M_S_readers_postA_UI':'UNBOUND_NOT_RUN'}
 deriv['current_nonself_artifacts']=[{'path':name,**pin(after[name])} for name in order if name!='source-derivation.json'];after['source-derivation.json']=enc(deriv)
 restore=copy.deepcopy(interface);restore['aftersale_review_normal_successor']['entry_bytes']=oldar['bytes'];restore['aftersale_review_normal_successor']['entry_sha256']=oldar['sha256'];restore['customer_form_current_profile']['php_sources']=oldseven;restore['source_tree_runtime_profile']['actual_candidate_tracked_sources'][0]={'path':AR,**oldar};assert enc(restore)==before['interface-v1.json']
 restore=copy.deepcopy(deriv);restore.pop(node);restore['new_business_entry']=prior_entry;restore['current_nonself_artifacts']=json.loads(before['source-derivation.json'])['current_nonself_artifacts'];assert enc(restore)==before['source-derivation.json']
 counts={};constant_seams=[]
 for name,keys in allowed.items():
  assert defs(before[name])==defs(after[name]);counts[name]=len(defs(before[name]));a=before[name].decode();z=after[name].decode();av=constants(before[name]);zv=constants(after[name]);assert {k:v for k,v in av.items() if k not in keys}=={k:v for k,v in zv.items() if k not in keys};restored=z
  for key in sorted(keys):
   get=lambda raw:next(x for x in ast.parse(raw).body if isinstance(x,ast.Assign) and len(x.targets)==1 and isinstance(x.targets[0],ast.Name) and x.targets[0].id==key)
   oldstmt=ast.get_source_segment(a,get(a));newstmt=ast.get_source_segment(z,get(z));assert restored.count(newstmt)==1;restored=restored.replace(newstmt,oldstmt,1);constant_seams.append({'path':name,'assignment':key,'before':oldstmt,'after':newstmt})
  assert restored.encode()==before[name]
 assert sum(counts.values())==344
 def phpdefs(text):
  matches=list(re.finditer(r'^function (\w+)',text,re.M));return {m.group(1):text[m.start():matches[i+1].start() if i+1<len(matches) else len(text)] for i,m in enumerate(matches)}
 a,z=phpdefs(before['ms4-initialize.php'].decode()),phpdefs(after['ms4-initialize.php'].decode());assert list(a)==list(z);changedphp=[k for k in a if a[k]!=z[k]];assert changedphp==['m4i_cu_form_source_files','m4i_worker_sources']
 for name in order:save('candidate/profile/'+name,after[name])
 forward=patch(before,after);reverse=patch(after,before);assert replay_files(before,forward)==after and replay_files(after,reverse)==before;save('FORWARD.diff',forward);save('REVERSE.diff',reverse)
 changed=[name for name in order if before[name]!=after[name]];assert set(changed)=={'ms4-initialize.php','ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py','interface-v1.json','source-derivation.json'}
 selected=[{'path':name,**pin(after[name])} for name in order]
 manifest={'schema':1,'source_sha':HEAD,'parent':PARENT,'scope':'SOURCE_ONLY_BAFB_LOCAL_NORMAL_NINE_PROFILE','before_selected9':pub['selected9'],'selected9':selected,'before_files':{name:pin(before[name]) for name in order},'candidate_files':{name:pin(after[name]) for name in order},'changed_files':changed,'whole_files':[name for name in order if name not in changed],'Python_constant_seams':constant_seams,'initializer_seams':seams,'initializer_functions':{'total':len(a),'changed':changedphp,'other_function_regions_raw':True},'all_Python_functions_raw':counts,'current_CU7':seven,'normal_success_result29_and_diagnostic4_raw':True,'ordinary_bidirectional_full_profile_replay':True,'historical_source_subtrees_whole_preserved':True,'source_admission':'LOCAL_ONLY_PENDING_FINAL_PUSH','runtime_ready':False,'targets_executed':False}
 put('MANIFEST.json',manifest)
 for path in list(READS):read(path)
 put('INPUTS.json',{'files':list(READS.values()),'all_first_final_complete_Source_windows':True,'targets_executed':False})
 put('REPORT.json',{'schema':1,'status':'PASS_STATIC_BAFB_PROFILE9_SIX_PIN_DAG_LEAVES_ONLY_NOT_RUNTIME_ADMITTED','MANIFEST':pointer(O/'MANIFEST.json'),'source_sha':HEAD,'parent':PARENT,'source_admission':'LOCAL_ONLY_PENDING_FINAL_PUSH','changed_files':changed,'whole_files':manifest['whole_files'],'Python_functions_raw':counts,'initializer_two_active_pin_seams':2,'complete_bidirectional_byte_replay':True,'historical_source_subtrees':'PRESERVED','actual_initializer_syntax_export_M_S_readers_postA_UI':'UNBOUND_NOT_RUN','W_Val_MEDIA_Run_canonical_changes':False,'findings':[]})
 members={p.relative_to(O).as_posix():pin(read(p)) for p in sorted(O.rglob('*')) if p.is_file()};put('PINS.json',{'schema':1,'scope':'IMMUTABLE_BAFB_LOCAL_NORMAL_PROFILE_SOURCE_ONLY','members':members,'member_count':len(members),'selected9':selected,'candidate':manifest['candidate_files'],'runtime_ready':False,'targets_executed':False})
 packet=obj(O/'PINS.json');assert set(p.relative_to(O).as_posix() for p in O.rglob('*') if p.is_file())==set(packet['members'])|{'PINS.json'}
 for name,wanted in packet['members'].items():read(O/name,wanted)
 print(json.dumps({'PINS':pointer(O/'PINS.json'),'REPORT':pointer(O/'REPORT.json'),'MANIFEST':pointer(O/'MANIFEST.json'),'initializer':pin(after['ms4-initialize.php']),'functions_raw':counts,'initializer_functions':manifest['initializer_functions'],'namespace':len(members)+1},ensure_ascii=False))
if __name__=='__main__':main()
