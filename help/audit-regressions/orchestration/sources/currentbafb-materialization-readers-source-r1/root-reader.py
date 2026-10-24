import argparse
import ast
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sys

CALLBACK_BOUND = True
CALLBACK_EXPECTED_CHECKS = 11680434
CALLBACK_INPUT_COUNT = 32497
CALLBACK_NATIVE_PID = 27244

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

assert CALLBACK_BOUND is True, 'REAL_CURRENT_CALLBACKS_AND_ROOT_READBACK_REQUIRED_BEFORE_READER'
sys.dont_write_bytecode = True
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


parser = argparse.ArgumentParser()
for name in ('result-bytes', 'freeze-bytes', 'outer-pid', 'source-pins-bytes'):
    parser.add_argument('--' + name, type=int, required=True)
for name in ('result-sha256', 'freeze-sha256', 'source-pins-sha256'):
    parser.add_argument('--' + name, required=True)
args = parser.parse_args()
assert args.result_bytes > 0 and args.freeze_bytes > 0 and args.outer_pid > 0
assert all(re.fullmatch('[a-f0-9]{64}', value) for value in (args.result_sha256, args.freeze_sha256))
B = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1')
SEALED_SOURCE_PINS = {'bytes': 13638, 'sha256': '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}
assert args.source_pins_bytes == SEALED_SOURCE_PINS['bytes'] and args.source_pins_sha256 == SEALED_SOURCE_PINS['sha256']
helper = B / 'customer-form-current-three-front-material-producer-independent-r1/review.py'
helper_raw = helper.read_bytes()
assert len(helper_raw) == 33902 and hashlib.sha256(helper_raw).hexdigest() == '4673d95c60c22f4653b6f22f833e24fb6accc3b286c555ca0462b0fb501252e0'
sp = importlib.util.spec_from_file_location('currentfa2088_actual_M_readonly', helper)
h = importlib.util.module_from_spec(sp)
sp.loader.exec_module(h)
r = h.Finite()
r.read(helper, h.fp(helper_raw))
source = Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source')
actual = source / 'validation/materialize-freeze-assets-r1'
source_pins = r.json(source / 'PINS.json', {'bytes': args.source_pins_bytes, 'sha256': args.source_pins_sha256})
reader_bytes = lambda pointer: r.read(Path(pointer['path']), pointer)
separate_author_maps = validate_reader_source_inheritance(source, source_pins, reader_bytes, h.loads, readback_check)
raw = r.read(actual / 'RESULT.json', {'bytes': args.result_bytes, 'sha256': args.result_sha256})
result = h.loads(raw)
assert result['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
assert result['source_sha'] == source_pins['source_sha'] == 'bafb6305062aeb6b6e945c21da572863062ca480'
assert result['parent'] == source_pins['parent'] == 'd0a5d5398546e223122b5c2a2f115e1908f78ced'
program = r.read(source / 'materializer/prepare.py', source_pins['files']['materializer/prepare.py'])
source_constants = {node.targets[0].id: ast.literal_eval(node.value) for node in ast.parse(program).body if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id in {'COMMITTED_PATHS', 'COMMIT_CHAIN', 'CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT', 'CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT', 'CURRENT_6E3850_PAID_PRODUCER_FAILURE_RETIREMENT', 'CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE', 'CURRENT_D0A5_FAILED_FINITE_INDEX', 'CURRENT_FA2088_PROFILE_REVISION', 'CURRENT_M_RESULT_KEYS35', 'CURRENT_M_RESULT_KEYS36', 'CURRENT_M_RESULT_KEYS37', 'CURRENT_OWNER_PROFILE_ATTEMPT', 'CURRENT_SOURCE_IO_SOURCE_HISTORY', 'CURRENT_SOURCE_IO_SOURCE_QUALIFICATION', 'CURRENT_TASK6_MOUNT_PROFILE_MANIFEST', 'CURRENT_TASK6_MOUNT_PROFILE_SOURCE', 'CURRENT_TASK6_MOUNT_SOURCE_HISTORY', 'CURRENT_TASK6_MOUNT_SOURCE_QUALIFICATION', 'CURRENT_TASK6_OWNER_PROFILE_MANIFEST', 'CURRENT_TASK6_OWNER_PROFILE_SOURCE', 'CURRENT_TASK6_OWNER_SOURCE_HISTORY', 'CURRENT_TASK6_OWNER_SOURCE_QUALIFICATION', 'CURRENT_TASK6_PROFILE_ATTEMPT', 'CURRENT_WARM_EXPORT_ROOT_READBACK', 'CURRENT_WARM_PROFILE_MANIFEST', 'CURRENT_WARM_PROFILE_NEW_NODE', 'CURRENT_WARM_PROFILE_SOURCE', 'CURRENT_WARM_QUALIFICATION_RECEIPTS', 'CURRENT_WARM_SOURCE_HISTORY', 'CURRENT_WARM_SOURCE_INPUTS', 'CURRENT_WARM_SOURCE_QUALIFICATION', 'HISTORICAL_TWENTYONE_PUBLICATION', 'HISTORICAL_TWENTYTHREE_AUTHORITY_SOURCE', 'HISTORICAL_TWENTYTHREE_CONSTANTS', 'HISTORICAL_TWENTYTHREE_M_SOURCE', 'HISTORICAL_TWENTYTHREE_PUBLICATION', 'HISTORICAL_TWENTYTWO_M_SOURCE', 'HISTORICAL_TWENTYTWO_PUBLICATION'}}
historical23_values = dict(source_constants)
historical23_values.update(source_constants['HISTORICAL_TWENTYTHREE_CONSTANTS'])
historical23_publication = r.json(Path(source_constants['HISTORICAL_TWENTYTHREE_PUBLICATION']['path']), source_constants['HISTORICAL_TWENTYTHREE_PUBLICATION'])
keys = source_constants['CURRENT_M_RESULT_KEYS37']
previous_keys36 = source_constants['CURRENT_M_RESULT_KEYS36']
previous_keys35 = source_constants['CURRENT_M_RESULT_KEYS35']
previous_keys34 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']
assert set(result) == set(keys) and len(result) == len(keys) == 37
assert [key for key in keys if key != 'current_6e3850_paid_producer_failure_retirement'] == previous_keys36
assert [key for key in previous_keys36 if key != 'current_175f_paid_producer_failure_retirement'] == previous_keys35
assert [key for key in previous_keys35 if key != 'current_28dc_paid_producer_failure_retirement'] == previous_keys34
assert result['commit_chain'] == source_constants['COMMIT_CHAIN'] and len(source_constants['COMMIT_CHAIN']) == 24
historical_chain = historical23_values['COMMIT_CHAIN'][:-2]  # genuine original21 retirement frame, independent of all three newer stages
assert len(historical_chain) == 21 and historical23_values['COMMIT_CHAIN'] == result['commit_chain'][:23]
assert [row['path'] for row in result['committed_files']] == source_constants['COMMITTED_PATHS']
ops = result['native_operations']
budget = 6 + sum(2 + 2 * len(step['files']) for step in result['commit_chain']) + 1 + len(result['committed_files']) + 2 + 3 + 1 + 4
source_budget = 6 + sum(2 + 2 * len(step['files']) for step in source_constants['COMMIT_CHAIN']) + 1 + len(source_constants['COMMITTED_PATHS']) + 2 + 3 + 1 + 4
assert len(ops) == budget == source_budget == 1150
for operation in ops:
    stem = operation['stem']
    assert r.json(actual / (stem + '.terminal.json')) == operation
    made = r.json(actual / (stem + '.created.json'))
    assert set(made) == {'pid', 'argv'}
    assert made['pid'] == operation['pid'] and made['argv'] == operation['argv']
    assert operation['native_exit'] == operation['wait_returncode'] == 0
    for stream in ('stdout', 'stderr'):
        value = r.read(actual / (stem + '.' + stream), {'bytes': operation[stream + '_bytes'], 'sha256': operation[stream + '_sha256']})
        if stream == 'stderr':
            assert value == b''
assert len({op['stem'] for op in ops}) == len(ops)
outer = r.json(source / 'launcher/materialize.terminal.json')
made = r.json(source / 'launcher/materialize.created.json')
assert set(made) == {'pid', 'argv', 'started_ns'}
assert outer['pid'] == made['pid'] == args.outer_pid and outer['native_exit'] == outer['wait_returncode'] == 0
for stream in ('stdout', 'stderr'):
    value = r.read(source / ('launcher/materialize.' + stream), outer[stream])
    if stream == 'stderr':
        assert value == b''
assert r.read(actual / 'root-materialize-source.py', result['materialize_source']) == program
materialize_inputs = result['materialize_source_inputs']
assert list(materialize_inputs) == [(source/'materializer'/name).as_posix() for name in ['PLAN.md','prepare.py','INVERSE.json']] and len(materialize_inputs) == 3
for materialize_name, materialize_row in materialize_inputs.items():
    r.read(Path(materialize_name), materialize_row)
    assert r.rows[str(Path(materialize_name))]['named_before'] == materialize_row['stat9']
freeze_raw = r.read(Path(result['freeze']), {'bytes': args.freeze_bytes, 'sha256': args.freeze_sha256})
assert result['freeze_bytes'] == args.freeze_bytes and result['freeze_sha256'] == args.freeze_sha256
assert 0 < len(freeze_raw) <= 16 * 1024 * 1024
freeze = h.loads(freeze_raw)
assert freeze['status'] == 'CLEAN_SOURCE_FROZEN_ONLY' and freeze['source_sha'] == result['source_sha']
groups = {'driver': 'driver_source_files', 'customer': 'customer_source_files', 'paid': 'paid_source_files', 'fulfillment': 'fulfillment_source_files', 'aftersale_review': 'aftersale_review_source_files', 'worker': 'worker_source_files'}
assert result['source_counts'] == {'driver': 10, 'customer': 10865, 'paid': 10866, 'fulfillment': 10881, 'aftersale_review': 10885, 'worker': 10886}
combined = {}
for key, field in groups.items():
    members = freeze[field]
    assert len(members) == result['source_counts'][key]
    for row in members:
        assert row['path'] not in combined or combined[row['path']] == row
        combined[row['path']] = row
assert len(combined) == result['unique_source_files_actual_read'] == 10886
r.read(source / 'validation/PINS.json', result['root_source_pins'])
root_snapshot = r.json(Path(result['root_source_snapshot']['path']), result['root_source_snapshot'])
snapshot = r.json(Path(result['complete_source_snapshot']['path']), result['complete_source_snapshot'])
assert len(root_snapshot) == 14 and len(snapshot) == 31500
qualification_pointer = source_constants['CURRENT_WARM_SOURCE_QUALIFICATION']
qualification = r.json(Path(qualification_pointer['path']), qualification_pointer)
inventory = next(row['stdout'] for row in qualification['operations'] if row['stem'] == 'main-tracked')
inventory_raw = r.read(Path(inventory['path']), inventory)
assert inventory_raw.endswith(b'\0')
names = sorted(x.decode('utf-8') for x in inventory_raw[:-1].split(b'\0'))
assert len(names) == len(set(names)) == 15750
publication = r.json(source / 'validation/PROFILE-PUBLICATION.json', source_pins['files']['validation/PROFILE-PUBLICATION.json'])
assert publication['source_sha'] == result['source_sha'] and publication['parent'] == result['parent']
assert publication['finite_contexts']['commit_chain'] == result['commit_chain']
profile_source = Path(source_constants['HISTORICAL_TWENTYONE_PUBLICATION']['path']).parent.parent; profile_values = historical23_values
profile_source_pins = r.json(profile_source/'PINS.json', {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentfa2088-profile-r2-normal-preparation-r1/normal-source/PINS.json', 'bytes': 44270, 'sha256': '80a00951c5398c9384df5ca4bbb52156182d3e86968290ca9c88f89802e99dfe'})
profile_publication = r.json(Path(source_constants['HISTORICAL_TWENTYONE_PUBLICATION']['path']),source_constants['HISTORICAL_TWENTYONE_PUBLICATION'])
profile_bytes = lambda pointer: r.read(Path(pointer['path']), pointer)
profile_json = lambda pointer: h.loads(profile_bytes(pointer))

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

historical_publication = profile_json(source_constants['HISTORICAL_TWENTYTWO_PUBLICATION'])
historical_values = dict(historical23_values)
historical_values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE'] = historical23_values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']['predecessor_composite']
assert validate_current_task6_profile(historical_values, historical_publication, profile_publication, {row['path']:row for row in historical_publication['repository_Source_requirements']}, profile_bytes)
assert validate_current_owner_profile(historical23_values, historical23_publication, profile_bytes)
assert validate_current_warm_profile(source_constants, publication, profile_bytes, h.loads, readback_check)
assert validate_current_d0a5_finite(source_constants, publication, profile_bytes, h.loads, readback_check)
code = {row['path']: {key: row[key] for key in ('bytes', 'sha256')} for row in publication['repository_Source_requirements']}
assert len(code) == 316
current_source_authority_pointer = source_constants['CURRENT_WARM_SOURCE_HISTORY']
current_source_authority = r.json(Path(current_source_authority_pointer['path']), current_source_authority_pointer)
assert current_source_authority['source_sha'] == result['source_sha'] and current_source_authority['parent'] == result['parent']
assert result['commit_chain'][-1]['files'] == current_source_authority['files'] and len(current_source_authority['files']) == 2
assert result['commit_chain'][-1]['commit'] == current_source_authority_pointer
assert [row['before'] is None for row in result['commit_chain'][-1]['files']] == [False, True]
assert all(row['after'] == {key:code[row['path']][key] for key in ('bytes','sha256')} for row in current_source_authority['files'])

for root in ('H:/天俪商城/worktrees/ms4-asset-consumers', 'H:/天俪商城/worktrees/ms4-aftersale-normal-check'):
    prefix = root + '/'
    actual_names = sorted(path[len(prefix):] for path in snapshot if path.startswith(prefix))
    assert actual_names == names
    for path, expected in code.items():
        assert {key: snapshot[prefix + path][key] for key in ('bytes', 'sha256')} == expected
for mapping in (root_snapshot, snapshot):
    for name, row in mapping.items():
        assert row['path'] == name and type(row['bytes']) is int and row['bytes'] >= 0
        assert type(row['stat9']) is list and len(row['stat9']) == 9 and all(type(value) is int for value in row['stat9'])
        assert row['stat9'][6] == row['bytes']
for name, row in root_snapshot.items():
    path = Path(name)
    r.read(path, row)
    assert r.rows[str(path)]['named_before'] == row['stat9']
for row in result['selected9']:
    assert row in freeze['artifact_files']
assert len(result['selected9']) == len(freeze['artifact_files']) == 9
assert len(result['commit_chain']) == 24 and len(result['committed_files']) == 311
assert result['commit_chain'][-2]['source_sha'] == result['parent'] and len(result['commit_chain'][-2]['files']) == 3
assert all(row['before'] is not None for row in result['commit_chain'][-2]['files'])
assert len(result['commit_chain'][-3]['files']) == 5 and len([row for row in result['commit_chain'][-3]['files'] if row['before'] is None]) == 1
assert len(historical_chain[-3]['files']) == 4
assert result['Run_created'] is False and result['App_DB_HTTP_business'] == 'NOT RUN'
eighth_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-upload-root-current28dc-normal-next-source-r1/CURRENT-0C13-RETIREMENT-COMPLETE.json', 'bytes': 7629389, 'sha256': '0c912bb891d4cf0c2f45817aa9241cf0dfc453fd36ed7258c80f051276dddf5b'}
eighth = r.json(Path(eighth_pointer['path']), eighth_pointer)
assert publication['finite_contexts']['current_0c13_paid_producer_failure_retirement'] == eighth_pointer
assert result['current_0c13_paid_producer_failure_retirement'] == eighth
assert eighth['original_S_native_exit'] == eighth['paid_producer_native_exit'] == 1 and eighth['business_acceptance'] == 'INCOMPLETE'
seventh_pointer = publication['finite_contexts']['current_881_paid_producer_failure_retirement']
seventh = r.json(Path(seventh_pointer['path']), seventh_pointer)
assert result['current_881_paid_producer_failure_retirement'] == seventh
for field in ('current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement'):
    previous = result[field]
    assert previous['original_S_native_exit'] == previous['paid_producer_native_exit'] == 1 and previous['business_acceptance'] == 'INCOMPLETE'
ninth_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1/CURRENT-28DC-RETIREMENT-COMPLETE.json', 'bytes': 33024728, 'sha256': '65cdd89dcd9d02247b99705af76322ecffedb5cd18b47c85776ac4b7fb7c01c8'}
ninth = r.json(Path(ninth_pointer['path']), ninth_pointer)
assert ninth_pointer == source_constants['CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT']
assert ninth_pointer == {'path': Path(ninth_pointer['path']).as_posix(), **h.fp(r.read(Path(ninth_pointer['path']), ninth_pointer))}
assert publication['finite_contexts']['current_28dc_paid_producer_failure_retirement'] == ninth_pointer
assert result['current_28dc_paid_producer_failure_retirement'] == ninth
assert ninth['scope'] == 'NINTH_CURRENT28DC_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY'
assert ninth['run_id'] == '202610231609185fbd23' and ninth['source_sha'] == historical_chain[-3]['parent'] == '28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
assert ninth['original_S_native_exit'] == ninth['paid_producer_native_exit'] == 1 and ninth['business_acceptance'] == 'INCOMPLETE'
assert ninth['M34_preserved'] is True and ninth['M_native_count'] == 1078 and ninth['M_commit_count'] == 18 and ninth['M_complete_source_metadata_rows'] == 31496
assert ninth['historical_eight_retirements']['current_0c13_paid_producer_failure_retirement'] == eighth

tenth_pointer = source_constants['CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT']
assert publication['finite_contexts']['current_175f_paid_producer_failure_retirement'] == tenth_pointer
tenth = r.json(Path(tenth_pointer['path']), tenth_pointer)
assert result['current_175f_paid_producer_failure_retirement'] == tenth
assert tenth['scope'] == 'TENTH_CURRENT175F_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY'
assert tenth['run_id'] == '20261023182634096f57' and tenth['source_sha'] == historical_chain[-2]['parent']
assert tenth['original_S_native_exit'] == tenth['paid_producer_native_exit'] == 1 and tenth['business_acceptance'] == 'INCOMPLETE'
assert tenth['M35_preserved'] is True and tenth['M_native_count'] == 1088 and tenth['M_commit_count'] == 19 and tenth['M_complete_source_metadata_rows'] == 31496
historical_nine_fields = ('prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement')
assert set(tenth['historical_nine_retirements']) == set(tenth['historical_nine_whole_encoded_pins']) == set(historical_nine_fields)
for field in historical_nine_fields:
    assert result[field] == tenth['historical_nine_retirements'][field]
    assert h.fp(h.enc(result[field])) == tenth['historical_nine_whole_encoded_pins'][field]
assert tenth['historical_nine_retirements']['current_28dc_paid_producer_failure_retirement'] == ninth
assert set(tenth['forbidden_environment_metadata']) == {'rows', 'body_reads', 'hashes_claimed'}
forbidden_metadata = tenth['forbidden_environment_metadata']['rows']
assert len(forbidden_metadata) == 2 and tenth['forbidden_environment_metadata']['body_reads'] == 0 and tenth['forbidden_environment_metadata']['hashes_claimed'] is False
for row in forbidden_metadata:
    assert set(row) == {'path', 'stat9', 'birthtime_ns', 'file_attributes'} and len(row['stat9']) == 9


# Complete tracked Source tree, independent of the smaller business union.
tree = freeze['source_tree']
assert type(tree) is dict and list(tree) == ['schema','scope','source_sha','requested_repo_source','volume_suffix','writer_destination','files','directories','file_count','directory_count','manifest_sha256']
val_root = 'H:/天俪商城/worktrees/ms4-aftersale-normal-check'
main_root = 'H:/天俪商城/worktrees/ms4-asset-consumers'
assert tree['schema'] == 1 and type(tree['schema']) is int and tree['scope'] == 'COMPLETE_TRACKED_SOURCE_TREE'
assert tree['source_sha'] == freeze['source_sha'] == result['source_sha'] and tree['requested_repo_source'] == freeze['requested_repo_source'] == val_root
assert tree['volume_suffix'] == 'source-tree' and tree['writer_destination'] == '/run/audit-source-tree'
source_tree_rows = []; source_tree_directories = set()
for relative in names:
    assert relative and not re.search(r'[\x00-\x1f\x7f\\:]', relative) and all(part not in ('','.','..','.git') for part in relative.split('/'))
    assert relative not in ['crmeb/.env','crmeb/public/install.lock']
    current = snapshot[val_root + '/' + relative]; other = snapshot[main_root + '/' + relative]
    assert {key: current[key] for key in ('bytes','sha256')} == {key: other[key] for key in ('bytes','sha256')}
    source_tree_rows.append({'path':relative,'bytes':current['bytes'],'sha256':current['sha256']})
    parts = relative.split('/')
    source_tree_directories.update('/'.join(parts[:count]) for count in range(1,len(parts)))
source_tree_directories = sorted(source_tree_directories)
assert tree['files'] == source_tree_rows and tree['directories'] == source_tree_directories
assert type(tree['file_count']) is int and tree['file_count'] == len(source_tree_rows) == len(names)
assert type(tree['directory_count']) is int and tree['directory_count'] == len(source_tree_directories)
source_tree_raw = json.dumps({'files':source_tree_rows,'directories':source_tree_directories},ensure_ascii=False,separators=(',',':')).encode('utf-8')
assert tree['manifest_sha256'] == hashlib.sha256(source_tree_raw).hexdigest()

eleventh_pointer = source_constants['CURRENT_6E3850_PAID_PRODUCER_FAILURE_RETIREMENT']
assert publication['finite_contexts']['current_6e3850_paid_producer_failure_retirement'] == eleventh_pointer
eleventh = r.json(Path(eleventh_pointer['path']), eleventh_pointer)
assert result['current_6e3850_paid_producer_failure_retirement'] == eleventh
assert eleventh['run_id'] == '2026102321491304a643' and eleventh['source_sha'] == historical_chain[-1]['parent']
assert eleventh['M36_preserved'] is True and eleventh['M_native_count'] == 1104 and eleventh['M_commit_count'] == 20 and eleventh['M_complete_source_metadata_rows'] == 31496
assert eleventh['original_S_native_exit'] == eleventh['paid_producer_native_exit'] == 1 and eleventh['business_acceptance'] == 'INCOMPLETE'
historical_ten_fields = (*historical_nine_fields,'current_175f_paid_producer_failure_retirement')
assert set(eleventh['historical_ten_retirements']) == set(eleventh['historical_ten_whole_encoded_pins']) == set(historical_ten_fields)
for field in historical_ten_fields:
    assert result[field] == eleventh['historical_ten_retirements'][field]
    assert h.fp(h.enc(result[field])) == eleventh['historical_ten_whole_encoded_pins'][field]
current_forbidden_metadata = eleventh['forbidden_environment_metadata']['rows']
assert len(current_forbidden_metadata) == 2 and eleventh['forbidden_environment_metadata']['body_reads'] == 0 and eleventh['forbidden_environment_metadata']['hashes_claimed'] is False
for item in current_forbidden_metadata:
    assert set(item) == {'path','stat9','birthtime_ns','file_attributes'} and len(item['stat9']) == 9

assert result['selected9'] == publication['selected9']


CALLBACK_ROOT_POINTER = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R100-CALLBACK-ACTUAL-ROOT-READBACK.json', 'bytes': 1947, 'sha256': '626e8fcee1ff71a8d09974e0b5df16bd3c5c3f1502a8be00d9b7114d4bca75f3'}
CALLBACK_ROOT_EXPECTED = {'schema': 1, 'status': 'ROOT_ACTUAL_COMPLETE_BAFB_R3_CALLBACKS0_PUBLIC_READBACK0_ADMIT_FULL_M_ONLY', 'actual_REPORT': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-original-source-callback-actual-r1/REPORT.json', 'bytes': 5466, 'sha256': '0d02c2aa925c85bf4a34fe6b3eba3bb2797d00b26171ef02fb4a665f4c4f3c77'}, 'actual_INPUTS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-original-source-callback-actual-r1/INPUTS.json', 'bytes': 52861412, 'sha256': '6491cab0bea2439c55e1fddb5f444b8784355c998d9cb4f75a2ad69a2489b588'}, 'original_tool_session': 87567, 'original_handle_consumed_terminal_chunk': 'dddba0', 'actual_PID': 27244, 'native_exit': 0, 'wait_returncode': 0, 'complete_streams_join': True, 'M_and_S_each_full_checks': 11680434, 'complete_return_objects_equal': True, 'canonical_return_pin': {'bytes': 198653693, 'sha256': 'f38cfab1c864367eb6ebb1e4edb756400358fb1c1ee4cfbf54b6375816cb8f2c'}, 'actual_source_paths': 32497, 'all_original_first_EOF_native9_cross4_closed_windows_verified': True, 'original_cache_semantics_preserved_no_second_disk_read_claim': True, 'new_finite_own_output_registry_counts': {'close': 367, 'zero': 53}, 'original_d0_Run_body_paths_absent': True, 'original_current_M_S_raw_stream_bodies_absent': True, 'mutable_original_C_D_not_consumed': True, 'callback_native_calls': 0, 'M_body_S_main_App_business': 'NOT RUN', 'A0': False, 'normal_Source_PINS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/PINS.json', 'bytes': 13638, 'sha256': '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}, 'duration_seconds': 212.0618549, 'Root_complete_own_public_input_files': 15, 'Root_all_windows_ended': True, 'completed_ns': 1792860043854698800}

callback_root = r.json(Path(CALLBACK_ROOT_POINTER['path']), CALLBACK_ROOT_POINTER)
assert callback_root == CALLBACK_ROOT_EXPECTED
callback_report = r.json(Path(callback_root['actual_REPORT']['path']), callback_root['actual_REPORT'])
callback_inputs = r.json(Path(callback_root['actual_INPUTS']['path']), callback_root['actual_INPUTS'])
assert callback_report['status'] == 'PASS_ORIGINAL_COMPLETE_SOURCE_AUTHORITY_CALLBACKS_ONLY'
assert callback_report['source_PINS'] == {'path': (source / 'PINS.json').as_posix(), **SEALED_SOURCE_PINS}
assert callback_report['stages'] == 24 and callback_report['retirements'] == 11 and callback_report['tracked'] == 15750 and callback_report['committed'] == 311
assert callback_report['complete_return_objects_equal'] is True and callback_report['callback_native_calls'] == 0 and callback_report['M_body_or_S_main_or_App_business'] == 'NOT RUN' and callback_report['A0'] is False
assert callback_root['canonical_return_pin'] == callback_report['complete_canonical_return_pin']
assert callback_root['M_and_S_each_full_checks'] == CALLBACK_EXPECTED_CHECKS
assert set(callback_report['qualification']) == {'M', 'S'}
for label, item in callback_report['qualification'].items():
    assert item['module'] == label and item['manual_dependency_injection'] is False and item['unbound_globals'] == [] and item['pure_source_checks'] == CALLBACK_EXPECTED_CHECKS
assert set(callback_inputs) == {'files', 'cache_semantics', 'callback_invocations'} and callback_inputs['callback_invocations'] == 2
assert callback_inputs['cache_semantics'] == 'ORIGINAL_FIRST_FULL_EOF_READ_ONCE_PER_PATH_THEN_CACHE'
callback_files = callback_inputs['files']
assert len(callback_files) == callback_report['actual_input_paths'] == CALLBACK_INPUT_COUNT
entry_names = {'source_reader.py', 'author_support.py', 'materializer/prepare.py', 'validation/run.py', 'validation/PROFILE-PUBLICATION.json'}
assert set(callback_report['entries']) == entry_names
bootstrap = {(source / 'PINS.json').as_posix(): SEALED_SOURCE_PINS, **{(source / name).as_posix(): wanted for name, wanted in callback_report['entries'].items()}}
assert len(bootstrap) == 6 and set(bootstrap) <= set(callback_files)
for name in entry_names:
    assert callback_report['entries'][name] == {key: source_pins['files'][name][key] for key in ('bytes', 'sha256')}
for path, wanted in bootstrap.items():
    assert {key: callback_files[path][key] for key in ('bytes', 'sha256')} == wanted
for path, row in callback_files.items():
    assert set(row) == {'path', 'bytes', 'sha256', 'native9', 'handle_before', 'handle_after', 'path_before', 'path_after', 'EOF', 'closed', 'started_ns', 'ended_ns'}
    assert row['path'] == path and row['native9'] == row['path_before'] == row['path_after'] and row['handle_before'] == row['handle_after']
    assert len(row['native9']) == len(row['handle_before']) == 9 and row['native9'][6] == row['bytes']
    assert [row['native9'][i] for i in (0, 1, 6, 7)] == [row['handle_before'][i] for i in (0, 1, 6, 7)]
    assert row['EOF'] is row['closed'] is True and callback_report['started_ns'] <= row['started_ns'] <= row['ended_ns'] <= callback_report['ended_ns']
authority_names = set(callback_files) - set(bootstrap)
assert len(authority_names) == len(callback_files) - len(bootstrap) == CALLBACK_INPUT_COUNT-6
authority_path = actual / 'AUTHORITY-INPUT-SNAPSHOT.json'
authority_raw = r.read(authority_path)
authority_snapshot = h.loads(authority_raw)
assert set(authority_snapshot) == authority_names
for path, row in authority_snapshot.items():
    assert set(row) == {'path', 'bytes', 'sha256', 'stat9'} and row['path'] == path
    assert {key: row[key] for key in ('bytes', 'sha256')} == {key: callback_files[path][key] for key in ('bytes', 'sha256')}
    assert row['stat9'] == callback_files[path]['native9'] and len(row['stat9']) == 9 and row['stat9'][6] == row['bytes']
for row in forbidden_metadata + current_forbidden_metadata:
    assert row['path'] not in callback_files and row['path'] not in authority_snapshot
assert len(source_pins['files']) == 29 and len(source_pins['source_members18']) == 18
assert {path for path in authority_names if path.startswith(source.as_posix()+'/')} == {(source/'validation/actual-profile'/row['path']).as_posix() for row in publication['selected9']}
author_intersections = validate_separate_author_intersections(authority_snapshot, separate_author_maps, readback_check)
assert callback_report['group_counts'] == result['source_counts']

ended = r.end()
out = B / 'currentbafb-complete-materialization-root-readback-r1'
out.mkdir(exist_ok=False)
(out / 'INPUTS.json').write_bytes(h.enc({'files': list(r.rows.values()), 'all_windows_ended': True, 'ended_ns': ended}))
report = {
    'status': 'PASS_ACTUAL_FULL_CURRENTBAFB_MATERIALIZATION_ONLY', 'source_sha': result['source_sha'],
    'actual_RESULT': {'path': (actual / 'RESULT.json').as_posix(), **h.fp(raw)},
    'actual_freeze': {'path': result['freeze'], **h.fp(freeze_raw)}, 'native_operations': budget,
    'all_original_native_created_terminal_and_streams_join': True, 'outer_pid': args.outer_pid, 'outer_native_exit_wait': 0,
    'M_RESULT_fields': 37, 'historical_M_RESULT_fields': 36, 'historical_before_ninth_fields': 34, 'complete_source_snapshot_rows': 31500, 'root_source_members': 14, 'M_source_members': 3,
    'steps': len(result['commit_chain']), 'tracked_per_checkout': 15750, 'cumulative': 311, 'source_group_union': 10886,
    'eighth_actual_failed_A_retirement_preserved': True, 'old_seven_retirements_preserved': True,
    'previous_seventh_whole_pinned_authority_and_result_join_preserved': True, 'reader_revision': 1,
    'ninth_actual_failed_A_whole_pinned_authority_and_result_join_preserved': True,
    'tenth_actual_failed_A_whole_pinned_authority_and_result_join_preserved': True, 'all_prior_nine_whole_encoded_objects_preserved': True,
    'original_complete_callback_Root_qualification': CALLBACK_ROOT_POINTER,
    'original_complete_callback_REPORT': callback_root['actual_REPORT'], 'original_complete_callback_INPUTS': callback_root['actual_INPUTS'],
    'callback_bootstrap_controls': 6, 'callback_complete_inputs': CALLBACK_INPUT_COUNT, 'M_complete_authority_inputs': len(authority_snapshot),
    'authority_snapshot': {'path': authority_path.as_posix(), **h.fp(authority_raw)},
    'full_authority_namespace_bytes_hash_stat9_joined_to_real_complete_callback': True,
    'forbidden_ENV_metadata_files': 2, 'forbidden_ENV_body_reads': 0,
    'both_checkouts_all316_required_file_metadata_bytes_joined': True, 'current_stage24_one_modified_one_added': True, 'historical_d0a5_stage23_three_existing_paths': True, 'historical41c0_exact_five_paths_and_one_parent_absence_preserved': True, 'historical_nineteenth_commit_exact_four_modified_paths': True, 'historical_twentieth_commit_exact_seven_modified_paths': True,
    'eleventh_actual_failed_A_whole_pinned_authority_and_result_join_preserved': True, 'all_prior_ten_whole_encoded_objects_preserved': True,
    'complete_tracked_source_tree_verified': {'file_count':tree['file_count'],'directory_count':tree['directory_count'],'manifest_sha256':tree['manifest_sha256'],'scope':tree['scope'],'Val_origin':tree['requested_repo_source']}, 'freeze_original_16MiB_limit_preserved': True,
    'current_profile_revision_and_baseline9_inverse_verified': True, 'finite_profile_attempt_not_twelfth_whole': True,
    'finite_reads': len(r.rows), 'all_input_windows_ended': True, 'separate_author_input_rows': {key:len(value) for key,value in separate_author_maps.items()}, 'separate_author_intersections': author_intersections, 'current_warm_profile9_and_d0_finite_complete': True,
    'old_source_bodies': 'NOT REREAD',
    'new31500_snapshot': 'FULL_METADATA_READ; ORIGINAL_M_FULL_SOURCE_READ_METHOD_AND_NATIVE_RECEIPTS_VERIFIED',
    'Run_App_DB_HTTP_business': 'NOT RUN', 'A0': 'NOT CREATED',
}
(out / 'REPORT.json').write_bytes(h.enc(report))
print(json.dumps({'REPORT': h.fp(h.enc(report)), 'finite_reads': len(r.rows), 'M': h.fp(raw), 'freeze': h.fp(freeze_raw)}))
