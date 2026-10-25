"""Bounded Source construction/AST/byte inverse only; no exporter import or execution."""
from pathlib import Path
import ast,copy,difflib,hashlib,json,os,stat
P=Path(__file__).resolve().parent;ORG=P.parent
OLD=ORG/'currentbafb-next-normal-preparation-r1/export-source-r1'
AD=ORG/'current41c0-normal-root-admission-r1'
HEAD='b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
PARENT='bafb6305062aeb6b6e945c21da572863062ca480'
W=Path('H:/天俪商城/worktrees/ms4-language-cache-normal')
VAL=Path('H:/天俪商城/worktrees/ms4-language-cache-normal-check')
BRANCH='fix/ms4-language-cache-fixture-20261025'
OUT=ORG/'currentb54-git-export-actual-r1'
READS={}
def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode('utf-8')
def n9(s):return [s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns]
def read(path,expected=None):
    a=path.lstat();assert stat.S_ISREG(a.st_mode) and not path.is_symlink() and not a.st_file_attributes&1024
    with path.open('rb') as f:b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
    z=path.lstat();assert n9(a)==n9(z) and n9(b)==n9(c) and a.st_birthtime_ns==z.st_birthtime_ns==b.st_birthtime_ns==c.st_birthtime_ns
    assert all(n9(a)[i]==n9(b)[i] for i in (0,1,6,7))
    if expected is not None:assert fp(raw)=={k:expected[k] for k in ('bytes','sha256')}
    row={'path':path.as_posix(),**fp(raw),'native9':n9(a),'fd9':n9(c),'birthtime_ns':a.st_birthtime_ns,'EOF':True,'closed':True}
    assert path.as_posix() not in READS or READS[path.as_posix()]==row;READS[path.as_posix()]=row;return raw
def write(rel,raw):
    path=P/rel;path.parent.mkdir(parents=True,exist_ok=True)
    with path.open('xb') as f:assert f.write(raw)==len(raw)
    assert path.read_bytes()==raw
def pointer(path):return {'path':path.as_posix(),**fp(read(path))}
adoption_path=AD/'R101-B54-FULL-NORMAL-REBIND-DESIGN-ADOPTION.json'
adoption_raw=read(adoption_path,{'bytes':2607,'sha256':'8101a0e9c7f284e76d1e0c6d91a7f39d864d2f3b2ca7c7dc4e56c08ef08db701'})
adopt=json.loads(adoption_raw);assert adopt['HEAD']==HEAD and adopt['PARENT']==PARENT and adopt['W']==W.as_posix() and adopt['Val']==VAL.as_posix() and adopt['branch']==BRANCH
write('inputs/ROOT-ADOPTION.json',adoption_raw)
fixed={}
for key in ['real_commit_record','new_Val_native_worktree','component_real_red_root','component_real_green_root']:
    row=adopt[key];raw=read(Path(row['path']),row);fixed[key]=row
    write('inputs/'+Path(row['path']).name,raw)
oldpinsraw=read(OLD/'PINS.json');oldpins=json.loads(oldpinsraw)
old=read(OLD/'candidate/export.py',oldpins['files']['candidate/export.py'])
oldexpected_raw=read(OLD/'EXPECTATIONS.json',oldpins['files']['EXPECTATIONS.json'])
oldexpected=json.loads(oldexpected_raw)
write('before/export.py',old);write('before/EXPECTATIONS.json',oldexpected_raw);write('inputs/BEFORE-PINS.json',oldpinsraw)
paths=sorted(line.split('\t',1)[1] for line in adopt['exact_real_changed_paths_M_A_A'])
assert paths==['docs/multi-merchant/records/ms4/language-cache-fixture.md','help/audit-regressions/isolated/ms4-aftersale-review-normal.php','help/audit-regressions/isolated/ms4-language-cache-normal.php']
before_required={row['path']:row for row in oldexpected['required_source_files']}
assert oldexpected['source_sha']==PARENT and len(oldexpected['complete_tracked_paths'])==len(set(oldexpected['complete_tracked_paths']))
changes=[]
for rel in paths:
    a=read(W/rel);b=read(VAL/rel);assert a==b
    previous={k:before_required[rel][k] for k in ('bytes','sha256')} if rel in before_required else None
    changes.append({'path':rel,'before':previous,'after':fp(a)})
assert [row['before'] is None for row in changes]==[True,False,True]
assert changes[1]['before']=={'bytes':244912,'sha256':'a1258f537a3aa2de776d5a64afa5621141df0173e6d602feb168bd1f0c12ea0c'}
tracked=set(oldexpected['complete_tracked_paths'])
for row in changes:
    assert (row['path'] in tracked)==(row['before'] is not None)
    tracked.add(row['path'])
