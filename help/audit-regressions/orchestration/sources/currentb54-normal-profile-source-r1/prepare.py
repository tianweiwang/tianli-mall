"""B54 nine-profile Source preparation only; no candidate import/native target."""
from pathlib import Path
import ast,copy,difflib,hashlib,json,os,re,stat
O=Path(__file__).resolve().parent;ORG=O.parent
N=ORG/'currentbafb-next-normal-preparation-r3/normal-source'
AD=ORG/'current41c0-normal-root-admission-r1'
W=Path('H:/天俪商城/worktrees/ms4-language-cache-normal')
VAL=Path('H:/天俪商城/worktrees/ms4-language-cache-normal-check')
HEAD='b54ad9c39ca95a1b8b15ed6724082b98b1370c53';PARENT='bafb6305062aeb6b6e945c21da572863062ca480'
AR='help/audit-regressions/isolated/ms4-aftersale-review-normal.php'
COMPONENT='help/audit-regressions/isolated/ms4-language-cache-normal.php'
DOC='docs/multi-merchant/records/ms4/language-cache-fixture.md'
READS={}
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def n9(s):return [s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns]
def read(path,expected=None):
 path=Path(path);a=path.lstat();assert stat.S_ISREG(a.st_mode) and not path.is_symlink() and not a.st_file_attributes&1024
 with path.open('rb') as f:
  b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
 d=path.lstat();assert n9(a)==n9(d) and n9(b)==n9(c) and a.st_birthtime_ns==d.st_birthtime_ns==b.st_birthtime_ns==c.st_birthtime_ns
 assert all(n9(a)[i]==n9(b)[i] for i in (0,1,6,7))
 if expected:assert pin(raw)=={k:expected[k] for k in ('bytes','sha256')}
 row={'path':path.as_posix(),**pin(raw),'native9':n9(d),'fd9':n9(c),'birthtime_ns':d.st_birthtime_ns,'EOF':True,'closed':True}
 assert row['path'] not in READS or READS[row['path']]==row;READS[row['path']]=row;return raw
def obj(path,expected=None):return json.loads(read(path,expected))
def pointer(path):return {'path':Path(path).as_posix(),**pin(read(path))}
def save(name,raw):
 p=O/name;p.parent.mkdir(parents=True,exist_ok=True)
 with p.open('xb') as f:assert f.write(raw)==len(raw)
 assert p.read_bytes()==raw
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
 return ''.join(''.join(difflib.unified_diff(before[n].decode().splitlines(True),after[n].decode().splitlines(True),fromfile='before/profile/'+n,tofile='candidate/profile/'+n)) for n in before if before[n]!=after[n]).encode()
