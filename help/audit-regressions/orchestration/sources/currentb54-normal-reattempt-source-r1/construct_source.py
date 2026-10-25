from pathlib import Path
import ast,difflib,hashlib,json,symtable,builtins,time
WORKSPACE=Path('H:/天俪商城');ORG=WORKSPACE/'artifacts/project-organization-20261023'
OUT=ORG/'currentb54-normal-reattempt-source-r1';CORE=ORG/'currentb54-next-normal-preparation-r3/normal-source';ADM=ORG/'current41c0-normal-root-admission-r1'
BEFORE_DRIVER=CORE/'validation/run.py';BEFORE_CALLER=ADM/'run-r107-b54-normal-s.py'
DRIVER=OUT/'candidate/normal-s.py';CALLER=OUT/'launcher/launch.py';INPUTS={}
def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def read(path):
 path=Path(path);before=path.stat();raw=path.read_bytes();after=path.stat()
 keys=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
 assert [getattr(before,k)for k in keys]==[getattr(after,k)for k in keys]
 INPUTS[path.as_posix()]={'path':path.as_posix(),**fp(raw)};return raw
def desc(path):return {'path':Path(path).as_posix(),**fp(read(path))}
def put(path,raw):
 path=Path(path);path.parent.mkdir(parents=True,exist_ok=True)
 if isinstance(raw,str):raw=raw.encode('utf-8')
 with path.open('xb')as f:f.write(raw)
def jput(path,value):put(path,json.dumps(value,ensure_ascii=False,indent=2)+'\n')
def functions(text):
 lines=text.splitlines(keepends=True)
 return {node.name:''.join(lines[node.lineno-1:node.end_lineno])for node in ast.parse(text).body if isinstance(node,ast.FunctionDef)}
def transform(name,before,after):
 a=before.splitlines(keepends=True);b=after.splitlines(keepends=True)
 ops=[row for row in difflib.SequenceMatcher(a=a,b=b,autojunk=False).get_opcodes()if row[0]!='equal'];forward=list(a);reverse=list(b)
 for _,i,j,k,l in reversed(ops):forward[i:j]=b[k:l];reverse[k:l]=a[i:j]
 assert ''.join(forward)==after and ''.join(reverse)==before
 put(OUT/(name+'.FORWARD.diff'),''.join(difflib.unified_diff(a,b,fromfile='engineering-before/'+name,tofile='candidate/'+name)))
 put(OUT/(name+'.REVERSE.diff'),''.join(difflib.unified_diff(b,a,fromfile='candidate/'+name,tofile='engineering-before/'+name)))
 return len(ops)

assert (OUT/'README.md').exists() and not DRIVER.exists() and not CALLER.exists()
old_driver=read(BEFORE_DRIVER).decode('utf-8');assert fp(old_driver.encode())=={'bytes':28528366,'sha256':'f23fd9ecee8447b0a50e328e6253ea9d73e97cc4f604b3c660f50c95f22c2deb'}
old_caller=read(BEFORE_CALLER).decode('utf-8');assert fp(old_caller.encode())=={'bytes':2208,'sha256':'b817ca0ca8d8415a332a7954898b0a291e3c0f1c4afaf2e77d3aadb2d4225b62'}
retirement_path=ADM/'R110-B54-RETIREMENT-ACTUAL-ROOT-READBACK.json';retirement=json.loads(read(retirement_path));retirement_desc=desc(retirement_path)
assert retirement['status']=='ROOT_ACTUAL_OBSERVER_INTERRUPTED_S1_CLOSE90_NATIVE0_AND_INDEPENDENT_ZERO12_NATIVE0_FULL_READBACK0'
assert retirement['actual_close_native_operations']==90 and retirement['actual_zero_native_operations']==12 and retirement['ordinary_mutations']==5
whitelist=retirement['own_public_whitelist'];whitelist_raw=read(whitelist['path']);assert desc(whitelist['path'])==whitelist
assert json.loads(whitelist_raw)['count']==420
m_accept_path=ADM/'R108-B54-M-DUAL-READERS-ACTUAL-ROOT-ACCEPTANCE.json';m_accept=json.loads(read(m_accept_path));m_accept_desc=desc(m_accept_path)
assert m_accept['M_Source_complete_readback_accepted'] is True and m_accept['actual_M_PID']==16792
baseline_root_pins=desc(CORE/'validation/PINS.json');baseline_driver=desc(BEFORE_DRIVER);setup=m_accept['actual_RESULT'];freeze=m_accept['actual_freeze']