required=dict(before_required)
for row in changes:required[row['path']]={'path':row['path'],**row['after']}
# Counts are planned expectations, never native or full-tree actual proof.
fixed_queries=9;blob_queries=sum(1+(row['before'] is not None) for row in changes)
export_count=fixed_queries+blob_queries;qualification_count=12
assert blob_queries==4 and export_count==13
e=copy.deepcopy(oldexpected)
e.update(source_sha=HEAD,parent=PARENT,changed_files=changes,complete_tracked_paths=sorted(tracked),
    required_source_files=[required[k] for k in sorted(required)],export_native_count=export_count,
    qualification_native_count=qualification_count,actual_local_commit=fixed['real_commit_record'],
    actual_validation_update=fixed['new_Val_native_worktree'],actual_component_red_root=fixed['component_real_red_root'],
    actual_component_green_root=fixed['component_real_green_root'],Root_design={'path':adoption_path.as_posix(),**fp(adoption_raw)},
    expected_branch=BRANCH,expected_W=W.as_posix(),expected_Val=VAL.as_posix(),
    baseline_current_parent_export_expectations={'path':(OLD/'EXPECTATIONS.json').as_posix(),**fp(oldexpected_raw)},
    source_derivation='Complete parent bafb Source expected namespace/required set plus the genuine Root-adopted A/M/A three-path b54 commit. Only six changed-code W/Val body reads done by author; actual committed blobs/current full tracked namespaces/all required file bodies await Root exporter execution.',
    new_export_qualification_M_S_actuals='UNBOUND_NOT_RUN',future_M_native_budget='UNBOUND_DERIVE_LATER_FROM_ORIGINAL_FULL_M_FORMULA',
    expected_plan_not_actual={'fixed_export_queries':fixed_queries,'after_blob_queries':3,'before_blob_queries':1,
       'export':export_count,'qualification':qualification_count,'total':export_count+qualification_count,
       'tracked_names_per_checkout':len(tracked),'required_files_per_checkout':len(required),
       'full_tracked_tree_bytes':'UNBOUND_NOT_READ_OR_CALCULATED_BY_THIS_EXPORT_SOURCE_AUTHOR',
       'source_tree_dual_namespace_and_M_native_budget':'UNBOUND_FUTURE_FULL_NORMAL_ASSEMBLY'})
write('EXPECTATIONS.json',enc(e))
text=old.decode('utf-8');edits=[]
def once(before,after):
    global text
    assert text.count(before)==1,(before[:100],text.count(before));text=text.replace(before,after,1);edits.append((before,after))
for before,after in [
 ("currentbafb-git-export-actual-r1","currentb54-git-export-actual-r1"),
 ("HEAD='bafb6305062aeb6b6e945c21da572863062ca480'","HEAD='"+HEAD+"'"),
 ("PARENT='d0a5d5398546e223122b5c2a2f115e1908f78ced'","PARENT='"+PARENT+"'"),
 ("W=Path('H:/天俪商城/worktrees/ms4-asset-consumers')","W=Path('"+W.as_posix()+"')"),
 ("VAL=Path('H:/天俪商城/worktrees/ms4-aftersale-normal-check')","VAL=Path('"+VAL.as_posix()+"')")]:once(before,after)
