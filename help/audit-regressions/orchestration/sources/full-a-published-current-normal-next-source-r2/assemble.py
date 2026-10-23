"""Historical584 Source successor only. No M/S, PHP, Docker or business execution."""
import ast,json,hashlib,re,time,subprocess,os
from pathlib import Path
OUT=Path(__file__).parent;B=OUT.parent;OLD=B/'full-a-published-current-normal-next-source-r1'
helper=(OLD/'source_reader.py').read_bytes();old=b"OLD=B/'full-a-raw-env-normal-next-source-r1'";new=b"OLD=B/'full-a-published-current-normal-next-source-r1'"
assert helper.count(old)==1;helper=helper.replace(old,new)
if (OUT/'source_reader.py').exists():assert (OUT/'source_reader.py').read_bytes()==helper
else:(OUT/'source_reader.py').write_bytes(helper)
import source_reader as r
SYNTAX=B/'published-active-contract-initializer-php-syntax-actual-r1'

def funcs(raw):
 text=raw.decode() if isinstance(raw,bytes) else raw
 return {n.name:ast.get_source_segment(text,n) for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def receipts(folder,operations):
 return [{kind:r.pointer(folder/(op['stem']+'.'+ext),True) for kind,ext in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]} for op in operations]
def historical_qualification(publication,oldpins):
 old_inputs=r.obj(OLD/'AUTHOR-INPUTS.json');assert r.pin(r.read(OLD/'AUTHOR-INPUTS.json'))==r.compact(oldpins['files']['AUTHOR-INPUTS.json'])
 main=Path('H:/天俪商城/worktrees/ms4-asset-consumers');val=Path('H:/天俪商城/worktrees/ms4-aftersale-normal-check')
 rows=publication['repository_Source_requirements'];target=OUT/'historical-source-qualification.json'
 if target.exists():
  held=r.obj(target);assert len(held['operations'])==len(rows)==194
 else:
  operations=[];folder=OUT/'historical-git-readback';folder.mkdir(exist_ok=False)
  for index,row in enumerate(rows,1):
   stem=str(index).zfill(3);argv=['git','-c','safe.directory='+main.as_posix(),'-C',str(main),'show',r.HEAD+':'+row['path']]
   stdout=folder/(stem+'.stdout');stderr=folder/(stem+'.stderr');started=time.time_ns()
   with stdout.open('xb') as out,stderr.open('xb') as err:
    process=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=out,stderr=err,env={**os.environ,'GIT_OPTIONAL_LOCKS':'0'})
    made={'pid':process.pid,'argv':argv,'started_ns':started};r.put('historical-git-readback/'+stem+'.created.json',made);rc=process.wait()
   operation={**made,'native_exit':rc,'wait_returncode':process.returncode,'finished_ns':time.time_ns(),'path':row['path'],
    'stdout':r.pointer(stdout),'stderr':r.pointer(stderr)}
   assert rc==process.returncode==0 and r.read(stderr)==b'' and r.pin(r.read(stdout))==r.compact(row)
   r.put('historical-git-readback/'+stem+'.terminal.json',operation);operations.append(operation)
  held={'schema':1,'scope':'HISTORICAL_584_GIT_BLOBS_PLUS_REUSED_CLOSED_MAIN_AND_ACTUAL_VAL_ONLY','source_sha':r.HEAD,
   'current_Main_working_tree_asserted':False,'prior_Main_and_Val_author_inputs':r.pointer(OLD/'AUTHOR-INPUTS.json'),'operations':operations}
  r.put('historical-source-qualification.json',held)
 for index,(row,operation) in enumerate(zip(rows,held['operations']),1):
  for root in [main,val]:
   previous=old_inputs['inputs'][(root/row['path']).as_posix()]
   assert r.compact(previous)==r.compact(row) and previous['EOF'] is True and previous['closed'] is True and previous['ended_ns']<=old_inputs['ended_ns']
  assert operation['path']==row['path'] and operation['native_exit']==operation['wait_returncode']==0
  assert operation['argv'][-1]==r.HEAD+':'+row['path'] and r.pin(r.fixed(operation['stdout']['path'],operation['stdout']))==r.compact(row)
  assert r.read(Path(operation['stderr']['path']))==b'' and r.pin(r.read(val/row['path']))==r.compact(row)
 return r.pointer(target)