retirement_function='''def validate_previous_attempt_retirement(read, check):
    import json
    from pathlib import Path
    pointer = '''+repr(retirement_desc)+'''
    raw = read(pointer)
    value = json.loads(raw)
    check(value['status'] == 'ROOT_ACTUAL_OBSERVER_INTERRUPTED_S1_CLOSE90_NATIVE0_AND_INDEPENDENT_ZERO12_NATIVE0_FULL_READBACK0'
        and value['run_id'] == '20261024235951bb22b4' and value['source_sha'] == SOURCE
        and value['original_S_native_exit_wait'] == 1 and value['business_acceptance'] == 'INCOMPLETE'
        and value['restart_retry_force_or_business_run'] is False, 'PREVIOUS_ATTEMPT_S1_RETIRED_NOT_BUSINESS_PASS')
    check(value['actual_close_native_operations'] == 90 and value['actual_zero_native_operations'] == 12
        and value['ordinary_mutations'] == 5 and value['removed'] == {'containers':4,'networks':1,'volumes':9}
        and value['six_fresh_queries_empty'] is True and value['all420_own_public_files_full_EOF_native_streams_verified'] is True,
        'PREVIOUS_ATTEMPT_ACTUAL_EXACT_OWNED_CLOSE90_ZERO12')
    check(value['original_Run_files_held'] == 4016 and value['original_Run_directories_held'] == 5
        and value['original_Run_public37_full_bodies_held'] is True and value['original_Run_other3979_metadata_only_held'] is True
        and value['foreign53_full_mount_multiset_network_close_before_through_zero_equal'] is True
        and value['original_initial53_all_fields_history'] == 'NOT_PROVEN_NOT_CLAIMED'
        and value['original_M_S_outer_streams'] == 'METADATA_ONLY_HELD_NO_BODY_READ'
        and value['all_original_Run_namespace_native9_birth_and_attrs_held'] is True
        and value['close_and_zero_source_body_and_actual_outputs_held'] is True,
        'PREVIOUS_ATTEMPT_COMPLETE_ROOT_PRESERVATION_AND_PRIVACY_SCOPE')
    check(value['own_public_whitelist'] == '''+repr(whitelist)+''', 'PREVIOUS_ATTEMPT_EXACT_FINITE_PUBLIC_LIST')
    listing = json.loads(read(value['own_public_whitelist']))
    check(set(listing) == {'files','count','all_full_EOF_root_read'} and listing['count'] == 420
        and listing['all_full_EOF_root_read'] is True and len(listing['files']) == 420, 'PREVIOUS_ATTEMPT_420_PUBLIC_FILES_ONLY')
    roots = {Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-observer-interruption-retirement-source-r1/actual-close-r1'):367,
        Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-observer-interruption-retirement-source-r1/actual-zero-r1'):53}
    paths = set()
    for row in listing['files']:
        check(set(row) == {'path','bytes','sha256'} and row['path'] not in paths, 'PREVIOUS_ATTEMPT_EXACT_PUBLIC_ROW')
        path = Path(row['path'])
        check(path.parent in roots and path.name not in ['', '.', '..'], 'PREVIOUS_ATTEMPT_NO_RUN_ENV_OR_OPAQUE_BODY_FOLLOW')
        paths.add(row['path']); read(row)
    for root, count in roots.items():
        wanted = {Path(path).name for path in paths if Path(path).parent == root}
        check(len(wanted) == count and {path.name for path in root.iterdir()} == wanted,
            'PREVIOUS_ATTEMPT_COMPLETE367_CLOSE53_ZERO_NAMESPACE')
    return {'Root_proof':pointer,'finite_public_files':420,'original_M_qualification_changed':False,'new_whole_added':False}
'''
driver=old_driver.replace('BASE = Path(__file__).resolve().parent', 'BASE = Path('+repr((CORE/'validation').as_posix())+')',1)
driver=driver.replace("ARCHIVE = BASE / 'native-normal-start-assets-r1'", "ARCHIVE = Path("+repr((OUT/'native-normal-start-attempt-r1').as_posix())+")",1)
driver=driver.replace('def main():\n',retirement_function+'\n\ndef main():\n',1)
driver=driver.replace("    parser.add_argument('--setup-sha256',required=True)\n", "    parser.add_argument('--setup-sha256',required=True)\n    parser.add_argument('--attempt-driver-sha256',required=True)\n",1)
driver=driver.replace("        and re.fullmatch('[a-f0-9]{64}',args.setup_sha256) is not None, 'ACTUAL_SOURCE_AND_SETUP_SHA_REQUIRED')", "        and re.fullmatch('[a-f0-9]{64}',args.setup_sha256) is not None\n        and re.fullmatch('[a-f0-9]{64}',args.attempt_driver_sha256) is not None, 'ACTUAL_SOURCE_AND_SETUP_SHA_REQUIRED')",1)
driver=driver.replace("    pins_raw = (BASE/'PINS.json').read_bytes()", "    # The executing attempt is reviewed separately; BASE/PINS remains the unchanged M engineering-before.\n    self_raw = Path(__file__).read_bytes()\n    pinned(Path(__file__).resolve(), {'bytes':len(self_raw),'sha256':args.attempt_driver_sha256})\n    pins_raw = (BASE/'PINS.json').read_bytes()",1)
driver=driver.replace("    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')", "    validate_previous_attempt_retirement(lambda row:pinned(row['path'],{key:row[key] for key in ['bytes','sha256']}), require)\n    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')",1)
before_functions=functions(old_driver);after_functions=functions(driver)
assert len(before_functions)==15 and all(after_functions[k]==v for k,v in before_functions.items()if k!='main')
assert set(after_functions)==set(before_functions)|{'validate_previous_attempt_retirement'}
business=old_driver[old_driver.index('    ARCHIVE.mkdir()'):old_driver.rindex("if __name__")]
assert driver[driver.index('    ARCHIVE.mkdir()'):driver.rindex("if __name__")]==business
ast.parse(driver);put(DRIVER,driver);driver_desc={'path':DRIVER.as_posix(),**fp(driver.encode())}
driver_splices=transform('driver',old_driver,driver)