start=text.index(" need(local['status']=='LOCAL_SCOPED_COMMIT_COMPLETE_NOT_PUSHED'")
end=text.index(" need(not OUT.exists()",start)
oldguard=text[start:end]
newguard=""" adoption=json.loads(read(e['Root_design']['path'],e['Root_design']))
 red=json.loads(read(e['actual_component_red_root']['path'],e['actual_component_red_root']));green=json.loads(read(e['actual_component_green_root']['path'],e['actual_component_green_root']))
 need(adoption['status']=='ROOT_DESIGN_ADOPTED_MINIMAL_B54_FULL_NORMAL_SUCCESSOR_SOURCE_FIRST'
      and adoption['HEAD']==HEAD and adoption['PARENT']==PARENT and adoption['W']==W.as_posix() and adoption['Val']==VAL.as_posix()
      and adoption['branch']==e['expected_branch']=='fix/ms4-language-cache-fixture-20261025'
      and adoption['both_roots_actual_HEAD_clean'] is adoption['old_all_packets_immutable'] is True
      and adoption['first_author_scope']=='SOURCE_ONLY_NEW_EXPORTER_ADAPTATION_NOT_EXECUTION'
      and adoption['real_commit_record']==e['actual_local_commit'] and adoption['new_Val_native_worktree']==e['actual_validation_update']
      and adoption['component_real_red_root']==e['actual_component_red_root'] and adoption['component_real_green_root']==e['actual_component_green_root'],
      'ROOT_ADOPTED_REAL_B54_LOCAL_COMMIT_VAL_AND_RED_GREEN_SOURCE_ONLY')
 need(local['status']=='LOCAL_L2_FIX_COMMITTED_CLEAN' and local['HEAD']==HEAD and local['parent']==PARENT
      and local['branch']==e['expected_branch'] and len(local['paths'])==len(set(local['paths']))==3
      and sorted(local['paths'])==[row['path'] for row in e['changed_files']]
      and local['actual_component_red_green']==[1,0] and local['syntax3']==0
      and local['ordinary_close_and_independent_zero']==[0,0] and local['push']=='NOT_RUN'
      and local['fullnormal_UI_acceptance']=='PENDING_NOT_ASSERTED','REAL_LOCAL_B54_THREE_PATH_COMMIT_NOT_FULL_NORMAL')
 need(validation['status']=='ACTUAL_NEW_FIX_W_AND_VALIDATION_HEAD_CLEAN' and validation['HEAD']==HEAD
      and validation['W']==W.as_posix() and validation['Val']==VAL.as_posix() and e['expected_W']==W.as_posix() and e['expected_Val']==VAL.as_posix()
      and type(validation['native_PID']) is int and validation['native_PID']>0 and validation['native_wait']==0
      and validation['old_main_and_old_validation_unchanged'] is True,'REAL_NEW_CLEAN_B54_VALIDATION_ROOTS')
 need(red['status']=='ROOT_FULL_ACTUAL_TARGET_RED1_CLOSE0_INDEPENDENT_ZERO0_VERIFIED' and red['native_wait']==1
      and green['status']=='ROOT_FULL_ACTUAL_SAME_COMMAND_RED1_GREEN0_CLOSE0_INDEPENDENT_ZERO0_VERIFIED' and green['native_wait']==0
      and red['same_natural_two_App_exit']==[0,1] and green['same_natural_two_App_exit']==[0,0]
      and red['two_empty_maps_persisted_from_first_to_fresh_second_App'] is True
      and green['same_actual_runner_CLI_as_red'] is green['only_shared_leaf_changed_between_real_red_green'] is True
      and green['two_original_empty_maps_rebuilt_by_real_getter'] is green['unrelated_type_id_cache_and_deadline_unchanged'] is True,
      'GENUINE_SAME_COMMAND_TARGET_RED_THEN_GREEN_NOT_SETUP_FAILURE')
 for observed in (red,green):
  need(observed['full_output_files_read']==613 and observed['all_native_pairs_and_whole_streams_verified']==[135,12]
       and observed['syntax3_native_wait']==0 and observed['source_full_rows']==[1,1,2515]
       and observed['all_current45_source_pins_match'] is observed['foreign53_all_safe_snapshots_unchanged'] is True
       and observed['ordinary_resources_removed']==[6,1,7] and observed['ordinary_close_mutations']==5
       and observed['fresh_six_raw_empty']==[6,6] and observed['full_normal_UI19_groups']=='OPEN_NOT_ASSERTED',
       'ROOT_FULL_COMPONENT_READBACK_AND_ORDINARY_CLOSE_INDEPENDENT_ZERO_ONLY')
 need(e['source_sha']==HEAD and e['parent']==PARENT and len(e['changed_files'])==3
      and [row['before'] is None for row in e['changed_files']]==[True,False,True]
      and adoption['exact_real_changed_paths_M_A_A']==[('A' if row['before'] is None else 'M')+'\\t'+row['path'] for row in e['changed_files']]
      and e['export_native_count']==9+sum(1+(row['before'] is not None) for row in e['changed_files'])==13
      and e['qualification_native_count']==12,'REAL_THREE_PATH_A_M_A_EXPECTED_PLAN_NOT_ACTUAL')
"""
once(oldguard,newguard)
for before,after in [
 ("'EXACT_TWO_CHANGED_PATHS'","'EXACT_THREE_CHANGED_PATHS'"),
 ("'EXACT_TWO_M_A_NAME_STATUS'","'EXACT_THREE_A_M_A_NAME_STATUS'"),
 ("'DERIVED_ACTUAL_EXPORT_FROM_TWO_MEMBERS_PLUS_REAL_BRANCH'","'DERIVED_ACTUAL_EXPORT_FROM_THREE_MEMBERS_PLUS_REAL_BRANCH'"),
 ("'ACTUAL_READ_ONLY_GIT_LOCAL_BAFB_WARM_FAILURE_CONTEXT_STEP'","'ACTUAL_READ_ONLY_GIT_LOCAL_B54_LANGUAGE_CACHE_FIX_STEP'"),
 ("'aftersale_warm_failure_context'","'language_cache_fixture_fix'"),
 ("'ACTUAL_LOCAL_BAFB_MAIN_AND_VALIDATION_COMPLETE_REQUIRED_FILES_PLUS_FULL_NAMESPACE'","'ACTUAL_LOCAL_B54_MAIN_AND_VALIDATION_COMPLETE_REQUIRED_FILES_PLUS_FULL_NAMESPACE'")]:once(before,after)
