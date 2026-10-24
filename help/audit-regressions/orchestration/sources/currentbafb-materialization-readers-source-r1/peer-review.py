"""Independent complete saved-M37 readback. No target imports, subprocesses, Git or resource APIs."""
import argparse,ast,hashlib,json,os,re,stat,time,traceback
from collections import Counter
from pathlib import Path

B=Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1')
SRC=Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source')
READER_SOURCE=Path(__file__).resolve().parent
OUT=Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1')
ROOT=SRC/'validation';PROFILE=ROOT/'actual-profile';ACTUAL=ROOT/'materialize-freeze-assets-r1'
HEAD = 'bafb6305062aeb6b6e945c21da572863062ca480';PARENT = 'd0a5d5398546e223122b5c2a2f115e1908f78ced'
PINS_EXPECTED = {'bytes': 13638, 'sha256': '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}
CALLBACK_REPORT = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-original-source-callback-actual-r1/REPORT.json', 'bytes': 5466, 'sha256': '0d02c2aa925c85bf4a34fe6b3eba3bb2797d00b26171ef02fb4a665f4c4f3c77'}
CALLBACK_INPUTS = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-original-source-callback-actual-r1/INPUTS.json', 'bytes': 52861412, 'sha256': '6491cab0bea2439c55e1fddb5f444b8784355c998d9cb4f75a2ad69a2489b588'}
CALLBACK_ROOT = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R100-CALLBACK-ACTUAL-ROOT-READBACK.json', 'bytes': 1947, 'sha256': '626e8fcee1ff71a8d09974e0b5df16bd3c5c3f1502a8be00d9b7114d4bca75f3'}
CALLBACK_RETURN_PIN = {'bytes': 198653693, 'sha256': 'f38cfab1c864367eb6ebb1e4edb756400358fb1c1ee4cfbf54b6375816cb8f2c'}
CALLBACK_ROOT_EXPECTED = {'schema': 1, 'status': 'ROOT_ACTUAL_COMPLETE_BAFB_R3_CALLBACKS0_PUBLIC_READBACK0_ADMIT_FULL_M_ONLY', 'actual_REPORT': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-original-source-callback-actual-r1/REPORT.json', 'bytes': 5466, 'sha256': '0d02c2aa925c85bf4a34fe6b3eba3bb2797d00b26171ef02fb4a665f4c4f3c77'}, 'actual_INPUTS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-original-source-callback-actual-r1/INPUTS.json', 'bytes': 52861412, 'sha256': '6491cab0bea2439c55e1fddb5f444b8784355c998d9cb4f75a2ad69a2489b588'}, 'original_tool_session': 87567, 'original_handle_consumed_terminal_chunk': 'dddba0', 'actual_PID': 27244, 'native_exit': 0, 'wait_returncode': 0, 'complete_streams_join': True, 'M_and_S_each_full_checks': 11680434, 'complete_return_objects_equal': True, 'canonical_return_pin': {'bytes': 198653693, 'sha256': 'f38cfab1c864367eb6ebb1e4edb756400358fb1c1ee4cfbf54b6375816cb8f2c'}, 'actual_source_paths': 32497, 'all_original_first_EOF_native9_cross4_closed_windows_verified': True, 'original_cache_semantics_preserved_no_second_disk_read_claim': True, 'new_finite_own_output_registry_counts': {'close': 367, 'zero': 53}, 'original_d0_Run_body_paths_absent': True, 'original_current_M_S_raw_stream_bodies_absent': True, 'mutable_original_C_D_not_consumed': True, 'callback_native_calls': 0, 'M_body_S_main_App_business': 'NOT RUN', 'A0': False, 'normal_Source_PINS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/PINS.json', 'bytes': 13638, 'sha256': '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}, 'duration_seconds': 212.0618549, 'Root_complete_own_public_input_files': 15, 'Root_all_windows_ended': True, 'completed_ns': 1792860043854698800}
CALLBACK_BOUND = True
CALLBACK_EXPECTED_CHECKS = 11680434
CALLBACK_INPUT_COUNT = 32497
CALLBACK_NATIVE_PID = 27244

ARGS=None
STAT_KEYS=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
CACHE={};WINDOWS={};COUNTS=Counter()

def need(ok,code):
    COUNTS[code]+=1
    if not ok:raise AssertionError(code)
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def compact(row):return {k:row[k] for k in ['bytes','sha256']}
def nine(s):return [int(getattr(s,k)) for k in STAT_KEYS]
def ordinary(p):
    need(p.is_absolute() and '..' not in p.parts,'ABSOLUTE_ORDINARY_SAVED_FILE_PATH')
    for node in [p,*p.parents]:
        facts=node.lstat();need(not stat.S_ISLNK(facts.st_mode) and not (getattr(facts,'st_file_attributes',0)&1024),'NO_SAVED_PATH_SYMLINK_OR_REPARSE')
    need(stat.S_ISREG(p.lstat().st_mode),'SAVED_FILE_IS_REGULAR')
def read(p):
    p=Path(p);key=p.as_posix()
    if key in CACHE:return CACHE[key]
    ordinary(p);started=time.time_ns();a=p.lstat();an=nine(a)
    with p.open('rb') as stream:
        b=os.fstat(stream.fileno());bn=nine(b);raw=stream.read();eof=stream.read(1);c=os.fstat(stream.fileno());cn=nine(c)
    d=p.lstat();dn=nine(d)
    need(an==dn and bn==cn and eof==b'' and len(raw)==bn[6],'FULL_EOF_NAMED_AND_FD_NATIVE9_EACH_STABLE')
    need(all(an[i]==bn[i] for i in [0,1,6,7]) and getattr(a,'st_birthtime_ns',None)==getattr(b,'st_birthtime_ns',None),'WINDOWS_CONFIRMED_CROSS_API_DEV_INO_SIZE_MTIME_AND_BIRTHTIME_IDENTITY')
    CACHE[key]=raw;WINDOWS[key]={'path':key,**pin(raw),'named_before':an,'named_after':dn,'fd_before':bn,'fd_after':cn,
        'named_birthtime_ns':getattr(a,'st_birthtime_ns',None),'fd_birthtime_ns':getattr(b,'st_birthtime_ns',None),
        'EOF':True,'closed':True,'started_ns':started,'ended_ns':time.time_ns()};return raw
def pairs(items):
    result={}
    for key,value in items:need(key not in result,'NO_DUPLICATE_JSON_KEYS');result[key]=value
    return result
def decode(raw):return json.loads(raw,object_pairs_hook=pairs)
def js(p):return decode(read(p))
def descriptor(p):return {'path':Path(p).as_posix(),**pin(read(p))}
def pinned(pointer):
    need(type(pointer) is dict and set(pointer) in [{'path','bytes','sha256'},{'path','bytes','sha256','native9'}]
        and type(pointer['bytes']) is int and pointer['bytes']>=0 and type(pointer['sha256']) is str
        and re.fullmatch('[a-f0-9]{64}',pointer['sha256']) is not None,'EXACT_FIXED_PUBLIC_DESCRIPTOR')
    p=Path(pointer['path']);raw=read(p);need(pin(raw)==compact(pointer),'PINNED_COMPLETE_SAVED_BYTES')
    if 'native9' in pointer:need(WINDOWS[p.as_posix()]['named_after']==pointer['native9'],'PINNED_ACTUAL_NAMED_NATIVE9')
    return raw
def source_constants(raw):
    result={}
    for node in ast.parse(raw).body:
        if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
            try:result[node.targets[0].id]=ast.literal_eval(node.value)
            except (ValueError,TypeError):pass
    return result
def row_valid(key,row):
    need(type(row) is dict and set(row)=={'path','bytes','sha256','stat9'} and row['path']==key
        and type(row['bytes']) is int and row['bytes']>=0 and re.fullmatch('[a-f0-9]{64}',row['sha256']) is not None
        and type(row['stat9']) is list and len(row['stat9'])==9 and all(type(x) is int for x in row['stat9'])
        and row['stat9'][6]==row['bytes'] and stat.S_ISREG(row['stat9'][2]),'COMPLETE_EXACT_SOURCE_METADATA_ROW')
def source_row(key,row):
    row_valid(key,row);raw=read(Path(key));need(pin(raw)==compact(row) and WINDOWS[key]['named_after']==row['stat9'],'FULL_ORIGINAL_SOURCE_BYTES_AND_SNAPSHOT_NATIVE9');return raw
def put(name,value):(OUT/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n','utf-8',newline='\n')

def validate_complete_source_tree(frozen, qualified, source, repository, check):
    """Pure complete-tracked freeze join; no body reads or native operations."""
    import hashlib,json,re
    tree=frozen['source_tree']
    check(type(tree) is dict and list(tree)==['schema','scope','source_sha','requested_repo_source','volume_suffix','writer_destination','files','directories','file_count','directory_count','manifest_sha256'],'SOURCE_TREE_COMPLETE_ELEVEN_KEY_FREEZE')
    check(type(tree['schema']) is int and tree['schema']==1 and tree['scope']=='COMPLETE_TRACKED_SOURCE_TREE'
        and tree['source_sha']==frozen['source_sha']==source
        and tree['requested_repo_source']==frozen['requested_repo_source']==repository
        and tree['volume_suffix']=='source-tree' and tree['writer_destination']=='/run/audit-source-tree','SOURCE_TREE_SAME_REAL_SOURCE_AND_VAL_ORIGIN')
    all_source=qualified['all']
    check(type(all_source) is dict and all_source and list(all_source)==sorted(all_source),'SOURCE_TREE_COMPLETE_TRACKED_ORDERED_AUTHORITY')
    files=[];parents=set()
    for relative,row in all_source.items():
        check(type(relative) is str and relative and not re.search(r'[\x00-\x1f\x7f\\:]',relative)
            and all(part not in ('','.','..','.git') for part in relative.split('/'))
            and relative not in ['crmeb/.env','crmeb/public/install.lock'],'SOURCE_TREE_SAFE_COMPLETE_TRACKED_PATH')
        check(type(row) is dict and set(row)=={'path','bytes','sha256'} and row['path']==relative
            and type(row['bytes']) is int and row['bytes']>=0 and type(row['sha256']) is str
            and re.fullmatch('[a-f0-9]{64}',row['sha256']) is not None,'SOURCE_TREE_COMPLETE_TRACKED_DESCRIPTOR')
        files.append({'path':relative,'bytes':row['bytes'],'sha256':row['sha256']})
        parts=relative.split('/')
        parents.update('/'.join(parts[:count]) for count in range(1,len(parts)))
    directories=sorted(parents)
    check(type(tree['files']) is list and type(tree['directories']) is list and tree['files']==files
        and tree['directories']==directories and type(tree['file_count']) is int and type(tree['directory_count']) is int
        and tree['file_count']==len(files) and tree['directory_count']==len(directories),'SOURCE_TREE_EXACT_ALL_TRACKED_FILES_AND_COMPLETE_PARENT_NAMESPACE')
    payload=json.dumps({'files':files,'directories':directories},ensure_ascii=False,separators=(',',':')).encode('utf-8')
    check(type(tree['manifest_sha256']) is str and tree['manifest_sha256']==hashlib.sha256(payload).hexdigest(),'SOURCE_TREE_COMPLETE_COMPACT_UTF8_NO_LF_MANIFEST')
    return tree

def validate_current_task6_profile(values, pub, oldpub, expected_sources, read):
    """Qualify the current fixed F2 Source nine; keep old FA profile independently historical."""
    CURRENT_TASK6_MOUNT_PROFILE_SOURCE=values['CURRENT_TASK6_MOUNT_PROFILE_SOURCE']
    CURRENT_TASK6_MOUNT_PROFILE_MANIFEST=values['CURRENT_TASK6_MOUNT_PROFILE_MANIFEST']
    CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']
    HISTORICAL_TWENTYONE_PUBLICATION=values['HISTORICAL_TWENTYONE_PUBLICATION']
    def decode(raw):return json.loads(raw)
    def compact(row):return {key:row[key] for key in ['bytes','sha256']}
    def check(ok,code):assert ok,code
    def constants(raw):
        result={}
        for node in ast.parse(raw).body:
            if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
                try:result[node.targets[0].id]=ast.literal_eval(node.value)
                except (ValueError,TypeError):pass
        return result
    profile_packet=decode(read(CURRENT_TASK6_MOUNT_PROFILE_SOURCE));profile_manifest=decode(read(CURRENT_TASK6_MOUNT_PROFILE_MANIFEST));profile_root=Path(CURRENT_TASK6_MOUNT_PROFILE_SOURCE['path']).parent
    for name,row in profile_packet['files'].items():read({'path':(profile_root/name).as_posix(),**compact(row)})
    check(profile_manifest['before_selected9']==oldpub['selected9'] and profile_manifest['selected9']==pub['selected9'] and len(pub['selected9'])==9,'TASK6_REPAIR_CURRENT_PROFILE9_AFTER_PINS_OVER_EXACT_ORIGINAL9')
    normal_profile=Path(CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE['profile_interface']['path']).parent;prior_profile=Path(HISTORICAL_TWENTYONE_PUBLICATION['path']).parent/'actual-profile'
    prior9={};current9={}
    for oldrow,newrow in zip(oldpub['selected9'],pub['selected9']):
        name=oldrow['path'];check(name==newrow['path'],'TASK6_REPAIR_NINE_PROFILE_IDENTITY_ORDER')
        oldraw=read({'path':(prior_profile/name).as_posix(),**compact(oldrow)});newraw=read({'path':(normal_profile/name).as_posix(),**compact(newrow)})
        check(oldraw==read({'path':(profile_root/'before/profile'/name).as_posix(),**compact(oldrow)}) and newraw==read({'path':(profile_root/'candidate/profile'/name).as_posix(),**compact(newrow)}),'TASK6_REPAIR_ALL_NINE_WHOLE_SOURCE_BEFORE_AND_AFTER')
        prior9[name]=oldraw;current9[name]=newraw
    check({name for name in current9 if prior9[name]!=current9[name]}=={'ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py','interface-v1.json','source-derivation.json'}
        and len(current9['ms4-initialize.php'])==91460 and all(current9[name]==prior9[name] for name in ['ms4-initialize.php','compose.yml','README.md','seed-source-inverse.json']),'TASK6_REPAIR_ONLY_FIVE_NECESSARY_PROFILE_PIN_DAG_LEAVES_FOUR_RAW_WHOLE')
    def current_profile_statement(raw,name):
        text=raw.decode('utf8');tree=ast.parse(text)
        rows=[node for node in tree.body if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name) and node.targets[0].id==name]
        check(len(rows)==1,'TASK6_REPAIR_ONE_ACTIVE_PROFILE_LITERAL_ASSIGNMENT')
        return ast.get_source_segment(text,rows[0]).encode()
    legacy='help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php';actual_helper=expected_sources[legacy]
    necessary={
        'ms4-host.py':{'INTERFACE_SHA256':hashlib.sha256(current9['interface-v1.json']).hexdigest()},
        'normal-close-ms4-fulfillment.py':{'HOST_SHA256':hashlib.sha256(current9['ms4-host.py']).hexdigest()},
        'verify-zero-ms4-fulfillment.py':{'INTERFACE_SHA256':hashlib.sha256(current9['interface-v1.json']).hexdigest(),'HOST_SHA256':hashlib.sha256(current9['ms4-host.py']).hexdigest(),'CLOSE_SHA256':hashlib.sha256(current9['normal-close-ms4-fulfillment.py']).hexdigest()}}
    for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
        before_values=constants(prior9[name]);wanted=[dict(row) for row in before_values['THREE_READ_SOURCE_FILES']]
        check(sum(row['path']==legacy for row in wanted)==1,'TASK6_REPAIR_SINGLE_CURRENT_THREE_READ_HELPER_TUPLE')
        necessary[name]['THREE_READ_SOURCE_FILES']=[dict(actual_helper) if row['path']==legacy else row for row in wanted]
    check(sum(len(values) for values in necessary.values())==7,'TASK6_REPAIR_EXACT_SEVEN_DERIVED_PYTHON_PIN_DAG_CONSTANTS')
    for name,wanted in necessary.items():
        before_values=constants(prior9[name]);after_values=constants(current9[name]);restored=current9[name]
        check(all(after_values[key]==value for key,value in wanted.items()) and {key:value for key,value in before_values.items() if key not in wanted}=={key:value for key,value in after_values.items() if key not in wanted},'TASK6_REPAIR_PYTHON_ONLY_REQUIRED_CURRENT_HELPER_AND_INTERFACE_HOST_CLOSE_DAG_PINS')
        for key in wanted:
            old_stmt=current_profile_statement(prior9[name],key);new_stmt=current_profile_statement(current9[name],key)
            check(prior9[name].count(old_stmt)==current9[name].count(new_stmt)==1,'TASK6_REPAIR_SINGLE_REQUIRED_LITERAL_DAG_SEAM')
            restored=restored.replace(new_stmt,old_stmt,1)
        check(restored==prior9[name],'TASK6_REPAIR_WHOLE_THREE_PYTHON_MODULE_INVERSE_ALL_FUNCTIONS_BUSINESS_IDENTICAL')
    expected_interface=decode(prior9['interface-v1.json']);expected_interface['fulfillment_normal_successor']['three_read_internal']['source_helper_files'][0]=dict(actual_helper)
    check(decode(current9['interface-v1.json'])==expected_interface,'TASK6_REPAIR_INTERFACE_ONLY_CURRENT_THREE_READ_HELPER_NO_HISTORICAL_REWRITE')
    expected_derivation=decode(prior9['source-derivation.json']);expected_derivation['three_read_helpers'][0]=dict(actual_helper)
    newpins={row['path']:row for row in pub['selected9']}
    expected_derivation['current_nonself_artifacts']=[dict(newpins[row['path']]) for row in expected_derivation['current_nonself_artifacts']]
    check(len(expected_derivation['current_nonself_artifacts'])==8 and decode(current9['source-derivation.json'])==expected_derivation,'TASK6_REPAIR_DERIVATION_ONLY_CURRENT_HELPER_AND_FULL_EIGHT_NONSELF_PINS_ALL_HISTORY_EXACT')
    attempt=decode(read(values['CURRENT_TASK6_PROFILE_ATTEMPT']))
    check(pub['finite_contexts']['current_task6_profile_attempt']==values['CURRENT_TASK6_PROFILE_ATTEMPT']
        and attempt['schema']==1 and attempt['status']=='ACTUAL_TASK6_PROFILE_ATTEMPT_PAID0_TASK6_1_ORDINARY_CLOSE0_ZERO0_FINITE_NOT_TWELFTH_WHOLE'
        and attempt['source_sha']==pub['parent'] and attempt['business_acceptance']=='INCOMPLETE'
        and attempt['new_recursive_retirement_whole'] is False,'CURRENT_TASK6_FINITE_PROFILE_ATTEMPT_NOT_NEW_WHOLE_OR_BUSINESS')
    check(attempt['privacy_scope']=={'qualified_Run_public_EOF_files':192,'other_Run_named_metadata_only':6613,'ENV_private_actor_cookie_upload_appchild_body_reads':0,'original_outer_M_S_stream_body_reads':0},'CURRENT_TASK6_FINITE_PUBLIC_AND_PRIVATE_METADATA_BOUNDARY')
    return True