caller=old_caller
caller=caller.replace("ADM=Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1')", 'ADM=Path('+repr((OUT/'launcher').as_posix())+')',1)
caller=caller.replace("ENTRY=Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/launcher/launch.py')", 'ENTRY=Path('+repr(DRIVER.as_posix())+')',1)
caller=caller.replace('R107-B54-NORMAL-S-ADMISSION.json','ROOT-NORMAL-S-ADMISSION.json',1)
caller=caller.replace('ONE_ORIGINAL_CURRENT_B54_COMPLETE_NORMAL_S_AFTER_BOTH_FULL_M_READBACKS','ONE_STATIC_B54_COMPLETE_NORMAL_S_REATTEMPT_REUSING_QUALIFIED_M0',1)
caller=caller.replace(" assert admission['root_wrapper']==fp(Path(__file__).resolve())", " assert admission['root_wrapper']==fp(Path(__file__).resolve())\n assert admission['driver_source']==fp(ENTRY)\n assert admission['engineering_before_driver']=="+repr(baseline_driver)+" and fp(Path(admission['engineering_before_driver']['path']))==admission['engineering_before_driver']\n assert admission['qualified_M_readback']=="+repr(m_accept_desc)+" and fp(Path(admission['qualified_M_readback']['path']))==admission['qualified_M_readback']\n assert admission['qualified_M_result']=="+repr(setup)+"\n assert admission['baseline_Root_PINS']=="+repr(baseline_root_pins),1)
caller=caller.replace(" argv=[sys.executable,'-I','-B','-X','utf8',str(ENTRY),'normal-start']", " argv=[sys.executable,'-B','-X','utf8',str(ENTRY),'--root-source-pins-sha256',"+repr(baseline_root_pins['sha256'])+",'--setup-sha256',"+repr(setup['sha256'])+",'--attempt-driver-sha256',admission['driver_source']['sha256']]",1)
caller=caller.replace("stem=ADM/'r107-b54-normal-s'","stem=ADM/'normal-start'",1)
assert "argv=[sys.executable,'-B','-X','utf8',str(ENTRY),'--root-source-pins-sha256'"in caller
before_caller_functions=functions(old_caller);after_caller_functions=functions(caller)
assert before_caller_functions['fp']==after_caller_functions['fp'] and before_caller_functions['put']==after_caller_functions['put']
suffix=old_caller[old_caller.index(' env=dict(os.environ)'):old_caller.index("if __name__")]
assert caller[caller.index(' env=dict(os.environ)'):caller.index("if __name__")]==suffix
ast.parse(caller);put(CALLER,caller);caller_desc={'path':CALLER.as_posix(),**fp(caller.encode())};caller_splices=transform('caller',old_caller,caller)