# The actual authority retains the two old consumer fields and additionally binds the exact adopted green/retirement qualification.
once("'actual_validation_update':e['actual_validation_update'],'source_admission':e['source_admission']}",
     "'actual_validation_update':e['actual_validation_update'],'actual_component_red_root':e['actual_component_red_root'],'actual_component_green_root':e['actual_component_green_root'],'Root_design':e['Root_design'],'source_admission':e['source_admission']}")
status_before="'ACTUAL_LOCAL_BAFB_READ_ONLY_SOURCE_EXPORT12_AND_QUALIFICATION12_ZERO_ONLY'"
status_after="'ACTUAL_LOCAL_B54_READ_ONLY_SOURCE_EXPORT13_AND_QUALIFICATION12_ZERO_ONLY'"
assert text.count(status_before)==2
text=text.replace(status_before,status_after);edits.append((status_before,status_after))
new=text.encode('utf-8');write('candidate/export.py',new)
restored=text
for before,after in reversed(edits):
    assert restored.count(after)==(2 if after==status_after else 1)
    restored=restored.replace(after,before)
assert restored.encode()==old
def functions(raw):
    lines=raw.decode().splitlines(True);tree=ast.parse(raw)
    return {n.name:''.join(lines[n.lineno-1:n.end_lineno]).encode() for n in tree.body if isinstance(n,ast.FunctionDef)}
a,b=functions(old),functions(new);assert list(a)==list(b)
assert [name for name in a if a[name]!=b[name]]==['main']
assert [name for name in a if a[name]==b[name]]==['fp','enc','need','read','put','pointer','git']
assert new.count(b'subprocess.Popen')==old.count(b'subprocess.Popen')==1
compile(new,str(P/'candidate/export.py'),'exec');ast.parse(new);assert b'\r' not in new
for direction,left,right in [('forward',old.decode().splitlines(True),text.splitlines(True)),('reverse',text.splitlines(True),old.decode().splitlines(True))]:
    write(direction.upper()+'.diff',''.join(difflib.unified_diff(left,right,fromfile='before/export.py' if direction=='forward' else 'candidate/export.py',tofile='candidate/export.py' if direction=='forward' else 'before/export.py')).encode())
write('SOURCE-PROOF.json',enc({'schema':1,'before':fp(old),'candidate':fp(new),'whole_module_inverse_exact':True,
    'raw_functions':{name:fp(a[name]) for name in a if name!='main'},'only_changed_function':'main',
    'Popen_samePID_created_terminal_double_stream_wait_function_git_whole_raw':True,
    'Source_expected_only':e['expected_plan_not_actual'],'actual_export_qualification_results':'UNBOUND',
    'candidate_AST_compile_only':True,'candidate_import_or_execution':False,'C_D_Run_private_or_M_S_body_read':False}))
write('CLI.json',enc({'schema':1,'status':'ROOT_ONLY_AFTER_SOURCE_REVIEW_NOT_EXECUTED',
    'argv':['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-I','-B','-X','utf8',(P/'candidate/export.py').as_posix(),
        '--source-pins-sha256','<ROOT_FIXED_THIS_PACKET_PINS_SHA256>','--authorize-readonly-source-export'],
    'actual_output_root':OUT.as_posix(),'expected_native_plan_only':{'export':export_count,'qualification':qualification_count,'total':export_count+qualification_count},
    'actual_PID_native_blobs_tree_M_S':'UNBOUND','previous_outputs_and_packets':'NEVER_RETRY_OVERWRITE_OR_MUTATE'}))