def replay_files(before,patchraw):
 result=dict(before);lines=patchraw.decode().splitlines(True);i=0
 while i<len(lines):
  assert lines[i].startswith('--- ');name=lines[i][4:].strip().split('/',2)[2];assert lines[i+1].startswith('+++ ');i+=2;source=result[name].decode().splitlines(True);out=[];pos=0
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
 dp=AD/'R101-B54-FULL-NORMAL-REBIND-DESIGN-ADOPTION.json'
 design=read(dp,{'bytes':2607,'sha256':'8101a0e9c7f284e76d1e0c6d91a7f39d864d2f3b2ca7c7dc4e56c08ef08db701'})
 adopted=json.loads(design);assert adopted['HEAD']==HEAD and adopted['PARENT']==PARENT and adopted['W']==W.as_posix() and adopted['Val']==VAL.as_posix()
 save('inputs/ROOT-DESIGN-ADOPTION.json',design)
 fixed={}
 for key in ('real_commit_record','new_Val_native_worktree','component_real_red_root','component_real_green_root'):
  row=adopted[key];fixed[key]=row;save('inputs/'+Path(row['path']).name,read(row['path'],row))
 packet=obj(N/'PINS.json',{'bytes':13638,'sha256':'7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'});assert packet['source_sha']==PARENT
 pp=obj(N/'validation/PROFILE-PINS.json',packet['files']['validation/PROFILE-PINS.json'])
 order=[n for n in pp['members'] if n!='PROFILE-PUBLICATION.json'];assert len(order)==9
 before={name:read(N/'validation/actual-profile'/name,pp['members'][name]) for name in order};after=dict(before)
 assert constants(before['ms4-host.py'])['ARTIFACTS']==order
 for name,body in before.items():save('before/profile/'+name,body)
 save('inputs/PRIOR-SOURCE-PINS.json',read(N/'PINS.json'));save('inputs/PRIOR-PROFILE-PINS.json',read(N/'validation/PROFILE-PINS.json'))
 algorithm=ORG/'currentbafb-next-normal-preparation-r1/profile-source-r1/prepare.py';save('inputs/PRIOR-AUTHOR-ALGORITHM.py',read(algorithm))
 oldar={'bytes':244912,'sha256':'a1258f537a3aa2de776d5a64afa5621141df0173e6d602feb168bd1f0c12ea0c'}
 newar={'bytes':245730,'sha256':'7b44a935d15ac658a0d4e40baf690411f4c9313e1126574245d0c3a8d2d20823'}
 component={'bytes':17628,'sha256':'57f0f2f59d07489df59680803209f82e7ab1a6146d7a1d704dc10e89a055c4c9'}
 doc={'bytes':3357,'sha256':'800034adcce930516c5dd29a4209518946a058bc5ab065e42973c24322fdf1ce'}
 for label,root in [('W',W),('Val',VAL)]:
  for relative,wanted in [(AR,newar),(COMPONENT,component),(DOC,doc)]:
   body=read(root/relative,wanted)
   if label=='W':save('inputs/current/'+relative,body)
 text=before['ms4-initialize.php'].decode();seams=[]
 for old,new in [("'bytes' => 244912, 'sha256' => '"+oldar['sha256']+"'","'bytes' => 245730, 'sha256' => '"+newar['sha256']+"'"),("[244912, '"+oldar['sha256']+"']","[245730, '"+newar['sha256']+"']")]:
  assert text.count(old)==1;text=text.replace(old,new,1);seams.append({'before':old,'after':new})
 after['ms4-initialize.php']=text.encode();restored=text
 for row in reversed(seams):assert restored.count(row['after'])==1;restored=restored.replace(row['after'],row['before'],1)
 assert restored.encode()==before['ms4-initialize.php']
 interface=json.loads(before['interface-v1.json']);oldseven=copy.deepcopy(interface['customer_form_current_profile']['php_sources'])
 seven=[{'path':r['path'],**newar} if r['path']==AR else r for r in oldseven];assert len(seven)==7 and sum(a!=b for a,b in zip(oldseven,seven))==1
 entry=interface['aftersale_review_normal_successor'];assert (entry['entry_bytes'],entry['entry_sha256'])==(oldar['bytes'],oldar['sha256'])
 entry['entry_bytes']=newar['bytes'];entry['entry_sha256']=newar['sha256'];interface['customer_form_current_profile']['php_sources']=seven
 tree=interface['source_tree_runtime_profile']['actual_candidate_tracked_sources'];assert tree[0]=={'path':AR,**oldar};tree[0]={'path':AR,**newar};after['interface-v1.json']=enc(interface)
 allowed={'ms4-host.py':{'CUSTOMER_FORM_SOURCE_FILES','AFTERSALE_REVIEW_ENTRY_BYTES','AFTERSALE_REVIEW_ENTRY_SHA256','INTERFACE_SHA256'},'normal-close-ms4-fulfillment.py':{'HOST_SHA256'},'verify-zero-ms4-fulfillment.py':{'CUSTOMER_FORM_SOURCE_FILES','AFTERSALE_REVIEW_ENTRY_BYTES','AFTERSALE_REVIEW_ENTRY_SHA256','INTERFACE_SHA256','INTERFACE_BYTES','HOST_SHA256','CLOSE_SHA256'}}
 for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
  assert list(constants(before[name])['CUSTOMER_FORM_SOURCE_FILES'])==oldseven
  for key,value in [('CUSTOMER_FORM_SOURCE_FILES',tuple(seven)),('AFTERSALE_REVIEW_ENTRY_BYTES',newar['bytes']),('AFTERSALE_REVIEW_ENTRY_SHA256',newar['sha256'])]:after[name]=const(after[name],key,value)
 after['ms4-host.py']=const(after['ms4-host.py'],'INTERFACE_SHA256',pin(after['interface-v1.json'])['sha256'])
 after['normal-close-ms4-fulfillment.py']=const(after['normal-close-ms4-fulfillment.py'],'HOST_SHA256',pin(after['ms4-host.py'])['sha256'])
 for key,value in [('INTERFACE_SHA256',pin(after['interface-v1.json'])['sha256']),('INTERFACE_BYTES',len(after['interface-v1.json'])),('HOST_SHA256',pin(after['ms4-host.py'])['sha256']),('CLOSE_SHA256',pin(after['normal-close-ms4-fulfillment.py'])['sha256'])]:after['verify-zero-ms4-fulfillment.py']=const(after['verify-zero-ms4-fulfillment.py'],key,value)
 deriv=json.loads(before['source-derivation.json']);prior_entry=copy.deepcopy(deriv['new_business_entry'])
 assert {k:prior_entry[k] for k in ['path','bytes','sha256']}=={'path':AR,**oldar}
 deriv['new_business_entry'].update(newar);deriv['new_business_entry']['status']='ACTUAL_LOCAL_COMMITTED_BYTES_FULL_NORMAL_NOT_EXECUTED'
 deriv['new_business_entry']['source']='Actual b54 local fix commit and clean dedicated W/Val; real component same-command red1/green0 with ordinaryclose/independentzero0 is separate from UNBOUND full normal export/M/S/UI qualification.'
 node='b54_language_cache_fixture_source_successor';assert node not in deriv
 deriv[node]={'schema':1,'scope':'SOURCE_ONLY_LOCAL_B54_LANGUAGE_CACHE_FIX_PROFILE_PIN_REFRESH','source_sha':HEAD,'parent':PARENT,
  'source_admission':'LOCAL_ONLY_PENDING_FINAL_PUSH','remote_qualification':'UNBOUND',
  'actual_local_commit':fixed['real_commit_record'],'actual_validation_update':fixed['new_Val_native_worktree'],
  'component_real_red_Root':fixed['component_real_red_root'],'component_real_green_Root':fixed['component_real_green_root'],
  'frozen_Root_design':pointer(O/'inputs/ROOT-DESIGN-ADOPTION.json'),'before_complete_r3_Source':pointer(N/'PINS.json'),
  'current_seven_PHP':seven,'separate_regression_component':{'path':COMPONENT,**component},'new_tracked_document':{'path':DOC,**doc},
  'component_scope':'Real isolated shared-leaf red1/green0 already qualified by Root; component is not an eighth CU7 member or full normal acceptance.',
  'initializer_two_identity_seams':seams,'current_initializer':pin(after['ms4-initialize.php']),
  'same_three_read_owner_helper':constants(before['ms4-host.py'])['THREE_READ_SOURCE_FILES'],
  'all_original_Python_functions':'RAW_IDENTICAL','all_other_initializer_bytes':'RAW_IDENTICAL',
  'normal_success_result_keys':29,'diagnostic_keys':4,'failure_only_optional_top_level':'warm_failure_context',
  'safe_context_fields':['ordinal','logical_name','expected_null','expected_wire_bytes','expected_wire_sha256','raw_type','raw_bytes','raw_sha256'],
  'actual_Git_export_qualification':'UNBOUND_NOT_CONSUMED_BY_THIS_SOURCE_PACKET',
  'new_initializer_actual_PHP82_syntax':'UNBOUND_NOT_RUN',
  'actual_full_tree_source_counts_M_native_budget_M_S_readers_postA_UI':'UNBOUND_NOT_RUN'}
 deriv['current_nonself_artifacts']=[{'path':name,**pin(after[name])} for name in order if name!='source-derivation.json'];after['source-derivation.json']=enc(deriv)
 restore=copy.deepcopy(interface);restore['aftersale_review_normal_successor']['entry_bytes']=oldar['bytes'];restore['aftersale_review_normal_successor']['entry_sha256']=oldar['sha256'];restore['customer_form_current_profile']['php_sources']=oldseven;restore['source_tree_runtime_profile']['actual_candidate_tracked_sources'][0]={'path':AR,**oldar}
 assert enc(restore)==before['interface-v1.json']
 restore=copy.deepcopy(deriv);restore.pop(node);restore['new_business_entry']=prior_entry;restore['current_nonself_artifacts']=json.loads(before['source-derivation.json'])['current_nonself_artifacts'];assert enc(restore)==before['source-derivation.json']
 counts={};constant_seams=[]
 for name,keys in allowed.items():
  assert defs(before[name])==defs(after[name]);counts[name]=len(defs(before[name]));a=before[name].decode();z=after[name].decode();av=constants(before[name]);zv=constants(after[name]);assert {k:v for k,v in av.items() if k not in keys}=={k:v for k,v in zv.items() if k not in keys};restored=z
  for key in sorted(keys):
   get=lambda raw:next(x for x in ast.parse(raw).body if isinstance(x,ast.Assign) and len(x.targets)==1 and isinstance(x.targets[0],ast.Name) and x.targets[0].id==key)
   oldstmt=ast.get_source_segment(a,get(a));newstmt=ast.get_source_segment(z,get(z));assert restored.count(newstmt)==1;restored=restored.replace(newstmt,oldstmt,1);constant_seams.append({'path':name,'assignment':key,'before':oldstmt,'after':newstmt})
  assert restored.encode()==before[name];compile(after[name],name,'exec')
 assert counts=={'ms4-host.py':182,'normal-close-ms4-fulfillment.py':22,'verify-zero-ms4-fulfillment.py':140}
 def phpdefs(text):
  matches=list(re.finditer(r'^function (\w+)',text,re.M));return {m.group(1):text[m.start():matches[i+1].start() if i+1<len(matches) else len(text)] for i,m in enumerate(matches)}
 a,z=phpdefs(before['ms4-initialize.php'].decode()),phpdefs(after['ms4-initialize.php'].decode());assert list(a)==list(z);changedphp=[k for k in a if a[k]!=z[k]];assert changedphp==['m4i_cu_form_source_files','m4i_worker_sources']
 for name in order:save('candidate/profile/'+name,after[name])
 forward=patch(before,after);reverse=patch(after,before);assert replay_files(before,forward)==after and replay_files(after,reverse)==before
 save('FORWARD.diff',forward);save('REVERSE.diff',reverse)
 changed=[name for name in order if before[name]!=after[name]]
 assert set(changed)=={'ms4-initialize.php','ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py','interface-v1.json','source-derivation.json'}
 assert all(before[n]==after[n] for n in ('README.md','compose.yml','seed-source-inverse.json'))
 selected=[{'path':name,**pin(after[name])} for name in order]
 manifest={'schema':1,'source_sha':HEAD,'parent':PARENT,'scope':'SOURCE_ONLY_B54_LOCAL_NORMAL_NINE_PROFILE',
  'before_selected9':[{'path':name,**pin(before[name])} for name in order],'selected9':selected,
  'before_files':{name:pin(before[name]) for name in order},'candidate_files':{name:pin(after[name]) for name in order},
  'changed_files':changed,'whole_files':[name for name in order if name not in changed],
  'Python_constant_seams':constant_seams,'initializer_seams':seams,
  'initializer_functions':{'total':len(a),'changed':changedphp,'other_function_regions_raw':True},
  'all_Python_functions_raw':counts,'current_CU7':seven,'normal_success_result29_and_diagnostic4_raw':True,
  'ordinary_bidirectional_full_profile_replay':True,'historical_source_subtrees_whole_preserved':True,
  'source_admission':'LOCAL_ONLY_PENDING_FINAL_PUSH','runtime_ready':False,'actual_export_qualification':'UNBOUND_NOT_CONSUMED','targets_executed':False}
 put('MANIFEST.json',manifest)
 put('SOURCE-PROOF.json',{'schema':1,'whole9_before_candidate_byte_inverse_and_forward':True,
  'Python_all_functions_classes_raw':counts,'initializer_full_module_reverse_two_literal_seams':True,
  'initializer_functions':manifest['initializer_functions'],'interface_inverse_exact':True,
  'derivation_inverse_exact':'Remove only new b54 node; restore new_business_entry/current_nonself; every historical subtree raw after re-encoding matches complete before bytes.',
  'new_component_not_in_CU7':True,'profile9_order_unchanged':order,'historical_BAFB_node_unchanged':deriv['bafb_warm_failure_context_source_successor']==json.loads(before['source-derivation.json'])['bafb_warm_failure_context_source_successor'],
  'no_candidate_import_or_execution':True,'Python_AST_compile_only':True,'PHP_syntax':'UNBOUND_NOT_RUN'})
 put('CLI.json',{'schema':1,'status':'SOURCE_ONLY_PROFILE_INPUT_NOT_A_NATIVE_ENTRY',
  'author_builder':['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-I','-B','-X','utf8',(O/'prepare.py').as_posix()],
  'author_builder_replay':'Existing PINS/output files fail closed; do not rerun into the sealed packet.',
  'candidate_profile_directory':(O/'candidate/profile').as_posix(),'future_integration':'Use MANIFEST selected9 plus this PINS only after Root/fresh review. Complete normal assembly must wait for genuine new export and Root readback.',
  'native_App_PHP_Git_Docker_CLI':'NONE; no target or test entry exposed','actual_export_M_S_Syntax':'UNBOUND_NOT_RUN'})
 for path in list(READS):read(path)
 put('INPUTS.json',{'schema':1,'files':list(READS.values()),'all_first_final_own9_fd9_cross4_birth_EOF_closed':True,'targets_executed':False})
 put('REPORT.json',{'schema':1,'status':'PASS_STATIC_B54_PROFILE9_SIX_PIN_DAG_LEAVES_ONLY_NOT_RUNTIME_ADMITTED',
  'MANIFEST':pointer(O/'MANIFEST.json'),'source_sha':HEAD,'parent':PARENT,'before_complete_Source':pointer(N/'PINS.json'),
  'Root_adoption':{'path':dp.as_posix(),**pin(design)},'fixed_Root_actual_public_inputs':fixed,
  'source_admission':'LOCAL_ONLY_PENDING_FINAL_PUSH','changed_files':changed,'whole_files':manifest['whole_files'],
  'Python_functions_classes_raw':counts,'initializer_two_active_pin_seams':2,'initializer_functions_total':len(a),'initializer_other_regions_raw_count':len(a)-len(changedphp),'initializer_other_regions_all_raw':True,
  'complete_bidirectional_byte_replay':True,'historical_source_subtrees':'PRESERVED',
  'actual_export_qualification':'UNBOUND_NOT_CONSUMED','actual_initializer_syntax_fullnormal_M_S_readers_postA_UI':'UNBOUND_NOT_RUN',
  'Source_checks':'Python AST/compile and byte/JSON inverse only; no candidate import, PHP/native/Git/Docker/App/test/export execution.',
  'W_Val_MEDIA_Run_C_D_old_packets_changed':False,'findings':[]})
 members={p.relative_to(O).as_posix():pin(read(p)) for p in sorted(O.rglob('*')) if p.is_file()}
 put('PINS.json',{'schema':1,'scope':'IMMUTABLE_B54_LOCAL_NORMAL_PROFILE_SOURCE_ONLY','members':members,'member_count':len(members),
  'selected9':selected,'candidate':manifest['candidate_files'],'runtime_ready':False,'targets_executed':False,'actual_export_qualification':'UNBOUND_NOT_CONSUMED'})
 assert set(p.relative_to(O).as_posix() for p in O.rglob('*') if p.is_file())==set(members)|{'PINS.json'}
 for name,wanted in members.items():read(O/name,wanted)
 print(json.dumps({'PINS':pointer(O/'PINS.json'),'REPORT':pointer(O/'REPORT.json'),'MANIFEST':pointer(O/'MANIFEST.json'),
  'initializer':pin(after['ms4-initialize.php']),'functions_raw':counts,'initializer_functions':manifest['initializer_functions'],'namespace':len(members)+1},ensure_ascii=False))
if __name__=='__main__':main()