def validate_current_owner_profile(values, pub, read):
    """Exact fixed current owner profile/finite-R89 fragments; no callback/module execution."""
    def decode(raw):return json.loads(raw)
    def compact(row):return {key:row[key] for key in ['bytes','sha256']}
    def check(ok,code):assert ok,code
    def constants(raw):
        result={}
        for node in ast.parse(raw).body:
            if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
                try:result[node.targets[0].id]=ast.literal_eval(node.value)
                except (ValueError,TypeError):pass
        return result
    def patch_apply(before,raw,oldlabel='a',newlabel='b'):
        lines=raw.decode('utf8').splitlines(keepends=True);index=0;result=dict(before)
        while index<len(lines):
            check(lines[index].startswith('--- '+oldlabel+'/'),'UPROOT_PATCH_HEADER');name=lines[index][len('--- '+oldlabel+'/'):].rstrip('\r\n');index+=1
            check(name in before and lines[index].rstrip('\r\n')=='+++ '+newlabel+'/'+name,'UPROOT_PATCH_SAME_MEMBER');index+=1
            original=before[name].decode('utf8').splitlines(keepends=True);cursor=0;out=[]
            while index<len(lines) and lines[index].startswith('@@ '):
                match=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n?',lines[index]);check(match is not None,'UPROOT_PATCH_HUNK')
                oldn=int(match[2] or 1);newn=int(match[4] or 1);start=int(match[1])-1 if oldn else int(match[1]);check(start>=cursor,'UPROOT_PATCH_ORDER')
                out.extend(original[cursor:start]);cursor=start;index+=1;used=0;made=0
                while index<len(lines) and not lines[index].startswith(('@@ ','--- '+oldlabel+'/')):
                    line=lines[index];check(line[:1] in [' ','+','-'],'UPROOT_PATCH_LINE')
                    if line[0] in [' ','-']:check(original[cursor]==line[1:],'UPROOT_PATCH_OLD_BYTES');cursor+=1;used+=1
                    if line[0] in [' ','+']:out.append(line[1:]);made+=1
                    index+=1
                check((used,made)==(oldn,newn),'UPROOT_PATCH_COUNTS')
            out.extend(original[cursor:]);result[name]=''.join(out).encode('utf8')
        return result
    CURRENT_TASK6_OWNER_PROFILE_SOURCE=values['CURRENT_TASK6_OWNER_PROFILE_SOURCE']
    CURRENT_TASK6_OWNER_PROFILE_MANIFEST=values['CURRENT_TASK6_OWNER_PROFILE_MANIFEST']
    CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']
    HISTORICAL_TWENTYTWO_PUBLICATION=values['HISTORICAL_TWENTYTWO_PUBLICATION']
    CURRENT_OWNER_PROFILE_ATTEMPT=values['CURRENT_OWNER_PROFILE_ATTEMPT']
    HISTORICAL_TWENTYTWO_M_SOURCE=values['HISTORICAL_TWENTYTWO_M_SOURCE']
    oldpub=decode(read(HISTORICAL_TWENTYTWO_PUBLICATION))
    expected_sources={row['path']:row for row in pub['repository_Source_requirements']}
    stage=decode(read(values['CURRENT_TASK6_OWNER_SOURCE_HISTORY']))
    profile_packet=decode(read(CURRENT_TASK6_OWNER_PROFILE_SOURCE));profile_manifest=decode(read(CURRENT_TASK6_OWNER_PROFILE_MANIFEST));profile_root=Path(CURRENT_TASK6_OWNER_PROFILE_SOURCE['path']).parent
    for name,row in profile_packet['files'].items():read({'path':(profile_root/name).as_posix(),**compact(row)})
    check(profile_manifest['before_selected9']==oldpub['selected9'] and profile_manifest['selected9']==pub['selected9'] and len(pub['selected9'])==9,'OWNER_REPAIR_CURRENT_PROFILE9_AFTER_PINS_OVER_EXACT_ORIGINAL9')
    normal_profile=Path(CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE['profile_interface']['path']).parent;prior_profile=Path(HISTORICAL_TWENTYTWO_PUBLICATION['path']).parent/'actual-profile'
    prior9={};current9={}
    for oldrow,newrow in zip(oldpub['selected9'],pub['selected9']):
        name=oldrow['path'];check(name==newrow['path'],'OWNER_REPAIR_NINE_PROFILE_IDENTITY_ORDER')
        oldraw=read({'path':(prior_profile/name).as_posix(),**compact(oldrow)});newraw=read({'path':(normal_profile/name).as_posix(),**compact(newrow)})
        check(oldraw==read({'path':(profile_root/'before/profile'/name).as_posix(),**compact(oldrow)}) and newraw==read({'path':(profile_root/'candidate/profile'/name).as_posix(),**compact(newrow)}),'OWNER_REPAIR_ALL_NINE_WHOLE_SOURCE_BEFORE_AND_AFTER')
        prior9[name]=oldraw;current9[name]=newraw
    check({name for name in current9 if prior9[name]!=current9[name]}=={'ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py','interface-v1.json','source-derivation.json'}
        and len(current9['ms4-initialize.php'])==91460 and all(current9[name]==prior9[name] for name in ['ms4-initialize.php','compose.yml','README.md','seed-source-inverse.json']),'OWNER_REPAIR_ONLY_FIVE_NECESSARY_PROFILE_PIN_DAG_LEAVES_FOUR_RAW_WHOLE')
    def current_profile_statement(raw,name):
        text=raw.decode('utf8');tree=ast.parse(text)
        rows=[node for node in tree.body if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name) and node.targets[0].id==name]
        check(len(rows)==1,'OWNER_REPAIR_ONE_ACTIVE_PROFILE_LITERAL_ASSIGNMENT')
        return ast.get_source_segment(text,rows[0]).encode()
    legacy='help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php';actual_helper=expected_sources[legacy]
    necessary={
        'ms4-host.py':{'INTERFACE_SHA256':hashlib.sha256(current9['interface-v1.json']).hexdigest()},
        'normal-close-ms4-fulfillment.py':{'HOST_SHA256':hashlib.sha256(current9['ms4-host.py']).hexdigest()},
        'verify-zero-ms4-fulfillment.py':{'INTERFACE_SHA256':hashlib.sha256(current9['interface-v1.json']).hexdigest(),'HOST_SHA256':hashlib.sha256(current9['ms4-host.py']).hexdigest(),'CLOSE_SHA256':hashlib.sha256(current9['normal-close-ms4-fulfillment.py']).hexdigest()}}
    for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
        before_values=constants(prior9[name]);wanted=[dict(row) for row in before_values['THREE_READ_SOURCE_FILES']]
        check(sum(row['path']==legacy for row in wanted)==1,'OWNER_REPAIR_SINGLE_CURRENT_THREE_READ_HELPER_TUPLE')
        necessary[name]['THREE_READ_SOURCE_FILES']=[dict(actual_helper) if row['path']==legacy else row for row in wanted]
    check(sum(len(values) for values in necessary.values())==7,'OWNER_REPAIR_EXACT_SEVEN_DERIVED_PYTHON_PIN_DAG_CONSTANTS')
    for name,wanted in necessary.items():
        before_values=constants(prior9[name]);after_values=constants(current9[name]);restored=current9[name]
        check(all(after_values[key]==value for key,value in wanted.items()) and {key:value for key,value in before_values.items() if key not in wanted}=={key:value for key,value in after_values.items() if key not in wanted},'OWNER_REPAIR_PYTHON_ONLY_REQUIRED_CURRENT_HELPER_AND_INTERFACE_HOST_CLOSE_DAG_PINS')
        for key in wanted:
            old_stmt=current_profile_statement(prior9[name],key);new_stmt=current_profile_statement(current9[name],key)
            check(prior9[name].count(old_stmt)==current9[name].count(new_stmt)==1,'OWNER_REPAIR_SINGLE_REQUIRED_LITERAL_DAG_SEAM')
            restored=restored.replace(new_stmt,old_stmt,1)
        check(restored==prior9[name],'OWNER_REPAIR_WHOLE_THREE_PYTHON_MODULE_INVERSE_ALL_FUNCTIONS_BUSINESS_IDENTICAL')
    expected_interface=decode(prior9['interface-v1.json']);expected_interface['fulfillment_normal_successor']['three_read_internal']['source_helper_files'][0]=dict(actual_helper)
    check(decode(current9['interface-v1.json'])==expected_interface,'OWNER_REPAIR_INTERFACE_ONLY_CURRENT_THREE_READ_HELPER_NO_HISTORICAL_REWRITE')
    expected_derivation=decode(prior9['source-derivation.json']);expected_derivation['three_read_helpers'][0]=dict(actual_helper)
    newpins={row['path']:row for row in pub['selected9']}
    expected_derivation['current_nonself_artifacts']=[dict(newpins[row['path']]) for row in expected_derivation['current_nonself_artifacts']]
    check(len(expected_derivation['current_nonself_artifacts'])==8 and decode(current9['source-derivation.json'])==expected_derivation,'OWNER_REPAIR_DERIVATION_ONLY_CURRENT_HELPER_AND_FULL_EIGHT_NONSELF_PINS_ALL_HISTORY_EXACT')
    attempt=decode(read(CURRENT_OWNER_PROFILE_ATTEMPT))
    check(attempt['schema']==1 and attempt['status']=='ACTUAL_TASK6_PROFILE_ATTEMPT_PAID0_TASK6_1_ORDINARY_CLOSE0_ZERO0_FINITE_NOT_TWELFTH_WHOLE'
        and attempt['source_sha']==pub['parent'] and attempt['run_id']=='2026102406240181bf85' and attempt['business_acceptance']=='INCOMPLETE'
        and attempt['new_recursive_retirement_whole'] is False,'OWNER_REPAIR_FINITE_REAL_FAILED_PROFILE_ATTEMPT_NOT_BUSINESS_SUCCESS')
    original_created=decode(read(attempt['original_S']['created']));original_ended=decode(read(attempt['original_S']['terminal']))
    expected_original_argv=['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-B','-X','utf8',str(Path(HISTORICAL_TWENTYTWO_M_SOURCE['path']).parent.parent/'validation/run.py'),
        '--root-source-pins-sha256','7a667bbfed48addff3d34d5ab39bd478747e8f3e4e84b96389c3b4e433bbc3b1','--setup-sha256','9e4b9917116e4405c692ba37259daebd94e735523bfe00779431e17b18c5edcd']
    check(set(original_created)=={'pid','argv','started_ns'} and set(original_ended)=={'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'}
        and original_created['pid']==original_ended['pid']==21412 and original_created['argv']==expected_original_argv
        and type(original_created['started_ns']) is type(original_ended['finished_ns']) is int and original_created['started_ns']<=original_ended['finished_ns']
        and original_ended['native_exit']==original_ended['wait_returncode']==1,'OWNER_REPAIR_ORIGINAL_REAL_CREATED3_TERMINAL6_S1_CONTROL_NO_RAW_STREAM_READ')
    for stream in ['stdout','stderr']:
        metadata=original_ended[stream]
        check(set(metadata)=={'path','bytes','sha256'} and metadata['path']==(Path(attempt['original_S']['created']['path']).parent/('normal-start.'+stream)).as_posix()
            and type(metadata['bytes']) is int and metadata['bytes']>=0 and type(metadata['sha256']) is str and re.fullmatch('[a-f0-9]{64}',metadata['sha256']) is not None,'OWNER_REPAIR_ORIGINAL_S_STREAM_DESCRIPTORS_METADATA_ONLY_NEVER_OPEN')
    close=decode(read(attempt['close']));zero=decode(read(attempt['zero']))
    check(close['status']=='VERIFIED_TASK6_FAILURE_NORMAL_CLOSE_ZERO_RESIDUAL' and zero['status']=='VERIFIED_TASK6_FAILURE_INDEPENDENT_FRESH_SIX_QUERY_ZERO'
        and close['run_id']==zero['run_id']==attempt['run_id'] and close['source_sha']==zero['source_sha']==attempt['source_sha']
        and close['freeze_sha256']==zero['freeze_sha256']==attempt['freeze_sha256'] and close['original_S_pid']==21412
        and close['original_S_native_exit_wait']==close['original_task6_exit']==1 and close['original_paid_exit']==0 and close['original_paid_status']=='EXITED_NATIVE0'
        and close['business_acceptance']==zero['business_acceptance']=='INCOMPLETE','OWNER_REPAIR_PAID0_TASK6_1_REAL_FAILED_STAGE')
    check(close['source']==attempt['close_Source'] and close['removed']=={'containers':4,'networks':1,'volumes':9}
        and len(close['resource_ids'])==4 and len(set(close['resource_ids'].values()))==4 and len(close['volume_mountpoints'])==9
        and close['ordinary_stop_roles']==['audit-mysql','audit-redis'] and close['ordinary_nonforce_only'] is True
        and close['global_foreign_baseline']==close['global_foreign_after'],'OWNER_REPAIR_EXACT_4CID_1NETWORK_9VOLUME_ORDINARY_FOREIGN_PRESERVED')
    check(zero['resource_mutations']==0 and zero['Host_imported'] is zero['close_imported_or_invoked'] is False
        and zero['close_result']==attempt['close'] and zero['close_actual_native_operations_verified']==90 and zero['close_five_ordinary_mutations_verified'] is True
        and close['all_Run_named_metadata_namespace_preserved'] is zero['all_Run_and_close_named_metadata_namespace_preserved'] is True,'OWNER_REPAIR_INDEPENDENT_ZERO_AND_COMPLETE_NAMED_NAMESPACE')
    privacy=attempt['privacy_scope']
    check(close['finite_public_full_body_count']==zero['finite_public_full_body_count']==privacy['qualified_Run_public_EOF_files']==194
        and close['other_files_metadata_only_count']==zero['other_Run_files_named_metadata_only_count']==privacy['other_Run_named_metadata_only']==6694
        and close['private_ENV_actor_cookie_upload_appchild_body_reads']==zero['private_ENV_actor_cookie_upload_appchild_body_reads']==privacy['ENV_private_actor_cookie_upload_appchild_body_reads']==0
        and close['original_outer_M_S_raw_stream_body_reads']==zero['original_outer_M_S_raw_stream_body_reads']==privacy['original_outer_M_S_stream_body_reads']==0,'OWNER_REPAIR_POSITIVE_PUBLIC_VS_FORBIDDEN_METADATA_BOUNDARY')
    preservation=close['frozen_Source_and_original_M_S_control_metadata_preserved']
    check(set(preservation)=={'public','original_outer_raw_metadata_only','initial_prepare_control_metadata','original_root_public_control_pairs','complete_normal_Source_named_namespace'}
        and len(preservation['original_outer_raw_metadata_only'])==4 and all(row['body']=='NOT READ' and len(row['named9'])==9 for row in preservation['original_outer_raw_metadata_only'].values())
        and len(preservation['original_root_public_control_pairs'])==80,'OWNER_REPAIR_ORIGINAL_FULL_PRESERVATION_ATTESTATION_NOT_REOPENING_RAW_BODIES')
    peer=decode(read(attempt['independent_Source_review']));sourcepins=decode(read(attempt['Source_PINS']))
    check(peer['status']=='PASS_DIRECTED_INDEPENDENT_SOURCE_ONLY_RETIREMENT_NOT_EXECUTED' and peer['findings']==[],'OWNER_REPAIR_ACTUAL_SOURCE_REVIEW0')
    for mode,result,count in [('close',close,90),('zero',zero,12)]:
        source=attempt[mode+'_Source'];source_raw=read(source);check(compact(source)==compact(sourcepins['files'][Path(source['path']).name]),'OWNER_REPAIR_FULL_QUALIFIED_RETIREMENT_SOURCE')
        admission=decode(read(attempt[mode+'_admission']));outer=attempt[mode+'_outer'];made=decode(read(outer['created']));ended=decode(read(outer['terminal']))
        wrapper=read(admission['wrapper'])
        check(set(admission)=={'scope','phase','source','wrapper','peer_source_review','argv','business_acceptance'}
            and admission['scope']=='ONE_CURRENT_FAILED_RUN_ORDINARY_RETIREMENT_PHASE' and admission['phase']==mode
            and admission['source']==source and admission['peer_source_review']==attempt['independent_Source_review'] and admission['business_acceptance']=='INCOMPLETE'
            and set(made)=={'pid','argv','started_ns'} and set(ended)=={'pid','argv','native_exit','wait_returncode','finished_ns','stdout','stderr'}
            and made['argv']==ended['argv']==admission['argv'] and made['pid']==ended['pid']>0 and made['started_ns']<=ended['finished_ns']
            and ended['native_exit']==ended['wait_returncode']==0,'OWNER_REPAIR_ROOT_ADMITTED_REAL_SINGLE_CREATED3_TERMINAL7_OUTER0')
        operations=result['operations'];receipts=attempt[mode+'_native_receipts'];check(len(operations)==len(receipts)==count,'OWNER_REPAIR_COMPLETE_PUBLIC_NATIVE90_12_LEDGERS')
        for index,(row,receipt) in enumerate(zip(operations,receipts),1):
            made=decode(read(receipt['created']));ended=decode(read(receipt['terminal']));out=read(receipt['stdout']);err=read(receipt['stderr'])
            check(row==ended and row['index']==index and row['stem'].startswith('%03d-'%index) and made=={k:row[k] for k in ['argv','pid']}
                and type(row['pid']) is int and row['pid']>0 and row['native_exit']==row['wait_returncode']==0 and err==b''
                and {'bytes':len(out),'sha256':hashlib.sha256(out).hexdigest()}=={'bytes':row['stdout_bytes'],'sha256':row['stdout_sha256']}
                and compact(receipt['stderr'])=={'bytes':row['stderr_bytes'],'sha256':row['stderr_sha256']},'OWNER_REPAIR_FULL_CURRENT_QUALIFIED_PUBLIC_NATIVE_BODY_PID_ARGV_JOIN')
        queries=result['fresh_six_queries'];check([(row['kind'],row['label']) for row in queries]==[(kind,label) for kind in ['container','network','volume'] for label in ['project','run']],'OWNER_REPAIR_SIX_EXACT_INDEPENDENT_QUERY_SCOPES')
        for query in queries:
            row=operations[query['operation_index']-1];receipt=receipts[query['operation_index']-1];args=row['argv'][1:]
            prefix={'container':['ps','-aq','--no-trunc'],'network':['network','ls','-q'],'volume':['volume','ls','-q']}[query['kind']]
            label=('com.docker.compose.project=tianli-audit-' if query['label']=='project' else 'tianli.audit.run=')+attempt['run_id']
            check(args==prefix+['--filter','label='+label] and read(receipt['stdout'])==b'','OWNER_REPAIR_ALL_SIX_TRUE_EMPTY_FRESH_QUERY_BODIES')
        mutations=[row for row in operations if row['argv'][1] in ['stop','rm'] or row['argv'][1:3] in [['network','rm'],['volume','rm']]]
        if mode=='zero':check(mutations==[],'OWNER_REPAIR_ZERO_READ_ONLY_NO_MUTATION')
        else:
            check(len(mutations)==5 and [row['argv'][1:4] for row in mutations[:2]]==[['stop','--timeout','-1'],['stop','--timeout','-1']]
                and [row['argv'][-1] for row in mutations[:2]]==[close['resource_ids'][role] for role in close['ordinary_stop_roles']]
                and mutations[2]['argv'][1]=='rm' and set(mutations[2]['argv'][2:])==set(close['resource_ids'].values())
                and mutations[3]['argv'][1:3]==['network','rm'] and len(mutations[3]['argv'])==4
                and mutations[4]['argv'][1:3]==['volume','rm'] and len(mutations[4]['argv'][3:])==9
                and all('--force' not in row['argv'] and '-f' not in row['argv'] for row in mutations),'OWNER_REPAIR_EXACT_FIVE_NORMAL_NONFORCE_MUTATIONS')
    diagnostic=decode(read(attempt['diagnostic']));fixpins=decode(read(attempt['normal_fix']['Source_PINS']))
    check(any(row.get('projection',{}).get('diagnostic',{}).get('code')=='TASK6_THREE_LEGACY_REUSES_TRUE_PAID_HTTP_OWNER' for row in diagnostic['safe_records']),'OWNER_REPAIR_TRUE_SAFE_ORIGINAL_OWNER_STATE_MARKER')
    fixroot=Path(attempt['normal_fix']['Source_PINS']['path']).parent;helper='help/audit-regressions/isolated/lib/ms4-three-legacy-producer.php'
    before_fix=read({'path':(fixroot/'before'/helper).as_posix(),**compact(fixpins['files']['before/'+helper])});after_fix=read({'path':(fixroot/'candidate'/helper).as_posix(),**compact(fixpins['files']['candidate/'+helper])})
    forward=read({'path':(fixroot/'FORWARD.diff').as_posix(),**compact(fixpins['files']['FORWARD.diff'])});reverse=read({'path':(fixroot/'REVERSE.diff').as_posix(),**compact(fixpins['files']['REVERSE.diff'])})
    check(patch_apply({helper:before_fix},forward)=={helper:after_fix} and patch_apply({helper:after_fix},reverse,'b','a')=={helper:before_fix}
        and compact(expected_sources[helper])=={'bytes':len(after_fix),'sha256':hashlib.sha256(after_fix).hexdigest()},'OWNER_REPAIR_FULL_ORDINARY_FIX_FORWARD_REVERSE_EXACT_COMMITTED_SOURCE')
    php_first=decode(read(attempt['normal_fix']['owner_PHP_actual']));php_combined=decode(read(attempt['normal_fix']['combined_nine_PHP_actual']))
    check(php_first['status']=='FIRST_SYNTAX_ATTEMPT_FAILED_INSTALL_SOURCE_PARSE_PRESERVED_THREE_EXACT_PHP_PASSED'
        and php_first['Root_native_records_and_streams_whole_read'] is True and len(php_first['passed_PHP_sources'])==3
        and compact(php_first['passed_PHP_sources'][0]['source'])==compact(expected_sources[helper])
        and php_combined['status']=='ROOT_COMBINED_EXACT_NINE_PHP_SYNTAX_PASSED_FIRST_THREE_PLUS_REMAINING_SIX'
        and php_combined['prior_three_actual_success_receipt']==attempt['normal_fix']['owner_PHP_actual'] and php_combined['first_failed_attempt_preserved'] is php_combined['no_prior_success_or_old39_rerun'] is True
        and php_combined['business_Auth_SQL_HTTP_UI']=='NOT_RUN','OWNER_REPAIR_OWNER_EXACT_SYNTAX0_NO_MEDIA_SOURCE_OR_FULL_BUSINESS_SIGNOFF')
    for key,status in [('close_Root_readback','ROOT_COMPLETE_ACTUAL_NORMAL_CLOSE_PUBLIC_READBACK_ZERO'),('zero_Root_readback','ROOT_COMPLETE_ACTUAL_INDEPENDENT_ZERO_PUBLIC_READBACK_ZERO')]:
        actual_root=decode(read(attempt[key]));check(actual_root['status']==status and actual_root['result']==attempt['close' if key.startswith('close') else 'zero'] and actual_root['business_acceptance']=='INCOMPLETE','OWNER_REPAIR_COMPLETE_ROOT_PUBLIC_ACTUAL_RETIREMENT_READBACK')
    check(attempt['actual_adoption']==stage['actual_adoption'] and attempt['actual_delivery']==stage['actual_delivery']
        and attempt['actual_delivery_verification']==stage['actual_delivery_verification'],'OWNER_REPAIR_FINITE_ATTEMPT_EXACT_REAL_DELIVERY_JOIN')
    return True