def main():
 assert not (OUT/'PINS.json').exists()
 for name in ['DESIGN.md','build_profile.py','assemble.py','source_reader.py','active-contract-repair-branch.py.fragment','predecessor-materialization-failure-branch.py.fragment']:r.read(OUT/name)
 oldpins=r.obj(OLD/'PINS.json');members=oldpins['source_members18'];assert len(members)==18
 before={name:r.fixed(OLD/name,oldpins['files'][name]) for name in members}
 for name,raw in before.items():r.write('before/'+name,raw)
 pp=r.obj(OUT/'profile-source/PINS.json');candidate9={name:r.fixed(OUT/'profile-source/candidate'/name,row) for name,row in pp['candidate'].items()}
 for name,raw in candidate9.items():r.write('validation/actual-profile/'+name,raw)
 publication=json.loads(before['validation/PROFILE-PUBLICATION.json']);context=publication['finite_contexts'];values=r.constants(before['materializer/prepare.py'].decode())
 assert publication['source_sha']==r.HEAD and len(values['COMMIT_CHAIN'])==14
 profile_pointer=r.pointer(OUT/'profile-source/PINS.json');composite=dict(context['current_composite_customer_form_source'])
 composite.update(profile_PINS=profile_pointer,profile_interface=r.pointer(OUT/'validation/actual-profile/interface-v1.json'),scope='HISTORICAL584_WITH_FINITE_ACTIVE_INDEPENDENT_CALLER_CONTRACT_REPAIR')
 selected=[{'path':row['path'],**r.pin(candidate9[row['path']])} for row in publication['selected9']];publication['selected9']=selected
 syntax=r.obj(SYNTAX/'RESULT.json');assert r.pin(r.read(SYNTAX/'RESULT.json'))=={'bytes':8586,'sha256':'ba2aeb68d058c49c716097d8bef51f61c675b81fc8f98762e72a907e482f8563'}
 init_auth={'schema':1,'result':r.pointer(SYNTAX/'RESULT.json'),'receipts':receipts(SYNTAX,syntax['operations']),'scope':'ACTUAL_REPAIRED_INITIALIZER_SYNTAX_ONLY_NO_APP'}
 r.put('CURRENT-INITIALIZER-SYNTAX-AUTHORITY.json',init_auth);init_pointer=r.pointer(OUT/'CURRENT-INITIALIZER-SYNTAX-AUTHORITY.json')
 faildir=OLD/'validation/materialize-freeze-assets-r1';failure=r.obj(faildir/'FAILURE.json')
 failrecord={'schema':1,'scope':'ACTUAL_PREDECESSOR_SOURCE_FREEZE_FAILED_ONLY','source_sha':r.HEAD,'failure':r.pointer(faildir/'FAILURE.json'),
  'root_source_PINS':r.pointer(OLD/'PINS.json'),'materializer_source':r.pointer(OLD/'materializer/prepare.py'),
  'receipts':receipts(faildir,failure['native_operations']),
  'outer':{kind:r.pointer(OLD/'launcher'/('materialize.'+ext),True) for kind,ext in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]},
  'readonly_diagnostic':r.pointer(B.parent/'normal-ms-source-freeze-diagnostic-r1/REPORT.json'),'new_business':'NOT RUN'}
 r.put('PREDECESSOR-MATERIALIZATION-FAILURE.json',failrecord);failpointer=r.pointer(OUT/'PREDECESSOR-MATERIALIZATION-FAILURE.json')
 context.update(current_composite_customer_form_source=composite,current_profile_frozen_source=profile_pointer,current_initializer_syntax_authority=init_pointer,
  active_caller_contract_source_repair=profile_pointer,predecessor_source_freeze_failure=failpointer,
  source_authoring_qualification_scope='HISTORICAL_584_ONLY_CURRENT_MAIN_WORKTREE_NOT_ASSERTED')
 values.update(CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=composite,CURRENT_INITIALIZER_SYNTAX_AUTHORITY=init_pointer,
  ACTIVE_CALLER_CONTRACT_SOURCE_REPAIR=profile_pointer,PREDECESSOR_SOURCE_FREEZE_FAILURE=failpointer)
 oldm=before['materializer/prepare.py'].decode();olds=before['validation/run.py'].decode();fn=r.function(oldm,'source_authority');assert fn==r.function(olds,'source_authority')
 marker="    previous = decode(read(context['baseline']['previous_full_freeze']))"
 fn=r.once(fn,marker,r.read(OUT/'active-contract-repair-branch.py.fragment').decode()+r.read(OUT/'predecessor-materialization-failure-branch.py.fragment').decode()+marker)
 namespace=dict(values);namespace.update(ast=ast,hashlib=hashlib,json=json,re=re,Path=Path)
 exec(compile(ast.Module(body=[ast.parse(fn).body[0]],type_ignores=[]),'<historical584-finite-active-contract-source-only>','exec'),namespace)
 def checked(pointer):
  raw=r.fixed(pointer['path'],pointer)
  if 'native9' in pointer:assert r.READS[Path(pointer['path']).as_posix()]['native9']==pointer['native9']
  return raw
 def check(ok,code):assert ok,code
 qualified=namespace['source_authority'](publication,checked,check)
 assert len(qualified['all'])==15629 and len(qualified['committed'])==188 and len(publication['repository_Source_requirements'])==194
 historical_pointer=historical_qualification(publication,oldpins)
 r.put('validation/PROFILE-PUBLICATION.json',publication)
 pins=json.loads(before['validation/PROFILE-PINS.json']);pins['members']={row['path']:r.compact(row) for row in selected};pins['members']['PROFILE-PUBLICATION.json']=r.pin(r.read(OUT/'validation/PROFILE-PUBLICATION.json'));r.put('validation/PROFILE-PINS.json',pins)
 note=('\n\nR2 HISTORICAL584 Source only; current Main working tree is NOT asserted. Frozen R1 closed Main194/Val194 evidence, actual Git584 blobs and actual Val194 qualify unchanged historical business sources. '
  'Active Host/zero/interface caller contracts and initializer donor tuple repaired; all original functions and business suffix preserved. Necessary new initializer syntax is actual7046b79... result linked. '
  'R1 real M673 failure remains failed; no new M/S/App/Docker/business run.14 steps/15629 tracked/188 cumulative/194 requirements/native677/M31 remain.\n').encode()
 r.write('validation/DESIGN-TASKS.md',before['validation/DESIGN-TASKS.md']+note)
 def shared(text):
  text=r.once(text,r.function(text,'source_authority'),fn)
  for key in ['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE','CURRENT_INITIALIZER_SYNTAX_AUTHORITY']:text=r.const(text,key,values[key])
  return r.once(text,'def source_authority(',''.join(key+' = '+repr(values[key])+'\n' for key in ['ACTIVE_CALLER_CONTRACT_SOURCE_REPAIR','PREDECESSOR_SOURCE_FREEZE_FAILURE'])+'\ndef source_authority(')
 s=shared(olds);s=r.const(s,'PINS',{name:r.pin(candidate9[name])['sha256'] for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']});s=r.const(s,'PROFILE_PUBLICATION',r.pin(r.read(OUT/'validation/PROFILE-PINS.json')));r.write('validation/run.py',s.encode())
 rp=json.loads(before['validation/PINS.json']);rp['members']={name:r.pin(r.read(OUT/'validation'/name)) for name in ['DESIGN-TASKS.md','run.py']};r.put('validation/PINS.json',rp)
 m=shared(oldm)
 for name in ['root','tool']:
  folder='validation' if name=='root' else 'materializer';m=r.once(m,name+"=Path('"+(OLD/folder).as_posix()+"')",name+"=Path('"+(OUT/folder).as_posix()+"')")
 m=r.once(m,"need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')",
  "need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(r.read(OUT/'validation/PINS.json')))+",'REVIEWED_ROOT_SOURCE_PINS')")
 r.write('materializer/prepare.py',m.encode());r.write('materializer/PLAN.md',before['materializer/PLAN.md']+note)
 inverse=json.loads(before['materializer/INVERSE.json']);inverse['finite_active_contract_repair']={'profile_PINS':profile_pointer,'predecessor_M_failure':failpointer,'actual_new_initializer_syntax':init_pointer,'historical_source_qualification':historical_pointer,'current_Main_worktree_asserted':False,'source_sha':r.HEAD,'all_runtime_clean_HEAD_and_dual_tree_guards_preserved':True};r.put('materializer/INVERSE.json',inverse)
 launcher=before['launcher/launch.py'].decode();launcher=r.once(launcher,"ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')")
 launcher=r.once(launcher,(OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix());launcher=r.once(launcher,r.pin(before['validation/PINS.json'])['sha256'],r.pin(r.read(OUT/'validation/PINS.json'))['sha256']);r.write('launcher/launch.py',launcher.encode())
 for text,old in [(m,oldm),(s,olds)]:
  for name,body in funcs(old).items():
   if name!='source_authority':assert funcs(text)[name]==body,name
 assert funcs(launcher)==funcs(before['launcher/launch.py'])
 suffix="    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')"
 assert s[s.index(suffix):]==olds[olds.index(suffix):] and r.function(m,'source_authority')==r.function(s,'source_authority')
 result=[node for node in ast.walk(ast.parse(m)) if isinstance(node,ast.Dict) and any(isinstance(key,ast.Constant) and key.value=='materialize_source_inputs' for key in node.keys)];assert len(result)==1 and len(result[0].keys)==31
 after={name:r.read(OUT/name) for name in members};forward=r.patch(before,after);reverse=r.patch(after,before);assert r.apply(before,forward)==after and r.apply(after,reverse)==before;r.write('DIFF.patch',forward.encode());r.write('REVERSE.patch',reverse.encode())
 original={name:r.read(OLD/'original-before'/name) for name in members}
 for name,raw in original.items():r.write('original-before/'+name,raw)
 forward=r.patch(original,after);reverse=r.patch(after,original);assert r.apply(original,forward)==after and r.apply(after,reverse)==original;r.write('FROM-ORIGINAL.patch',forward.encode());r.write('TO-ORIGINAL.patch',reverse.encode())
 checks={'status':'HISTORICAL_SOURCE_READY_NOT_CURRENT_MAIN_WORKTREE_OR_RUNTIME_READY','source_sha':r.HEAD,'parent':r.PARENT,'steps':14,'tracked':15629,'cumulative':188,'requirements':194,'M_native_count':677,'M_RESULT_fields':31,
  'all_original_runtime_clean_HEAD_and_full_dual_tree_checks_retained':True,'current_Main_working_tree_asserted':False,'source_qualification':historical_pointer,
  'original_business_suffix_exact':True,'all_non_authority_Python_functions_exact':True,'four_complete_patch_directions_replayed':True,'necessary_new_initializer_syntax_actual':init_pointer,'new_M_S_Docker_App_business':'NOT RUN'};r.put('AUTHOR-CHECKS.json',checks)
 ended=time.time_ns();assert all(row['closed'] and row['EOF'] and row['ended_ns']<=ended for row in r.READS.values())
 r.put('AUTHOR-INPUTS.json',{'schema':1,'input_count':len(r.READS),'all_input_windows_ended':True,'ended_ns':ended,'inputs':r.READS,'target_runtime_execution':'NOT RUN','current_Main_working_tree_asserted':False})
 files={p.relative_to(OUT).as_posix():{'path':p.as_posix(),**r.pin(p.read_bytes()),'native9':r.n9(p.stat())} for p in sorted(OUT.rglob('*')) if p.is_file() and p!=OUT/'PINS.json'}
 r.put('PINS.json',{'schema':1,'status':'FROZEN_HISTORICAL584_SOURCE_ONLY_NOT_CURRENT_MAIN_WORKTREE_OR_RUNTIME_READY','source_sha':r.HEAD,'parent':r.PARENT,'source_members18':members,'files':files,'Root14_M3_contract':True,
  'before_source':r.pointer(OLD/'PINS.json'),'profile_source':profile_pointer,'predecessor_source_freeze_failure':failpointer,'actual_initializer_syntax':init_pointer,'historical_source_qualification':historical_pointer,
  'pure_authority':checks,'all_input_windows_ended':True,'input_count':len(r.READS),'ended_ns':ended,'new_M_S_Host_PHP_Docker_App_business':'NOT RUN'})
 print(json.dumps({'status':checks['status'],'PINS':r.pin((OUT/'PINS.json').read_bytes()),'M':r.pin(after['materializer/prepare.py']),'S':r.pin(after['validation/run.py']),'rootPINS':r.pin(after['validation/PINS.json']),'input_count':len(r.READS)},ensure_ascii=False))
if __name__=='__main__':
 try:main()
 except Exception as error:
  p=OUT/'AUTHORING-FAILURE-01.json'
  if not p.exists():p.write_text(json.dumps({'status':'SOURCE_AUTHORING_FAILED_WITHOUT_TARGET_RUNTIME','class':type(error).__name__,'message':str(error)},ensure_ascii=False,indent=2)+'\n',encoding='utf8')
  raise
