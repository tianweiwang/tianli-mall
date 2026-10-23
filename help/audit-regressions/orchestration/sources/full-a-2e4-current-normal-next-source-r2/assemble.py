"""Complete Source-only AST import successor; no target module import or execution."""
import ast,builtins,hashlib,json,os,re,subprocess,symtable,time
from pathlib import Path
OUT=Path(__file__).parent;B=OUT.parent;OLD=B/'full-a-2e4-current-normal-next-source-r1'
helper=(OLD/'source_reader.py').read_bytes();a=b"OLD=B/'full-a-published-current-normal-next-source-r2'";b=b"OLD=B/'full-a-2e4-current-normal-next-source-r1'";assert helper.count(a)==1;helper=helper.replace(a,b)
if (OUT/'source_reader.py').exists():assert (OUT/'source_reader.py').read_bytes()==helper
else:(OUT/'source_reader.py').write_bytes(helper)
import source_reader as r

def funcs(raw):
 text=raw.decode() if isinstance(raw,bytes) else raw
 return {n.name:ast.get_source_segment(text,n) for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}

def current_qualification(publication,expected,values):
 folder=OUT/'current-source-readback';folder.mkdir(exist_ok=False);operations=[]
 def git(repo,stem,args):
  argv=['git','-c','safe.directory='+repo.as_posix(),'-C',repo.as_posix(),*args]
  started=time.time_ns();stdout=folder/(stem+'.stdout');stderr=folder/(stem+'.stderr')
  with stdout.open('xb') as out,stderr.open('xb') as err:
   process=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=out,stderr=err,env={**os.environ,'GIT_OPTIONAL_LOCKS':'0'})
   made={'pid':process.pid,'argv':argv,'started_ns':started};r.put('current-source-readback/'+stem+'.created.json',made);rc=process.wait()
  operation={**made,'native_exit':rc,'wait_returncode':process.returncode,'finished_ns':time.time_ns(),'stem':stem,'stdout':r.pointer(stdout),'stderr':r.pointer(stderr)}
  r.put('current-source-readback/'+stem+'.terminal.json',operation);operations.append(operation)
  assert rc==process.returncode==0 and r.read(stderr)==b'',stem
  return r.read(stdout)
 roots=[('main',Path(values['MAIN_SOURCE'])),('validation',Path(values['VALIDATION_SOURCE']))]
 for label,root in roots:
  assert git(root,label+'-head-before',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
  assert git(root,label+'-clean-before',['status','--porcelain=v1','--untracked-files=all'])==b''
  assert git(root,label+'-parent',['rev-list','--parents','-n','1',r.HEAD])==(r.HEAD+' '+r.PARENT+'\n').encode()
  raw=git(root,label+'-tracked',['ls-files','-z']);names=raw.decode('utf-8').split('\0');assert names[-1]=='' and sorted(names[:-1])==list(expected)
 for label,root in roots:
  for row in publication['repository_Source_requirements']:assert r.pin(r.read(root/row['path']))==r.compact(row),(label,row['path'])
 for label,root in roots:
  assert git(root,label+'-head-after',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
  assert git(root,label+'-clean-after',['status','--porcelain=v1','--untracked-files=all'])==b''
 assert len(operations)==12
 result={'schema':1,'scope':'ACTUAL_CURRENT_2E4_MAIN_AND_VALIDATION_COMPLETE310_REQUIRED_FILES_PLUS_FULL_NAMESPACE','source_sha':r.HEAD,'parent':r.PARENT,
  'required_files_per_checkout':310,'tracked_names_per_checkout':15745,'current_Main_working_tree_asserted':True,'current_Val_working_tree_asserted':True,
  'operations':operations,'all_operations_read_only':True,'target_runtime':'NOT RUN','ended_ns':time.time_ns()}
 r.put('CURRENT-SOURCE-QUALIFICATION.json',result);return r.pointer(OUT/'CURRENT-SOURCE-QUALIFICATION.json')

def actual_namespace_qualification(raw,label,publication):
 """Derive globals from this actual module only; never supply missing modules."""
 tree=ast.parse(raw);nodes=[];names=[]
 for node in tree.body:
  if isinstance(node,(ast.Import,ast.ImportFrom)):
   modules=[a.name.split('.')[0] for a in node.names] if isinstance(node,ast.Import) else [node.module.split('.')[0]]
   assert all(name in __import__('sys').stdlib_module_names for name in modules)
   nodes.append(node)
  elif isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
   try:ast.literal_eval(node.value)
   except (TypeError,ValueError):continue
   nodes.append(node)
  elif isinstance(node,ast.FunctionDef) and node.name=='source_authority':nodes.append(node)
 namespace={'__name__':'actual_declared_source_namespace_only'}
 exec(compile(ast.Module(body=nodes,type_ignores=[]),'<'+label+'-declared-imports-literal-constants-and-pure-definition>','exec'),namespace)
 root=symtable.symtable(raw.decode('utf8'),'<actual-'+label+'>','exec');required=set()
 def descend(scope):
  required.update(s.get_name() for s in scope.get_symbols() if s.is_global() and s.is_referenced())
  for child in scope.get_children():descend(child)
 descend(next(c for c in root.get_children() if c.get_name()=='source_authority'))
 missing=sorted(required-set(namespace)-set(dir(builtins)));assert missing==[],(label,missing)
 count=0
 def checked(pointer):
  data=r.fixed(pointer['path'],pointer)
  if 'native9' in pointer:assert r.READS[Path(pointer['path']).as_posix()]['native9']==pointer['native9']
  return data
 def check(ok,code):
  nonlocal count
  assert ok,code;count+=1
 result=namespace['source_authority'](publication,checked,check)
 return result,{'module':label,'qualification_namespace':'ONLY_ACTUAL_DECLARED_MODULE_IMPORTS_AND_LITERAL_ASSIGNMENTS','manual_dependency_injection':False,
  'recursive_global_dependencies':sorted(required),'unbound_globals':missing,'pure_source_checks':count}

def main():
 assert not (OUT/'PINS.json').exists()
 for name in ['DESIGN.md','assemble.py','source_reader.py']:r.read(OUT/name)
 prior=r.obj(OLD/'PINS.json');assert r.pin(r.read(OLD/'PINS.json'))=={'bytes':65254,'sha256':'921841e192956785295b322e3b9b1e26fcf753f1ef8875b00ddcf4dbbfda2a3f'}
 members=prior['source_members18'];assert len(members)==18;before={name:r.fixed(OLD/name,prior['files'][name]) for name in members}
 for name,data in before.items():r.write('before/'+name,data)
 for name in members:
  if name.startswith('validation/actual-profile/'):r.write(name,before[name])
 # Actual failed outer launcher, with no fabricated inner materialization failure receipt.
 outer={kind:r.pointer(OLD/'launcher'/('materialize.'+ext),True) for kind,ext in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]}
 created=json.loads(r.read(outer['created']['path']));terminal=json.loads(r.read(outer['terminal']['path']));stdout=r.read(outer['stdout']['path']);stderr=r.read(outer['stderr']['path'])
 assert created['pid']==terminal['pid']==35456 and terminal['native_exit']==terminal['wait_returncode']==1
 assert r.pin(stdout)=={'bytes':116,'sha256':'7ba54554da9b0867a19cdf1b919a229574448241c8097a9cdae6f7a69a460d77'} and stderr==b''
 for stream in ['stdout','stderr']:assert r.compact(terminal[stream])==r.compact(outer[stream])
 failure=json.loads(stdout);assert failure=={'status':'MATERIALIZATION_INCOMPLETE_STATE_PRESERVED','phase':'preflight','class':'NameError','code':None}
 diagnostic=B.parent/'current2e4-materializer-preflight-diagnostic-r1/REPORT.json';diag=r.obj(diagnostic)
 assert r.pin(r.read(diagnostic))=={'bytes':1762,'sha256':'815a740e411d2c5844b72b369c620777593daf3d73bb1e1bdd7765fb3de8caef'}
 assert diag['symbol']=='ast' and diag['source_authority_missing_global_bindings']=={'M':['ast'],'S':['ast']} and diag['actual_out_exists'] is False and diag['original_failure_file_exists'] is False
 predecessor={'schema':1,'scope':'ACTUAL_PREDECESSOR_PREFLIGHT_NAMEERROR_WITHOUT_INNER_FAILURE_FILE','source_sha':r.HEAD,
  'root_source_PINS':r.pointer(OLD/'PINS.json'),'materializer_source':r.pointer(OLD/'materializer/prepare.py'),'actual_outer_receipts':outer,
  'actual_outer_failure':failure,'original_inner_FAILURE_file':'ABSENT_BECAUSE_OUT_MKDIR_WAS_NOT_REACHED','diagnostic':r.pointer(diagnostic),
  'first_NameError_symbol':'ast','original_failed_preflight_preserved':True,'new_target_runtime':'NOT RUN'}
 r.put('PREDECESSOR-PREFLIGHT-FAILURE.json',predecessor);failure_pointer=r.pointer(OUT/'PREDECESSOR-PREFLIGHT-FAILURE.json')
 pub=json.loads(before['validation/PROFILE-PUBLICATION.json']);context=pub['finite_contexts'];composite=dict(context['current_composite_customer_form_source'])
 composite['profile_interface']=r.pointer(OUT/'validation/actual-profile/interface-v1.json');context['current_composite_customer_form_source']=composite
 r.put('validation/PROFILE-PUBLICATION.json',pub)
 pp=json.loads(before['validation/PROFILE-PINS.json']);pp['members']['PROFILE-PUBLICATION.json']=r.pin(r.read(OUT/'validation/PROFILE-PUBLICATION.json'));r.put('validation/PROFILE-PINS.json',pp)
 note=('\n\nR2 current2e4 Source dependency correction: M/S now explicitly import ast. All source_authority and other function bodies, profile9 and business suffix unchanged. '
  'Actual r1 preflight NameError/outer1 and absent inner FAILURE retained independently; handler is unchanged. Qualification uses only each actual module declared imports/literal constants and recursive unbound globals must be empty. '
  '15/15745/304/310/259/native1037/M31 unchanged; current full310 Main/Val plus clean HEAD/parent/tracked checks freshly required. No M/S/Host/PHP/Docker/App/business run.\n').encode()
 r.write('validation/DESIGN-TASKS.md',before['validation/DESIGN-TASKS.md']+note)
 oldm=before['materializer/prepare.py'].decode();olds=before['validation/run.py'].decode()
 s=r.once(olds,'from pathlib import Path\n','from pathlib import Path\nimport ast\n');s=r.const(s,'CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE',composite)
 s=r.const(s,'PROFILE_PUBLICATION',r.pin(r.read(OUT/'validation/PROFILE-PINS.json')));r.write('validation/run.py',s.encode())
 rp=json.loads(before['validation/PINS.json']);rp['members']={name:r.pin(r.read(OUT/'validation'/name)) for name in ['DESIGN-TASKS.md','run.py']};r.put('validation/PINS.json',rp)
 m=r.once(oldm,'from pathlib import Path\n','from pathlib import Path\nimport ast\n');m=r.const(m,'CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE',composite)
 for name,folder in [('root','validation'),('tool','materializer')]:m=r.once(m,name+"=Path('"+(OLD/folder).as_posix()+"')",name+"=Path('"+(OUT/folder).as_posix()+"')")
 m=r.once(m,"need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')",
  "need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(r.read(OUT/'validation/PINS.json')))+",'REVIEWED_ROOT_SOURCE_PINS')")
 r.write('materializer/prepare.py',m.encode());r.write('materializer/PLAN.md',before['materializer/PLAN.md']+note)
 inverse=json.loads(before['materializer/INVERSE.json']);inverse['declared_ast_import_source_successor']={'predecessor_preflight_failure':failure_pointer,'module_fix':'M_AND_S_EXPLICIT_STDLIB_AST_IMPORT','same_source_authority_and_all_other_functions':True,'new_target_runtime':'NOT RUN'};r.put('materializer/INVERSE.json',inverse)
 launcher=before['launcher/launch.py'].decode();launcher=r.once(launcher,"ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')")
 launcher=r.once(launcher,(OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix());launcher=r.once(launcher,r.pin(before['validation/PINS.json'])['sha256'],r.pin(r.read(OUT/'validation/PINS.json'))['sha256']);r.write('launcher/launch.py',launcher.encode())
 for current,old in [(m,oldm),(s,olds),(launcher,before['launcher/launch.py'])]:assert funcs(current)==funcs(old)
 assert r.function(m,'source_authority')==r.function(s,'source_authority')==r.function(oldm,'source_authority')
 suffix="    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')";assert s[s.index(suffix):]==olds[olds.index(suffix):]
 result=[n for n in ast.walk(ast.parse(m)) if isinstance(n,ast.Dict) and any(isinstance(k,ast.Constant) and k.value=='materialize_source_inputs' for k in n.keys)];assert len(result)==1 and len(result[0].keys)==31
 qualified,mchecks=actual_namespace_qualification(m.encode(),'M',pub);other,schecks=actual_namespace_qualification(s.encode(),'S',pub);assert qualified==other
 values=r.constants(m);oldvalues=r.constants(oldm)
 for name in ['COMMIT_CHAIN','COMMITTED_PATHS','SOURCE_REQUIREMENT_PATHS','NEW_TRACKED_PATHS']:assert values[name]==oldvalues[name]
 assert len(qualified['all'])==15745 and len(qualified['committed'])==304 and len(pub['repository_Source_requirements'])==310
 assert 6+sum(2+2*len(step['files']) for step in qualified['commit_chain'])+1+len(qualified['committed'])+2+3+1+4==1037
 r.put('ACTUAL-MODULE-NAMESPACE-CHECKS.json',{'M':mchecks,'S':schecks,'exact_both_qualification_results':True})
 after={name:r.read(OUT/name) for name in members}
 for name,raw in after.items():
  if name.endswith('.py'):ast.parse(raw)
  if name.endswith('.json'):json.loads(raw)
 forward=r.patch(before,after);reverse=r.patch(after,before);assert r.apply(before,forward)==after and r.apply(after,reverse)==before;r.write('DIFF.patch',forward.encode());r.write('REVERSE.patch',reverse.encode())
 original={name:r.read(OLD/'original-before'/name) for name in members}
 for name,raw in original.items():r.write('original-before/'+name,raw)
 forward=r.patch(original,after);reverse=r.patch(after,original);assert r.apply(original,forward)==after and r.apply(after,reverse)==original;r.write('FROM-ORIGINAL.patch',forward.encode());r.write('TO-ORIGINAL.patch',reverse.encode())
 qualification=current_qualification(pub,qualified['all'],values)
 checks={'status':'CURRENT2E4_DECLARED_MODULE_DEPENDENCIES_SOURCE_READY_NOT_M_S_EXECUTED','source_sha':r.HEAD,'parent':r.PARENT,
  'steps':15,'tracked':15745,'cumulative':304,'requirements':310,'new_baseline_members':259,'M_native_count':1037,'M_RESULT_fields':31,
  'all_fifteen_steps_and_original_globals_except_composite_wrapper_exact':True,'profile9_exact':True,'all_function_bodies_including_source_authority_exact':True,'original_business_suffix_exact':True,
  'actual_module_import_namespaces':r.pointer(OUT/'ACTUAL-MODULE-NAMESPACE-CHECKS.json'),'recursive_unbound_globals_M':[],'recursive_unbound_globals_S':[],
  'four_complete_patch_directions_replayed':True,'complete18_AST_JSON_PASS':True,'current_source_qualification':qualification,'predecessor_preflight_failure':failure_pointer,'new_target_runtime':'NOT RUN'}
 r.put('AUTHOR-CHECKS.json',checks);ended=time.time_ns();assert all(row['closed'] and row['EOF'] and row['ended_ns']<=ended for row in r.READS.values())
 r.put('AUTHOR-INPUTS.json',{'schema':1,'input_count':len(r.READS),'all_input_windows_ended':True,'ended_ns':ended,'inputs':r.READS,'target_runtime_execution':'NOT RUN','current_Main_and_Val_working_trees_asserted':True})
 files={p.relative_to(OUT).as_posix():{'path':p.as_posix(),**r.pin(p.read_bytes()),'native9':r.n9(p.stat())} for p in sorted(OUT.rglob('*')) if p.is_file() and p!=OUT/'PINS.json'}
 r.put('PINS.json',{'schema':1,'status':'FROZEN_CURRENT2E4_COMPLETE_SOURCE_DECLARED_AST_DEPENDENCY_ONLY_NOT_M_S_EXECUTED','source_sha':r.HEAD,'parent':r.PARENT,'source_members18':members,'files':files,
  'Root14_M3_contract':True,'before_source':r.pointer(OLD/'PINS.json'),'profile_source':prior['profile_source'],'predecessor_preflight_failure':failure_pointer,
  'current_source_qualification':qualification,'pure_authority':checks,'all_input_windows_ended':True,'input_count':len(r.READS),'ended_ns':ended,'new_M_S_Host_PHP_Docker_App_business':'NOT RUN'})
 print(json.dumps({'status':checks['status'],'PINS':r.pin((OUT/'PINS.json').read_bytes()),'M':r.pin(after['materializer/prepare.py']),'S':r.pin(after['validation/run.py']),
  'rootPINS':r.pin(after['validation/PINS.json']),'publication':r.pin(after['validation/PROFILE-PUBLICATION.json']),'input_count':len(r.READS),'M_checks':mchecks['pure_source_checks'],'S_checks':schecks['pure_source_checks']},ensure_ascii=False))

if __name__=='__main__':
 try:main()
 except Exception as error:
  for index in range(1,100):
   p=OUT/('AUTHORING-FAILURE-'+str(index).zfill(2)+'.json')
   if not p.exists():p.write_text(json.dumps({'status':'SOURCE_AUTHORING_FAILED_WITHOUT_TARGET_RUNTIME','class':type(error).__name__,'message':str(error)},ensure_ascii=False,indent=2)+'\n',encoding='utf8');break
  raise