for name,text in [('driver',driver),('caller',caller)]:
 table=symtable.symtable(text,name,'exec');bound={s.get_name()for s in table.get_symbols()if s.is_assigned()or s.is_imported()or s.is_namespace()};missing=[]
 def visit(scope):
  for symbol in scope.get_symbols():
   if symbol.is_global()and symbol.is_referenced()and symbol.get_name()not in bound and symbol.get_name()not in vars(builtins)and symbol.get_name()!='__file__':missing.append(symbol.get_name())
  for child in scope.get_children():visit(child)
 visit(table);assert not missing,(name,missing)

bindings={'status':'SOURCE_ONLY_DISTINCT_M_BASELINE_AND_NEW_S_EXECUTION_NOT_RUN','M_engineering_before':{'driver':baseline_driver,'Root_PINS':baseline_root_pins,'RESULT':setup,'freeze':freeze,'full_acceptance':m_accept_desc,'M_PID':16792,'M37_stages25_native1160':True,'new_M_executed_or_claimed':False},'new_S':{'driver':driver_desc,'caller':caller_desc,'native_before_Run_archive':(OUT/'native-normal-start-attempt-r1').as_posix(),'caller_outputs_stem':(OUT/'launcher/normal-start').as_posix(),'created_keys':['argv','pid','started_ns','process_only_git_config'],'terminal_keys':['argv','pid','native_exit','wait_returncode','stdout','stderr','finished_ns','process_only_git_config'],'driver_argv_items':11,'Root_admission_file':(OUT/'launcher/ROOT-NORMAL-S-ADMISSION.json').as_posix(),'S_PID_Run_context_RESULTS':'UNBOUND_NOT_RUN'},'new_finite_retirement':retirement_desc,'new_whole_count':0,'success_readback_Source_successor':'PENDING_FIXED_NEW_DRIVER_CALLER_PINS_KEEP_SEPARATE_M_AND_S_BINDINGS'}
jput(OUT/'M-AND-S-BINDINGS.json',bindings)
jput(OUT/'SOURCE-INPUTS.json',{'scope':'fixed_existing_Source_and_Root_public_qualification_only','files':list(INPUTS.values()),'original_Run_private_ENV_or_business_stream_reads':0})
jput(OUT/'SOURCE-REVIEW.json',{'status':'AUTHOR_SOURCE_ONLY_DRIVER_CALLER_COMPLETE_NOT_ROOT_OR_FRESH_REVIEW','driver':driver_desc,'caller':caller_desc,'old_driver_referenced_not_copied':baseline_driver,'fourteen_original_helpers_raw':[k for k in before_functions if k!='main'],'complete_original_business_suffix_raw':True,'driver_diff_splices':driver_splices,'caller_diff_splices':caller_splices,'caller_fp_put_and_environment_Popen_wait_stream_suffix_raw':True,'source_authority_executed_imported_or_called':False,'candidate_or_target_execution':False,'Root_execution_admission_created':False,'Source_sealed':False,'created_ns':time.time_ns()})
print(json.dumps({'driver':driver_desc,'caller':caller_desc,'driver_splices':driver_splices,'caller_splices':caller_splices,'helpers_raw':14,'business_suffix_raw':True,'new_M':False,'candidate_or_target_execution':False,'Source_sealed':False},ensure_ascii=False))