interface='''# b54 readonly exporter Source only

Root reviews this packet and a fresh nonauthor review before invoking CLI.json once with the fixed PINS SHA. candidate/export.py retains all7 original IO/native helpers byte for byte. Only current constants/main qualification bindings differ. No source_authority/App/target/normal profile or new M/S is executed here.

The exact actual Root adoption, three-path local commit, new W/Val and real same-command red1→green0 ordinaryclose/independentzero0 small public reports are fixed inputs. The new reports have their real small schemas; old commit15/Val6 operation arrays are not invented. The exporter itself must obtain fresh real Git HEAD/clean/parent/branch/diff/name-status, every committed before/after blob, both full tracked namespaces, every required file body, own9/fd9/cross4/birth/EOF and final windows.

Expected argv plan is13 export (9fixed queries,3after blobs,1before blob) plus12 qualification =25. These are Source plan counts, never completed native results. Parent Source expected paths/required list plus exact3 A/M/A derives the new expected names/required list; full-tree bytes, dual-source physical copy, M native budget and all M/S results remain unbound for the later original full assembly. Old12+12/24/15750/31500/1150 are not asserted for b54.

The sole actual output root is currentb54-git-export-actual-r1 and must be absent. Local-only/pending-final-push remains explicit; no remote Git operation is added. Root summary result pointers inside the small public reports are metadata, not authority to follow Run/private/environment or old M/S streams. The author reads only these fixed small reports, original exporter/expectations Source, and the exact three committed code/doc paths in W and Val. C/D remain mutable Root progress records and are not runtime inputs.

Actual exporter/qualification PID, samePID receipts, committed blob proof and complete current tree qualification are UNBOUND until Root really executes and reads them back. Component green is not full normal/business/UI acceptance. New profile/full normal implementation is outside this first-stage packet.
'''
write('INTERFACE.md',interface.encode('utf-8'))
for name in list(READS):read(Path(name))
write('INPUT-WINDOWS.json',enc({'schema':1,'files':READS,'all_first_final_own9_FD9_cross4_birth_EOF_closed':True,
    'scope':'FIXED_SOURCE_PLUS_ROOT_ADOPTION_SMALL_PUBLIC_REPORTS_AND_EXACT_THREE_W_VAL_COMMITTED_PATHS_ONLY'}))
write('REPORT.json',enc({'schema':1,'status':'SOURCE_ONLY_READONLY_EXPORTER_SUCCESSOR_NOT_EXECUTED','HEAD':HEAD,'parent':PARENT,
    'W':W.as_posix(),'Val':VAL.as_posix(),'branch':BRANCH,'Root_adoption':{'path':adoption_path.as_posix(),**fp(adoption_raw)},
    'actual_Root_inputs':fixed,'exact_changed_files_Source_expectation':changes,
    'expected_plan_not_actual':e['expected_plan_not_actual'],'7_IO_native_helpers_whole_raw':True,
    'whole_exporter_before_candidate_forward_reverse_proof':True,'only_changed_function':'main',
    'current_Root_schemas_preserved':'New local commit638/Val339 and red2376/green2347 actual small report fields are consumed exactly; no old15/6 operation array invented.',
    'input_scope':'Original exporter Source/expectations/PINS, Root adopted small public summaries, exact3 committed paths in both clean W/Val. No actual Git invocation, all new after/before blobs still expected not Git-qualified.',
    'actual_current_export_qualification_tree_blobs_PID_M_S':'UNBOUND_NOT_RUN','full_tree_bytes_and_future_M_budget':'UNBOUND_NO_OLD_CONSTANT_PREFILL',
    'Source_checks':'AST/compile and whole module inverse only; candidate/helper not imported/executed.',
    'writes':'Only this new Source packet. No C/D/oldSource/W/Val/Run/Git changes.'}))
files={p.relative_to(P).as_posix():fp(p.read_bytes()) for p in sorted(P.rglob('*')) if p.is_file() and p.name!='PINS.json'}
write('PINS.json',enc({'schema':1,'scope':'B54_READONLY_THREE_PATH_EXPORT_SOURCE_ONLY','files':files,'member_count':len(files),'candidate_execution':False,'actual_outputs':'UNBOUND'}))
assert {p.relative_to(P).as_posix() for p in P.rglob('*') if p.is_file()}==set(files)|{'PINS.json'}
print(json.dumps({'members':len(files)+1,'candidate':fp(new),'PINS':fp((P/'PINS.json').read_bytes()),'REPORT':fp((P/'REPORT.json').read_bytes()),'expected_native_plan':[export_count,qualification_count],'expected_names_required':[len(tracked),len(required)]},ensure_ascii=False))
