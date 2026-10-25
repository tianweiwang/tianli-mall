from pathlib import Path
import ast,difflib,hashlib,json,symtable,builtins,time
ROOT=Path('H:/天俪商城');ORG=ROOT/'artifacts/project-organization-20261023';OUT=ORG/'currentb54-normal-reattempt-source-r1'
BEFORE=ORG/'currentb54-complete-normal-S-readers-source-r2/candidate';DRIVER=OUT/'candidate/normal-s.py';CALLER=OUT/'launcher/launch.py';READERS=OUT/'success-readers';INPUTS={}
def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def read(p):
 p=Path(p);a=p.stat();raw=p.read_bytes();b=p.stat();assert (a.st_dev,a.st_ino,a.st_size,a.st_mtime_ns,a.st_ctime_ns)==(b.st_dev,b.st_ino,b.st_size,b.st_mtime_ns,b.st_ctime_ns)
 INPUTS[p.as_posix()]={'path':p.as_posix(),**fp(raw)};return raw
def put(p,raw):
 p=Path(p);p.parent.mkdir(parents=True,exist_ok=True)
 if isinstance(raw,str):raw=raw.encode('utf-8')
 with p.open('xb')as f:f.write(raw)
def jput(p,d):put(p,json.dumps(d,ensure_ascii=False,indent=2)+'\n')
def funcs(text):
 lines=text.splitlines(keepends=True)
 return {node.name:''.join(lines[node.lineno-1:node.end_lineno])for node in ast.parse(text).body if isinstance(node,(ast.FunctionDef,ast.ClassDef))}