def validate_reader_source_inheritance(source, source_pins, read, decode, check):
    """Whole fixed Source refs plus separate historical/current metadata; no path recursion inside records."""
    from pathlib import Path
    import hashlib,os,stat
    def compact(row):return {key:row[key] for key in ('bytes','sha256')}
    def namespace(folder,expected):
        actual=[]
        for current,dirs,files in os.walk(folder,followlinks=False):
            for name in [*dirs,*files]:
                path=Path(current)/name;facts=path.lstat()
                check(not path.is_symlink() and not getattr(facts,'st_file_attributes',0)&1024,'HISTORY_SOURCE_NO_SYMLINK_OR_REPARSE')
            for name in files:
                path=Path(current)/name;check(stat.S_ISREG(path.lstat().st_mode),'HISTORY_SOURCE_REGULAR_LEAF')
                actual.append(path.relative_to(folder).as_posix())
        check(sorted(actual)==sorted(expected),'COMPLETE_FIXED_HISTORY_SOURCE_NAMESPACE')
    def fixed_reference(pointer,count,self_name):
        folder=Path(pointer['path']).parent;packet=decode(read(pointer))
        check(type(packet['files']) is dict and len(packet['files'])==count,'COMPLETE_HISTORY_SOURCE_PINNED_MEMBER_COUNT')
        for relative,row in packet['files'].items():
            path=folder/relative
            check(type(relative) is str and relative and all(part not in ('','.','..') for part in relative.split('/'))
                and row['path']==path.as_posix(),'HISTORY_SOURCE_CANONICAL_DECLARED_PATH')
            raw=read(row);check({'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}==compact(row),'WHOLE_FIXED_HISTORY_SOURCE_FILE_HASH')
        namespace(folder,set(packet['files'])|{self_name})
        return packet
    inherit=decode(read(source_pins['files']['SOURCE-INHERITANCE.json']))
    old=fixed_reference(inherit['full_r1_FINAL_PINS'],355,'FINAL-PINS.json')
    fixed_reference(inherit['R2_qualification_only_overlay'],21,'PINS.json')
    check(len(source_pins['files'])==29 and len(source_pins['source_members18'])==18,'CURRENT29_ORIGINAL_RUNTIME18_SOURCE_CONTRACT')
    for name,row in source_pins['files'].items():
        check(set(row)=={'path','bytes','sha256'} and row['path']==(source/name).as_posix(),'REAL_CURRENT_THREE_FIELD_SOURCE_MEMBER')
        raw=read(row);check({'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}==compact(row),'WHOLE_CURRENT29_SOURCE_BYTES')
    historical_pointer=old['files']['normal-source/predecessor-source/AUTHOR-INPUTS.json']
    warm_pointer=old['files']['normal-source/AUTHOR-INPUTS.json']
    historical=decode(read(historical_pointer))['files'];warm=decode(read(warm_pointer))['files']
    check(type(historical) is dict and len(historical)==693 and type(warm) is dict and len(warm)==390,'SEPARATE_FULL693_HISTORICAL_AND390_WARM_AUTHOR_INPUT_MAPS')
    current=decode(read(source_pins['files']['SOURCE-WINDOWS.json']))
    check(set(current)=={'files','all_own9_fd9_cross4_birthtime_EOF_and_final_named_windows','target_callback_execution'}
        and type(current['files']) is list and len(current['files'])==28
        and current['all_own9_fd9_cross4_birthtime_EOF_and_final_named_windows'] is True
        and current['target_callback_execution'] is False,'CURRENT28_SOURCE_WINDOWS_TRUE_LIST_NOT_OLD_AUTHOR_DICT')
    current_rows={}
    for row in current['files']:
        check(type(row['path']) is str and row['path'] not in current_rows,'UNIQUE_CURRENT_SOURCE_WINDOW_PATH')
        current_rows[row['path']]=row
    expected={(source/name).as_posix() for name in source_pins['files'] if name!='SOURCE-WINDOWS.json'}
    check(set(current_rows)==expected,'COMPLETE_CURRENT28_WINDOWS_EXCLUDE_ONLY_THEIR_OWN_SOURCE_FILE')
    for mapping in (historical,warm,current_rows):
        for path,row in mapping.items():
            check(type(row) is dict and row['path']==path and type(row['bytes']) is int and row['bytes']>=0
                and type(row['native9']) is list and type(row['fd9']) is list and len(row['native9'])==len(row['fd9'])==9
                and all(type(value) is int for value in row['native9']+row['fd9'])
                and row['native9'][6]==row['fd9'][6]==row['bytes']
                and [row['native9'][i] for i in (0,1,6,7)]==[row['fd9'][i] for i in (0,1,6,7)]
                and type(row['birthtime_ns']) is int and row['EOF'] is row['closed'] is True,
                'ALL_SEPARATE_STATIC_AUTHOR_NATIVE9_FD9_CROSS4_EOF_WINDOW_SHAPES_NO_BODY_FOLLOW')
    statkeys=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')
    for path,row in current_rows.items():
        facts=Path(path).lstat()
        check([int(getattr(facts,key)) for key in statkeys]==row['native9'] and facts.st_birthtime_ns==row['birthtime_ns'],
            'CURRENT_SOURCE_WINDOW_TRUE_NAMED_NINE_AND_BIRTHTIME_STILL_HELD')
    return {'historical693':historical,'warm390':warm,'current28':current_rows}


def validate_separate_author_intersections(authority, mappings, check):
    """Keep every old/current intersecting pin/native join under its own provenance."""
    counts={}
    for label,mapping in mappings.items():
        count=0
        for path,row in authority.items():
            if path in mapping:
                held=mapping[path]
                check({key:row[key] for key in ('bytes','sha256')}=={key:held[key] for key in ('bytes','sha256')}
                    and row['stat9']==held['native9'],'EVERY_SEPARATE_STATIC_AUTHOR_AUTHORITY_INTERSECTION_BYTES_AND_TRUE_NATIVE9')
                count+=1
        check(count>0,'EACH_COMPLETE_STATIC_AUTHOR_PROVENANCE_HAS_REAL_AUTHORITY_INTERSECTIONS')
        counts[label]=count
    return counts


def readback_check(ok,code):
    assert ok,code

def validate_current_warm_profile(values, pub, read, decode, check):
    import ast,hashlib
    from pathlib import Path
    CURRENT_WARM_PROFILE_SOURCE=values['CURRENT_WARM_PROFILE_SOURCE']
    CURRENT_WARM_PROFILE_MANIFEST=values['CURRENT_WARM_PROFILE_MANIFEST']
    CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']
    HISTORICAL_TWENTYTHREE_PUBLICATION=values['HISTORICAL_TWENTYTHREE_PUBLICATION']
    CURRENT_WARM_PROFILE_NEW_NODE=values['CURRENT_WARM_PROFILE_NEW_NODE']
    oldpub=decode(read(HISTORICAL_TWENTYTHREE_PUBLICATION))
    expected_sources={row['path']:row for row in pub['repository_Source_requirements']}
    def compact(row):return {key:row[key] for key in ['bytes','sha256']}
    def constants(raw):
        result={}
        for node in ast.parse(raw.decode('utf8')).body:
            if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
                try:result[node.targets[0].id]=ast.literal_eval(node.value)
                except (TypeError,ValueError):pass
        return result
    profile_packet=decode(read(CURRENT_WARM_PROFILE_SOURCE));profile_manifest=decode(read(CURRENT_WARM_PROFILE_MANIFEST));profile_root=Path(CURRENT_WARM_PROFILE_SOURCE['path']).parent
    for name,row in profile_packet['members'].items():read({'path':(profile_root/name).as_posix(),**compact(row)})
    check(profile_manifest['before_selected9']==oldpub['selected9'] and profile_manifest['selected9']==pub['selected9'] and len(pub['selected9'])==9
        and profile_manifest['source_admission']=='LOCAL_ONLY_PENDING_FINAL_PUSH' and profile_manifest['runtime_ready'] is False,'WARM_CURRENT_NORMAL_PROFILE9_OVER_EXACT_ORIGINAL9_SOURCE_ONLY')
    normal_profile=Path(CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE['profile_interface']['path']).parent;prior_profile=Path(HISTORICAL_TWENTYTHREE_PUBLICATION['path']).parent/'actual-profile'
    prior9={};current9={}
    for oldrow,newrow in zip(oldpub['selected9'],pub['selected9']):
        name=oldrow['path'];check(name==newrow['path'],'WARM_NINE_PROFILE_IDENTITY_ORDER')
        oldraw=read({'path':(prior_profile/name).as_posix(),**compact(oldrow)});newraw=read({'path':(normal_profile/name).as_posix(),**compact(newrow)})
        check(oldraw==read({'path':(profile_root/'before/profile'/name).as_posix(),**compact(oldrow)})
            and newraw==read({'path':(profile_root/'candidate/profile'/name).as_posix(),**compact(newrow)}),'WARM_ALL_NINE_WHOLE_BEFORE_AFTER_PROFILE_SOURCES')
        prior9[name]=oldraw;current9[name]=newraw
    check({name for name in current9 if prior9[name]!=current9[name]}=={'ms4-initialize.php','ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py','interface-v1.json','source-derivation.json'}
        and all(current9[name]==prior9[name] for name in ['compose.yml','README.md','seed-source-inverse.json']),'WARM_EXACT_SIX_PIN_DAG_LEAVES_THREE_RAW_WHOLE')
    ar='help/audit-regressions/isolated/ms4-aftersale-review-normal.php';entry=expected_sources[ar]
    oldseven=decode(prior9['interface-v1.json'])['customer_form_current_profile']['php_sources'];seven=[dict(entry) if row['path']==ar else row for row in oldseven]
    check(len(seven)==7 and sum(a!=b for a,b in zip(oldseven,seven))==1,'WARM_SINGLE_CURRENT_AR_ROW_IN_ORIGINAL_CU7')
    def current_profile_statement(raw,name):
        text=raw.decode('utf8');tree=ast.parse(text)
        rows=[node for node in tree.body if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name) and node.targets[0].id==name]
        check(len(rows)==1,'WARM_ONE_ACTIVE_PROFILE_LITERAL_ASSIGNMENT')
        return ast.get_source_segment(text,rows[0]).encode()
    necessary={
        'ms4-host.py':{'CUSTOMER_FORM_SOURCE_FILES':tuple(seven),'AFTERSALE_REVIEW_ENTRY_BYTES':entry['bytes'],'AFTERSALE_REVIEW_ENTRY_SHA256':entry['sha256'],'INTERFACE_SHA256':hashlib.sha256(current9['interface-v1.json']).hexdigest()},
        'normal-close-ms4-fulfillment.py':{'HOST_SHA256':hashlib.sha256(current9['ms4-host.py']).hexdigest()},
        'verify-zero-ms4-fulfillment.py':{'CUSTOMER_FORM_SOURCE_FILES':tuple(seven),'AFTERSALE_REVIEW_ENTRY_BYTES':entry['bytes'],'AFTERSALE_REVIEW_ENTRY_SHA256':entry['sha256'],
            'INTERFACE_SHA256':hashlib.sha256(current9['interface-v1.json']).hexdigest(),'INTERFACE_BYTES':len(current9['interface-v1.json']),
            'HOST_SHA256':hashlib.sha256(current9['ms4-host.py']).hexdigest(),'CLOSE_SHA256':hashlib.sha256(current9['normal-close-ms4-fulfillment.py']).hexdigest()}}
    check(sum(len(values) for values in necessary.values())==12,'WARM_EXACT_TWELVE_CURRENT_PYTHON_PIN_DAG_ASSIGNMENTS')
    for name,wanted in necessary.items():
        before_values=constants(prior9[name]);after_values=constants(current9[name]);restored=current9[name]
        check(all(after_values[key]==value for key,value in wanted.items())
            and {key:value for key,value in before_values.items() if key not in wanted}=={key:value for key,value in after_values.items() if key not in wanted},'WARM_ONLY_CURRENT_AR_AND_INTERFACE_HOST_CLOSE_PYTHON_PINS')
        for key in wanted:
            old_stmt=current_profile_statement(prior9[name],key);new_stmt=current_profile_statement(current9[name],key)
            check(prior9[name].count(old_stmt)==current9[name].count(new_stmt)==1,'WARM_SINGLE_REQUIRED_LITERAL_DAG_SEAM');restored=restored.replace(new_stmt,old_stmt,1)
        check(restored==prior9[name],'WARM_FULL_THREE_PYTHON_MODULE_INVERSE_ALL344_FUNCTIONS_AND_BUSINESS_RAW')
    initializer=current9['ms4-initialize.php'];seams=profile_manifest['initializer_seams'];check(len(seams)==2,'WARM_INITIALIZER_EXACT_TWO_ACTIVE_AR_SEAMS')
    for row in reversed(seams):
        old=row['before'].encode();new=row['after'].encode();check(initializer.count(new)==1 and prior9['ms4-initialize.php'].count(old)==1,'WARM_INITIALIZER_SINGLE_BOUNDED_LITERAL');initializer=initializer.replace(new,old,1)
    check(initializer==prior9['ms4-initialize.php'] and profile_manifest['initializer_functions']=={'total':26,'changed':['m4i_cu_form_source_files','m4i_worker_sources'],'other_function_regions_raw':True},
        'WARM_INITIALIZER_FULL_INVERSE_ALL_OTHER_BYTES_SOURCE_TREE_COPY_MOUNTS_FIXTURE_AND_TRANSACTION_RAW')
    interface=decode(prior9['interface-v1.json']);interface['aftersale_review_normal_successor']['entry_bytes']=entry['bytes'];interface['aftersale_review_normal_successor']['entry_sha256']=entry['sha256']
    interface['customer_form_current_profile']['php_sources']=seven;interface['source_tree_runtime_profile']['actual_candidate_tracked_sources'][0]=dict(entry)
    check(decode(current9['interface-v1.json'])==interface,'WARM_INTERFACE_ONLY_THREE_ACTIVE_AR_PIN_LOCATIONS_SUCCESS29_DIAGNOSTIC4_UNCHANGED')
    derivation=decode(prior9['source-derivation.json']);derivation['new_business_entry'].update(compact(entry));derivation['new_business_entry']['status']='ACTUAL_LOCAL_COMMITTED_BYTES_NORMAL_NOT_EXECUTED'
    derivation['new_business_entry']['source']='Actual bafb local commit and clean Main/Val; final push and complete new normal execution UNBOUND'
    derivation['bafb_warm_failure_context_source_successor']=CURRENT_WARM_PROFILE_NEW_NODE
    derivation['current_nonself_artifacts']=[row for row in pub['selected9'] if row['path']!='source-derivation.json']
    check(decode(current9['source-derivation.json'])==derivation and len(derivation['current_nonself_artifacts'])==8
        and CURRENT_WARM_PROFILE_NEW_NODE['source_sha']==pub['source_sha'] and CURRENT_WARM_PROFILE_NEW_NODE['parent']==pub['parent']
        and CURRENT_WARM_PROFILE_NEW_NODE['current_seven_PHP']==seven and CURRENT_WARM_PROFILE_NEW_NODE['normal_success_result_keys']==29
        and CURRENT_WARM_PROFILE_NEW_NODE['diagnostic_keys']==4 and CURRENT_WARM_PROFILE_NEW_NODE['failure_only_optional_top_level']=='warm_failure_context'
        and CURRENT_WARM_PROFILE_NEW_NODE['source_admission']=='LOCAL_ONLY_PENDING_FINAL_PUSH' and CURRENT_WARM_PROFILE_NEW_NODE['remote_qualification']=='UNBOUND',
        'WARM_ONLY_CURRENT_ENTRY_AND_EIGHT_NONSELF_AND_FINITE_NEW_NODE_ALL_HISTORICAL_DERIVATION_SUBTREES_RAW')
    return True

def validate_current_d0a5_finite(values, pub, read, decode, check):
    import hashlib
    from pathlib import Path
    CURRENT_D0A5_FAILED_FINITE_INDEX=values['CURRENT_D0A5_FAILED_FINITE_INDEX']
    context=pub['finite_contexts']
    oldpub=decode(read(values['HISTORICAL_TWENTYTHREE_PUBLICATION']))
    historical_code={row['path']:row for row in oldpub['repository_Source_requirements']}
    def compact(row):return {key:row[key] for key in ['bytes','sha256']}
    failed_index=decode(read(CURRENT_D0A5_FAILED_FINITE_INDEX))
    check(context['current_d0a5_failed_finite_history']==CURRENT_D0A5_FAILED_FINITE_INDEX
        and failed_index['scope']=='ROOT_QUALIFIED_CURRENT_D0A5_FAILED_FINITE_HISTORY_ONLY_NOT_A_NEW_WHOLE_HISTORY_OR_SUCCESS'
        and failed_index['run_id']=='20261024114233c6e65e' and failed_index['source_sha']==oldpub['source_sha']=='d0a5d5398546e223122b5c2a2f115e1908f78ced'
        and failed_index['original_S_pid']==15252 and failed_index['original_S_native_exit_wait']==failed_index['aftersale_review_exit']==1
        and failed_index['Task6_exit0_retired'] is True and failed_index['phase']=='real_source_cache_preparation'
        and failed_index['failed_assertion']=='TASK78_WARM_EXACT_SERIALIZED_SOURCE_VALUE' and failed_index['historical_failed_key_or_cause']=='UNKNOWN',
        'BAFB_ORIGINAL_D0A5_TRUE_S1_TASK6_RETIRED_WARM_FAILURE_FINITE_NOT_SUCCESS_OR_NEW_WHOLE')
    failed_rows=failed_index['qualified_public']
    check(set(failed_rows)=={'failed_projection','resource_snapshot','close_result','close_Root','zero_result','zero_Root','preserved_public_inputs','original_input_metadata_closure'},
        'BAFB_ONLY_EIGHT_FIXED_SAVED_QUALIFIED_PUBLIC_REPORTS_NO_DESCRIPTOR_RECURSION')
    failed={name:decode(read(row)) for name,row in failed_rows.items()}
    projection=failed['failed_projection'];diagnostic=projection['projection']['diagnostic']
    check(projection['status']=='ROOT_SOURCE_QUALIFIED_PUBLIC_PHASE_DIAGNOSTIC_ONLY'
        and projection['raw_M_S_Run_ENV_actor_App_streams_read'] is False and projection['remaining_public_result_fields_not_printed'] is True
        and projection['projection']=={'status':'INCOMPLETE_MS4_AFTERSALE_REVIEW_HTTP_ONLY','phase':'real_source_cache_preparation','entry_return_code':1,
            'termination':'EXCEPTION','full_acceptance':'INCOMPLETE','diagnostic':{'code':'TASK78_WARM_EXACT_SERIALIZED_SOURCE_VALUE','class':'RuntimeException','file':'ms4-aftersale-review-normal.php','line':117}}
        and compact(projection['verified_producer_Source'])==compact(historical_code['help/audit-regressions/isolated/ms4-aftersale-review-normal.php']),
        'BAFB_RETAIN_ORIGINAL_D0A5_FOUR_FIELD_DIAGNOSTIC_NO_HISTORICAL_KEY_OR_CAUSE_INFERENCE')
    snap=failed['resource_snapshot'];closed=failed['close_result'];zeroed=failed['zero_result'];close_root=failed['close_Root'];zero_root=failed['zero_Root']
    check(snap['status']=='ROOT_ACTUAL_FAILED_D0A5_READONLY_RESOURCE_IDENTITY_SNAPSHOT' and snap['resource_mutations']==0
        and snap['raw_Run_ENV_actors_App_or_original_stream_bodies_read'] is False
        and snap['owned_counts']=={'container':4,'network':1,'volume':9} and len(snap['foreign'])==53
        and len(snap['native_operations'])==23 and all(row['native_exit']==row['wait_returncode']==0 for row in snap['native_operations']),
        'BAFB_ORIGINAL_SAFE_FOUR_ONE_NINE_AND_FOREIGN53_SNAPSHOT_NOT_NEW_RESOURCE_OPERATION')
    check(closed['status']=='VERIFIED_AFTERSALE_REVIEW_FAILURE_NORMAL_CLOSE_ZERO_RESIDUAL' and zeroed['status']=='VERIFIED_AFTERSALE_REVIEW_FAILURE_INDEPENDENT_FRESH_SIX_QUERY_ZERO'
        and closed['run_id']==zeroed['run_id']==snap['run_id']==failed_index['run_id'] and closed['source_sha']==zeroed['source_sha']==snap['source_sha']==oldpub['source_sha']
        and closed['freeze_sha256']==zeroed['freeze_sha256']=='043373180121add4b9cf37458ea2bf0d386f182052860aa4df54a16d4cdbc189'
        and closed['original_S_pid']==15252 and closed['original_S_native_exit_wait']==closed['original_aftersale_review_exit']==1
        and closed['original_task6_exit']==closed['original_paid_exit']==0 and closed['original_paid_status']=='EXITED_NATIVE0'
        and closed['ordinary_nonforce_only'] is True and closed['business_acceptance']==zeroed['business_acceptance']=='INCOMPLETE'
        and type(closed['removed']) is dict and closed['removed']=={'containers':4,'networks':1,'volumes':9} and all(type(value) is int for value in closed['removed'].values())
        and len(closed['operations'])==90 and len(zeroed['operations'])==12 and zeroed['resource_mutations']==0
        and zeroed['Host_imported'] is zeroed['close_imported_or_invoked'] is False
        and zeroed['close_actual_native_operations_verified']==90 and zeroed['close_five_ordinary_mutations_verified'] is True
        and zeroed['close_result']==failed_rows['close_result'], 'BAFB_FINITE_REAL_ORDINARY90_AND_INDEPENDENT12_NO_FORCE_RETRY_OR_FALSE_S0')
    check(close_root['status']=='ROOT_CLOSE90_FULL_PUBLIC_READBACK0_ADMIT_INDEPENDENT_ZERO' and close_root['result']==failed_rows['close_result']
        and close_root['all_native_created_terminal_wait_full_streams']==90 and close_root['fresh_six_queries_empty'] is True
        and close_root['mutations']==5 and close_root['only_exact4_1_9_ordinary_removed'] is close_root['all53_full_safe_foreign_mount_multiset_networks_and_mode_unchanged'] is close_root['original_S1_and_AR1_preserved'] is True
        and close_root['private_original_stream_body_reads']==0 and close_root['business_acceptance']=='INCOMPLETE'
        and zero_root['status']=='ROOT_INDEPENDENT_ZERO_FULL_ACTUAL_READBACK0' and zero_root['result']==failed_rows['zero_result']
        and zero_root['native_operations']==12 and zero_root['full_created_terminal_wait_streams_EOF'] is zero_root['fresh_six_label_kind_queries_empty'] is True
        and zero_root['resource_mutations']==0 and zero_root['ordinary_close']==failed_rows['close_result'] and zero_root['current_S']=='FAILED1_NOT_RETRIED'
        and zero_root['business_acceptance']=='INCOMPLETE','BAFB_BIND_BOTH_ORIGINAL_ROOT_FULL_READBACKS_NOT_SOURCE_EQUALS_BUSINESS')
    def d0_finite_own_outputs(mode,result,root_report):
        import os,stat
        own=Path(failed_rows[mode+'_result']['path']).parent
        expected_root=Path('H:/天俪商城/artifacts/project-organization-20261023/currentd0a5-aftersale-failed-retirement-source-r1')/('actual-'+mode+'-r1')
        check(own==expected_root,'BAFB_FIXED_TWO_D0A5_OWN_OUTPUT_ROOTS_ONLY')
        keys=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')
        stamp=lambda facts:tuple(getattr(facts,key) for key in keys)
        def safe(path,kind):
            facts=path.lstat();check(stat.S_IFMT(facts.st_mode)==kind and not path.is_symlink() and not getattr(facts,'st_file_attributes',0)&1024,'BAFB_OWN_PUBLIC_REGULAR_NO_LINK_OR_REPARSE')
            return stamp(facts),facts.st_birthtime_ns
        def namespace():
            names=[];directories=[];observations={}
            for directory,children,files in os.walk(own,followlinks=False):
                d=Path(directory);relative=d.relative_to(own).as_posix();directories.append(relative);observations[d.as_posix()]=safe(d,stat.S_IFDIR)
                for child in children:safe(d/child,stat.S_IFDIR)
                for name in files:
                    file=d/name;names.append(file.relative_to(own).as_posix());observations[file.as_posix()]=safe(file,stat.S_IFREG)
            return sorted(names),sorted(directories),observations
        expected={};parents={'.'}
        for row in root_report['output_files']:
            path=Path(row['path']);check(path.is_absolute() and path.is_relative_to(own) and '..' not in path.parts and path!=own,'BAFB_EXACT_QUALIFIED_OWN_LEAF_PATH_NO_OUTSIDE_FOLLOW')
            relative=path.relative_to(own).as_posix();check(relative not in expected,'BAFB_UNIQUE_OWN_PUBLIC_OUTPUT_PATH');expected[relative]=row
            parents.update('/'.join(relative.split('/')[:i]) for i in range(1,len(relative.split('/'))))
        check(root_report['full_own_public_output_files_EOF'] if mode=='close' else root_report['all_public_output_files_EOF'], 'BAFB_ROOT_COMPLETE_OWN_OUTPUT_BODY_READBACK_PRESENT')
        reported=root_report['full_own_public_output_files_EOF'] if mode=='close' else root_report['all_public_output_files_EOF']
        check(type(reported) is int and reported==len(expected) and expected,'BAFB_OWN_OUTPUT_COUNTS_FROM_TRUE_CLOSED_LIST_NOT_FUTURE_ASSUMPTIONS')
        first=namespace();check(first[:2]==(sorted(expected),sorted(parents)),'BAFB_EXACT_FIRST_SAVED_OWN_PUBLIC_NAMESPACE')
        payloads={}
        for relative,row in expected.items():
            path=own/relative;before=safe(path,stat.S_IFREG)
            with path.open('rb') as stream:
                fb=os.fstat(stream.fileno());raw=stream.read();eof=stream.read(1);fa=os.fstat(stream.fileno())
            after=safe(path,stat.S_IFREG)
            check(eof==b'' and before==after==first[2][path.as_posix()] and stamp(fb)==stamp(fa) and fb.st_birthtime_ns==fa.st_birthtime_ns
                and [before[0][i] for i in (0,1,6,7)]==[stamp(fb)[i] for i in (0,1,6,7)]
                and {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}==compact(row)
                and raw==read({'path':path.as_posix(),**compact(row)}),'BAFB_ALL_FINITE_OWN_OUTPUT_FULL_EOF_HASH_OWN9_CROSS4_BIRTHTIME_AND_AUTHORITY_REGISTRATION')
            payloads[relative]=raw
        check(namespace()==first,'BAFB_COMPLETE_SAVED_OWN_PUBLIC_BYTES_AND_NATIVE_NAMESPACE_LAST_WINDOW')
        check('RESULT.json' in expected and compact(expected['RESULT.json'])==compact(failed_rows[mode+'_result']) and decode(payloads['RESULT.json'])==result,'BAFB_SAME_ACTUAL_SAVED_OWN_RESULT')
        for index,row in enumerate(result['operations'],1):
            check(row['index']==index and type(row['pid']) is int and row['pid']>0 and row['native_exit']==row['wait_returncode']==0
                and row['stderr_bytes']==0 and row['stderr_sha256']==hashlib.sha256(b'').hexdigest(),'BAFB_CURRENT_FINITE_NATIVE_PID_WAIT0_METADATA')
            stem=row['stem'];made=decode(payloads[stem+'.created.json']);ended=decode(payloads[stem+'.terminal.json'])
            check(made=={'argv':row['argv'],'pid':row['pid']} and ended==row
                and len(payloads[stem+'.stdout'])==row['stdout_bytes'] and hashlib.sha256(payloads[stem+'.stdout']).hexdigest()==row['stdout_sha256']
                and payloads[stem+'.stderr']==b'','BAFB_SAME_CREATED_TERMINAL_AND_TWO_FULL_NATIVE_STREAMS_NO_INNER_DESCRIPTOR_RECURSION')
        queries=result['fresh_six_queries'];check({(row['kind'],row['label']) for row in queries}=={(kind,label) for kind in ('container','network','volume') for label in ('project','run')} and len(queries)==6,'BAFB_CURRENT_FINITE_SIX_INDEPENDENT_SINGLE_LABEL_KINDS')
        for query in queries:
            row=result['operations'][query['operation_index']-1]
            check(payloads[row['stem']+'.stdout']==b'' and row['stdout_bytes']==0,'BAFB_CURRENT_FINITE_SIX_ACTUAL_EMPTY_OUTPUTS')
    d0_finite_own_outputs('close',closed,close_root);d0_finite_own_outputs('zero',zeroed,zero_root)
    preserved=failed['preserved_public_inputs'];windows=failed['original_input_metadata_closure'];frozen=closed['frozen_Source_and_original_M_S_control_metadata_preserved']
    check(preserved['root']=='H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs/20261024114233c6e65e'
        and preserved['public_full_body_files']==closed['finite_public_full_body_count']==zeroed['finite_public_full_body_count']==close_root['public_body_count']==318
        and preserved['other_files_named_metadata_only']==closed['other_files_metadata_only_count']==zeroed['other_Run_files_named_metadata_only_count']==11058
        and len(preserved['files'])==318+11058 and sum('public_full_EOF_pin' in row for row in preserved['files'].values())==318
        and all(row.get('body','').endswith('METADATA ONLY') for row in preserved['files'].values() if 'public_full_EOF_pin' not in row)
        and closed['all_Run_named_metadata_namespace_preserved'] is zeroed['all_Run_and_close_named_metadata_namespace_preserved'] is True
        and closed['private_ENV_actor_cookie_upload_appchild_body_reads']==zeroed['private_ENV_actor_cookie_upload_appchild_body_reads']==closed['original_outer_M_S_raw_stream_body_reads']==zeroed['original_outer_M_S_raw_stream_body_reads']==0,
        'BAFB_ALL_ORIGINAL318_PUBLIC_AND11058_METADATA_PRESERVED_NO_ORIGINAL_RUN_BODY_READ_BY_NEW_FINITE_CALLBACK')
    check(frozen==close_root['frozen_source_and_original_control_preservation'] and len(frozen['original_root_public_control_pairs'])==138
        and len(frozen['original_outer_raw_metadata_only'])==4 and all(row['body']=='NOT READ' for row in frozen['original_outer_raw_metadata_only'].values())
        and frozen['initial_prepare_control_metadata']['created']['pid']==frozen['initial_prepare_control_metadata']['terminal']['pid']
        and frozen['initial_prepare_control_metadata']['terminal']['native_exit']==frozen['initial_prepare_control_metadata']['terminal']['wait_returncode']==0
        and all(row['EOF'] is row['closed'] is True and len(row['named9'])==len(row['fd9'])==9 and [row['named9'][i] for i in (0,1,6,7)]==[row['fd9'][i] for i in (0,1,6,7)] for row in windows),
        'BAFB_ORIGINAL139_CONTROLS_FROZEN_SOURCE_AND_M_S_RAW_METADATA_RETAINED_WITHOUT_REOPENING_ORIGINAL_BODIES')
    check(failed_index['counts']=={'close_native':90,'independent_zero_native':12,'mutations':5,'containers_removed':4,'network_removed':1,'volumes_removed':9,'current_residual':0,
        'original_root_controls':138,'separate_prepare_controls':1,'total_original_controls':139,'Host_proof_pairs':18,'allowlisted_public_body_paths':331,
        'existing_public_full_body_reads':318,'other_Run_files_metadata_only':11058,'foreign_complete_safe_containers':53},'BAFB_ORIGINAL_FINITE_COUNTS_EXACT_NOT_RECURSIVE_WHOLE')
    host_proofs=('prepare','register-initializer','initializer','register-driver','driver','register-paid-producer','paid-producer',
        'register-fulfillment-migration','fulfillment-migration','files-bootstrap','register-customer-prepare','customer-prepare','register-paid-after-c3',
        'register-fulfillment-normal','fulfillment-normal','authorize-fulfillment-cleanup','register-aftersale-review-normal','aftersale-review-normal')
    check(len(host_proofs)==18 and all('public_full_EOF_pin' in preserved['files']['evidence/'+proof+'-'+suffix+'.json']
        for proof in host_proofs for suffix in ('created','terminal')),'BAFB_ALL_ORIGINAL18_HOST_PUBLIC_PROOF_PAIRS_RETAINED_IN_SAVED_METADATA_NO_ORIGINAL_READ')
    return True


def main():
    need(CALLBACK_BOUND is True,'REAL_CURRENT_CALLBACKS_AND_ROOT_READBACK_REQUIRED_BEFORE_READER')
    need(not (OUT/'PINS.json').exists(),'ONE_INDEPENDENT_REVIEW_ONLY')
    OUT.mkdir(exist_ok=False)
    started=time.time_ns();read(READER_SOURCE/'peer-review.py');read(READER_SOURCE/'DESIGN.md')
    own_source_pins=js(READER_SOURCE/'SOURCE-PINS.json')
    for name,wanted in own_source_pins['files'].items():need(pin(read(READER_SOURCE/name))==wanted,'OWN_FIXED_READER_SOURCE_WHOLE_PIN')
    source_pins=js(SRC/'PINS.json');need(pin(read(SRC/'PINS.json'))==PINS_EXPECTED,'FROZEN_CURRENT41C0_R3_SOURCE_EXACT_IDENTITY')
    need(len(source_pins['files'])==29,'FIXED_COMPLETE29_CURRENT_SOURCE_MEMBERS')
    for name,pointer in source_pins['files'].items():
        need(pointer['path']==(SRC/name).as_posix(),'SOURCE212_CANONICAL_MEMBER_PATH')
        pinned(pointer)
    separate_author_maps=validate_reader_source_inheritance(SRC,source_pins,pinned,decode,need)
    # Actual complete original callbacks; metadata only, no target execution.
    callback_report=decode(pinned(CALLBACK_REPORT));callback_inputs=decode(pinned(CALLBACK_INPUTS));callback_root=decode(pinned(CALLBACK_ROOT))
    need(callback_report['status']=='PASS_ORIGINAL_COMPLETE_SOURCE_AUTHORITY_CALLBACKS_ONLY' and callback_report['source_PINS']=={'path':(SRC/'PINS.json').as_posix(),**PINS_EXPECTED}
        and callback_report['complete_return_objects_equal'] is True and callback_report['complete_canonical_return_pin']==CALLBACK_RETURN_PIN
        and callback_report['actual_input_paths']==CALLBACK_INPUT_COUNT and callback_report['stages']==24 and callback_report['retirements']==11
        and callback_report['callback_native_calls']==0 and callback_report['M_body_or_S_main_or_App_business']=='NOT RUN' and callback_report['A0'] is False,'TRUE_COMPLETED_ORIGINAL_M_S_SOURCE_CALLBACKS_NOT_BUSINESS')
    need(set(callback_report['qualification'])=={'M','S'} and all(q['module']==label and q['qualification_namespace']=='ONLY_ACTUAL_DECLARED_MODULE_IMPORTS_AND_LITERAL_ASSIGNMENTS'
        and q['manual_dependency_injection'] is False and q['unbound_globals']==[] and q['pure_source_checks']==CALLBACK_EXPECTED_CHECKS for label,q in callback_report['qualification'].items()),'BOTH_REAL_COMPLETE_DECLARED_NAMESPACES_AND_ALL_ORIGINAL_CHECKS')
    need(callback_root['status']=='ROOT_ACTUAL_COMPLETE_BAFB_R3_CALLBACKS0_PUBLIC_READBACK0_ADMIT_FULL_M_ONLY' and callback_root['actual_REPORT']==CALLBACK_REPORT and callback_root['actual_INPUTS']==CALLBACK_INPUTS
        and callback_root['actual_PID']==CALLBACK_NATIVE_PID and callback_root['native_exit']==callback_root['wait_returncode']==0 and callback_root['complete_streams_join'] is True
        and callback_root['M_and_S_each_full_checks']==CALLBACK_EXPECTED_CHECKS and callback_root['complete_return_objects_equal'] is True and callback_root['canonical_return_pin']==CALLBACK_RETURN_PIN
        and callback_root==CALLBACK_ROOT_EXPECTED
        and callback_root['callback_native_calls']==0 and callback_root['M_body_S_main_App_business']=='NOT RUN' and callback_root['A0'] is False,'ROOT_ACTUAL_CALLBACK_NATIVE0_AND_COMPLETE_PUBLIC_GRAPH_READBACK')
    need(set(callback_inputs)=={'files','cache_semantics','callback_invocations'} and callback_inputs['cache_semantics']=='ORIGINAL_FIRST_FULL_EOF_READ_ONCE_PER_PATH_THEN_CACHE'
        and callback_inputs['callback_invocations']==2 and type(callback_inputs['files']) is dict and len(callback_inputs['files'])==CALLBACK_INPUT_COUNT,'REAL_COMPLETE31324_FIRST_EOF_CACHE_GRAPH_NOT_STATIC_AUTHOR_SAMPLE')
    callback_graph=callback_inputs['files']
    callback_bootstrap={(SRC/'PINS.json').as_posix():PINS_EXPECTED,**{(SRC/name).as_posix():wanted for name,wanted in callback_report['entries'].items()}}
    need(set(callback_report['entries'])=={'source_reader.py','author_support.py','materializer/prepare.py','validation/run.py','validation/PROFILE-PUBLICATION.json'} and len(callback_bootstrap)==6,'ORIGINAL_THIN_ENTRY_EXACT_SIX_PRE_CALLBACK_BOOTSTRAP_READS')
    for name,wanted in callback_report['entries'].items():need(compact(source_pins['files'][name])==wanted,'REAL_CALLBACK_ALL_FIVE_FROZEN_SOURCE_ENTRY_PINS')
    for key,held in callback_graph.items():
        need(set(held)=={'path','bytes','sha256','native9','handle_before','handle_after','path_before','path_after','EOF','closed','started_ns','ended_ns'} and held['path']==key,'EXACT_ORIGINAL_CALLBACK_FILE_WINDOW_SCHEMA')
        row_valid(key,{'path':key,**compact(held),'stat9':held['native9']})
        need(held['native9']==held['path_before']==held['path_after'] and held['handle_before']==held['handle_after']
            and all(type(v) is int for v in held['handle_before']) and len(held['handle_before'])==9
            and [held['native9'][i] for i in [0,1,6,7]]==[held['handle_before'][i] for i in [0,1,6,7]]
            and held['EOF'] is held['closed'] is True and type(held['started_ns']) is type(held['ended_ns']) is int and 0<held['started_ns']<=held['ended_ns'],'ALL_CALLBACK_ORIGINAL_COMPLETE_NATIVE9_CROSS4_EOF_CLOSED_WINDOWS')
    for key,wanted in callback_bootstrap.items():need(key in callback_graph and compact(callback_graph[key])==wanted,'REAL_BOOTSTRAP_SIX_COMPLETE_PINNED_INPUTS')
    callback_authority_paths=set(callback_graph)-set(callback_bootstrap)
    frozen={name:pinned(source_pins['files'][name]) for name in source_pins['source_members18']}
    need({key for key in callback_authority_paths if key.startswith(SRC.as_posix()+'/')}=={(SRC/'validation/actual-profile'/row['path']).as_posix() for row in decode(frozen['validation/PROFILE-PUBLICATION.json'])['selected9']},'STATICALLY_DECLARED_CURRENT_R3_NINE_PROFILE_PATHS_EXCLUDE_CALLER_BOOTSTRAP')
    members=source_pins['source_members18'];need(len(members)==18,'COMPLETE_ORIGINAL18_PROGRAM_FILES')
    frozen={name:pinned(source_pins['files'][name]) for name in members}
    m=frozen['materializer/prepare.py'];values=source_constants(m);launcher_values=source_constants(frozen['launcher/launch.py'])
    need(values['head']==HEAD and values['parent']==PARENT,'SOURCE_CURRENT_HEAD_AND_PARENT')
    pub=decode(frozen['validation/PROFILE-PUBLICATION.json']);context=pub['finite_contexts'];chain=values['COMMIT_CHAIN']
    need(pub['source_sha']==HEAD and pub['parent']==PARENT and chain==context['commit_chain'] and len(chain)==24,'CURRENT_SOURCE_PUBLICATION_AND_ALL24_STEPS')
    historical23_values=dict(values);historical23_values.update(values['HISTORICAL_TWENTYTHREE_CONSTANTS'])
    historical23_publication=decode(pinned(values['HISTORICAL_TWENTYTHREE_PUBLICATION']))
    historical_chain=historical23_values['COMMIT_CHAIN'][:-2]
    need(len(historical_chain)==21 and historical23_values['COMMIT_CHAIN']==chain[:23],'TRUE_ORIGINAL21_AND23_HISTORICAL_FRAMES')
    profile_source = Path(values['HISTORICAL_TWENTYONE_PUBLICATION']['path']).parent.parent; profile_values = historical23_values
    profile_source_pins = decode(pinned({'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentfa2088-profile-r2-normal-preparation-r1/normal-source/PINS.json', 'bytes': 44270, 'sha256': '80a00951c5398c9384df5ca4bbb52156182d3e86968290ca9c88f89802e99dfe'}))
    profile_publication = decode(pinned(values['HISTORICAL_TWENTYONE_PUBLICATION']))
    profile_bytes = pinned
    profile_json = lambda pointer: decode(pinned(pointer))

    # Same-HEAD current profile revision: preserve the original nine inversely,
    # then qualify all actual current nine; this is not a twelfth retirement.
    profile_revision = profile_json(profile_values['CURRENT_FA2088_PROFILE_REVISION'])
    assert set(profile_revision) == {'schema','status','source_sha','parent','baseline_source','baseline_publication','baseline_selected9','selected9','files','initializer_fix','syntax_checks','profile_attempt','scope','runtime_ready'}
    assert profile_revision['schema'] == 1 and profile_revision['status'] == 'FA2088_PROFILE_INITIALIZER_MOUNT_CONSUMER_FIX_SOURCE_ONLY'
    assert profile_revision['source_sha'] == profile_publication['source_sha'] and profile_revision['parent'] == profile_publication['parent'] and profile_revision['runtime_ready'] is False
    assert profile_revision['scope'] == 'SAME_HEAD_PROFILE_REVISION_NO_GIT_STAGE_NO_T4_ADOPTION'
    assert profile_values['CURRENT_FA2088_PROFILE_REVISION'] == profile_source_pins['profile_source'] == profile_publication['finite_contexts']['current_fa2088_profile_revision']
    profile_baseline = profile_json(profile_revision['baseline_publication'])
    profile_inverse = json.loads(json.dumps(profile_publication))
    profile_inverse['selected9'] = profile_baseline['selected9']
    profile_inverse['finite_contexts'].pop('current_fa2088_profile_revision')
    profile_inverse['finite_contexts'].pop('current_fa2088_profile_attempt')
    profile_inverse['finite_contexts']['current_composite_customer_form_source'] = profile_baseline['finite_contexts']['current_composite_customer_form_source']
    assert profile_inverse == profile_baseline and profile_revision['baseline_selected9'] == profile_baseline['selected9']
    assert profile_revision['selected9'] == profile_publication['selected9'] and len(profile_revision['files']) == 9
    profile_baseline_rows = {row['path']:row for row in profile_baseline['selected9']}
    assert set(profile_revision['files']) == set(profile_baseline_rows)
    profile_changed = set()
    for profile_item in profile_publication['selected9']:
        profile_name = profile_item['path']; profile_pointer = profile_revision['files'][profile_name]
        assert Path(profile_pointer['path']) == profile_source / 'validation/actual-profile' / profile_name
        assert {key:profile_pointer[key] for key in ('bytes','sha256')} == {key:profile_item[key] for key in ('bytes','sha256')}
        assert profile_bytes(profile_pointer) == profile_bytes(profile_source_pins['files']['validation/actual-profile/'+profile_name])
        if {key:profile_item[key] for key in ('bytes','sha256')} != {key:profile_baseline_rows[profile_name][key] for key in ('bytes','sha256')}: profile_changed.add(profile_name)
    assert profile_changed == {'README.md','ms4-initialize.php','source-derivation.json'}
    assert {key:profile_revision['files']['ms4-initialize.php'][key] for key in ('bytes','sha256')} == {'bytes':91460,'sha256':'4e13defe45053ace0ab05b65e7548f686bc0bf56d47e510caad5c94ab092fd3b'}
    profile_derivation = profile_json(profile_revision['files']['source-derivation.json'])
    profile_old_derivation = profile_json({'path':(Path(profile_revision['baseline_publication']['path']).parent/'actual-profile/source-derivation.json').as_posix(),**{key:profile_baseline_rows['source-derivation.json'][key] for key in ('bytes','sha256')}})
    assert profile_derivation['current_nonself_artifacts'] == [row for row in profile_publication['selected9'] if row['path'] != 'source-derivation.json']
    assert {key:value for key,value in profile_derivation.items() if key not in {'current_nonself_artifacts','fa2088_initializer_mount_consumer_profile_revision'}} == {key:value for key,value in profile_old_derivation.items() if key != 'current_nonself_artifacts'}
    profile_attempt_pointer = profile_revision['profile_attempt']
    assert profile_attempt_pointer == profile_publication['finite_contexts']['current_fa2088_profile_attempt']
    profile_attempt = profile_json(profile_attempt_pointer)
    assert profile_attempt['schema'] == 1 and profile_attempt['status'] == 'ACTUAL_FA2088_PROFILE_ATTEMPT_S1_ORDINARY_RETIREMENT0_ZERO0_NOT_A_TWELFTH_WHOLE'
    assert profile_attempt['source_sha'] == profile_publication['source_sha'] and profile_attempt['business_acceptance'] == 'INCOMPLETE'
    assert profile_attempt['privacy_scope'] == {'qualified_Run_public_EOF_files':37,'other_Run_named_metadata_only':4280,'ENV_private_actor_cookie_upload_appchild_body_reads':0,'original_outer_M_S_stream_body_reads':0,'new_recursive_retirement_whole':False}

    historical_publication=decode(pinned(values['HISTORICAL_TWENTYTWO_PUBLICATION']));historical_values=dict(historical23_values)
    historical_values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']=historical23_values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']['predecessor_composite']
    need(validate_current_task6_profile(historical_values,historical_publication,profile_publication,{row['path']:row for row in historical_publication['repository_Source_requirements']},pinned),'HISTORICAL_F2_FULL_NINE_PROFILE_AND_FINITE_TASK6_ATTEMPT')
    need(validate_current_owner_profile(historical23_values,historical23_publication,pinned),'HISTORICAL23_OWNER_PROFILE9_AND_FINITE_R89_90_12')
    need(validate_current_warm_profile(values,pub,pinned,decode,need),'CURRENT24_FULL_WARM_PROFILE9')
    need(validate_current_d0a5_finite(values,pub,pinned,decode,need),'CURRENT_D0A5_FULL_FINITE8_AND_EXACT420_OWN_LEAVES')
    # Independent complete overlay derivation; no execution of target source_authority.
    rows=[decode(line) for line in pinned(context['baseline']['inventory']).splitlines()]
    original={row['path']:{k:row[k] for k in ['path','bytes','sha256']} for row in rows};need(len(rows)==len(original)==15486,'ORIGINAL_COMPLETE15486_BASELINE_INVENTORY')
    expected=dict(original);first={}
    for step in chain:
        for row in step['files']:
            name=row['path'];need(row['before']==(compact(expected[name]) if name in expected else None),'INDEPENDENT_EACH_SOURCE_OVERLAY_BEFORE')
            first.setdefault(name,compact(original[name]) if name in original else None);expected[name]={'path':name,**row['after']}
    expected=dict(sorted(expected.items()));changes=[{'path':name,'before':first[name],'after':compact(expected[name])} for name in sorted(first)]
    committed=[expected[row['path']] for row in changes];additions=sorted(set(expected)-set(original));requirements=values['SOURCE_REQUIREMENT_PATHS']
    need(len(expected)==15750 and len(committed)==311 and len(requirements)==316 and len(additions)==264
        and changes==context['committed_changes'] and committed==context['committed_files'] and additions==values['NEW_TRACKED_PATHS']==context['new_tracked_paths']
        and pub['repository_Source_requirements']==[expected[name] for name in requirements],'INDEPENDENT_CURRENT15749_CUMULATIVE310_REQUIRED315_ADDITIONS263')
    need(pin(read(ACTUAL/'RESULT.json'))=={'bytes':ARGS.result_bytes,'sha256':ARGS.result_sha256},'ACTUAL_RESULT_ROOT_SUPPLIED_COMPLETE_PIN')
    result=js(ACTUAL/'RESULT.json');result_pointer=descriptor(ACTUAL/'RESULT.json')
    need(type(result) is dict and set(result)==set(values['CURRENT_M_RESULT_KEYS37']) and len(result)==37,'EXACT_CURRENT_M37_NOT_HISTORICAL36')
    need(result['schema']==1 and type(result['schema']) is int and result['status']=='ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
        and result['source_sha']==HEAD and result['parent']==PARENT and result['source_patch_base']==values['BASELINE_AUTHORITY']['source_sha']
        and result['commit_chain']==chain and result['committed_files']==committed and result['selected9']==pub['selected9']
        and result['Run_created'] is False and result['App_DB_HTTP_business']=='NOT RUN','ACTUAL_CURRENT_COMPLETE_SOURCE_FREEZE_ONLY_NOT_BUSINESS')
    mainroot=Path(values['MAIN_SOURCE']);valroot=Path(values['VALIDATION_SOURCE']);python=str(Path(launcher_values['PY']))
    need(result['repo']==valroot.as_posix() and result['profile']==PROFILE.as_posix(),'ORIGINAL_CURRENT_SOURCE_AND_PROFILE_ROOTS')
    previous=decode(pinned(context['baseline']['previous_full_freeze']));groups={};combined={};roots={};extras={}
    for prefix in ['driver','customer','paid','fulfillment','aftersale_review','worker']:
        if prefix=='driver':names=[row['path'] for row in previous[prefix+'_source_files']]
        else:
            roots[prefix]=previous[prefix+'_source_roots']
            extras[prefix]=sorted(set(previous[prefix+'_source_extra'])|({values['ASSET_CLI']} if prefix in ['fulfillment','aftersale_review','worker'] else set())|{'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'})
            names=sorted({name for name in expected if any(name.startswith(root+'/') for root in roots[prefix])}|set(extras[prefix]))
        groups[prefix]=[expected[name] for name in names]
        for row in groups[prefix]:need(row['path'] not in combined or combined[row['path']]==row,'INDEPENDENT_GROUP_COMPLETE_OVERLAPS');combined[row['path']]=row
    counts={prefix:len(group) for prefix,group in groups.items()};need(counts==context['source_counts']==callback_report['group_counts'] and list(counts.values())==[10,10865,10866,10881,10885,10886] and len(combined)==counts['worker']==10886,'ALL_SIX_COMPLETE_GROUPS_AND10886_UNION')
    frozen_value=decode(pinned({'path':result['freeze'],'bytes':result['freeze_bytes'],'sha256':result['freeze_sha256']}));freeze_pointer=descriptor(ACTUAL/'freeze-binding.json')
    need(compact(freeze_pointer)=={'bytes':ARGS.freeze_bytes,'sha256':ARGS.freeze_sha256},'ACTUAL_FREEZE_ROOT_SUPPLIED_COMPLETE_PIN')
    need(set(frozen_value)==set(previous)|{'source_tree'} and type(frozen_value['schema']) is int and result['freeze']==(ACTUAL/'freeze-binding.json').as_posix() and frozen_value['schema']==1 and frozen_value['status']=='CLEAN_SOURCE_FROZEN_ONLY'
        and frozen_value['source_sha']==HEAD and frozen_value['requested_repo_source']==valroot.as_posix() and frozen_value['artifact_files']==pub['selected9']
        and frozen_value['runtime_SQL_App']=='NOT RUN' and result['source_counts']==counts and result['unique_source_files_actual_read']==len(combined),'ACTUAL_FULL_FREEZE_RESULT_AND_ORIGINAL_NO_APP_SCOPE')
    validate_complete_source_tree(frozen_value, {'all':expected}, HEAD, valroot.as_posix(), need)
    need(frozen_value['source_tree']['file_count']==len(expected)==15750 and len(frozen_value['source_tree']['directories'])==frozen_value['source_tree']['directory_count'],'COMPLETE_SOURCE_TREE_ALL_TRACKED_PLAN_NO_INITIALIZER_OR_VOLUME_CLAIM')
    for prefix,group in groups.items():
        need(frozen_value[prefix+'_source_files']==group,'FULL_FREEZE_GROUP_EXPECTED_EVERY_ROW')
        if prefix!='driver':need(frozen_value[prefix+'_source_roots']==roots[prefix] and frozen_value[prefix+'_source_extra']==extras[prefix],'FULL_FREEZE_ORIGINAL_ROOTS_AND_COMPLETE_EXTRAS')
    references=[expected[row['path']] for row in previous['preparation_source_references']]
    need(frozen_value['preparation_source_references']==references==previous['preparation_source_references'] and len(references)==4,'ALL_ORIGINAL_FOUR_PREPARATION_REFERENCES')
    need(result['proof_resource_required'] is True and result['proof_php_ini']==frozen_value['proof_php_ini']==previous['proof_php_ini'],'ORIGINAL_COMPLETE_SEVEN_FIELD_PROOF_INI_GROUP')
    for row in result['proof_php_ini']['files']:
        need(pin(read(Path(result['proof_php_ini']['source_root'])/row['path']))==compact(row),'ORIGINAL_COMPLETE_PROOF_INI_SOURCE_BYTES')
    pinned(result['proof_php_ini']['config_reference'])
    # Derive the entire native plan from immutable Source declarations and all20 actual overlays.
    plan=[]
    def git(repo,label,*args):plan.append((label,['git','-c','safe.directory='+repo.as_posix(),'-C',str(repo),*args]))
    for label,repo in [('main',mainroot),('validation',valroot)]:
        git(repo,label+'-head-before','rev-parse','HEAD');git(repo,label+'-status-before','status','--porcelain=v1','--untracked-files=all');git(repo,label+'-parent','rev-list','--parents','-n','1',HEAD)
    for index,step in enumerate(chain,1):
        label='source-step-'+str(index);git(valroot,label+'-parent','rev-list','--parents','-n','1',step['source_sha']);git(valroot,label+'-paths','diff-tree','--no-commit-id','--name-only','-r',step['source_sha'])
        for position,row in enumerate(step['files'],1):
            mark=label+'-member-'+str(position);git(valroot,mark+'-after','show',step['source_sha']+':'+row['path'])
            if row['before'] is None:git(valroot,mark+'-absent-before','ls-tree','--name-only',step['parent'],'--',row['path'])
            else:git(valroot,mark+'-before','show',step['parent']+':'+row['path'])
    git(valroot,'cumulative-changed-paths','diff','--name-only',values['BASELINE_AUTHORITY']['source_sha'],HEAD)
    for index,row in enumerate(committed,1):git(valroot,'committed-blob-'+str(index),'show',HEAD+':'+row['path'])
    for label,repo in [('main',mainroot),('validation',valroot)]:git(repo,label+'-complete-tracked-inventory','ls-files','-z')
    for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:plan.append(('help-'+name.replace('.','-'),[python,'-B','-X','utf8',str(PROFILE/name),'--help']))
    plan.append(('freeze',[python,'-B','-X','utf8',str(PROFILE/'ms4-host.py'),'freeze','--repo',str(valroot),'--source',HEAD,'--output',str(ACTUAL/'freeze-binding.json'),'--proof-ini-source',str(Path(result['proof_php_ini']['source_root']))]))
    for label,repo in [('main',mainroot),('validation',valroot)]:git(repo,label+'-head-after','rev-parse','HEAD');git(repo,label+'-status-after','status','--porcelain=v1','--untracked-files=all')
    budget=6+sum(2+2*len(step['files']) for step in chain)+1+len(committed)+2+3+1+4
    need(len(plan)==len(result['native_operations'])==budget==1150,'COMPLETE_ORIGINAL_FORMULA_NATIVE1150_PLAN')
    outputs={};actual_names={'ROOT-SOURCE-SNAPSHOT.json','AUTHORITY-INPUT-SNAPSHOT.json','root-materialize-source.py','freeze-binding.json','COMPLETE-SOURCE-SNAPSHOT.json','RESULT.json'}
    opkeys={'label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','stem'}
    for index,((label,argv),operation) in enumerate(zip(plan,result['native_operations']),1):
        stem='%03d-'%index+label
        need(set(operation)==opkeys and operation['stem']==stem and operation['label']==label and operation['argv']==argv
            and type(operation['pid']) is int and operation['pid']>0 and type(operation['native_exit']) is int and type(operation['wait_returncode']) is int
            and operation['native_exit']==operation['wait_returncode']==0 and type(operation['stdout_bytes']) is int and type(operation['stderr_bytes']) is int,
            'ALL1143_EXACT_SOURCE_DERIVED_ORDER_ARGV_SAME_NATIVE_WAIT')
        created=js(ACTUAL/(stem+'.created.json'));terminal=js(ACTUAL/(stem+'.terminal.json'))
        need(set(created)=={'pid','argv'} and created=={key:operation[key] for key in ['pid','argv']} and terminal==operation,'ALL1135_ACTUAL_CREATED2_AND_TERMINAL10_COMPLETE')
        for stream in ['stdout','stderr']:
            raw=read(ACTUAL/(stem+'.'+stream));need(len(raw)==operation[stream+'_bytes'] and hashlib.sha256(raw).hexdigest()==operation[stream+'_sha256'],'ALL1135_COMPLETE_DOUBLE_RAW_STREAMS_HASH_JOIN')
            if stream=='stderr':need(raw==b'','ALL1135_EXACT_ZERO_BYTE_STDERR')
            else:outputs[label]=raw
        actual_names|={stem+'.'+ext for ext in ['created.json','terminal.json','stdout','stderr']}
    need({p.name for p in ACTUAL.iterdir()}==actual_names and len(actual_names)==4*budget+6 and all(p.is_file() for p in ACTUAL.iterdir()),'EXACT_COMPLETE4546_SUCCESS_OUTPUT_NAMESPACE_NO_FAILURE')
    for label in ['main','validation']:
        need(outputs[label+'-head-before']==outputs[label+'-head-after']==(HEAD+'\n').encode() and outputs[label+'-status-before']==outputs[label+'-status-after']==b''
            and outputs[label+'-parent']==(HEAD+' '+PARENT+'\n').encode(),'ACTUAL_SOURCE_HEAD_CLEAN_PARENT_BEFORE_AND_AFTER')
        names=outputs[label+'-complete-tracked-inventory'].decode('utf-8').split('\0');need(names[-1]=='' and sorted(names[:-1])==list(expected) and len(names[:-1])==15750,'BOTH_COMPLETE_ACTUAL_NATIVE_TRACKED_NAMESPACES')
    for index,step in enumerate(chain,1):
        label='source-step-'+str(index);need(outputs[label+'-parent']==(step['source_sha']+' '+step['parent']+'\n').encode()
            and sorted(outputs[label+'-paths'].decode().splitlines())==[row['path'] for row in step['files']],'ALL22_REAL_NATIVE_PARENT_AND_COMPLETE_PATHS')
        for position,row in enumerate(step['files'],1):
            mark=label+'-member-'+str(position);need(pin(outputs[mark+'-after'])==row['after'],'ALL22_COMPLETE_NATIVE_ACTUAL_AFTER_BLOBS')
            if row['before'] is None:need(outputs[mark+'-absent-before']==b'' and row['path'] in additions,'ALL_ACTUAL_NEW_FILES_ABSENT_FROM_REAL_PARENT')
            else:need(pin(outputs[mark+'-before'])==row['before'],'ALL22_COMPLETE_NATIVE_ACTUAL_BEFORE_BLOBS')
    need(sorted(outputs['cumulative-changed-paths'].decode().splitlines())==[row['path'] for row in committed],'ACTUAL_NATIVE_CUMULATIVE310_PATHS')
    for index,row in enumerate(committed,1):need(pin(outputs['committed-blob-'+str(index)])==compact(row),'ALL310_COMPLETE_CURRENT_NATIVE_COMMITTED_BLOBS')
    for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
        raw=outputs['help-'+name.replace('.','-')];need(raw.startswith(b'usage: ') and len(raw)>0,'THREE_ACTUAL_ORIGINAL_HELP_OUTPUTS_NONEMPTY')
    need(decode(outputs['freeze'])=={'status':'CLEAN_SOURCE_FROZEN_ONLY','source_sha':HEAD,'freeze_sha256':result['freeze_sha256'],'runtime_SQL_App':'NOT RUN'},'ONE_NATIVE_FREEZE_REPLY_EXACT_RESULT_JOIN')
    # Full Root14 and M3 original source bytes/native identities.
    rootsnapshot=decode(pinned(result['root_source_snapshot']));rootnames=[(ROOT/n).as_posix() for n in ['DESIGN-TASKS.md','run.py','PINS.json','PROFILE-PINS.json','PROFILE-PUBLICATION.json']]+[(PROFILE/row['path']).as_posix() for row in pub['selected9']]
    need(list(rootsnapshot)==rootnames and len(rootsnapshot)==14,'EXACT_ROOT14_COMPLETE_SOURCE_SNAPSHOT_NAMESPACE')
    for key,row in rootsnapshot.items():source_row(key,row)
    need(result['root_source_pins']==descriptor(ROOT/'PINS.json'),'ACTUAL_ROOT_SOURCE_PINS_DESCRIPTOR')
    need(result['profile_publication']=={'root':ROOT.as_posix(),'pins':descriptor(ROOT/'PROFILE-PINS.json'),'publication':descriptor(ROOT/'PROFILE-PUBLICATION.json')},'COMPLETE_ROOT_PROFILE_PUBLICATION_DESCRIPTOR_JOIN')
    rootpins=decode(frozen['validation/PINS.json']);profilepins=decode(frozen['validation/PROFILE-PINS.json'])
    for name,row in rootpins['members'].items():need(pin(frozen['validation/'+name])==row,'ROOT_TWO_FULL_REVIEWED_PROGRAM_SOURCES')
    for row in pub['selected9']:need(pin(frozen['validation/actual-profile/'+row['path']])==compact(row) and profilepins['members'][row['path']]==compact(row),'ALL_NINE_FULL_PROFILE_SOURCE_AND_PUBLICATION_PINS')
    need(profilepins['members']['PROFILE-PUBLICATION.json']==pin(frozen['validation/PROFILE-PUBLICATION.json']),'TENTH_PUBLICATION_PROFILE_PIN')
    tool=result['materialize_source_inputs'];toolnames=[(SRC/'materializer'/n).as_posix() for n in ['PLAN.md','prepare.py','INVERSE.json']]
    need(list(tool)==toolnames and len(tool)==3,'EXACT_M3_COMPLETE_PRE_NATIVE_SOURCE_NAMESPACE')
    for key,row in tool.items():source_row(key,row)
    need(pinned(result['materialize_source'])==m and result['materialize_source']['path']==(ACTUAL/'root-materialize-source.py').as_posix(),'ORIGINAL_COMPLETE_M_SOURCE_COPY_BYTES_NOT_INODE_COERCION')
    # All current31496 metadata rows, no actual worktree-body reread.
    snapshot=decode(pinned(result['complete_source_snapshot']));keys=[(repo/name).as_posix() for repo in [mainroot,valroot] for name in expected]
    need(list(snapshot)==keys and len(snapshot)==31500,'EXACT31498_COMPLETE_DUAL_SOURCE_METADATA_NAMESPACES')
    for repo in [mainroot,valroot]:
        for name,row in expected.items():
            key=(repo/name).as_posix();held=snapshot[key];row_valid(key,held);need(compact(held)==compact(row),'EVERY_COMPLETE_TRACKED_SOURCE_METADATA_ROW_INDEPENDENT_EXPECTATION')
    authority=js(ACTUAL/'AUTHORITY-INPUT-SNAPSHOT.json');authorinputs=separate_author_maps['historical693']
    need(set(authority)==callback_authority_paths,'EXACT_COMPLETE_ACTUAL_M_AUTHORITY_GRAPH_FROM_ORIGINAL_CALLBACK_CHECKED_BOUNDARY')
    for key,row in authority.items():
        row_valid(key,row);need(key in callback_graph and compact(row)==compact(callback_graph[key]) and row['stat9']==callback_graph[key]['native9'],'EVERY_AUTHORITY_METADATA_ROW_MATCHES_REAL_COMPLETE_CALLBACK_QUALIFICATION_WINDOW')
        if key in authorinputs:need(compact(row)==compact(authorinputs[key]) and row['stat9']==authorinputs[key]['native9'],'EVERY_INTERSECTING_STATIC_AUTHOR_METADATA_ROW_RETAINS_ORIGINAL_PIN_NATIVE9_JOIN')
    need(len(authority)==32491 and len(authority)==len(callback_graph)-len(callback_bootstrap),'COMPLETE_ORIGINAL_AUTHORITY32491_METADATA_NOT_SMALL_SAMPLE')
    author_intersections=validate_separate_author_intersections(authority,separate_author_maps,need)
    # All eight original retirement facts and separately named ninth actual28dc failure remain full distinct objects.
    retirement_constants={'prior_retirement':'CURRENT_RETIREMENT','current_driver_failure_retirement':'CURRENT_DRIVER_FAILURE_RETIREMENT','current_early_failure_retirement':'CURRENT_EARLY_FAILURE_RETIREMENT',
        'current_customer_prepare_failure_retirement':'CURRENT_CUSTOMER_PREPARE_FAILURE_RETIREMENT','current_paid_producer_failure_retirement':'CURRENT_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_2e4_paid_producer_failure_retirement':'CURRENT_2E4_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_881_paid_producer_failure_retirement':'CURRENT_881_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_0c13_paid_producer_failure_retirement':'CURRENT_0C13_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_28dc_paid_producer_failure_retirement':'CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_175f_paid_producer_failure_retirement':'CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_6e3850_paid_producer_failure_retirement':'CURRENT_6E3850_PAID_PRODUCER_FAILURE_RETIREMENT'}
    for field,symbol in retirement_constants.items():need(result[field]==decode(pinned(values[symbol])),'ALL_ELEVEN_DISTINCT_ORIGINAL_RETIREMENT_FACTS_FULL_OBJECT_JOIN')
    need(result['current_paid_producer_failure_retirement']['source_sha']=='53e6d521b387b19a4cf370e0e381670b762d325e'
        and result['current_2e4_paid_producer_failure_retirement']['original_S_native_exit']==1
        and result['current_2e4_paid_producer_failure_retirement']['business_acceptance']=='INCOMPLETE','HISTORICAL53E_AND_CURRENT2E4_FAILURES_NOT_CONFLATED')
    for field in ['current_881_paid_producer_failure_retirement','current_0c13_paid_producer_failure_retirement','current_28dc_paid_producer_failure_retirement']:
        held=result[field]
        need(held['original_S_native_exit']==held['paid_producer_native_exit']==1 and held['business_acceptance']=='INCOMPLETE','SEVENTH_EIGHTH_NINTH_COMPLETE_FAILURES_REMAIN_INCOMPLETE')
    ninth=result['current_28dc_paid_producer_failure_retirement']
    need(context['current_28dc_paid_producer_failure_retirement']==values['CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT']
        and ninth['scope']=='NINTH_CURRENT28DC_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY'
        and ninth['run_id']=='202610231609185fbd23' and ninth['source_sha']=='28dcab632da3e62f7c8b8d926b6ab9406534d0d3' and ninth['A0'] is False
        and ninth['M34_preserved'] is True and ninth['M_native_count']==1078 and ninth['M_commit_count']==18
        and ninth['M_complete_source_metadata_rows']==31496
        and ninth['historical_eight_retirements']=={field:result[field] for field in retirement_constants if field not in ['current_28dc_paid_producer_failure_retirement','current_175f_paid_producer_failure_retirement','current_6e3850_paid_producer_failure_retirement']},'NINTH_WHOLE_REAL_FAILURE_PRESERVES_ALL_EIGHT_PREDECESSOR_FACTS')
    tenth=result['current_175f_paid_producer_failure_retirement']
    need(context['current_175f_paid_producer_failure_retirement']==values['CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT']
        and tenth['scope']=='TENTH_CURRENT175F_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY' and tenth['run_id']=='20261023182634096f57' and tenth['source_sha']=='175f3cd6183894185656a2974579e1be39eed7a7'
        and tenth['M35_preserved'] is True and tenth['M_native_count']==1088 and tenth['M_commit_count']==19 and tenth['M_complete_source_metadata_rows']==31496
        and tenth['original_S_native_exit']==tenth['paid_producer_native_exit']==1 and tenth['original_paid_restart_count']==0 and tenth['A0'] is False and tenth['business_acceptance']=='INCOMPLETE','TENTH_WHOLE_REAL175F_FAILURE_NOT_NEW_M_OR_BUSINESS_SUCCESS')
    old_nine={field:result[field] for field in retirement_constants if field not in ['current_175f_paid_producer_failure_retirement','current_6e3850_paid_producer_failure_retirement']}
    need(tenth['historical_nine_retirements']==old_nine and tenth['historical_nine_whole_encoded_pins']=={field:pin((json.dumps(held,ensure_ascii=False,indent=2)+'\n').encode('utf8')) for field,held in old_nine.items()},'ALL_NINE_PREDECESSOR_RETIREMENTS_AND_COMPLETE_ENCODED_PINS_PRESERVED')
    policy=tenth['safe_read_policy']
    need(policy['categories']['PUBLIC_GIT_METADATA_STDOUT']==317 and policy['M_git_show_stdout_metadata_count']==771
        and policy['M_git_show_stdout_body_reads']==policy['environment_private_application_child_body_reads']==policy['old_W_Val_body_reads']==policy['Run_stream_body_reads']==0,'TENTH_ORIGINAL_PUBLIC317_SHOW771_AND_PRIVATE_STREAM_METADATA_ONLY_POLICY')
    for mode in ['M','S']:
        for stream in ['stdout','stderr']:need(tenth['outer_native_receipts'][mode][stream]['path'] not in callback_graph,'ORIGINAL175F_OUTER_STREAM_BODIES_ABSENT_FROM_QUALIFICATION_GRAPH')
    for held in tenth['forbidden_environment_metadata']['rows']:need(held['path'] not in callback_graph,'ORIGINAL175F_ENV_NAMED_METADATA_ONLY_NO_BODY_IN_QUALIFICATION_GRAPH')
    eleventh=result['current_6e3850_paid_producer_failure_retirement']
    need(context['current_6e3850_paid_producer_failure_retirement']==values['CURRENT_6E3850_PAID_PRODUCER_FAILURE_RETIREMENT']
        and eleventh['scope']=='ELEVENTH_CURRENT6E3850_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY'
        and eleventh['run_id']=='2026102321491304a643' and eleventh['source_sha']==historical_chain[-1]['parent']
        and eleventh['M36_preserved'] is True and eleventh['M_native_count']==1104 and eleventh['M_commit_count']==20
        and eleventh['M_complete_source_metadata_rows']==31496 and eleventh['original_S_native_exit']==eleventh['paid_producer_native_exit']==1
        and eleventh['original_paid_restart_count']==0 and eleventh['A0'] is False and eleventh['business_acceptance']=='INCOMPLETE','ELEVENTH_WHOLE_RETIRED6E_FAILURE_NOT_FA_M_OR_BUSINESS_SUCCESS')
    old_ten={field:result[field] for field in retirement_constants if field!='current_6e3850_paid_producer_failure_retirement'}
    need(eleventh['historical_ten_retirements']==old_ten and eleventh['historical_ten_whole_encoded_pins']=={field:pin((json.dumps(held,ensure_ascii=False,indent=2)+'\n').encode('utf8')) for field,held in old_ten.items()},'ALL_TEN_PREDECESSOR_WHOLE_OBJECTS_AND_ENCODED_IDENTITIES_PRESERVED')
    current_retired_policy=eleventh['safe_read_policy']
    need(current_retired_policy['categories']['PUBLIC_GIT_METADATA_STDOUT']==319 and current_retired_policy['M_git_show_stdout_metadata_count']==785
        and current_retired_policy['M_git_show_stdout_body_reads']==current_retired_policy['environment_private_application_child_body_reads']==current_retired_policy['old_W_Val_body_reads']==current_retired_policy['Run_stream_body_reads']==0,'ELEVENTH_RETIRED319_SHOW785_METADATA_POLICY_NOT_APPLIED_TO_CURRENT_M_STREAMS')
    for mode in ['M','S']:
        for stream in ['stdout','stderr']:need(eleventh['outer_native_receipts'][mode][stream]['path'] not in callback_graph,'ORIGINAL6E_OUTER_STREAM_BODIES_ABSENT_FROM_CURRENT_QUALIFICATION_GRAPH')
    for held in eleventh['forbidden_environment_metadata']['rows']:need(held['path'] not in callback_graph,'ORIGINAL6E_ENV_ONLY_NAMED_METADATA_NEVER_CURRENT_BODY')
    # Original outer launcher is independently native0 and joins this exact current M result/freeze.
    made=js(SRC/'launcher/materialize.created.json');terminal=js(SRC/'launcher/materialize.terminal.json')
    need(set(made)=={'pid','argv','started_ns'} and set(terminal)=={'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'}
        and made['pid']==terminal['pid']==ARGS.outer_pid and type(made['pid']) is int and made['pid']>0 and terminal['native_exit']==terminal['wait_returncode']==0
        and made['argv']==[launcher_values['PY'],'-B','-X','utf8',str(SRC/'materializer/prepare.py')]
        and type(made['started_ns']) is int and terminal['finished_ns']>made['started_ns'],'TRUE_OUTER_CREATED3_TERMINAL6_SAME_NATIVE_WAIT_ZERO')
    for stream in ['stdout','stderr']:
        need(terminal[stream]==descriptor(SRC/'launcher'/('materialize.'+stream)),'OUTER_NESTED_COMPLETE_STREAM_DESCRIPTOR')
        pinned(terminal[stream])
    need(read(SRC/'launcher/materialize.stderr')==b'','OUTER_EXACT_ZERO_BYTE_STDERR')
    outer=decode(read(SRC/'launcher/materialize.stdout'))
    need(outer=={'status':result['status'],'result':result_pointer,'freeze':freeze_pointer,'native':budget,'unique_source_files':len(combined),'Run_created':False,'App_DB_HTTP_business':'NOT RUN'},'OUTER_NATIVE_STDOUT_EXACT_CURRENT_RESULT_AND_FREEZE_SUMMARY')
    # Exact fixed Source namespace plus the single real materialization and outer receipts.
    source_namespace=set()
    for current,dirs,files in os.walk(SRC,followlinks=False):
        for name in [*dirs,*files]:
            held=(Path(current)/name).lstat();need(not stat.S_ISLNK(held.st_mode) and not getattr(held,'st_file_attributes',0)&1024,'ALL_SOURCE_AND_ACTUAL_PATHS_ORDINARY_NO_REPARSE')
        source_namespace.update((Path(current)/name).relative_to(SRC).as_posix() for name in files)
    expected_namespace=set(source_pins['files'])|{'PINS.json'}|{'launcher/materialize.'+suffix for suffix in ['created.json','terminal.json','stdout','stderr']}|{'validation/materialize-freeze-assets-r1/'+name for name in actual_names}
    need(source_namespace==expected_namespace and len(source_namespace)==len(source_pins['files'])+1+4+len(actual_names),'EXACT212_FIXED_SOURCE_PLUS_ACTUAL4578_AND_OUTER4_FILE_NAMESPACE')
    # All own finite read windows are held through the end, without reading worktree bodies.
    for key,window in WINDOWS.items():need(nine(Path(key).lstat())==window['named_after'],'ALL_READBACK_INPUT_NAMED_NATIVE9_HELD_THROUGH_END')
    ended=time.time_ns();put('INPUTS.json',{'schema':1,'finite_input_count':len(WINDOWS),'windows':WINDOWS,'all_finite_windows_closed':True,'started_ns':started,'ended_ns':ended})
    put('CHECKS.json',{'schema':1,'counts':dict(COUNTS),'total_checks':sum(COUNTS.values())})
    report={'schema':1,'status':'PASS_COMPLETE_ACTUAL_CURRENTBAFB_M37_SOURCE_MATERIALIZATION_READBACK_ONLY','source_sha':HEAD,'parent':PARENT,
        'actual_RESULT':result_pointer,'actual_freeze':freeze_pointer,'actual_M_native_operations':budget,'all1150_created_terminal_and_double_raw_streams_complete':True,'actual_outer_pid':terminal['pid'],'actual_outer_native_exit_wait':0,
        'source_program_members':18,'Root_source_full_bytes_and_native9':14,'M_source_full_bytes_and_native9':3,'complete_tracked_rows_per_tree':15750,'complete_dual_source_metadata_rows':31500,'authority_metadata_rows':len(authority),
        'all24_actual_commit_native_before_after_blobs_and_paths_verified':True,'cumulative311_required316_baseline_additions264':True,'six_full_source_groups':counts,'complete_normal_source_union':len(combined),'M_RESULT_current_fields':37,'M_RESULT_historical_fields_preserved':36,
        'eleven_retirement_facts_whole_joined_without_overwriting_old_ten':True,'complete29_current_and355_21_fixed_history_Source_bytes_native9_preserved':True,'Source_plus_actual_output_namespace_files':len(source_namespace),'original_source_clean_before_after_from_real_native_streams':True,'saved_output_namespace_files':len(actual_names),
        'old_or_current_worktree_bodies_reread':False,'target_module_or_source_authority_import_or_execution':False,'new_Git_Docker_resource_or_process_calls':0,
        'Run_created_by_M':False,'M_App_DB_HTTP_business':'NOT RUN','S_or_full_business_acceptance':'NOT ASSERTED_BY_THIS_M_READBACK','finite_input_count':len(WINDOWS),'all_finite_windows_closed':True,'complete_callback_REPORT':CALLBACK_REPORT,'complete_callback_INPUTS':CALLBACK_INPUTS,'Root_callback_actual_readback':CALLBACK_ROOT,'complete_callback_graph_rows':len(callback_graph),'exact_M_authority_projection_rows':len(authority),'static_author_input_rows':len(authorinputs),'separate_author_input_rows':{key:len(value) for key,value in separate_author_maps.items()},'separate_author_intersections':author_intersections,'current_warm_profile9_and_d0_finite_complete':True,'source_tree_complete_freeze_plan':frozen_value['source_tree'],'source_tree_copy_or_volume_created_by_M':False,'current_profile_revision_and_baseline9_inverse_verified':True,'finite_profile_attempt_not_twelfth_whole':True,'ended_ns':ended}
    put('REPORT.json',report)
    put('PINS.json',{'schema':1,'scope':'INDEPENDENT_COMPLETE_SAVED_ACTUAL_M37_EVIDENCE_READBACK','files':{p.name:pin(p.read_bytes()) for p in sorted(OUT.iterdir()) if p.is_file() and p.name!='PINS.json'}})
    print(json.dumps({'REPORT':pin((OUT/'REPORT.json').read_bytes()),'PINS':pin((OUT/'PINS.json').read_bytes()),'finite_inputs':len(WINDOWS),'native':budget,'Source_only_M':True},ensure_ascii=False))

if __name__=='__main__':
    parser=argparse.ArgumentParser(description='Read completed exact fa2088 saved M evidence only; no target calls.')
    for name in ['result-bytes','freeze-bytes','outer-pid']:parser.add_argument('--'+name,type=int,required=True)
    for name in ['result-sha256','freeze-sha256']:parser.add_argument('--'+name,required=True)
    ARGS=parser.parse_args()
    need(ARGS.result_bytes>0 and ARGS.freeze_bytes>0 and ARGS.outer_pid>0 and all(re.fullmatch('[a-f0-9]{64}',v) for v in [ARGS.result_sha256,ARGS.freeze_sha256]),'EXACT_REAL_COMPLETED_RESULT_FREEZE_AND_PID_ARGUMENTS')
    try:main()
    except Exception as error:
        index=len(list(OUT.glob('READER-FAILURE-*.json')))+1
        put('READER-FAILURE-'+str(index).zfill(2)+'.json',{'status':'OWN_READBACK_REFUSED_ORIGINAL_M_UNCHANGED','class':type(error).__name__,'message':str(error),'traceback':traceback.format_exc(),'all_target_or_resource_calls':0})
        raise
