"""Own copied Source-only author support, derived from full-a-2e4-r2; no target module import."""
import ast,builtins,json,os,subprocess,symtable,time
from pathlib import Path
import source_reader as r
OUT=r.OUT

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
 result={'schema':1,'scope':'ACTUAL_NORMAL_HTTP_DIAGNOSTIC_MAIN_AND_VALIDATION_COMPLETE_REQUIRED_FILES_PLUS_FULL_NAMESPACE','source_sha':r.HEAD,'parent':r.PARENT,
  'required_files_per_checkout':len(publication['repository_Source_requirements']),'tracked_names_per_checkout':len(expected),'current_Main_working_tree_asserted':True,'current_Val_working_tree_asserted':True,
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