assert not READERS.exists()
driver_raw=read(DRIVER);caller_raw=read(CALLER);driver_pin=fp(driver_raw);caller_pin=fp(caller_raw)
assert driver_pin=={'bytes':28532764,'sha256':'f0fb35ff9080f97496ee858a1b6a9e0ece77631f46f0e8aea5aa17bbca523a70'}
assert caller_pin=={'bytes':3812,'sha256':'cd22a17462a43620b1b0be52a4c36698d8ac7e392408ec249c7c6f2fe9dbfed3'}
finite_function=funcs(driver_raw.decode())['validate_previous_attempt_retirement']
results={}
for name in ['root-reader.py','peer-reader.py']:
 old=read(BEFORE/name).decode('utf-8');text=old;old_defs=funcs(old);seams=[]
 def rep(before,after,count=1):
  global text
  assert text.count(before)==count,(name,before[:100],text.count(before),count)
  text=text.replace(before,after);seams.append({'before':before,'after':after,'count':count})
 rep('parser = argparse.ArgumentParser(',finite_function+'\n\nSOURCE = '+repr('b54ad9c39ca95a1b8b15ed6724082b98b1370c53')+'\n\nparser = argparse.ArgumentParser(',1)
 rep("'20261024174817c66be6'):","'20261024174817c66be6', '20261024235951bb22b4'):",1)
 rep("assert (source / 'launcher/normal-start.terminal.json').is_file(),", "assert Path("+repr((OUT/'launcher/normal-start.terminal.json').as_posix())+").is_file(),",1)
 rep("outer = source / 'launcher/normal-start'", 'outer = Path('+repr((OUT/'launcher/normal-start').as_posix())+')',1)
 rep("assert set(created) == {'pid', 'argv', 'started_ns'} and created['pid'] == terminal['pid'] == args.outer_pid", "assert set(created) == {'pid', 'argv', 'started_ns', 'process_only_git_config'} and created['pid'] == terminal['pid'] == args.outer_pid",1)
 rep("assert set(terminal) == {'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr'}", "assert set(terminal) == {'argv', 'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr', 'process_only_git_config'}\nassert terminal['argv'] == created['argv']\nassert created['process_only_git_config'] == terminal['process_only_git_config'] == {'GIT_CONFIG_COUNT':'1','GIT_CONFIG_KEY_0':'core.longpaths','GIT_CONFIG_VALUE_0':'true'}",1)
 rep("assert len(created['argv']) == 9 and Path(created['argv'][4]).resolve() == (source/'validation/run.py').resolve()", "assert len(created['argv']) == 11 and Path(created['argv'][4]).resolve() == Path("+repr(DRIVER.as_posix())+").resolve()",1)
 rep("assert created['argv'][5:] == ['--root-source-pins-sha256', ROOT_SOURCE_PINS['sha256'], '--setup-sha256', M_RESULT['sha256']]", "assert created['argv'][5:] == ['--root-source-pins-sha256', ROOT_SOURCE_PINS['sha256'], '--setup-sha256', M_RESULT['sha256'], '--attempt-driver-sha256', "+repr(driver_pin['sha256'])+"]",1)
 line=next(line for line in text.splitlines(keepends=True)if line.startswith('driver_pin = '))
 rep(line,line+'attempt_driver_pin = '+repr(driver_pin)+'\nattempt_caller_pin = '+repr(caller_pin)+'\nr.read(Path('+repr(DRIVER.as_posix())+'), attempt_driver_pin)\nr.read(Path('+repr(CALLER.as_posix())+'), attempt_caller_pin)\nprevious_attempt_retirement = validate_previous_attempt_retirement(lambda row:r.read(Path(row[\'path\']), {key:row[key] for key in [\'bytes\',\'sha256\']}), '+('readback_check' if name.startswith('root')else'need')+')\n',1)
 rep("r.read(archive / 'root-driver-source.py', driver_pin)","r.read(archive / 'root-driver-source.py', attempt_driver_pin)\nr.read(Path("+repr((OUT/'native-normal-start-attempt-r1/root-driver-source.py').as_posix())+"), attempt_driver_pin)",1)
 rep("original(source / 'validation/native-normal-start-assets-r1/001-prepare', args.prepare_pid)", "original(Path("+repr((OUT/'native-normal-start-attempt-r1/001-prepare').as_posix())+"), args.prepare_pid)",1)
 rep("'original_root_driver_matches_frozen_source': driver_pin,", "'baseline_M_root_driver_source_preserved': driver_pin, 'original_root_driver_matches_reviewed_attempt_source': attempt_driver_pin, 'actual_attempt_caller_source': attempt_caller_pin, 'previous_attempt_finite_retirement': previous_attempt_retirement,",1)
 if name.startswith('peer'):
  line=next(line for line in text.splitlines(keepends=True)if line.startswith("r.read(Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-normal-S-readers-source-r2/candidate/root-reader.py')"))
  rep(line,line+'r.read(Path('+repr((READERS/'root-reader.py').as_posix())+'), '+repr(results['root-reader.py']['candidate'])+')\n',1)
 ast.parse(text);new_defs=funcs(text)
 assert all(new_defs[k]==v for k,v in old_defs.items()) and set(new_defs)==set(old_defs)|{'validate_previous_attempt_retirement'}
 a=old.splitlines(keepends=True);b=text.splitlines(keepends=True);ops=[op for op in difflib.SequenceMatcher(a=a,b=b,autojunk=False).get_opcodes()if op[0]!='equal'];forward=list(a);reverse=list(b)
 for _,i,j,k,l in reversed(ops):forward[i:j]=b[k:l];reverse[k:l]=a[i:j]
 assert ''.join(forward)==text and ''.join(reverse)==old
 table=symtable.symtable(text,name,'exec');bound={s.get_name()for s in table.get_symbols()if s.is_assigned()or s.is_imported()or s.is_namespace()};missing=[]
 def visit(scope):
  for symbol in scope.get_symbols():
   if symbol.is_global()and symbol.is_referenced()and symbol.get_name()not in bound and symbol.get_name()not in vars(builtins)and symbol.get_name()!='__file__':missing.append(symbol.get_name())
  for child in scope.get_children():visit(child)
 visit(table);assert not missing,(name,missing)
 put(READERS/name,text);put(OUT/(name+'.FORWARD.diff'),''.join(difflib.unified_diff(a,b,fromfile='engineering-before/'+name,tofile='success-readers/'+name)));put(OUT/(name+'.REVERSE.diff'),''.join(difflib.unified_diff(b,a,fromfile='success-readers/'+name,tofile='engineering-before/'+name)))
 results[name]={'before':fp(old.encode()),'candidate':fp(text.encode()),'all_original_functions_and_classes_raw':list(old_defs),'new_finite_function_raw_from_new_driver':True,'full_module_forward_reverse':True,'diff_splices':len(ops),'seams':len(seams),'M_original_source_PINS_RESULT_freeze_history_and_callbacks_preserved':True,'actual_new_S_entry_and_driver_qualify_separately':True}
helper=read(BEFORE/'finite-helper.py');assert fp(helper)=={'bytes':33935,'sha256':'a84cb0edd5beaa998a26f8b96819b765e8cfe83e52a62619846b431c417855c0'};put(READERS/'finite-helper.py',helper)
jput(OUT/'READERS-SOURCE-INPUTS.json',{'scope':'before_complete_readers_and_fixed_new_driver_caller_Source_only','files':list(INPUTS.values()),'candidate_or_target_import_execution':False})
jput(OUT/'READERS-SOURCE-REVIEW.json',{'status':'AUTHOR_SOURCE_ONLY_COMPLETE_UNSEALED_PENDING_ROOT_AND_FRESH_REVIEW','programs':results,'helper_raw':fp(helper),'S0_CLI_Run_PIDs_context_and_outputs':'UNBOUND_NOT_RUN','no_actual_reader_admission':True,'created_ns':time.time_ns()})
review_path=OUT/'SOURCE-REVIEW.json';review=json.loads(review_path.read_bytes());review['complete_success_readers_Source']=results;review_path.write_text(json.dumps(review,ensure_ascii=False,indent=2)+'\n','utf-8',newline='\n')
print(json.dumps({'directory':OUT.as_posix(),'readers':results,'helper':fp(helper),'unsealed':True,'actual_execution':False},ensure_ascii=False))
