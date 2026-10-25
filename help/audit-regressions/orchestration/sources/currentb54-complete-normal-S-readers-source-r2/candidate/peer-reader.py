"""Standalone independent reader for the actual completed original fa2088 S.

SOURCE ONLY until Root authorizes completed native0/wait0. Imports stdlib only.
Retains the reviewed full normal-chain receipt contract; no target execution.
"""
import argparse
import ast
import hashlib
import json
from pathlib import Path
import sys
import os
import stat
import time
import re

if sys.flags.optimize:
    raise RuntimeError('OPTIMIZED_PYTHON_FORBIDDEN_BEFORE_ANY_INPUT_READ')

KEYS = ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']

def need(ok, code):
    if not ok:
        raise AssertionError(code)

def fp(raw):
    return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}

def pairs(rows):
    out = {}
    for k,v in rows:
        need(k not in out,'DUPLICATE_KEY:'+k)
        out[k] = v
    return out

def loads(raw):
    return json.loads(raw.decode('utf-8'),object_pairs_hook=pairs)

def enc(value):
    return (json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode('utf-8')

def pinpart(value):
    return {k:value[k] for k in ['bytes','sha256']}

def n9(info):
    out = [getattr(info,k) for k in KEYS]
    need(all(type(v) is int for v in out),'EXACT_PYTHON_INT_NATIVE9')
    return out

class Finite:
    def __init__(self):
        self.rows = {}
        self.raws = {}

    def observe(self,path):
        path = Path(path)
        a = path.lstat()
        need(stat.S_ISREG(a.st_mode) and not a.st_file_attributes & 0x400,'ORDINARY_FILE:'+str(path))
        pn = n9(a)
        total = 0
        digest = hashlib.sha256()
        chunks = []
        with path.open('rb') as f:
            x = os.fstat(f.fileno())
            fn = n9(x)
            # Windows path ctime and descriptor ctime have different API meanings.
            need(all(pn[i] == fn[i] for i,k in enumerate(KEYS) if k in {'st_dev', 'st_ino', 'st_size', 'st_mtime_ns'}),'NAMED_FD_COMMON_IDENTITY:'+str(path))
            need(a.st_birthtime_ns == x.st_birthtime_ns,'NAMED_FD_BIRTHTIME:'+str(path))
            while True:
                raw = f.read(262144)
                if not raw:
                    break
                chunks.append(raw);digest.update(raw);total += len(raw)
            need(f.read(1) == b'' and total == a.st_size,'FULL_EOF:'+str(path))
            y = os.fstat(f.fileno())
            need(n9(y) == fn and y.st_birthtime_ns == x.st_birthtime_ns,'FD_FIRST_FINAL:'+str(path))
        z = path.lstat()
        need(n9(z) == pn and z.st_birthtime_ns == a.st_birthtime_ns,'NAMED_FIRST_FINAL:'+str(path))
        return {'path':path.as_posix(),'pin':{'bytes':total,'sha256':digest.hexdigest()},
                'named_before':pn,'named_after':n9(z),'fd_open':fn,'fd_after':n9(y),
                'named_birthtime_ns':a.st_birthtime_ns,'fd_birthtime_ns':x.st_birthtime_ns},b''.join(chunks)

    def read(self,path,expected=None):
        path = Path(path);key = str(path)
        if key not in self.rows:
            row,raw = self.observe(path)
            self.rows[key] = row;self.raws[key] = raw
        raw = self.raws[key]
        if expected is not None:
            need(fp(raw) == pinpart(expected),'EXPECTED_FULL_PIN:'+path.as_posix())
        return raw

    def json(self,path,expected=None):
        return loads(self.read(path,expected))

    def end(self):
        for key,row in self.rows.items():
            final,raw = self.observe(Path(key))
            need(final == row and raw == self.raws[key],'WHOLE_REVIEW_FIRST_FINAL:'+key)
        return time.time_ns()


def positive_pid(value):
    parsed = int(value)
    if parsed <= 0:
        raise argparse.ArgumentTypeError('PID must be positive')
    return parsed


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


def validate_current_b54_source(values, pub, oldpub, expected_sources, read, decode, check):
    """Join the exact stage25 exporter and all qualified public Source receipts; no target calls."""
    from pathlib import Path
    import hashlib,re
    compact=lambda row:{key:row[key] for key in ('bytes','sha256')}
    check(type(expected_sources) is dict and len(expected_sources)==15752,'B54_CALLER_INDEPENDENT_COMPLETE_EXPECTED_TRACKED_MAP')
    prior_context=oldpub['finite_contexts']
    COMMITTED_PATHS=values['COMMITTED_PATHS']
    COMMIT_CHAIN=values['COMMIT_CHAIN']
    CURRENT_BAFB_FAILED_FINITE_INDEX=values['CURRENT_BAFB_FAILED_FINITE_INDEX']
    CURRENT_BAFB_OWN_OUTPUTS=values['CURRENT_BAFB_OWN_OUTPUTS']
    CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']
    CURRENT_FIX_EXPORT_ROOT_READBACK=values['CURRENT_FIX_EXPORT_ROOT_READBACK']
    CURRENT_FIX_PROFILE_SOURCE=values['CURRENT_FIX_PROFILE_SOURCE']
    CURRENT_FIX_PROFILE_SOURCE_ADMISSION=values['CURRENT_FIX_PROFILE_SOURCE_ADMISSION']
    CURRENT_FIX_QUALIFICATION_RECEIPTS=values['CURRENT_FIX_QUALIFICATION_RECEIPTS']
    CURRENT_FIX_SOURCE_HISTORY=values['CURRENT_FIX_SOURCE_HISTORY']
    CURRENT_FIX_SOURCE_INPUTS=values['CURRENT_FIX_SOURCE_INPUTS']
    CURRENT_FIX_SOURCE_QUALIFICATION=values['CURRENT_FIX_SOURCE_QUALIFICATION']
    HISTORICAL_TWENTYFOUR_CONSTANTS=values['HISTORICAL_TWENTYFOUR_CONSTANTS']
    HISTORICAL_TWENTYFOUR_PUBLICATION=values['HISTORICAL_TWENTYFOUR_PUBLICATION']
    MAIN_SOURCE=values['MAIN_SOURCE']
    NEW_TRACKED_PATHS=values['NEW_TRACKED_PATHS']
    SOURCE_REQUIREMENT_PATHS=values['SOURCE_REQUIREMENT_PATHS']
    VALIDATION_SOURCE=values['VALIDATION_SOURCE']
    context=pub['finite_contexts']
    overlay={'commit_chain','committed_changes','committed_files','new_tracked_paths','actual_complete_expected_tracked_count','source_counts','current_composite_customer_form_source','source_authoring_qualification_scope','current_published_source_scope'}
    extra={'current_fix_export_Root_readback','historical_twentyfourth_publication','current_fix_source_history','current_fix_source_qualification','current_fix_source_inputs','current_bafb_failed_finite_history','current_fix_profile_source','current_fix_profile_Source_admission','current_bafb_failed_finite_own_outputs'}
    check(set(context)==set(prior_context)|extra and all(context[key]==value for key,value in prior_context.items() if key not in overlay),'FIX_ALL23_CONTEXT_ELEVEN_WHOLES_OLD_FINITE_ATTEMPTS_PRESERVED')
    check(context['current_fix_export_Root_readback']==CURRENT_FIX_EXPORT_ROOT_READBACK and context['historical_twentyfourth_publication']==HISTORICAL_TWENTYFOUR_PUBLICATION
        and context['current_fix_source_history']==CURRENT_FIX_SOURCE_HISTORY and context['current_fix_source_qualification']==CURRENT_FIX_SOURCE_QUALIFICATION
        and context['current_fix_source_inputs']==CURRENT_FIX_SOURCE_INPUTS and context['current_bafb_failed_finite_history']==CURRENT_BAFB_FAILED_FINITE_INDEX
        and context['current_fix_profile_source']==CURRENT_FIX_PROFILE_SOURCE and context['current_fix_profile_Source_admission']==CURRENT_FIX_PROFILE_SOURCE_ADMISSION
        and context['current_bafb_failed_finite_own_outputs']==CURRENT_BAFB_OWN_OUTPUTS,'FIX_EXACT_CURRENT_SAFE_PUBLIC_POINTERS')
    stage=decode(read(CURRENT_FIX_SOURCE_HISTORY));chain=context['commit_chain'];step=chain[-1]
    given_expected_sources=dict(expected_sources);historical_sources=dict(expected_sources)
    for changed in step['files']:
        relative=changed['path']
        check(relative in historical_sources and compact(historical_sources[relative])==changed['after'],'B54_CURRENT_STAGE_AFTER_EQUALS_INDEPENDENT_COMPLETE_OVERLAY')
        if changed['before'] is None:del historical_sources[relative]
        else:historical_sources[relative]={'path':relative,**changed['before']}
    historical={'commit_chain':oldpub['finite_contexts']['commit_chain'],'all':dict(sorted(historical_sources.items()))}
    check(chain==COMMIT_CHAIN and chain[:-1]==historical['commit_chain'] and len(chain)==len(historical['commit_chain'])+1
        and step=={key:stage[key] for key in ['kind','source_sha','parent','files']}|{'commit':CURRENT_FIX_SOURCE_HISTORY}
        and step['source_sha']==pub['source_sha']=='b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
        and step['parent']==pub['parent']==oldpub['source_sha']=='bafb6305062aeb6b6e945c21da572863062ca480','FIX_TRUE_NEXT_STAGE_OVER_COMPLETE24')
    paths=[row['path'] for row in step['files']]
    check(paths==sorted(set(paths)) and len(paths)==3 and [row['before'] is None for row in step['files']]==[True,False,True]
        and stage['kind']=='language_cache_fixture_fix' and stage['scope']=='ACTUAL_READ_ONLY_GIT_LOCAL_B54_LANGUAGE_CACHE_FIX_STEP'
        and stage['business']=='NOT RUN' and stage['worktree_mutations']==0,'FIX_REAL_THREE_PATH_A_M_A_STAGE_OVER_COMPLETE24')
    local=decode(read(stage['actual_local_commit']));validation=decode(read(stage['actual_validation_update']));adoption=decode(read(stage['Root_design']))
    red=decode(read(stage['actual_component_red_root']));green=decode(read(stage['actual_component_green_root']))
    check(stage['source_admission']=={'mode':'LOCAL_ONLY_PENDING_FINAL_PUSH','remote_qualification':'UNBOUND','final_push':'PENDING'}
        and local['status']=='LOCAL_L2_FIX_COMMITTED_CLEAN' and local['HEAD']==pub['source_sha'] and local['parent']==pub['parent']
        and local['branch']=='fix/ms4-language-cache-fixture-20261025' and len(local['paths'])==len(set(local['paths']))==3 and sorted(local['paths'])==paths
        and local['actual_component_red_green']==[1,0] and local['syntax3']==0 and local['ordinary_close_and_independent_zero']==[0,0]
        and local['push']=='NOT_RUN' and local['fullnormal_UI_acceptance']=='PENDING_NOT_ASSERTED','FIX_TRUE_LOCAL_B54_THREE_PATH_COMPONENT_ONLY_NO_FULL_NORMAL_OR_PUSH')
    check(validation['status']=='ACTUAL_NEW_FIX_W_AND_VALIDATION_HEAD_CLEAN' and validation['HEAD']==pub['source_sha']
        and validation['W']==MAIN_SOURCE and validation['Val']==VALIDATION_SOURCE and type(validation['native_PID']) is int and validation['native_PID']>0 and validation['native_wait']==0
        and validation['old_main_and_old_validation_unchanged'] is True,'FIX_SEPARATE_ACTUAL_VAL_ROOT_NO_BORROWED_BAFB_HEAD')
    check(adoption['status']=='ROOT_DESIGN_ADOPTED_MINIMAL_B54_FULL_NORMAL_SUCCESSOR_SOURCE_FIRST' and adoption['HEAD']==pub['source_sha'] and adoption['PARENT']==pub['parent']
        and adoption['W']==MAIN_SOURCE and adoption['Val']==VALIDATION_SOURCE and adoption['branch']==local['branch']
        and adoption['exact_real_changed_paths_M_A_A']==[('A' if row['before'] is None else 'M')+'\t'+row['path'] for row in step['files']]
        and adoption['real_commit_record']==stage['actual_local_commit'] and adoption['new_Val_native_worktree']==stage['actual_validation_update']
        and adoption['component_real_red_root']==stage['actual_component_red_root'] and adoption['component_real_green_root']==stage['actual_component_green_root'],
        'FIX_REAL_ROOT_ADOPTION_COMMIT_VAL_RED_GREEN_FIVE_SMALL_QUALIFIED_PUBLIC_REPORTS')
    check(red['status']=='ROOT_FULL_ACTUAL_TARGET_RED1_CLOSE0_INDEPENDENT_ZERO0_VERIFIED' and red['native_wait']==1 and red['same_natural_two_App_exit']==[0,1]
        and green['status']=='ROOT_FULL_ACTUAL_SAME_COMMAND_RED1_GREEN0_CLOSE0_INDEPENDENT_ZERO0_VERIFIED' and green['native_wait']==0 and green['same_natural_two_App_exit']==[0,0]
        and green['same_actual_runner_CLI_as_red'] is green['only_shared_leaf_changed_between_real_red_green'] is green['two_original_empty_maps_rebuilt_by_real_getter'] is True,
        'FIX_ACTUAL_COMPONENT_SAME_COMMAND_RED_GREEN_NOT_NORMAL_A')
    for observed in (red,green):
        check(observed['full_output_files_read']==613 and observed['all_native_pairs_and_whole_streams_verified']==[135,12]
            and observed['source_full_rows']==[1,1,2515] and observed['syntax3_native_wait']==0
            and observed['foreign53_all_safe_snapshots_unchanged'] is True and observed['ordinary_resources_removed']==[6,1,7]
            and observed['ordinary_close_mutations']==5 and observed['fresh_six_raw_empty']==[6,6]
            and observed['full_normal_UI19_groups']=='OPEN_NOT_ASSERTED','FIX_BOTH_COMPONENT_COMPLETE_ROOT_CLOSED_ZERO_QUALIFICATION_ONLY')
    root_export=decode(read(CURRENT_FIX_EXPORT_ROOT_READBACK))
    check(root_export['status']=='ACTUAL_B54_SOURCE_EXPORT_AND_CURRENT_MAIN_VAL_FULL_ROOT_READBACK0'
        and root_export['authority']==CURRENT_FIX_SOURCE_HISTORY and root_export['qualification']==CURRENT_FIX_SOURCE_QUALIFICATION
        and root_export['source_inputs']==CURRENT_FIX_SOURCE_INPUTS and root_export['actual_native']==25 and root_export['export_native']==stage['native_count']==13
        and root_export['qualification_native']==12 and root_export['all_created_terminal_PID_samehandle_wait_full_double_streams_readback0'] is True
        and root_export['remote_push']=='UNBOUND_PENDING_FINAL_PUSH' and root_export['M_S_App_business']=='NOT RUN'
        and root_export['full15752_tracked_byte_replay']=='UNBOUND_PENDING_ORIGINAL_M','FIX_REAL_EXPORT13_AND_QUAL12_ROOT_FULL_PUBLIC_READBACK_NOT_COMPLETE_TREE_BYTES_OR_M_S')
    export_root=Path(CURRENT_FIX_SOURCE_HISTORY['path']).parent;export_names=set()
    for row in root_export['output_files']:
        path=Path(row['path']);check(path.is_absolute() and path.is_relative_to(export_root) and '..' not in path.parts and path.as_posix() not in export_names,'FIX_EXACT_OWN_EXPORT_OUTPUT_CLOSED_FILE_IDENTITY')
        export_names.add(path.as_posix());read(row)
    check(type(root_export['all_own_output_files_EOF']) is int and len(export_names)==root_export['all_own_output_files_EOF'],'FIX_ALL_ACTUAL_OWN_EXPORT_FILES_EOF_NO_INNER_DESCRIPTOR_RECURSION')
    git_raw={}
    for label,receipt in stage['git_operations'].items():
        made=decode(read(receipt['created']));ended=decode(read(receipt['terminal']));out=read(receipt['stdout']);err=read(receipt['stderr'])
        check(set(made)=={'pid','argv','started_ns'} and set(ended)=={'pid','argv','started_ns','finished_ns','native_exit','wait_returncode','stdout','stderr'} and all(ended[key]==value for key,value in made.items())
            and type(made['pid']) is int and made['pid']>0 and made['started_ns']<=ended['finished_ns']
            and ended['native_exit']==ended['wait_returncode']==0 and compact(ended['stdout'])==compact(receipt['stdout']) and compact(ended['stderr'])==compact(receipt['stderr']) and err==b'','FIX_FULL_EXPORT_NATIVE_RECEIPTS')
        check(made['argv'][:5]==['git','-c','safe.directory='+MAIN_SOURCE,'-C',MAIN_SOURCE],'FIX_EXACT_READONLY_GIT_REPOSITORY')
        git_raw[label]=(made['argv'][5:],out)
    commands={'head-before':['rev-parse','HEAD'],'clean-before':['status','--porcelain=v1','--untracked-files=all'],'parent':['rev-list','--parents','-n','1',pub['source_sha']],
        'commit-object':['cat-file','-p',pub['source_sha']],'changed-paths':['diff-tree','--no-commit-id','--name-only','-r',pub['source_sha']],
        'name-status':['diff-tree','--no-commit-id','--name-status','-r',pub['source_sha']],'branch':['branch','--show-current'],'head-after':['rev-parse','HEAD'],'clean-after':['status','--porcelain=v1','--untracked-files=all']}
    check(git_raw['head-before'][1]==git_raw['head-after'][1]==(pub['source_sha']+'\n').encode() and git_raw['clean-before'][1]==git_raw['clean-after'][1]==b''
        and git_raw['parent'][1]==(pub['source_sha']+' '+pub['parent']+'\n').encode() and git_raw['commit-object'][1].splitlines()[1]==('parent '+pub['parent']).encode()
        and git_raw['branch'][1]==(local['branch']+'\n').encode() and git_raw['changed-paths'][1].decode().splitlines()==paths
        and git_raw['name-status'][1].decode().splitlines()==[('A' if row['before'] is None else 'M')+'\t'+row['path'] for row in step['files']],'FIX_COMPLETE_CURRENT_GIT_METADATA')
    expected_sources=dict(historical['all']);blobs={}
    for index,row in enumerate(step['files'],1):
        relative=row['path'];stem='member-'+str(index);commands[stem+'-after']=['show',pub['source_sha']+':'+relative]
        after=git_raw[stem+'-after'][1]
        check({'bytes':len(after),'sha256':hashlib.sha256(after).hexdigest()}==row['after'],'FIX_COMPLETE_ACTUAL_AFTER_BLOB')
        if row['before'] is None:
            check(relative not in expected_sources and relative in {'docs/multi-merchant/records/ms4/language-cache-fixture.md','help/audit-regressions/isolated/ms4-language-cache-normal.php'},'FIX_TRUE_NEW_DOCUMENT_ABSENT_FROM_COMPLETE_PARENT_AUTHORITY')
        else:
            commands[stem+'-before']=['show',pub['parent']+':'+relative];before=git_raw[stem+'-before'][1]
            check(relative in expected_sources and row['before']==compact(expected_sources[relative])
                and {'bytes':len(before),'sha256':hashlib.sha256(before).hexdigest()}==row['before'],'FIX_COMPLETE_ACTUAL_EXISTING_BEFORE_BLOB')
        expected_sources[relative]={'path':relative,**row['after']};blobs[relative]=after
    check(set(git_raw)==set(commands) and all(git_raw[key][0]==value for key,value in commands.items())
        and stage['native_count']==len(commands)==6+sum(1+(row['before'] is not None) for row in step['files'])+2+1,'FIX_REAL_EXPORT_DERIVED_FROM_ALL_ACTUAL_MEMBERS_NOT_FUTURE_M_BUDGET')
    expected_sources=dict(sorted(expected_sources.items()));changes={row['path']:dict(row) for row in prior_context['committed_changes']}
    for row in step['files']:
        if row['path'] in changes:changes[row['path']]['after']=row['after']
        else:changes[row['path']]=dict(row)
    changes=[changes[name] for name in sorted(changes)];committed=[expected_sources[row['path']] for row in changes]
    requirements=sorted(set(HISTORICAL_TWENTYFOUR_CONSTANTS['SOURCE_REQUIREMENT_PATHS'])|set(paths))
    additions={row['path'] for row in step['files'] if row['before'] is None};newtracked=sorted(set(HISTORICAL_TWENTYFOUR_CONSTANTS['NEW_TRACKED_PATHS'])|additions)
    check(context['committed_changes']==changes and context['committed_files']==committed and COMMITTED_PATHS==[row['path'] for row in changes]
        and SOURCE_REQUIREMENT_PATHS==requirements and context['new_tracked_paths']==NEW_TRACKED_PATHS==newtracked
        and pub['repository_Source_requirements']==[expected_sources[name] for name in requirements]
        and context['actual_complete_expected_tracked_count']==len(expected_sources)==len(historical['all'])+len(additions),'FIX_FULL_TRACKED_ADDITION_AND_CUMULATIVE_SCOPE')
    check(expected_sources==given_expected_sources and len(expected_sources)==15752 and len(committed)==313 and len(requirements)==318 and len(newtracked)==266,'B54_FULL_CURRENT25_SOURCE_OVERLAY_EQUALS_CALLER_INDEPENDENT_EXPECTED_MAP')
    composite=CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE
    check(context['current_composite_customer_form_source']==composite and composite['source_sha']==pub['source_sha']
        and composite['predecessor_composite']==prior_context['current_composite_customer_form_source']
        and composite['profile_PINS']==CURRENT_FIX_PROFILE_SOURCE
        and all(compact(expected_sources[row['path']])==compact(row) for row in composite['php_sources']),'FIX_RETAINED_CURRENT_CU7_PROFILE_COMPOSITE')
    qualification=decode(read(CURRENT_FIX_SOURCE_QUALIFICATION));qfolder=Path(CURRENT_FIX_SOURCE_QUALIFICATION['path']).parent/'current-source-readback';qraw={}
    check(qualification['source_sha']==pub['source_sha'] and qualification['parent']==pub['parent']
        and qualification['required_files_per_checkout']==len(requirements) and qualification['tracked_names_per_checkout']==len(expected_sources)
        and len(qualification['operations'])==12 and qualification['all_operations_read_only'] is qualification['current_Main_working_tree_asserted'] is qualification['current_Val_working_tree_asserted'] is True,'FIX_ACTUAL_TWO_WORKTREE_QUALIFICATION12')
    for row in qualification['operations']:
        receipt=CURRENT_FIX_QUALIFICATION_RECEIPTS[row['stem']];base=qfolder/row['stem']
        made=decode(read(receipt['created']));ended=decode(read(receipt['terminal']));out=read(row['stdout']);err=read(row['stderr'])
        check(set(made)=={'pid','argv','started_ns'} and set(row)=={'pid','argv','started_ns','finished_ns','native_exit','wait_returncode','stdout','stderr','stem'} and ended==row and all(made[key]==row[key] for key in made) and type(made['pid']) is int and made['pid']>0 and made['started_ns']<=ended['finished_ns'] and row['native_exit']==row['wait_returncode']==0 and err==b'','FIX_WHOLE_QUALIFICATION_RECEIPTS')
        check(Path(receipt['created']['path'])==Path(str(base)+'.created.json') and Path(receipt['terminal']['path'])==Path(str(base)+'.terminal.json')
            and compact(receipt['stdout'])==compact(row['stdout']) and compact(receipt['stderr'])==compact(row['stderr']),'FIX_QUALIFICATION_NATIVE_FILE_IDENTITY')
        qraw[row['stem']]=(row['argv'],out)
    for label,repository in [('main',MAIN_SOURCE),('validation',VALIDATION_SOURCE)]:
        expected_q={'head-before':['rev-parse','HEAD'],'clean-before':['status','--porcelain=v1','--untracked-files=all'],'parent':['rev-list','--parents','-n','1',pub['source_sha']],'tracked':['ls-files','-z'],'head-after':['rev-parse','HEAD'],'clean-after':['status','--porcelain=v1','--untracked-files=all']}
        for suffix,argv in expected_q.items():check(qraw[label+'-'+suffix][0]==['git','-c','safe.directory='+repository,'-C',repository,*argv],'FIX_EXACT_CURRENT_QUALIFICATION_ARGV')
        check(qraw[label+'-head-before'][1]==qraw[label+'-head-after'][1]==(pub['source_sha']+'\n').encode() and qraw[label+'-clean-before'][1]==qraw[label+'-clean-after'][1]==b''
            and qraw[label+'-parent'][1]==(pub['source_sha']+' '+pub['parent']+'\n').encode() and qraw[label+'-tracked'][1].decode().split('\0')==[*expected_sources,''],'FIX_TRUE_COMPLETE_TRACKED_NAMES')
    inputs=decode(read(CURRENT_FIX_SOURCE_INPUTS));input_rows=inputs['files']
    check(inputs['all_first_final_EOF_closed'] is inputs['full_group_final_native9_birthtime'] is True and inputs['application_or_runtime_bodies_read'] is False,'FIX_SOURCE_EXPORT_COMPLETE_INPUT_WINDOWS')
    check(root_export['full_source_input_EOF_windows_preserved']==len(input_rows),'FIX_ROOT_READBACK_OF_ALL_ACTUAL_SOURCE_INPUT_EOF_WINDOWS')
    check(type(input_rows) is dict and len(input_rows)==760,'B54_COMPLETE760_EXPORT_SOURCE_INPUT_WINDOW_DICTIONARY')
    for path,row in input_rows.items():
        check(type(path) is str and type(row) is dict and set(row)=={'path','bytes','sha256','native9','fd9','birthtime_ns','EOF','closed'} and row['path']==path
            and type(row['bytes']) is int and row['bytes']>=0 and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}',row['sha256']) is not None
            and type(row['native9']) is list and type(row['fd9']) is list and len(row['native9'])==len(row['fd9'])==9
            and all(type(number) is int for number in row['native9']+row['fd9']) and row['native9'][6]==row['fd9'][6]==row['bytes']
            and [row['native9'][i] for i in (0,1,6,7)]==[row['fd9'][i] for i in (0,1,6,7)] and type(row['birthtime_ns']) is int and row['birthtime_ns']>0 and row['EOF'] is row['closed'] is True,
            'B54_EVERY_SOURCE760_NATIVE9_FD9_CROSS4_BIRTH_EOF_ATTESTATION_NO_RUNTIME_BODY_FOLLOW')
    for repository in [MAIN_SOURCE,VALIDATION_SOURCE]:
        for relative in requirements:
            path=(Path(repository)/relative).as_posix();row=input_rows[path]
            check(compact(row)==compact(expected_sources[relative]) and row['path']==path and row['EOF'] is row['closed'] is True
                and len(row['native9'])==len(row['fd9'])==9 and [row['native9'][i] for i in (0,1,6,7)]==[row['fd9'][i] for i in (0,1,6,7)]
                and type(row['birthtime_ns']) is int and row['birthtime_ns']>0,'FIX_ORIGINAL_SCOPE_FULL_DERIVED_EOF_SOURCE_ATTESTATIONS_PER_CHECKOUT_NOT_FRESH_BODY_READ')
    return True

def validate_current_b54_metadata(source, source_pins, read, decode, check):
    """Current Core33 and constructor input48 are separate from historical29/input28."""
    from pathlib import Path
    import hashlib, re, stat
    def compact(row):return {key:row[key] for key in ('bytes','sha256')}
    def digest(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
    def named(path):
        facts=Path(path).lstat()
        check(stat.S_ISREG(facts.st_mode) and not stat.S_ISLNK(facts.st_mode)
            and not getattr(facts,'st_file_attributes',0)&1024,'B54_SOURCE_REGULAR_FILE_NO_REPARSE')
        return [int(getattr(facts,key)) for key in ('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')],facts.st_birthtime_ns
    source=Path(source)
    expected_source=Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source')
    check(source==expected_source,'B54_EXACT_CURRENT_R3_CORE_SOURCE_ROOT')
    expected_members={
        'author_support.py','authority-next.py.fragment','bafb-failed-finite-checks.py.fragment',
        'current-profile-checks.py.fragment','DIFF.patch','REVERSE.patch','namespace_static.py',
        'source_reader.py','SOURCE-INHERITANCE.json','SOURCE-PROOF.json','SOURCE-WINDOWS.json',
        'inputs/R1-INDEPENDENT-REPORT.json','inputs/R103-DESIGN-ADOPTION.json','inputs/R103-F1-ROOT.json',
        'inputs/ROOT-DESIGN-ADOPTION.json','launcher/launch.py','materializer/INVERSE.json',
        'materializer/PLAN.md','materializer/prepare.py','validation/DESIGN-TASKS.md','validation/PINS.json',
        'validation/PROFILE-PINS.json','validation/PROFILE-PUBLICATION.json','validation/run.py',
        'validation/actual-profile/README.md','validation/actual-profile/compose.yml',
        'validation/actual-profile/ms4-host.py','validation/actual-profile/ms4-initialize.php',
        'validation/actual-profile/seed-source-inverse.json','validation/actual-profile/interface-v1.json',
        'validation/actual-profile/source-derivation.json','validation/actual-profile/normal-close-ms4-fulfillment.py',
        'validation/actual-profile/verify-zero-ms4-fulfillment.py'}
    check(type(source_pins['files']) is dict and set(source_pins['files'])==expected_members
        and source_pins['member_count']==33 and len(source_pins['source_members18'])==18
        and set(source_pins['source_members18'])<=expected_members,'B54_CURRENT33_DECLARED_SOURCE_ONLY_NOT_DYNAMIC_M_OUTPUTS')
    current_rows={}
    for relative,pointer in source_pins['files'].items():
        path=source/relative
        check(set(pointer)=={'path','bytes','sha256'} and pointer['path']==path.as_posix(),
            'B54_CURRENT33_CANONICAL_THREE_FIELD_SOURCE_DESCRIPTOR')
        raw=read(pointer);check(digest(raw)==compact(pointer),'B54_CURRENT33_WHOLE_SOURCE_BYTES')
        native,birth=named(path)
        check(native[6]==len(raw),'B54_CURRENT33_REAL_NAMED_SOURCE_LENGTH')
        current_rows[path.as_posix()]={'path':path.as_posix(),**compact(pointer),'native9':native,
            'birthtime_ns':birth,'metadata_origin':'CURRENT_READER_FULL_CORE33_SOURCE_READ'}
    inherit=decode(read(source_pins['files']['SOURCE-INHERITANCE.json']))
    historical={'path':'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/PINS.json',
        'bytes':13638,'sha256':'7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}
    engineering={'path':'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r2/normal-source/PINS.json',
        'bytes':13692,'sha256':'66b613acf0cd5d98cb4ed956bd5fe0f2b53b78cb5a1f81eb331e0ae5c2d3887a'}
    check(inherit['before']==source_pins['before_source']==source_pins['historical24_before_unchanged']==historical
        and inherit['current_Source_identity']==source_pins['source_sha']=='b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
        and inherit['original24_fn']=={'bytes':808768,'sha256':'ff5afcdf7364f98ee2bff78ef1f5062683968a19aad34bcaac1c4979166445db'}
        and inherit['original_source_runtime18_full_patch_inverse'] is True,
        'B54_TRUE_HISTORICAL24_BEFORE_IS_BAFB_R3_NOT_ENGINEERING_R2')
    successor=inherit['R105_new_root_Source_only_successor']
    check(successor['engineering_before_R2']==source_pins['engineering_before_R2']==engineering
        and successor['historical_before_bafbR3_unchanged']==historical
        and successor['M_git_argv_and_functions_changed'] is successor['new_Git_stage_or_whole_or_Run'] is False
        and successor['original982_public_bodies_not_reread_or_nested'] is True,
        'B54_ENGINEERING_BEFORE_R2_DISTINCT_NO_NEW_WHOLE_OR_GIT_STAGE')
    check(successor['failed_M_Root']==source_pins['R105_failed_M_preserved']
        and successor['Root_process_only_environment_Source']==source_pins['R105_process_only_environment_Source'],
        'B54_FAILED_SOURCE_PREPARATION_AND_PROCESS_ENV_POINTERS_PRESERVED')
    engineer=decode(read(engineering));engineer_root=Path(engineering['path']).parent
    check(type(engineer['files']) is dict and set(engineer['files'])==expected_members
        and engineer['member_count']==33 and len(engineer['source_members18'])==18,
        'B54_EXACT_ENGINEERING_R2_SOURCE33_NOT_ITS_FAILED_M_OUTPUT_NAMESPACE')
    for relative,pointer in engineer['files'].items():
        check(set(pointer)=={'path','bytes','sha256'} and pointer['path']==(engineer_root/relative).as_posix(),
            'B54_ENGINEERING_R2_CANONICAL_DECLARED_SOURCE_ONLY')
    frozen=decode(read(source_pins['files']['SOURCE-WINDOWS.json']))
    check(set(frozen)=={'schema','files','all_first_final_own9_FD9_cross4_birth_EOF_closed',
            'original_Run_M_S_private_ENV_body_reads','candidate_import_or_call'}
        and frozen['schema']==1 and type(frozen['files']) is dict and len(frozen['files'])==48
        and frozen['all_first_final_own9_FD9_cross4_birth_EOF_closed'] is True
        and frozen['original_Run_M_S_private_ENV_body_reads'] is frozen['candidate_import_or_call'] is False,
        'B54_TRUE48_INPUT_WINDOWS_DICTIONARY_NOT_CURRENT33_OR_OLD28_LIST')
    prior_lint=decode(read(successor['prior_exact8PHP_actual_syntax_Root']))
    check(prior_lint['status']=='ROOT_COMPLETE_NORMAL8_PHP82_8CHILDREN_8HOST_40PUBLIC_READBACK0'
        and prior_lint['Root_outer_native_exit']==prior_lint['Root_outer_wait_returncode']==0
        and prior_lint['RESULT_keys']==18 and prior_lint['host_native']==prior_lint['complete_PHP_native_wait0']==8
        and prior_lint['total_public_files']==40 and prior_lint['business_executed'] is False,
        'B54_PRIOR_REAL_NORMAL8_SOURCE_INPUT_NOT_A_NEW_LINT_OR_HOST_EXECUTION')
    lint_source=Path(prior_lint['source_PINS']['path']).parent
    lint_packet=decode(read(prior_lint['source_PINS']))
    leaves_pointer={'path':(lint_source/'LEAVES.json').as_posix(),**compact(lint_packet['files']['LEAVES.json'])}
    leaves=decode(read(leaves_pointer))
    check(type(leaves) is list and len(leaves)==8 and [row['ordinal'] for row in leaves]==list(range(1,9))
        and len({row['bundle_name'] for row in leaves})==8,'B54_EXACT_EIGHT_PRIOR_COMPILE_SOURCE_INPUTS')
    expected_inputs={engineering['path'],*((engineer_root/name).as_posix() for name in engineer['files']),
        successor['failed_M_Root']['path'],successor['failed_M_public_list']['path'],
        successor['Root_process_only_environment_Source']['path'],successor['prior_exact8PHP_actual_syntax_Root']['path'],
        prior_lint['source_PINS']['path'],leaves_pointer['path'],
        *((lint_source/'bundle'/row['bundle_name']).as_posix() for row in leaves)}
    check(len(expected_inputs)==48 and set(frozen['files'])==expected_inputs
        and not any(path.startswith(source.as_posix()+'/') for path in frozen['files']),
        'B54_EXACT48_INPUT_SOURCE_PUBLIC_PATH_SET_NO_CURRENT_SOURCE_SELF_MAP_OR_RUN_FOLLOW')
    input_rows={}
    for path,row in frozen['files'].items():
        check(set(row)=={'path','bytes','sha256','named9','fd9','birthtime_ns','EOF','closed'} and row['path']==path
            and type(row['bytes']) is int and row['bytes']>=0 and type(row['sha256']) is str
            and re.fullmatch('[a-f0-9]{64}',row['sha256']) is not None
            and type(row['named9']) is type(row['fd9']) is list and len(row['named9'])==len(row['fd9'])==9
            and all(type(value) is int for value in row['named9']+row['fd9'])
            and row['named9'][6]==row['fd9'][6]==row['bytes']
            and [row['named9'][i] for i in (0,1,6,7)]==[row['fd9'][i] for i in (0,1,6,7)]
            and type(row['birthtime_ns']) is int and row['EOF'] is row['closed'] is True,
            'B54_ALL48_REAL_NAMED9_FD9_CROSS4_BIRTH_EOF_INPUT_WINDOW_SHAPES')
        raw=read({'path':path,**compact(row)})
        current,birth=named(path)
        check(digest(raw)==compact(row) and current==row['named9'] and birth==row['birthtime_ns'],
            'B54_ALL48_SOURCE_PUBLIC_FULL_BYTES_AND_REAL_SAVED_NAMED9_BIRTH_CONTINUITY')
        input_rows[path]={'path':path,**compact(row),'native9':row['named9'],
            'birthtime_ns':row['birthtime_ns'],'metadata_origin':'R3_CONSTRUCTOR_INPUT48_SAVED_NAMED9'}
    for row in leaves:
        key=(lint_source/'bundle'/row['bundle_name']).as_posix()
        check(compact(input_rows[key])==compact(row)==compact(lint_packet['files']['bundle/'+row['bundle_name']]),
            'B54_ALL_EIGHT_TRUE_LINT_BODY_INPUT_DESCRIPTORS_JOIN')
    failed=decode(read(successor['failed_M_Root']));public=decode(read(successor['failed_M_public_list']))
    check(failed['normal_Source_PINS']==engineering and failed['actual_M_PID']==35032 and failed['native_exit_wait']==1
        and failed['native_operations']==241 and failed['preceding_native0_empty_stderr']==240
        and failed['last_native']['native_exit']==failed['last_native']['wait_returncode']==128
        and failed['own_M_complete_outputs']==968 and failed['outer_complete_outputs']==13
        and failed['all_public_files']==len(public)==982 and failed['no_RESULT_or_freeze_or_S_start'] is True
        and failed['App_DB_HTTP_Docker_Run_or_business_started'] is False
        and failed['single_failed_attempt_preserved_no_retry'] is failed['source_environment_failure_not_business_result'] is True,
        'B54_ENGINEERING_R2_FAILED_M_SOURCE_ONLY_FACT_NOT_NEW_APP_RUN_OR_WHOLE')
    check(all(type(row) is dict and set(row)=={'bytes','sha256','named9','fd9','birthtime_ns','EOF','closed'}
        and type(path) is str and Path(path).is_absolute() and row['EOF'] is row['closed'] is True
        for path,row in public.items()),'B54982_PUBLIC_LIST_IS_NAMED_METADATA_ONLY_NO_NESTED_BODY_FOLLOW')
    # The 982 listed output pointers above are never dereferenced by this helper.
    return {'b54_input48':input_rows,'b54_currentCore33':current_rows}

def validate_current_b54_intersections(authority, mappings, check):
    """Join every genuine overlap; input48 has no invented positive-overlap promise."""
    check(set(mappings)=={'b54_input48','b54_currentCore33'}
        and len(mappings['b54_input48'])==48 and len(mappings['b54_currentCore33'])==33,
        'B54_CURRENT_SOURCE33_AND_INPUT48_DISTINCT_INTERSECTION_PROVENANCE')
    counts={}
    for label,mapping in mappings.items():
        count=0
        for path,row in authority.items():
            if path in mapping:
                held=mapping[path]
                check({key:row[key] for key in ('bytes','sha256')}=={key:held[key] for key in ('bytes','sha256')}
                    and row['stat9']==held['native9'],
                    'B54_EVERY_CURRENT_SOURCE_OR_SAVED_NAMED_INPUT_AUTHORITY_OVERLAP_PIN_AND_NATIVE9')
                count+=1
        if label=='b54_currentCore33':
            check(count>0,'B54_CURRENT_CORE_HAS_GENUINE_CURRENT_PROFILE_AUTHORITY_INTERSECTIONS')
        counts[label]=count
    return counts

def validate_current_b54_profile(values, pub, oldpub, expected_sources, read, decode, check):
    import ast,hashlib
    from pathlib import Path
    def compact(row):return {key:row[key] for key in ('bytes','sha256')}
    def constants(raw):
        result={}
        for node in ast.parse(raw.decode('utf8')).body:
            if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
                try:result[node.targets[0].id]=ast.literal_eval(node.value)
                except (TypeError,ValueError):pass
        return result
    CURRENT_FIX_PROFILE_SOURCE=values['CURRENT_FIX_PROFILE_SOURCE']
    CURRENT_FIX_PROFILE_MANIFEST=values['CURRENT_FIX_PROFILE_MANIFEST']
    CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=values['CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']
    HISTORICAL_TWENTYFOUR_PUBLICATION=values['HISTORICAL_TWENTYFOUR_PUBLICATION']
    CURRENT_FIX_PROFILE_NEW_NODE=values['CURRENT_FIX_PROFILE_NEW_NODE']
    CURRENT_FIX_PROFILE_SOURCE_ADMISSION=values['CURRENT_FIX_PROFILE_SOURCE_ADMISSION']
    CURRENT_FIX_EXPORT_ROOT_READBACK=values['CURRENT_FIX_EXPORT_ROOT_READBACK']
    profile_packet=decode(read(CURRENT_FIX_PROFILE_SOURCE));profile_manifest=decode(read(CURRENT_FIX_PROFILE_MANIFEST));profile_root=Path(CURRENT_FIX_PROFILE_SOURCE['path']).parent
    for name,row in profile_packet['members'].items():read({'path':(profile_root/name).as_posix(),**compact(row)})
    check(profile_manifest['before_selected9']==oldpub['selected9'] and profile_manifest['selected9']==pub['selected9'] and len(pub['selected9'])==9
        and profile_manifest['source_admission']=='LOCAL_ONLY_PENDING_FINAL_PUSH' and profile_manifest['runtime_ready'] is False,'FIX_CURRENT_NORMAL_PROFILE9_OVER_EXACT_ORIGINAL9_SOURCE_ONLY')
    normal_profile=Path(CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE['profile_interface']['path']).parent;prior_profile=Path(HISTORICAL_TWENTYFOUR_PUBLICATION['path']).parent/'actual-profile'
    prior9={};current9={}
    for oldrow,newrow in zip(oldpub['selected9'],pub['selected9']):
        name=oldrow['path'];check(name==newrow['path'],'FIX_NINE_PROFILE_IDENTITY_ORDER')
        oldraw=read({'path':(prior_profile/name).as_posix(),**compact(oldrow)});newraw=read({'path':(normal_profile/name).as_posix(),**compact(newrow)})
        check(oldraw==read({'path':(profile_root/'before/profile'/name).as_posix(),**compact(oldrow)})
            and newraw==read({'path':(profile_root/'candidate/profile'/name).as_posix(),**compact(newrow)}),'FIX_ALL_NINE_WHOLE_BEFORE_AFTER_PROFILE_SOURCES')
        prior9[name]=oldraw;current9[name]=newraw
    check({name for name in current9 if prior9[name]!=current9[name]}=={'ms4-initialize.php','ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py','interface-v1.json','source-derivation.json'}
        and all(current9[name]==prior9[name] for name in ['compose.yml','README.md','seed-source-inverse.json']),'FIX_EXACT_SIX_PIN_DAG_LEAVES_THREE_RAW_WHOLE')
    ar='help/audit-regressions/isolated/ms4-aftersale-review-normal.php';entry=expected_sources[ar]
    oldseven=decode(prior9['interface-v1.json'])['customer_form_current_profile']['php_sources'];seven=[dict(entry) if row['path']==ar else row for row in oldseven]
    check(len(seven)==7 and sum(a!=b for a,b in zip(oldseven,seven))==1,'FIX_SINGLE_CURRENT_AR_ROW_IN_ORIGINAL_CU7')
    def current_profile_statement(raw,name):
        text=raw.decode('utf8');tree=ast.parse(text)
        rows=[node for node in tree.body if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name) and node.targets[0].id==name]
        check(len(rows)==1,'FIX_ONE_ACTIVE_PROFILE_LITERAL_ASSIGNMENT')
        return ast.get_source_segment(text,rows[0]).encode()
    necessary={
        'ms4-host.py':{'CUSTOMER_FORM_SOURCE_FILES':tuple(seven),'AFTERSALE_REVIEW_ENTRY_BYTES':entry['bytes'],'AFTERSALE_REVIEW_ENTRY_SHA256':entry['sha256'],'INTERFACE_SHA256':hashlib.sha256(current9['interface-v1.json']).hexdigest()},
        'normal-close-ms4-fulfillment.py':{'HOST_SHA256':hashlib.sha256(current9['ms4-host.py']).hexdigest()},
        'verify-zero-ms4-fulfillment.py':{'CUSTOMER_FORM_SOURCE_FILES':tuple(seven),'AFTERSALE_REVIEW_ENTRY_BYTES':entry['bytes'],'AFTERSALE_REVIEW_ENTRY_SHA256':entry['sha256'],
            'INTERFACE_SHA256':hashlib.sha256(current9['interface-v1.json']).hexdigest(),'INTERFACE_BYTES':len(current9['interface-v1.json']),
            'HOST_SHA256':hashlib.sha256(current9['ms4-host.py']).hexdigest(),'CLOSE_SHA256':hashlib.sha256(current9['normal-close-ms4-fulfillment.py']).hexdigest()}}
    check(sum(len(values) for values in necessary.values())==12,'FIX_EXACT_TWELVE_CURRENT_PYTHON_PIN_DAG_ASSIGNMENTS')
    for name,wanted in necessary.items():
        before_values=constants(prior9[name]);after_values=constants(current9[name]);restored=current9[name]
        check(all(after_values[key]==value for key,value in wanted.items())
            and {key:value for key,value in before_values.items() if key not in wanted}=={key:value for key,value in after_values.items() if key not in wanted},'FIX_ONLY_CURRENT_AR_AND_INTERFACE_HOST_CLOSE_PYTHON_PINS')
        for key in wanted:
            old_stmt=current_profile_statement(prior9[name],key);new_stmt=current_profile_statement(current9[name],key)
            check(prior9[name].count(old_stmt)==current9[name].count(new_stmt)==1,'FIX_SINGLE_REQUIRED_LITERAL_DAG_SEAM');restored=restored.replace(new_stmt,old_stmt,1)
        check(restored==prior9[name],'FIX_FULL_THREE_PYTHON_MODULE_INVERSE_ALL344_FUNCTIONS_AND_BUSINESS_RAW')
    initializer=current9['ms4-initialize.php'];seams=profile_manifest['initializer_seams'];check(len(seams)==2,'FIX_INITIALIZER_EXACT_TWO_ACTIVE_AR_SEAMS')
    for row in reversed(seams):
        old=row['before'].encode();new=row['after'].encode();check(initializer.count(new)==1 and prior9['ms4-initialize.php'].count(old)==1,'FIX_INITIALIZER_SINGLE_BOUNDED_LITERAL');initializer=initializer.replace(new,old,1)
    check(initializer==prior9['ms4-initialize.php'] and profile_manifest['initializer_functions']=={'total':26,'changed':['m4i_cu_form_source_files','m4i_worker_sources'],'other_function_regions_raw':True},
        'FIX_INITIALIZER_FULL_INVERSE_ALL_OTHER_BYTES_SOURCE_TREE_COPY_MOUNTS_FIXTURE_AND_TRANSACTION_RAW')
    interface=decode(prior9['interface-v1.json']);interface['aftersale_review_normal_successor']['entry_bytes']=entry['bytes'];interface['aftersale_review_normal_successor']['entry_sha256']=entry['sha256']
    interface['customer_form_current_profile']['php_sources']=seven;interface['source_tree_runtime_profile']['actual_candidate_tracked_sources'][0]=dict(entry)
    check(decode(current9['interface-v1.json'])==interface,'FIX_INTERFACE_ONLY_THREE_ACTIVE_AR_PIN_LOCATIONS_SUCCESS29_DIAGNOSTIC4_UNCHANGED')
    derivation=decode(prior9['source-derivation.json']);derivation['new_business_entry'].update(compact(entry));derivation['new_business_entry']['status']='ACTUAL_LOCAL_COMMITTED_BYTES_FULL_NORMAL_NOT_EXECUTED'
    derivation['new_business_entry']['source']='Actual b54 local fix commit and clean dedicated W/Val; real component same-command red1/green0 with ordinaryclose/independentzero0 is separate from UNBOUND full normal export/M/S/UI qualification.'
    derivation['b54_language_cache_fixture_source_successor']=CURRENT_FIX_PROFILE_NEW_NODE
    derivation['current_nonself_artifacts']=[row for row in pub['selected9'] if row['path']!='source-derivation.json']
    check(decode(current9['source-derivation.json'])==derivation and len(derivation['current_nonself_artifacts'])==8
        and CURRENT_FIX_PROFILE_NEW_NODE['source_sha']==pub['source_sha'] and CURRENT_FIX_PROFILE_NEW_NODE['parent']==pub['parent']
        and CURRENT_FIX_PROFILE_NEW_NODE['current_seven_PHP']==seven and CURRENT_FIX_PROFILE_NEW_NODE['normal_success_result_keys']==29
        and CURRENT_FIX_PROFILE_NEW_NODE['diagnostic_keys']==4 and CURRENT_FIX_PROFILE_NEW_NODE['failure_only_optional_top_level']=='warm_failure_context'
        and CURRENT_FIX_PROFILE_NEW_NODE['source_admission']=='LOCAL_ONLY_PENDING_FINAL_PUSH' and CURRENT_FIX_PROFILE_NEW_NODE['remote_qualification']=='UNBOUND',
        'FIX_ONLY_CURRENT_ENTRY_AND_EIGHT_NONSELF_AND_FINITE_NEW_NODE_ALL_HISTORICAL_DERIVATION_SUBTREES_RAW')
    profile_admission=decode(read(CURRENT_FIX_PROFILE_SOURCE_ADMISSION))
    check(profile_admission['status']=='ROOT_AND_INDEPENDENT_B54_PROFILE9_SOURCE0_NO_RUNTIME_ACCEPTANCE'
        and profile_admission['profile_PINS']==CURRENT_FIX_PROFILE_SOURCE
        and profile_admission['actual_readonly_export_root']==CURRENT_FIX_EXPORT_ROOT_READBACK
        and profile_admission['profile9_only_pin_DAG_changes'] is True
        and profile_admission['new_initializer_actual_PHP82_syntax_normal8']=='NOT_RUN' and profile_admission['new_actual_M_S']=='NOT_RUN'
        and CURRENT_FIX_PROFILE_NEW_NODE['actual_Git_export_qualification']=='UNBOUND_NOT_CONSUMED_BY_THIS_SOURCE_PACKET',
        'FIX_EXACT_FROZEN_PROFILE_SOURCE_ADMISSION_AND_LATER_ACTUAL_EXPORT_SEPARATE_FROM_PROFILE_SOURCE_TIME_UNBOUND')
    return True

def validate_current_bafb_finite(values, pub, oldpub, read, decode, check):
    from pathlib import Path
    import hashlib,json,os,stat
    def compact(row):return {key:row[key] for key in ('bytes','sha256')}
    CURRENT_BAFB_FAILED_FINITE_INDEX=values['CURRENT_BAFB_FAILED_FINITE_INDEX']
    CURRENT_BAFB_OWN_OUTPUTS=values['CURRENT_BAFB_OWN_OUTPUTS']
    context=pub['finite_contexts']
    failed_index=decode(read(CURRENT_BAFB_FAILED_FINITE_INDEX))
    check(context['current_bafb_failed_finite_history']==CURRENT_BAFB_FAILED_FINITE_INDEX
        and failed_index['scope']=='ROOT_QUALIFIED_CURRENT_BAFB_FAILED_FINITE_HISTORY_ONLY_NOT_A_NEW_WHOLE_HISTORY_OR_SUCCESS'
        and failed_index['run_id']=='20261024174817c66be6' and failed_index['source_sha']==oldpub['source_sha']=='bafb6305062aeb6b6e945c21da572863062ca480'
        and failed_index['original_S_pid']==20556 and failed_index['original_S_native_exit_wait']==failed_index['aftersale_review_exit']==1
        and failed_index['Task6_exit0_retired'] is True and failed_index['worker']=='NOT_RUN' and failed_index['phase']=='real_source_cache_preparation'
        and failed_index['failed_assertion']=='TASK78_WARM_EXACT_SERIALIZED_SOURCE_VALUE'
        and failed_index['original_Run_first_cache_writer']=='UNPROVEN_NOT_INFERRED_FROM_COMPONENT',
        'B54_ORIGINAL_BAFB_TRUE_S1_TASK6_RETIRED_WARM_FAILURE_FINITE_NOT_SUCCESS_OR_NEW_WHOLE')
    failed_rows=failed_index['qualified_public']
    check(set(failed_rows)=={'failed_projection','resource_snapshot','close_result','close_Root','zero_result','zero_Root','preserved_public_inputs','original_input_metadata_closure'},
        'B54_ONLY_EIGHT_FIXED_SAVED_QUALIFIED_PUBLIC_REPORTS_NO_DESCRIPTOR_RECURSION')
    failed={name:decode(read(row)) for name,row in failed_rows.items()}
    projection=failed['failed_projection'];diagnostic=projection['diagnostic']
    check(projection['status']=='SAFE_CURRENT_BAFB_ACTUAL_FAILED_AR_CACHE_PREPARATION_PROJECTION_ONLY'
        and projection['run_id']==failed_index['run_id'] and projection['source_sha']==failed_index['source_sha']
        and projection['original_S_pid']==20556 and projection['original_S_native_exit_wait']==projection['host_AR_exit']==1
        and projection['phase']==failed_index['phase'] and projection['full_acceptance']=='INCOMPLETE'
        and diagnostic=={'code':'TASK78_WARM_EXACT_SERIALIZED_SOURCE_VALUE','class':'RuntimeException','file':'ms4-aftersale-review-normal.php','line':117}
        and projection['warm_failure_context']==failed_index['safe_failure_context']=={
            'ordinal':37,'logical_name':'sys_lang_source_map','expected_null':False,'expected_wire_bytes':116796,
            'expected_wire_sha256':'3662a8ddf48251018a88dad97ec27b45064a52f07e7149071f731bac61f53b57',
            'raw_type':'string','raw_bytes':6,'raw_sha256':'35786c7117b4e38d0f169239752ce71158266ae2f6e4aa230fbbb87bd699c0e3'}
        and projection['raw_matches_standard_empty_PHP_array_wire'] is projection['original_assertion_envelope_retained'] is True
        and projection['raw_cache_values_DB_ENV_actors_private_or_original_M_S_stream_bodies_read'] is projection['current_run_restarted_or_repaired'] is False
        and compact(projection['source_fingerprint'])=={'bytes':244912,'sha256':'a1258f537a3aa2de776d5a64afa5621141df0173e6d602feb168bd1f0c12ea0c'},
        'B54_RETAIN_ORIGINAL_BAFB_FOUR_FIELD_DIAGNOSTIC_EIGHT_FIELD_CONTEXT_NO_FIRST_WRITER_INFERENCE')
    snap=failed['resource_snapshot'];closed=failed['close_result'];zeroed=failed['zero_result'];close_root=failed['close_Root'];zero_root=failed['zero_Root']
    check(snap['status']=='ROOT_CURRENT_FAILED_RUN_READONLY_SAFE_RESOURCE_STATE' and snap['resource_mutations']==0
        and snap['raw_Run_ENV_actors_App_or_original_stream_bodies_read'] is False and snap['foreign_full_safe_fields'] is True
        and snap['owned_counts']=={'container':4,'network':1,'volume':9} and len(snap['containers'])==4 and len(snap['networks'])==1 and len(snap['volumes'])==9 and len(snap['foreign'])==53
        and len(snap['native_operations'])==23 and all(row['native_exit']==row['wait_returncode']==0 for row in snap['native_operations']),
        'B54_ORIGINAL_SAFE_FOUR_ONE_NINE_AND_FOREIGN53_SNAPSHOT_NOT_NEW_RESOURCE_OPERATION')
    check(closed['status']=='VERIFIED_AFTERSALE_REVIEW_FAILURE_NORMAL_CLOSE_ZERO_RESIDUAL' and zeroed['status']=='VERIFIED_AFTERSALE_REVIEW_FAILURE_INDEPENDENT_FRESH_SIX_QUERY_ZERO'
        and closed['run_id']==zeroed['run_id']==snap['run_id']==failed_index['run_id'] and closed['source_sha']==zeroed['source_sha']==snap['source_sha']==oldpub['source_sha']
        and closed['freeze_sha256']==zeroed['freeze_sha256']=='7d4a8c79a358009aff3716ea305d34ea69e8e79de17bb2972973ee384c2c2bb1'
        and closed['original_S_pid']==20556 and closed['original_S_native_exit_wait']==closed['original_aftersale_review_exit']==1
        and closed['original_task6_exit']==closed['original_paid_exit']==0 and closed['original_paid_status']=='EXITED_NATIVE0'
        and closed['ordinary_nonforce_only'] is True and closed['business_acceptance']==zeroed['business_acceptance']=='INCOMPLETE'
        and type(closed['removed']) is dict and closed['removed']=={'containers':4,'networks':1,'volumes':9} and all(type(value) is int for value in closed['removed'].values())
        and len(closed['operations'])==90 and len(zeroed['operations'])==12 and zeroed['resource_mutations']==0
        and zeroed['Host_imported'] is zeroed['close_imported_or_invoked'] is False
        and zeroed['close_actual_native_operations_verified']==90 and zeroed['close_five_ordinary_mutations_verified'] is True
        and zeroed['close_result']==failed_rows['close_result'], 'B54_FINITE_REAL_ORDINARY90_AND_INDEPENDENT12_NO_FORCE_RETRY_OR_FALSE_S0')
    snapshot_foreign={row['id']:{**row,'mounts':sorted(row['mounts'],key=lambda value:json.dumps(value,sort_keys=True,separators=(',',':')))} for row in snap['foreign']}
    check(len(snapshot_foreign)==53 and closed['global_foreign_baseline']==closed['global_foreign_after']==snapshot_foreign
        and set(closed['resource_ids'].values())=={row['id'] for row in snap['containers']},
        'B54_COMPLETE_ORIGINAL53_FOREIGN_MOUNT_MULTISET_NETWORK_AND_MODE_JOIN_SAFE_SNAPSHOT')
    mutation_rows=[row for row in closed['operations'] if row['argv'][1:2] in (['stop'],['rm']) or row['argv'][1:3] in (['network','rm'],['volume','rm'])]
    expected_mutations=[['stop','--timeout','-1',closed['resource_ids']['audit-mysql']],['stop','--timeout','-1',closed['resource_ids']['audit-redis']],
        ['rm',*sorted(closed['resource_ids'].values())],['network','rm',snap['networks'][0]['Id']],['volume','rm',*sorted(row['Name'] for row in snap['volumes'])]]
    check([row['index'] for row in mutation_rows]==[28,34,57,66,81] and [row['argv'][1:] for row in mutation_rows]==expected_mutations
        and closed['ordinary_stop_roles']==['audit-mysql','audit-redis'], 'B54_EXACT_OLD_FIVE_ORDINARY_SAME_CID_NONFORCE_MUTATIONS')
    check(close_root['status']=='ROOT_COMPLETE_CLOSE_NATIVE_OUTPUTS_READBACK0' and zero_root['status']=='ROOT_COMPLETE_ZERO_NATIVE_OUTPUTS_READBACK0',
        'B54_BIND_REAL_COMPACT_ROOT_READBACK_SCHEMA_NOT_OLD_FULL_ROOT_REPORT')
    for mode,root_report,count,mutations,outer,outputs,reads in [('close',close_root,90,5,14772,367,1415),('zero',zero_root,12,0,652,53,791)]:
        check(set(root_report)=={'schema','status','result','source_program','native_outer_pid','native_operations','ordinary_mutations','fresh_six_native_empty','foreign_preserved','complete_output_files','full_public_EOF_reads','original_Run_and_Source_metadata_preserved','original_private_and_M_S_raw_bodies_read','failed_S1_AR1_and_Task6_0_retained','business_acceptance','completed_ns'}
            and root_report['schema']==1 and root_report['result']==failed_rows[mode+'_result']
            and root_report['native_outer_pid']==outer and root_report['native_operations']==count and root_report['ordinary_mutations']==mutations
            and root_report['fresh_six_native_empty'] is root_report['original_Run_and_Source_metadata_preserved'] is root_report['failed_S1_AR1_and_Task6_0_retained'] is True
            and root_report['foreign_preserved']==53 and root_report['complete_output_files']==outputs and root_report['full_public_EOF_reads']==reads
            and root_report['original_private_and_M_S_raw_bodies_read'] is False and root_report['business_acceptance']=='INCOMPLETE',
            'B54_EXACT_COMPACT_ROOT_COMPLETE_CLOSE_ZERO_QUALIFICATION')
    bafb_own_outputs=decode(read(CURRENT_BAFB_OWN_OUTPUTS))
    check(bafb_own_outputs['schema']==1 and bafb_own_outputs['scope']=='ROOT_PRECISE_EXISTING_BAFB_FAILED_RETIREMENT_OWN_OUTPUT_CLOSED_LIST_NOT_RUN_OUTPUTS'
        and bafb_own_outputs['source_sha']==failed_index['source_sha'] and bafb_own_outputs['failed_finite_index']==CURRENT_BAFB_FAILED_FINITE_INDEX
        and bafb_own_outputs['full_closed_namespace_files']==420 and bafb_own_outputs['full_closed_namespace_bytes']==12661425
        and bafb_own_outputs['all420_full_body_EOF_and_named_FD_held_windows'] is bafb_own_outputs['only_two_fixed_prequalified_own_output_directories'] is bafb_own_outputs['original_Result_and_Source_body_pins_joined_to_prior_Root_proofs'] is True
        and bafb_own_outputs['original_Run_private_ENV_actor_M_S_stream_reads'] is bafb_own_outputs['Docker_or_any_mutation_to_old_materials'] is False
        and len(bafb_own_outputs['windows'])==420,
        'B54_EXACT_FIXED_367_PLUS53_OWN_OUTPUT_ADDENDUM_NOT_ARBITRARY_ROOT_DESCRIPTOR_FOLLOW')
    def bafb_finite_own_outputs(mode,result,root_report):
        import os,stat
        own_rule=bafb_own_outputs[mode]
        own=Path(failed_rows[mode+'_result']['path']).parent
        check(own_rule['root']==own.as_posix() and own_rule['original_qualified_result']==failed_rows[mode+'_result']
            and own_rule['original_Root_complete_readback']==failed_rows[mode+'_Root']
            and own_rule['original_native_ledger_count']==len(result['operations']), 'B54_OWN_LIST_REAL_RESULT_ROOT_AND_NATIVE_LEDGER_JOIN')
        expected_root=Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-aftersale-failed-retirement-source-r1')/('actual-'+mode+'-r1')
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
        for row in own_rule['output_files']:
            check(set(row)=={'path','bytes','sha256'},'B54_OWN_OUTPUT_LIST_EXACT_THREE_FIELD_ROWS')
            path=Path(row['path']);check(path.is_absolute() and path.is_relative_to(own) and '..' not in path.parts and path!=own,'BAFB_EXACT_QUALIFIED_OWN_LEAF_PATH_NO_OUTSIDE_FOLLOW')
            relative=path.relative_to(own).as_posix();check(relative not in expected,'BAFB_UNIQUE_OWN_PUBLIC_OUTPUT_PATH');expected[relative]=row
            parents.update('/'.join(relative.split('/')[:i]) for i in range(1,len(relative.split('/'))))
        reported=own_rule['all_own_output_files_EOF']
        check(reported==root_report['complete_output_files']==(367 if mode=='close' else 53), 'B54_COMPACT_ROOT_AND_PRECISE_OWN_CLOSED_LIST_COUNTS')
        check(type(reported) is int and reported==len(expected) and expected,'BAFB_OWN_OUTPUT_COUNTS_FROM_TRUE_CLOSED_LIST_NOT_FUTURE_ASSUMPTIONS')
        first=namespace();check(first[:2]==(sorted(expected),sorted(parents)),'BAFB_EXACT_FIRST_SAVED_OWN_PUBLIC_NAMESPACE')
        payloads={}
        for relative,row in expected.items():
            path=own/relative;before=safe(path,stat.S_IFREG);saved=bafb_own_outputs['windows'][path.as_posix()]
            check(before==(tuple(saved['native9']),saved['birthtime_ns']) and saved['EOF'] is saved['closed'] is True
                and len(saved['native9'])==len(saved['fd9'])==9 and saved['native9'][6]==row['bytes']
                and [saved['native9'][i] for i in (0,1,6,7)]==[saved['fd9'][i] for i in (0,1,6,7)], 'B54_EXACT_ADDENDUM_OWN9_FD9_CROSS4_BIRTH_METADATA_JOIN')
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
    bafb_finite_own_outputs('close',closed,close_root);bafb_finite_own_outputs('zero',zeroed,zero_root)
    all_owned_rows=bafb_own_outputs['close']['output_files']+bafb_own_outputs['zero']['output_files']
    check(len(all_owned_rows)==len({row['path'] for row in all_owned_rows})==420 and set(bafb_own_outputs['windows'])=={row['path'] for row in all_owned_rows}
        and sum(row['bytes'] for row in all_owned_rows)==bafb_own_outputs['full_closed_namespace_bytes'], 'B54_WHOLE_CLOSED_OWN_OUTPUT_NAMESPACE_NO_MISSING_OR_EXTRA_WINDOW')
    preserved=failed['preserved_public_inputs'];windows=failed['original_input_metadata_closure'];frozen=closed['frozen_Source_and_original_M_S_control_metadata_preserved']
    check(preserved['root']=='H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs/20261024174817c66be6'
        and preserved['public_full_body_files']==closed['finite_public_full_body_count']==zeroed['finite_public_full_body_count']==316
        and preserved['other_files_named_metadata_only']==closed['other_files_metadata_only_count']==zeroed['other_Run_files_named_metadata_only_count']==11057
        and len(preserved['files'])==11373 and len(preserved['directories'])==5
        and sum('public_full_EOF_pin' in row for row in preserved['files'].values())==316
        and all(row.get('body','')=='NOT READ; NAMED METADATA ONLY' for row in preserved['files'].values() if 'public_full_EOF_pin' not in row)
        and closed['all_Run_named_metadata_namespace_preserved'] is zeroed['all_Run_and_close_named_metadata_namespace_preserved'] is True
        and closed['private_ENV_actor_cookie_upload_appchild_body_reads']==zeroed['private_ENV_actor_cookie_upload_appchild_body_reads']==closed['original_outer_M_S_raw_stream_body_reads']==zeroed['original_outer_M_S_raw_stream_body_reads']==0,
        'B54_ALL_ORIGINAL316_PUBLIC_AND11057_METADATA_PRESERVED_NO_ORIGINAL_RUN_BODY_REOPEN')
    check(set(frozen)=={'public','original_outer_raw_metadata_only','initial_prepare_control_metadata','original_root_public_control_pairs','complete_normal_Source_named_namespace'}
        and len(frozen['public'])==6 and len(frozen['original_root_public_control_pairs'])==137
        and len(frozen['original_outer_raw_metadata_only'])==4 and all(row['body']=='NOT READ' for row in frozen['original_outer_raw_metadata_only'].values())
        and set(frozen['initial_prepare_control_metadata']['created'])=={'argv','pid'}
        and set(frozen['initial_prepare_control_metadata']['terminal'])=={'pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256'}
        and frozen['initial_prepare_control_metadata']['created']['pid']==frozen['initial_prepare_control_metadata']['terminal']['pid']==25700
        and frozen['initial_prepare_control_metadata']['terminal']['native_exit']==frozen['initial_prepare_control_metadata']['terminal']['wait_returncode']==0,
        'B54_ORIGINAL137_ROOT_PLUS_DISTINCT_PREPARE138_CONTROLS_FROZEN_WITHOUT_REOPENING_BODIES')
    check(type(windows) is list and len(windows)==3101 and all(set(row)=={'path','bytes','sha256','named9','fd9','birthtime_ns','EOF','closed'}
        and row['EOF'] is row['closed'] is True and len(row['named9'])==len(row['fd9'])==9
        and row['bytes']==row['named9'][6]==row['fd9'][6]
        and [row['named9'][i] for i in (0,1,6,7)]==[row['fd9'][i] for i in (0,1,6,7)] for row in windows),
        'B54_ORIGINAL_SAVED_INPUT_WINDOWS_LIST_TRUE_EIGHT_FIELDS_NO_RUNTIME_REOPEN')
    input_by_path={}
    for row in windows:check(row==input_by_path.setdefault(row['path'],row),'B54_DUPLICATE_SAVED_INPUT_WINDOW_EXACT')
    check(len(input_by_path)==695,'B54_ALL_ORIGINAL_INPUT_WINDOW_UNIQUE_PATHS')
    original_root=Path(preserved['root'])
    for pair in frozen['original_root_public_control_pairs']:
        check(set(pair)=={'created','terminal'},'B54_ROOT_CONTROL_PAIR_DESCRIPTOR_SHAPE')
        for kind,row in pair.items():
            path=Path(row['path']);check(path.is_relative_to(original_root/'root-orchestration'),'B54_ROOT_CONTROLS_ONLY_METADATA_DESCRIPTORS')
            relative=path.relative_to(original_root).as_posix()
            check(preserved['files'][relative]['public_full_EOF_pin']==compact(row)
                and compact(input_by_path[row['path']])==compact(row),'B54_ALL137_CONTROL_PAIR_METADATA_PINS_JOIN_SAVED_PUBLIC_AND_WINDOW_NO_ORIGINAL_BODY')
    source_meta=frozen['complete_normal_Source_named_namespace']
    check(source_meta['root']=='H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source'
        and len(source_meta['files'])==4650 and len(source_meta['directories'])==7
        and source_meta['public_full_body_files']==9 and source_meta['other_files_named_metadata_only']==4641,
        'B54_COMPLETE_OLD_NORMAL_SOURCE_NAMED_METADATA_NAMESPACE_RETAINED')
    check(failed_index['counts']=={'close_native':90,'independent_zero_native':12,'mutations':5,'containers_removed':4,'network_removed':1,'volumes_removed':9,'current_residual':0,
        'original_root_controls':137,'separate_prepare_controls':1,'total_original_controls':138,'existing_public_full_body_reads':316,
        'other_Run_files_metadata_only':11057,'foreign_complete_safe_containers':53,'close_complete_outputs':367,'zero_complete_outputs':53},
        'B54_ORIGINAL_FINITE_COUNTS_EXACT_NOT_RECURSIVE_WHOLE')
    host_proofs=('prepare','register-initializer','initializer','register-driver','driver','register-paid-producer','paid-producer',
        'register-fulfillment-migration','fulfillment-migration','files-bootstrap','register-customer-prepare','customer-prepare','register-paid-after-c3',
        'register-fulfillment-normal','fulfillment-normal','authorize-fulfillment-cleanup','register-aftersale-review-normal','aftersale-review-normal')
    check(len(host_proofs)==18 and all('public_full_EOF_pin' in preserved['files']['evidence/'+proof+'-'+suffix+'.json']
        for proof in host_proofs for suffix in ('created','terminal')),'B54_ALL_ORIGINAL18_HOST_PUBLIC_PROOF_PAIRS_RETAINED_IN_SAVED_METADATA_NO_ORIGINAL_READ')
    return True

def validate_b54_actual_observation(pointer, result_pin, freeze_pin, m_pid, source_pin, read, decode, check):
    value=decode(read(pointer))
    check(value['status']=='ACTUAL_R3_M_NATIVE0_SMALL_PUBLIC_BINDING_ONLY_NOT_FULL_M_READER_ACCEPTANCE'
        and value['actual_M_PID']==m_pid==16792 and value['native_exit_wait']==0
        and value['actual_RESULT']==result_pin and value['actual_freeze']==freeze_pin and value['Core_PINS']==source_pin,
        'B54_ORIGINAL_TRUE_M_PID_AND_RESULT_FREEZE_SOURCE_BINDING_NOT_EXTERNAL_LAUNCHER')
    check(value['actual_RESULT_fields']==37 and value['actual_commit_stages']==25 and value['actual_native_operations']==1160
        and value['actual_all_native0_empty_stderr'] is True and value['actual_committed_files']==313
        and value['actual_union']==10886 and value['actual_source_counts']=={'driver':10,'customer':10865,'paid':10866,'fulfillment':10881,'aftersale_review':10885,'worker':10886},
        'B54_ROOT_OBSERVED_M37_25_1160_ONLY_BINDING_NOT_READER_ORACLE')
    check(value['Root_process_only_git_config']=={'GIT_CONFIG_COUNT':'1','GIT_CONFIG_KEY_0':'core.longpaths','GIT_CONFIG_VALUE_0':'true'}
        and value['first_failed_longpath_read_now_actual0'] is True
        and value['source_only']=={'Run_created':False,'App_DB_HTTP_business':'NOT RUN','SourceTree_copy_not_installation':True}
        and value['complete_two_M_readers']=='NOT_RUN' and value['normal_S']=='NOT_RUN',
        'B54_OBSERVATION_FROZEN_PRE_READER_SOURCE_ONLY_SCOPE_NO_S_OR_BUSINESS')
    check([row['pid'] for row in value['inner_and_outer_terminals']]==[33232,40920,16792],
        'B54_KEEP_ORGANIZED_LAUNCHER_AND_REAL_M_PIDS_DISTINCT')
    return True

def derive_complete_b54_expected(values, pub, read, decode, check):
    """Independently replay every original baseline/commit overlay; no worktree-body read."""
    compact=lambda row:{key:row[key] for key in ('bytes','sha256')}
    context=pub['finite_contexts']
    rows=[decode(line) for line in read(context['baseline']['inventory']).splitlines()]
    original={row['path']:{key:row[key] for key in ('path','bytes','sha256')} for row in rows}
    check(len(rows)==len(original)==15486,'B54_ROOT_COMPLETE_ORIGINAL15486_BASELINE')
    expected=dict(original);first={};chain=values['COMMIT_CHAIN']
    check(len(chain)==25 and chain==context['commit_chain'],'B54_ROOT_FULL25_SOURCE_CHAIN')
    for step in chain:
        for row in step['files']:
            name=row['path']
            check(row['before']==(compact(expected[name]) if name in expected else None),'B54_ROOT_EVERY_STAGE_TRUE_BEFORE_OVERLAY')
            first.setdefault(name,compact(original[name]) if name in original else None)
            expected[name]={'path':name,**row['after']}
    expected=dict(sorted(expected.items()))
    changes=[{'path':name,'before':first[name],'after':compact(expected[name])} for name in sorted(first)]
    committed=[expected[row['path']] for row in changes]
    additions=sorted(set(expected)-set(original));requirements=values['SOURCE_REQUIREMENT_PATHS']
    check(len(expected)==15752 and len(committed)==313 and len(requirements)==318 and len(additions)==266
        and changes==context['committed_changes'] and committed==context['committed_files']
        and additions==values['NEW_TRACKED_PATHS']==context['new_tracked_paths']
        and [row['path'] for row in committed]==values['COMMITTED_PATHS']
        and pub['repository_Source_requirements']==[expected[name] for name in requirements],
        'B54_ROOT_COMPLETE15752_CUMULATIVE313_REQUIRED318_ADDITIONS266')
    check(sum(row['bytes'] for row in expected.values())==341961431,'B54_ROOT_ALL_TRACKED341961431_EXPECTED_SOURCE_BYTES')
    return expected

parser = argparse.ArgumentParser(description='Read only the genuinely completed successful current b54 r3 normal S receipts')
parser.add_argument('--run-id', required=True)
parser.add_argument('--outer-pid', required=True, type=positive_pid)
parser.add_argument('--prepare-pid', required=True, type=positive_pid)
parser.add_argument('--context-bytes', required=True, type=int)
parser.add_argument('--context-sha256', required=True)
parser.add_argument('--authorize-completed-s0', action='store_true', help='Root has authorized readback after original S native0/wait0')
args = parser.parse_args()
if re.fullmatch(r'[0-9]{14}[a-f0-9]{6}', args.run_id) is None or args.run_id in ('20261023124755f45a9e', '202610231609185fbd23', '20261023182634096f57', '2026102321491304a643', '2026102402025362a539', '2026102403521460e674', '2026102406240181bf85', '20261024114233c6e65e', '20261024174817c66be6'):
    parser.error('Supply the actual new completed successful Run id; retired failed0c13, failed28dc, failed175f and failed6e Runs are forbidden')
if args.outer_pid == args.prepare_pid:
    parser.error('Supply the distinct original outer and initial-prepare PIDs')
if not args.authorize_completed_s0:
    parser.error('Root must authorize completed original S native0/wait0 before this reader runs')
if args.context_bytes <= 0 or re.fullmatch('[a-f0-9]{64}', args.context_sha256) is None:
    parser.error('Supply the genuine completed Run context whole byte count and SHA256')

org = Path('H:/天俪商城/artifacts/project-organization-20261023')
workspace = org.parents[1]
base = workspace / 'artifacts/ms4-worker-normal-source-preparation-r1'
source = Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source')
run = workspace / 'artifacts/ms4-aftersale-review-normal-runs' / args.run_id
archive = run / 'root-orchestration'
assert (source / 'launcher/normal-start.terminal.json').is_file(), 'Original S is not terminal; do not restart or run this reader yet'
r = Finite()
reader_source_pin = fp(r.read(Path(__file__)))
reviewed_reader = org / 'current28dc-complete-normal-S-readback-source-r1/candidate/readback-current28dc-complete-normal-S.py'
r.read(reviewed_reader, {'bytes': 12417, 'sha256': 'f76984c14e1bf004edf73d885093b6bfbecd2ff302316facfbed416f1e76a02e'})
reviewed_static = r.json(org / 'current28dc-normal-S-reader-source-independent-r1/REPORT.json',
    {'bytes': 2868, 'sha256': '577dd6c704da17e9b2af45d075c3edb7f6a436f2acd2af983b34ca27442a57cc'})
assert reviewed_static['status'] == 'PASS_INDEPENDENT_STATIC_CURRENT28DC_COMPLETE_NORMAL_S_READER_SOURCE_ONLY'
# Preserve fixed ancestor qualification; bind the full current Root Source separately.
r.read(Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-normal-S-readers-source-r2/candidate/root-reader.py'), {'bytes': 176252, 'sha256': 'c997e6bb4ccd4712bbc3b86e7128a9d8a7324d2b86f5205d85d491f60bdb7da7'})

SOURCE_PINS = {'bytes': 14595, 'sha256': '36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c'}
ROOT_SOURCE_PINS = {'bytes': 358, 'sha256': 'a5aba6db21420553b0cb1d9247512c0be8f5383081f41b8bf0730c46ac4f9863'}
M_RESULT = {'bytes': 265006271, 'sha256': '437984dd506e48300396b17ada94eda51d8c329c5d2e66463137b41e4adf96c3'}
FREEZE = {'bytes': 14519075, 'sha256': 'caceceaab16b56d1177aeb8d423796d324c50a654a888526f5a0769211e3d5f0'}
M_ROOT_READBACK = {'bytes': 4845, 'sha256': '4be1804d5487a3cf27e09993ca174f93320335984ad6c4b465f272ac69feeb60'}
PROFILE_SOURCE_PINS9 = {'README.md': {'bytes': 92239, 'sha256': '8ef95e9271dc027b08592f84f05ecc57668f2de5f0656bb28c35cbf37cdabd88'}, 'compose.yml': {'bytes': 13115, 'sha256': '22aed47453c67499c361efa57b24c954433fb5a68863a07ede5896f845451b7b'}, 'ms4-host.py': {'bytes': 357359, 'sha256': '20a97efeee9cf828c151bfb9f4f46b6ce04f4f304c90f439a65ea5672804f862'}, 'ms4-initialize.php': {'bytes': 91460, 'sha256': '13982ebc8a4ac9db52490040ada710128122698e941a14db4156422703e9918f'}, 'seed-source-inverse.json': {'bytes': 639, 'sha256': 'c35433231ddcff315931e270d737a022a14818f8aac8c05ed67c3b155e88e14a'}, 'interface-v1.json': {'bytes': 110526, 'sha256': '13c0a6672fefb30a09b40246dcc819b8d7aa2d9f75fee6a588ca3655efb03824'}, 'source-derivation.json': {'bytes': 640200, 'sha256': '180d06183f984ee59b2febe9525c38b8957fcf18f2687c15e2dd20b1f4d404f7'}, 'normal-close-ms4-fulfillment.py': {'bytes': 48793, 'sha256': 'bf490a612f4d0dba47578b1b53948939410c87bcb516bd62f21af0e1f5210c87'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 302705, 'sha256': '61816d3dbef3a62579e47846901754b874ddf25321d49a528b9648f467da3d20'}}
actual_source_pins = r.json(source / 'PINS.json', SOURCE_PINS)
assert actual_source_pins['source_sha'] == 'b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
actual_root_pins = r.json(source / 'validation/PINS.json', ROOT_SOURCE_PINS)
actual_M = r.json(source / 'validation/materialize-freeze-assets-r1/RESULT.json', M_RESULT)
assert actual_M['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
assert actual_M['source_sha'] == 'b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
assert actual_M['repo'] == (workspace / 'worktrees/ms4-language-cache-normal-check').as_posix()
assert actual_M['profile'] == (source / 'validation/actual-profile').as_posix()
assert actual_M['freeze'] == (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix()
assert actual_M['freeze_sha256'] == FREEZE['sha256']
m_readback = r.json(Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r2/REPORT.json'), M_ROOT_READBACK)
assert m_readback['status'] == 'PASS_ACTUAL_FULL_CURRENTB54_MATERIALIZATION_ONLY' and m_readback['source_sha'] == 'b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
assert m_readback['actual_RESULT'] == {'path': (source / 'validation/materialize-freeze-assets-r1/RESULT.json').as_posix(), **M_RESULT}
assert m_readback['actual_freeze'] == {'path': (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix(), **FREEZE}
CURRENT_M_RESULT_KEYS36 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement', 'current_175f_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']
CURRENT_M_RESULT_KEYS37 = CURRENT_M_RESULT_KEYS36 + ['current_6e3850_paid_producer_failure_retirement']
assert set(actual_M) == set(CURRENT_M_RESULT_KEYS37) and len(actual_M) == 37
assert len(actual_M['commit_chain']) == 25 and len(actual_M['native_operations']) == 1160
assert m_readback['M_RESULT_fields'] == 37 and m_readback['historical_M_RESULT_fields'] == 36 and m_readback['steps'] == 25 and m_readback['native_operations'] == 1160

for name, expected in PROFILE_SOURCE_PINS9.items():
    r.read(source / 'validation/actual-profile' / name, expected)

# Full actual M/source/metadata qualification; no path in its metadata maps is dereferenced.
source_pins = actual_source_pins
reader_bytes = lambda pointer: r.read(Path(pointer['path']), pointer)
historical24_source = Path(source_pins['before_source']['path']).parent
historical24_source_pins = r.json(Path(source_pins['before_source']['path']), source_pins['before_source'])
separate_author_maps = validate_reader_source_inheritance(historical24_source, historical24_source_pins, reader_bytes, loads, need)
current_author_maps = validate_current_b54_metadata(source, source_pins, reader_bytes, loads, need)
SEALED_SOURCE_PINS = SOURCE_PINS
result = actual_M
actual = source / 'validation/materialize-freeze-assets-r1'
ninth = result['current_28dc_paid_producer_failure_retirement']
materializer_source_raw = r.read(source / 'materializer/prepare.py', source_pins['files']['materializer/prepare.py'])
source_constants = {}
for materializer_node in ast.parse(materializer_source_raw).body:
    if isinstance(materializer_node,ast.Assign) and len(materializer_node.targets)==1 and isinstance(materializer_node.targets[0],ast.Name):
        try: source_constants[materializer_node.targets[0].id] = ast.literal_eval(materializer_node.value)
        except (ValueError,TypeError): pass
historical24_values = dict(source_constants)
historical24_values.update(source_constants['HISTORICAL_TWENTYFOUR_CONSTANTS'])
historical24_publication = r.json(Path(source_constants['HISTORICAL_TWENTYFOUR_PUBLICATION']['path']), source_constants['HISTORICAL_TWENTYFOUR_PUBLICATION'])
historical23_values = dict(historical24_values)
historical23_values.update(source_constants['HISTORICAL_TWENTYTHREE_CONSTANTS'])
historical23_publication = r.json(Path(source_constants['HISTORICAL_TWENTYTHREE_PUBLICATION']['path']), source_constants['HISTORICAL_TWENTYTHREE_PUBLICATION'])
historical_chain = historical23_values['COMMIT_CHAIN'][:-2]
assert len(historical_chain) == 21 and result['commit_chain'] == source_constants['COMMIT_CHAIN'] and historical23_values['COMMIT_CHAIN'] == result['commit_chain'][:23]
publication = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/PROFILE-PUBLICATION.json'), {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/PROFILE-PUBLICATION.json', 'bytes': 1473227, 'sha256': '2b49f51ed5893ea48d499975b87be830a824ac1084e22aba6beb11bb46918f7a'})
full_expected = derive_complete_b54_expected(source_constants, publication, reader_bytes, loads, readback_check)
profile_pins = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/PROFILE-PINS.json'), {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/PROFILE-PINS.json', 'bytes': 1518, 'sha256': '2a0ceee3e34e113cd24779695d288061e0bcdd18f8a730eacdd0a456f5ced9bd'})
assert set(actual_root_pins['members']) == {'run.py', 'DESIGN-TASKS.md'} and len(profile_pins['members']) == 10
for name, wanted in actual_root_pins['members'].items():
    r.read(source / 'validation' / name, wanted)
for name, wanted in profile_pins['members'].items():
    r.read(source / 'validation' / name if name == 'PROFILE-PUBLICATION.json' else source / 'validation/actual-profile' / name, wanted)
assert m_readback['root_source_members'] == 14 and m_readback['complete_source_snapshot_rows'] == 31504 and m_readback['tracked_per_checkout'] == 15752 and m_readback['cumulative'] == 313 and m_readback['source_group_union'] == 10886
assert m_readback['all_prior_nine_whole_encoded_objects_preserved'] is True and m_readback['tenth_actual_failed_A_whole_pinned_authority_and_result_join_preserved'] is True
peer_M = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/REPORT.json'), {'bytes': 3537913, 'sha256': 'b91de4c4d239cac6118e29b6027255c5830222fbf5ebb4a9bceea8c11703e30a'})
peer_M_PINS = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/PINS.json'), {'bytes': 509, 'sha256': 'd75f2d59b80fbe036b88cbe1e38b182fd72be6a040322c67822a15ce9790663f'})
assert peer_M_PINS['files']['REPORT.json'] == {'bytes': 3537913, 'sha256': 'b91de4c4d239cac6118e29b6027255c5830222fbf5ebb4a9bceea8c11703e30a'}
assert peer_M['status'] == 'PASS_COMPLETE_ACTUAL_CURRENTB54_M37_SOURCE_MATERIALIZATION_READBACK_ONLY' and peer_M['source_sha'] == result['source_sha']
assert peer_M['actual_RESULT'] == m_readback['actual_RESULT'] and peer_M['actual_freeze'] == m_readback['actual_freeze']
assert peer_M['actual_M_native_operations'] == 1160 and peer_M['M_RESULT_current_fields'] == 37 and peer_M['M_RESULT_historical_fields_preserved'] == 36
assert peer_M['source_program_members'] == 18 and peer_M['Root_source_full_bytes_and_native9'] == 14 and peer_M['M_source_full_bytes_and_native9'] == 3 and peer_M['complete_dual_source_metadata_rows'] == 31504
assert peer_M['complete_normal_source_union'] == 10886 and peer_M['eleven_retirement_facts_whole_joined_without_overwriting_old_ten'] is True and peer_M['all_finite_windows_closed'] is True
assert peer_M['all1160_created_terminal_and_double_raw_streams_complete'] is True and m_readback['all_original_native_created_terminal_and_streams_join'] is True
assert peer_M['actual_outer_pid'] == m_readback['outer_pid'] == 16792 and peer_M['actual_outer_native_exit_wait'] == m_readback['outer_native_exit_wait'] == 0
dual_M_qualification = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R108-B54-M-DUAL-READERS-ACTUAL-ROOT-ACCEPTANCE.json'), {'bytes': 6251, 'sha256': '2ee996cc88a555f608a056d1d69ae64a71658a6bc31ed7270a53a2e3c8f687d7'})
assert dual_M_qualification == {'status': 'ROOT_COMPLETE_ACTUAL_BOTH_FULL_M_READERS0_PUBLIC_OUTPUTS_AND_ALL_INPUT_WINDOWS_ACCEPTED', 'actual_M_PID': 16792, 'actual_RESULT': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/materialize-freeze-assets-r1/RESULT.json', 'bytes': 265006271, 'sha256': '437984dd506e48300396b17ada94eda51d8c329c5d2e66463137b41e4adf96c3'}, 'actual_freeze': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/materialize-freeze-assets-r1/freeze-binding.json', 'bytes': 14519075, 'sha256': 'caceceaab16b56d1177aeb8d423796d324c50a654a888526f5a0769211e3d5f0'}, 'reader_source_PINS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-materialization-readers-source-r2/PINS.json', 'bytes': 6443, 'sha256': '6e0e7577fc3629d8868e079cbc0def94afc64808e1a4cb8ae1483c6c521e47cc'}, 'readers': [{'reader': 'root', 'PID': 15196, 'native_exit_wait': 0, 'original_session_consumed': 11074, 'terminal_chunk': '3123c6'}, {'reader': 'peer', 'PID': 52128, 'native_exit_wait': 0, 'original_session_consumed': 31636, 'terminal_chunk': 'dc4474'}], 'Root_reader_INPUT_windows': 6695, 'peer_reader_INPUT_windows': 6745, 'all_saved_input_windows_native9_fd9_cross4_birth_held': True, 'all_own14_public_outputs_full_EOF_namespace_and_streams_verified': True, 'complete_Root14_M3_37_25_1160_11whole_SourceTree15752_dual31504_fullscope': True, 'historical24_23_22_21_and_current25_profile9_bafbfinite420_metadata48_domains_retained': True, 'whole_callback33091_authority33085_all_intersections_joined': True, 'SourceTree_copy_only_not_installation': True, 'normal_S_App_DB_HTTP_business': 'NOT_RUN', 'M_Source_complete_readback_accepted': True, 'next_scope': 'ONE_ORIGINAL_R3_COMPLETE_NORMAL_S_MAY_BE_SEPARATELY_ADMITTED', 'Root_report': {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r2/REPORT.json', 'bytes': 4845, 'sha256': '4be1804d5487a3cf27e09993ca174f93320335984ad6c4b465f272ac69feeb60'}, 'peer_report': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/REPORT.json', 'bytes': 3537913, 'sha256': 'b91de4c4d239cac6118e29b6027255c5830222fbf5ebb4a9bceea8c11703e30a'}, 'own_public_outputs': [{'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r2/INPUTS.json', 'bytes': 8334156, 'sha256': '4734730744935ed51793cf471a7a474f9c946af8958b1098338e5768ff2c2773'}, {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r2/REPORT.json', 'bytes': 4845, 'sha256': '4be1804d5487a3cf27e09993ca174f93320335984ad6c4b465f272ac69feeb60'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/CHECKS.json', 'bytes': 17985, 'sha256': '988421010aadc24cfc543c1bcee649787a05de0582e95e8b48acde550a5bc602'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/INPUTS.json', 'bytes': 10273815, 'sha256': '664576d08e4b59d86c4870f60be044540a70902fa17f217bf0201ee8e5e04214'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/PINS.json', 'bytes': 509, 'sha256': 'd75f2d59b80fbe036b88cbe1e38b182fd72be6a040322c67822a15ce9790663f'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/REPORT.json', 'bytes': 3537913, 'sha256': 'b91de4c4d239cac6118e29b6027255c5830222fbf5ebb4a9bceea8c11703e30a'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r108-b54-m-root.created.json', 'bytes': 1006, 'sha256': '4e0cd7b17c346ea5518064d6aa3f73a23601aa69f1a128e73b977027cb20b5f8'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r108-b54-m-root.terminal.json', 'bytes': 1547, 'sha256': '666441f468cc2497b9144d1826d4d9cd24e2adec7b5d20231a4c90fb002140b5'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r108-b54-m-root.stdout', 'bytes': 343, 'sha256': 'dbe38db615be412f0765a97746384af7270053bb895215c25c37eef3bae3dec8'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r108-b54-m-root.stderr', 'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r108-b54-m-peer.created.json', 'bytes': 866, 'sha256': 'b49277d32a4fc26f2be76b1988880fd55cd3fe53e894627b0d9acccd3f484e07'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r108-b54-m-peer.terminal.json', 'bytes': 1407, 'sha256': 'c87491998070277b7fbc3ca8ded17d5ce9770903f815200d4026a44477c943c5'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r108-b54-m-peer.stdout', 'bytes': 274, 'sha256': '00c868f444b99035f58a376ccbb7724516a82c28ecbddec104e1b6cb57c996e6'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r108-b54-m-peer.stderr', 'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}], 'completed_ns': 1792886079547683900}
assert dual_M_qualification['Root_report'] == {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r2/REPORT.json', 'bytes': 4845, 'sha256': '4be1804d5487a3cf27e09993ca174f93320335984ad6c4b465f272ac69feeb60'} and dual_M_qualification['peer_report'] == {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/REPORT.json', 'bytes': 3537913, 'sha256': 'b91de4c4d239cac6118e29b6027255c5830222fbf5ebb4a9bceea8c11703e30a'} and {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-complete-materialization-independent-readback-r2/PINS.json', 'bytes': 509, 'sha256': 'd75f2d59b80fbe036b88cbe1e38b182fd72be6a040322c67822a15ce9790663f'} in dual_M_qualification['own_public_outputs']
tenth_pointer = source_constants['CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT']
assert publication['finite_contexts']['current_175f_paid_producer_failure_retirement'] == tenth_pointer
tenth = r.json(Path(tenth_pointer['path']), tenth_pointer)
assert result['current_175f_paid_producer_failure_retirement'] == tenth
assert tenth['scope'] == 'TENTH_CURRENT175F_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY'
assert tenth['run_id'] == '20261023182634096f57' and tenth['source_sha'] == '175f3cd6183894185656a2974579e1be39eed7a7'
assert tenth['original_S_native_exit'] == tenth['paid_producer_native_exit'] == 1 and tenth['business_acceptance'] == 'INCOMPLETE'
assert tenth['M35_preserved'] is True and tenth['M_native_count'] == 1088 and tenth['M_commit_count'] == 19 and tenth['M_complete_source_metadata_rows'] == 31496
historical_nine_fields = ('prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement')
assert set(tenth['historical_nine_retirements']) == set(tenth['historical_nine_whole_encoded_pins']) == set(historical_nine_fields)
for field in historical_nine_fields:
    assert result[field] == tenth['historical_nine_retirements'][field]
    assert fp(enc(result[field])) == tenth['historical_nine_whole_encoded_pins'][field]
assert tenth['historical_nine_retirements']['current_28dc_paid_producer_failure_retirement'] == ninth
assert set(tenth['forbidden_environment_metadata']) == {'rows', 'body_reads', 'hashes_claimed'}
forbidden_metadata = tenth['forbidden_environment_metadata']['rows']
assert len(forbidden_metadata) == 2 and tenth['forbidden_environment_metadata']['body_reads'] == 0 and tenth['forbidden_environment_metadata']['hashes_claimed'] is False
for row in forbidden_metadata:
    assert set(row) == {'path', 'stat9', 'birthtime_ns', 'file_attributes'} and len(row['stat9']) == 9

eleventh_pointer = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-eleventh-retirement-authority-preparation-r1/CURRENT-6E3850-RETIREMENT-COMPLETE.json', 'bytes': 142275335, 'sha256': '835c2cbff9ce7ccf5412c1dd3b91d75110d968cecdd08b0139ebcd98c2fd4dda'}
assert publication['finite_contexts']['current_6e3850_paid_producer_failure_retirement'] == eleventh_pointer
eleventh = r.json(Path(eleventh_pointer['path']), eleventh_pointer)
assert result['current_6e3850_paid_producer_failure_retirement'] == eleventh
assert eleventh['scope'] == 'ELEVENTH_CURRENT6E3850_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY'
assert eleventh['run_id'] == '2026102321491304a643' and eleventh['source_sha'] == historical_chain[-1]['parent'] == '6e3850fe210b2b1e99b2a33baa68123e34aefab6'
assert eleventh['original_S_native_exit'] == eleventh['paid_producer_native_exit'] == 1 and eleventh['business_acceptance'] == 'INCOMPLETE'
assert eleventh['M36_preserved'] is True and eleventh['M_native_count'] == 1104 and eleventh['M_commit_count'] == 20 and eleventh['M_complete_source_metadata_rows'] == 31496
historical_ten_fields = historical_nine_fields + ('current_175f_paid_producer_failure_retirement',)
assert set(eleventh['historical_ten_retirements']) == set(eleventh['historical_ten_whole_encoded_pins']) == set(historical_ten_fields)
for field in historical_ten_fields:
    assert actual_M[field] == eleventh['historical_ten_retirements'][field]
    assert fp(enc(actual_M[field])) == eleventh['historical_ten_whole_encoded_pins'][field]
assert eleventh['historical_ten_retirements']['current_175f_paid_producer_failure_retirement'] == tenth
assert set(eleventh['forbidden_environment_metadata']) == {'rows', 'body_reads', 'hashes_claimed'}
eleventh_forbidden_metadata = eleventh['forbidden_environment_metadata']['rows']
assert len(eleventh_forbidden_metadata) == 2 and eleventh['forbidden_environment_metadata']['body_reads'] == 0 and eleventh['forbidden_environment_metadata']['hashes_claimed'] is False
for row in eleventh_forbidden_metadata:
    assert set(row) == {'path', 'stat9', 'birthtime_ns', 'file_attributes'} and len(row['stat9']) == 9
assert m_readback['all_prior_ten_whole_encoded_objects_preserved'] is True and m_readback['eleventh_actual_failed_A_whole_pinned_authority_and_result_join_preserved'] is True

assert result['selected9'] == publication['selected9']

profile_source = Path(source_constants['HISTORICAL_TWENTYONE_PUBLICATION']['path']).parent.parent; profile_values = historical23_values
profile_source_pins = r.json(profile_source/'PINS.json', {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentfa2088-profile-r2-normal-preparation-r1/normal-source/PINS.json', 'bytes': 44270, 'sha256': '80a00951c5398c9384df5ca4bbb52156182d3e86968290ca9c88f89802e99dfe'})
profile_publication = r.json(Path(source_constants['HISTORICAL_TWENTYONE_PUBLICATION']['path']),source_constants['HISTORICAL_TWENTYONE_PUBLICATION'])
profile_bytes = lambda pointer: r.read(Path(pointer['path']), pointer)
profile_json = lambda pointer: loads(profile_bytes(pointer))

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
assert validate_current_warm_profile(historical24_values, historical24_publication, profile_bytes, loads, need)
assert validate_current_d0a5_finite(historical24_values, historical24_publication, profile_bytes, loads, need)
assert validate_current_b54_source(source_constants, publication, historical24_publication, full_expected, profile_bytes, loads, need)
assert validate_current_b54_profile(source_constants, publication, historical24_publication, full_expected, profile_bytes, loads, need)
assert validate_current_bafb_finite(source_constants, publication, historical24_publication, profile_bytes, loads, need)
assert validate_b54_actual_observation({'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R106-B54-R3-M-ACTUAL-PUBLIC-OBSERVATION.json', 'bytes': 2928, 'sha256': '497e6066de86516dae075d6a1f5a9ff1f404dca6e5b4165cb98dd03145800cbd'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/materialize-freeze-assets-r1/RESULT.json', 'bytes': 265006271, 'sha256': '437984dd506e48300396b17ada94eda51d8c329c5d2e66463137b41e4adf96c3'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/materialize-freeze-assets-r1/freeze-binding.json', 'bytes': 14519075, 'sha256': 'caceceaab16b56d1177aeb8d423796d324c50a654a888526f5a0769211e3d5f0'}, 16792, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/PINS.json', 'bytes': 14595, 'sha256': '36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c'}, reader_bytes, loads, need)
assert m_readback['current_profile_revision_and_baseline9_inverse_verified'] is peer_M['current_profile_revision_and_baseline9_inverse_verified'] is True
assert m_readback['finite_profile_attempt_not_twelfth_whole'] is peer_M['finite_profile_attempt_not_twelfth_whole'] is True



CALLBACK_ROOT_POINTER = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R106-B54-CALLBACK-ACTUAL-ROOT-READBACK.json', 'bytes': 4131, 'sha256': '2426a231bff2d7655b8dede264be984629a1a28ef1e5edbf3f502bede4445d63'}
CALLBACK_ROOT_EXPECTED = {'schema': 1, 'status': 'ROOT_ACTUAL_COMPLETE_B54_R3_CALLBACKS0_PUBLIC_READBACK0_ADMIT_FULL_M_ONLY', 'actual_REPORT': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-original-source-callback-actual-r2/REPORT.json', 'bytes': 5609, 'sha256': 'fdcc949cea39d97b78c18936b2d2add15eb55d047274c77b114eef0c55ff3b14'}, 'actual_INPUTS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-original-source-callback-actual-r2/INPUTS.json', 'bytes': 53796610, 'sha256': 'f8aa080d2feac4070d0539fa920ca9c979399f109e74578fb7445c29e0caaaac'}, 'original_tool_session': 1808, 'original_handle_consumed_terminal_chunk': '220459', 'actual_PID': 44748, 'native_exit': 0, 'wait_returncode': 0, 'complete_streams_join': True, 'M_and_S_each_full_checks': 11940693, 'complete_return_objects_equal': True, 'canonical_return_pin': {'bytes': 198655490, 'sha256': 'ddfd325dfeb7041446364edbffcfb346dad8984994d357a9d1af5f38bcc8ffda'}, 'actual_source_paths': 33091, 'all_original_first_EOF_native9_cross4_closed_windows_verified': True, 'original_cache_semantics_preserved_no_second_disk_read_claim': True, 'new_finite_own_output_registry_counts': {'close': 367, 'zero': 53}, 'old_complete_R2_graph_paths_retained_except_exact_bootstrap6_and_profile9_root_rebind': True, 'R2_actual_callback_Root_proof': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R104-B54-CALLBACK-ACTUAL-ROOT-READBACK.json', 'bytes': 3908, 'sha256': '0edf006c5778a45164383f55c16b0aef7ec6cd5ee6af87d18ed5fb71103298e3'}, 'original_bafb_Run_body_paths_absent': True, 'mutable_original_C_D_not_consumed': True, 'callback_native_calls': 0, 'M_body_S_main_App_business': 'NOT RUN', 'A0': False, 'normal_Source_PINS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/PINS.json', 'bytes': 14595, 'sha256': '36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c'}, 'duration_seconds': 117.1704101, 'Root14_M3_independent17_files_held_after_actualcallbacks': True, 'Root_all_windows_ended': True, 'actual_public_outputs': [{'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-original-source-callback-actual-r2/REPORT.json', 'bytes': 5609, 'sha256': 'fdcc949cea39d97b78c18936b2d2add15eb55d047274c77b114eef0c55ff3b14'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-original-source-callback-actual-r2/INPUTS.json', 'bytes': 53796610, 'sha256': 'f8aa080d2feac4070d0539fa920ca9c979399f109e74578fb7445c29e0caaaac'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r106-b54-callbacks.created.json', 'bytes': 402, 'sha256': '47a0333e9ac6d2c18a79cf32605486f820c49271d7cd639ca2ab7d2e69b88887'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r106-b54-callbacks.terminal.json', 'bytes': 950, 'sha256': '9a4f4893caacadad1425916fc32daedef9207e2def267bbf299f467d3bbf80fa'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r106-b54-callbacks.stdout', 'bytes': 4420, 'sha256': '2ff89ae95be93b0fdf5a29ddbf7dc426a66322488486bcf810eefcdfb8ca70aa'}, {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/r106-b54-callbacks.stderr', 'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}], 'Root_reader_first_attempt_note': 'Original saved33091 window checks passed; first reader assumed graph delta bootstrap6 only. Actual declared delta is bootstrap6 plus profile9=15, all exact R2-to-R3 root rebind. Reader corrected without candidate change or callback rerun.', 'completed_ns': 1792882889705829600}

callback_root = r.json(Path(CALLBACK_ROOT_POINTER['path']), CALLBACK_ROOT_POINTER)
assert callback_root == CALLBACK_ROOT_EXPECTED
callback_report = r.json(Path(callback_root['actual_REPORT']['path']), callback_root['actual_REPORT'])
callback_inputs = r.json(Path(callback_root['actual_INPUTS']['path']), callback_root['actual_INPUTS'])
assert callback_report['status'] == 'PASS_ORIGINAL_COMPLETE_SOURCE_AUTHORITY_CALLBACKS_ONLY'
assert callback_report['source_PINS'] == {'path': (source / 'PINS.json').as_posix(), **SEALED_SOURCE_PINS}
assert callback_report['stages'] == 25 and callback_report['retirements'] == 11 and callback_report['tracked'] == 15752 and callback_report['committed'] == 313
assert callback_report['complete_return_objects_equal'] is True and callback_report['callback_native_calls'] == 0 and callback_report['M_body_or_S_main_or_App_business'] == 'NOT RUN' and callback_report['A0'] is False
assert callback_root['canonical_return_pin'] == callback_report['complete_canonical_return_pin']
assert callback_root['M_and_S_each_full_checks'] == 11940693
assert set(callback_report['qualification']) == {'M', 'S'}
for label, item in callback_report['qualification'].items():
    assert item['module'] == label and item['manual_dependency_injection'] is False and item['unbound_globals'] == [] and item['pure_source_checks'] == 11940693
assert set(callback_inputs) == {'files', 'cache_semantics', 'callback_invocations'} and callback_inputs['callback_invocations'] == 2
assert callback_inputs['cache_semantics'] == 'ORIGINAL_FIRST_FULL_EOF_READ_ONCE_PER_PATH_THEN_CACHE'
callback_files = callback_inputs['files']
assert len(callback_files) == callback_report['actual_input_paths'] == 33091
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
assert len(authority_names) == 33085
authority_path = actual / 'AUTHORITY-INPUT-SNAPSHOT.json'
authority_raw = r.read(authority_path)
authority_snapshot = loads(authority_raw)
assert set(authority_snapshot) == authority_names
for path, row in authority_snapshot.items():
    assert set(row) == {'path', 'bytes', 'sha256', 'stat9'} and row['path'] == path
    assert {key: row[key] for key in ('bytes', 'sha256')} == {key: callback_files[path][key] for key in ('bytes', 'sha256')}
    assert row['stat9'] == callback_files[path]['native9'] and len(row['stat9']) == 9 and row['stat9'][6] == row['bytes']
for row in forbidden_metadata + eleventh_forbidden_metadata:
    assert row['path'] not in callback_files and row['path'] not in authority_snapshot
assert len(source_pins['files']) == 33 and len(source_pins['source_members18']) == 18
assert {path for path in authority_names if path.startswith(source.as_posix()+'/')} == {(source/'validation/actual-profile'/row['path']).as_posix() for row in publication['selected9']}
author_intersections = validate_separate_author_intersections(authority_snapshot, separate_author_maps, need)
author_intersections.update(validate_current_b54_intersections(authority_snapshot, current_author_maps, need))
assert callback_report['group_counts'] == result['source_counts']

assert m_readback['M_complete_authority_inputs'] == peer_M['exact_M_authority_projection_rows'] == len(authority_snapshot) == 33085
assert m_readback['callback_complete_inputs'] == peer_M['complete_callback_graph_rows'] == len(callback_files) == 33091
assert m_readback['authority_snapshot'] == {'path': authority_path.as_posix(), **fp(authority_raw)}
assert m_readback['forbidden_ENV_body_reads'] == 0 and peer_M['complete_callback_REPORT'] == callback_root['actual_REPORT'] and peer_M['complete_callback_INPUTS'] == callback_root['actual_INPUTS']


# Full current M Source and metadata qualification; no worktree body dereference.
for name in source_pins['source_members18']:
    r.read(source / name, source_pins['files'][name])
root_snapshot_pointer = actual_M['root_source_snapshot']
root_snapshot = r.json(Path(root_snapshot_pointer['path']), root_snapshot_pointer)
root_names = [(source / 'validation' / name).as_posix() for name in ['DESIGN-TASKS.md', 'run.py', 'PINS.json', 'PROFILE-PINS.json', 'PROFILE-PUBLICATION.json']] + [(source / 'validation/actual-profile' / row['path']).as_posix() for row in publication['selected9']]
assert list(root_snapshot) == root_names and len(root_snapshot) == 14
for path, row in root_snapshot.items():
    assert row['path'] == path and len(row['stat9']) == 9 and row['stat9'][6] == row['bytes']
    r.read(Path(path), row)
    assert r.rows[str(Path(path))]['named_before'] == row['stat9']
materialize_inputs = actual_M['materialize_source_inputs']
assert list(materialize_inputs) == [(source / 'materializer' / name).as_posix() for name in ['PLAN.md', 'prepare.py', 'INVERSE.json']]
for path, row in materialize_inputs.items():
    assert row['path'] == path and len(row['stat9']) == 9 and row['stat9'][6] == row['bytes']
    r.read(Path(path), row)
    assert r.rows[str(Path(path))]['named_before'] == row['stat9']
frozen = r.json(source / 'validation/materialize-freeze-assets-r1/freeze-binding.json', FREEZE)
assert FREEZE['bytes'] <= 16777216 and m_readback['freeze_original_16MiB_limit_preserved'] is True
tree = frozen['source_tree']
assert list(tree) == ['schema', 'scope', 'source_sha', 'requested_repo_source', 'volume_suffix', 'writer_destination', 'files', 'directories', 'file_count', 'directory_count', 'manifest_sha256']
assert type(tree['schema']) is int and tree['schema'] == 1 and tree['scope'] == 'COMPLETE_TRACKED_SOURCE_TREE'
assert tree['source_sha'] == frozen['source_sha'] == actual_M['source_sha'] and tree['requested_repo_source'] == actual_M['repo']
assert tree['volume_suffix'] == 'source-tree' and tree['writer_destination'] == '/run/audit-source-tree'
assert type(tree['file_count']) is int and tree['file_count'] == len(tree['files']) == 15752
assert type(tree['directory_count']) is int and tree['directory_count'] == len(tree['directories']) == 3880
paths = []; parents = set()
for row in tree['files']:
    assert type(row) is dict and list(row) == ['path', 'bytes', 'sha256']
    assert type(row['path']) is str and row['path'] and not re.search(r'[\x00-\x1f\x7f\\:]', row['path'])
    assert all(part not in ['', '.', '..', '.git'] for part in row['path'].split('/')) and row['path'] not in ['crmeb/.env', 'crmeb/public/install.lock']
    assert type(row['bytes']) is int and row['bytes'] >= 0 and type(row['sha256']) is str and re.fullmatch('[a-f0-9]{64}', row['sha256'])
    paths.append(row['path']); parts = row['path'].split('/')
    parents.update('/'.join(parts[:count]) for count in range(1, len(parts)))
assert paths == sorted(set(paths)) and tree['directories'] == sorted(parents)
assert {row['path']:row for row in tree['files']} == full_expected
manifest_raw = json.dumps({'files': tree['files'], 'directories': tree['directories']}, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
assert tree['manifest_sha256'] == hashlib.sha256(manifest_raw).hexdigest()
assert peer_M['source_tree_complete_freeze_plan'] == tree and peer_M['source_tree_copy_or_volume_created_by_M'] is False
assert m_readback['complete_tracked_source_tree_verified'] == {'file_count': tree['file_count'], 'directory_count': tree['directory_count'], 'manifest_sha256': tree['manifest_sha256'], 'scope': tree['scope'], 'Val_origin': actual_M['repo']}
dual_pointer = actual_M['complete_source_snapshot']
dual = r.json(Path(dual_pointer['path']), dual_pointer)
tree_roots = [workspace / 'worktrees/ms4-language-cache-normal', Path(actual_M['repo'])]
assert list(dual) == [(repo / name).as_posix() for repo in tree_roots for name in paths] and len(dual) == 31504
for repo in tree_roots:
    for wanted in tree['files']:
        key = (repo / wanted['path']).as_posix(); row = dual[key]
        assert set(row) == {'path', 'bytes', 'sha256', 'stat9'} and row['path'] == key
        assert pinpart(row) == pinpart(wanted) and len(row['stat9']) == 9 and row['stat9'][6] == row['bytes']


def original(stem, expected_pid=None):
    created = r.json(stem.with_suffix('.created.json'))
    terminal = r.json(stem.with_suffix('.terminal.json'))
    assert set(created) == {'argv', 'pid'}
    assert created['pid'] == terminal['pid']
    if expected_pid is not None:
        assert created['pid'] == expected_pid
    assert terminal['native_exit'] == terminal['wait_returncode'] == 0
    for stream in ('stdout', 'stderr'):
        payload = r.read(stem.with_suffix('.' + stream), {'bytes': terminal[stream + '_bytes'], 'sha256': terminal[stream + '_sha256']})
        if stream == 'stderr':
            assert payload == b''
    return created, terminal


outer = source / 'launcher/normal-start'
created = r.json(outer.with_suffix('.created.json'))
terminal = r.json(outer.with_suffix('.terminal.json'))
assert set(created) == {'pid', 'argv', 'started_ns'} and created['pid'] == terminal['pid'] == args.outer_pid
assert terminal['native_exit'] == terminal['wait_returncode'] == 0
assert set(terminal) == {'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr'}
assert 0 < created['started_ns'] < terminal['finished_ns']
assert len(created['argv']) == 9 and Path(created['argv'][4]).resolve() == (source/'validation/run.py').resolve()
assert created['argv'][5:] == ['--root-source-pins-sha256', ROOT_SOURCE_PINS['sha256'], '--setup-sha256', M_RESULT['sha256']]
for stream in ('stdout', 'stderr'):
    payload = r.read(outer.with_suffix('.' + stream), terminal[stream])
    if stream == 'stderr':
        assert payload == b''
driver_pin = {'bytes': 28528366, 'sha256': 'f23fd9ecee8447b0a50e328e6253ea9d73e97cc4f604b3c660f50c95f22c2deb'}
r.read(source / 'validation/run.py', driver_pin)
r.read(archive / 'root-driver-source.py', driver_pin)
assert actual_root_pins['members']['run.py'] == driver_pin
assert {key: actual_source_pins['files']['validation/run.py'][key] for key in ('bytes', 'sha256')} == driver_pin
context = r.json(run / 'actual-run-context.json', {'bytes': args.context_bytes, 'sha256': args.context_sha256})
manifest = r.json(run / 'manifest.json')
assert context['run_id'] == manifest['run_id'] == args.run_id
assert context['source_sha'] == manifest['commit_sha'] == 'b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
assert context['freeze_sha256'] == FREEZE['sha256']
assert Path(context['run_dir']).resolve() == run.resolve()
assert Path(context['repo_dir']).resolve() == Path(actual_M['repo']).resolve()
assert Path(context['prep_dir']).resolve() == Path(actual_M['profile']).resolve()
assert r.read(run / 'preparation-source-binding.json', FREEZE) == r.read(source / 'validation/materialize-freeze-assets-r1/freeze-binding.json', FREEZE)
r.read(source / 'validation/materialize-freeze-assets-r1/freeze-binding.json', FREEZE)
result = r.json(archive / 'RESULT.json')
assert result == {'status': 'ROOT_NORMAL_MIGRATIONS_BUSINESS_WORKER_CLOSE_AND_INDEPENDENT_ZERO_NATIVE_ZERO',
    'run_id': context['run_id'], 'source_sha': context['source_sha'], 'context': (run / 'actual-run-context.json').as_posix(),
    'worker_scope': 'VERIFIED_NORMAL_WORKER_NON_DUE_LIFECYCLE_ONLY', 'full_acceptance': 'INCOMPLETE',
    'DOM_nonempty_history_due_finance_release': 'NOT RUN'}
original(source / 'validation/native-normal-start-assets-r1/001-prepare', args.prepare_pid)
scripts = {'ms4-host.py': {'bytes': 357359, 'sha256': '20a97efeee9cf828c151bfb9f4f46b6ce04f4f304c90f439a65ea5672804f862'}, 'normal-close-ms4-fulfillment.py': {'bytes': 48793, 'sha256': 'bf490a612f4d0dba47578b1b53948939410c87bcb516bd62f21af0e1f5210c87'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 302705, 'sha256': '61816d3dbef3a62579e47846901754b874ddf25321d49a528b9648f467da3d20'}}
commands = []
labels = []
for path in sorted(archive.glob('*.created.json'), key=lambda item: int(item.name.split('-', 1)[0])):
    stem = path.with_name(path.name.removesuffix('.created.json'))
    c, t = original(stem)
    argv = c['argv']
    assert Path(argv[0]).resolve() == Path(sys.executable).resolve()
    assert argv[1:4] == ['-B', '-X', 'utf8']
    script = Path(argv[4])
    assert script.parent.resolve() == (source / 'validation/actual-profile').resolve() and script.name in scripts
    r.read(script, scripts[script.name])
    sequence_text, label = stem.name.split('-', 1)
    sequence = int(sequence_text)
    assert stem.name == f'{sequence:03d}-{label}'
    labels.append(label)
    if script.name == 'ms4-host.py':
        expected = argv[5] + ('-' + argv[argv.index('--proof') + 1] if '--proof' in argv else '')
        assert label == expected
    else:
        assert label in ('worker-normal-close', 'worker-independent-zero')
        assert '--worker-normal' in argv and argv[argv.index('--context') + 1] == str(run / 'actual-run-context.json')
    commands.append({'sequence': sequence, 'label': label, 'pid': c['pid'], 'native_exit': t['native_exit']})
assert [item['sequence'] for item in commands] == list(range(2, len(commands) + 2))
assert set(path.name for path in archive.iterdir()) == {'root-driver-source.py', 'RESULT.json'} | {
    f"{item['sequence']:03d}-{item['label']}.{suffix}" for item in commands
    for suffix in ('created.json', 'terminal.json', 'stdout', 'stderr')}
for required in ('paid-start', 'fulfillment-normal-start', 'fulfillment-normal-retire', 'aftersale-review-start',
                 'aftersale-review-retire', 'worker-start', 'worker-normal-close', 'worker-independent-zero'):
    assert labels.count(required) == 1
proofs = {}
for proof in ('initializer', 'driver', 'fulfillment-migration', 'files-bootstrap', 'customer-prepare', 'paid-producer',
              'fulfillment-normal', 'aftersale-review-normal', 'worker-normal'):
    made = r.json(run / 'evidence' / (proof + '-created.json'))
    ended = r.json(run / 'evidence' / (proof + '-terminal.json'))
    assert made['id'] == ended['id']
    assert made['run_id'] == context['run_id'] and made['source_sha'] == context['source_sha']
    assert made['proof_name'] == proof
    assert ended['status'] == 'exited' and ended['exit_code'] == 0 and ended['pid'] == 0 and ended['restart_count'] == 0
    proofs[proof] = {'native_exit': ended['exit_code'], 'same_original_id': True, 'no_restart': True}
# Real complete SourceTree copy and sole-writer removal, never inferred from M.
expected_context_tree = {'volume_name': context['project'] + '_source-tree', 'manifest_sha256': tree['manifest_sha256'], 'file_count': tree['file_count'], 'directory_count': tree['directory_count']}
assert list(context['source_tree']) == list(expected_context_tree) and context['source_tree'] == expected_context_tree
volume_suffixes = ['app-secrets', 'bootstrap-secrets', 'fixture-secrets', 'mysql-data', 'redis-data', 'redis-secrets', 'aftersale-evidence', 'customer-form-evidence', 'source-tree']
assert sorted(row['name'] for row in context['initial_volumes']) == sorted(context['project'] + '_' + suffix for suffix in volume_suffixes) and len(context['initial_volumes']) == 9
for volume in context['initial_volumes']:
    assert volume['labels']['com.docker.compose.project'] == context['project'] and volume['labels']['tianli.audit.run'] == context['run_id'] and volume['created_at']
initializer_created = r.json(run / 'evidence/initializer-created.json')
initializer_terminal = r.json(run / 'evidence/initializer-completed-terminal.json')
initializer_result = r.json(run / 'evidence/initializer-result.json')
assert initializer_created['id'] == initializer_terminal['id'] and initializer_created['proof_name'] == 'initializer'
assert initializer_terminal['status'] == 'exited' and initializer_terminal['exit_code'] == 0 and initializer_terminal['pid'] == initializer_terminal['restart_count'] == 0
assert initializer_result['status'] == 'VERIFIED_MS5_PAYMENT_PREREQUISITES_ONLY' and initializer_result['native_exit'] == 0 and initializer_result['private_cleanup_verified'] is True
assert initializer_result['run_id'] == context['run_id'] and initializer_result['source_sha'] == context['source_sha']
assert initializer_result['binding']['container_id'] == initializer_created['id'] and initializer_result['binding']['source_sha'] == context['source_sha'] and initializer_result['binding']['freeze_sha256'] == context['freeze_sha256']
assert initializer_result['binding']['created_sha256'] == fp(r.read(run / 'evidence/initializer-created.json'))['sha256']
copy = initializer_result['source_tree']
assert list(copy) == ['schema', 'status', 'source_sha', 'freeze_sha256', 'manifest_sha256', 'volume_name', 'writer_destination', 'file_count', 'directory_count', 'root_identity', 'empty_before', 'complete_namespace', 'all_regular_no_links', 'source_bytes_readback_matches', 'writer_role']
assert type(copy['schema']) is int and copy['schema'] == 1 and copy['status'] == 'COPIED_AND_VERIFIED_COMPLETE_TRACKED_SOURCE_TREE'
assert copy['source_sha'] == context['source_sha'] and copy['freeze_sha256'] == context['freeze_sha256'] and copy['writer_destination'] == tree['writer_destination']
assert all(copy[key] == expected_context_tree[key] for key in ['manifest_sha256', 'volume_name', 'file_count', 'directory_count'])
assert all(copy[key] is True for key in ['empty_before', 'complete_namespace', 'all_regular_no_links', 'source_bytes_readback_matches']) and copy['writer_role'] == 'audit-ms4-initialize'
identity = copy['root_identity']
assert list(identity) == ['dev', 'ino', 'mode', 'uid', 'gid'] and all(type(v) is int for v in identity.values())
assert identity['dev'] >= 0 and identity['ino'] > 0 and identity['mode'] == 0o40700 and identity['uid'] == identity['gid'] == 0
writer_mounts = [row for row in initializer_created['mounts'] if row['Destination'] == tree['writer_destination']]
assert len(writer_mounts) == 1 and writer_mounts[0]['Type'] == 'volume' and writer_mounts[0]['Name'] == expected_context_tree['volume_name'] and writer_mounts[0]['RW'] is True
source_volume_root = writer_mounts[0]['Source'].rstrip('/')
assert source_volume_root and source_volume_root.startswith('/')
# Six actual guarded starts, in the existing append-only public native ledger.
native_paths = sorted((run / 'evidence').glob('native-*.json'), key=lambda path: int(path.stem.split('-', 1)[1]))
assert [path.name for path in native_paths] == [f'native-{index:04d}.json' for index in range(1, len(native_paths) + 1)]
native_rows = [r.json(path) for path in native_paths]
native_keys = {'stage', 'native_exit', 'stdout_bytes', 'stdout_sha256', 'stderr_bytes', 'stderr_sha256', 'private_environment_logs_omitted'}
for row in native_rows:
    assert set(row) == native_keys and type(row['native_exit']) is int and row['native_exit'] == 0 and row['private_environment_logs_omitted'] is True
    assert type(row['stdout_bytes']) is int and row['stdout_bytes'] >= 0 and re.fullmatch('[a-f0-9]{64}', row['stdout_sha256'])
    assert type(row['stderr_bytes']) is int and row['stderr_bytes'] >= 0 and re.fullmatch('[a-f0-9]{64}', row['stderr_sha256'])
removal_positions = [i for i,row in enumerate(native_rows) if row['stage'] == 'initializer-exact-nonforce-remove']
assert len(removal_positions) == 1
removal_index = removal_positions[0]; removal = native_rows[removal_index]
assert removal['stderr_bytes'] == 0 and removal['stderr_sha256'] == hashlib.sha256(b'').hexdigest()
assert pinpart({'bytes':removal['stdout_bytes'], 'sha256':removal['stdout_sha256']}) in [fp((initializer_created['id'] + ending).encode()) for ending in ['\n', '\r\n']]
guard_stage = 'source-tree-writer-absent-before-existing-start'
start_stages = ['driver-start', 'customer-prepare-start', 'paid-start', 'fulfillment-normal-start', 'aftersale-review-start', 'worker-start']
guard_positions = [i for i,row in enumerate(native_rows) if row['stage'] == guard_stage]
assert len(guard_positions) == len(start_stages) == 6
for stage in start_stages:
    positions = [i for i,row in enumerate(native_rows) if row['stage'] == stage]
    assert len(positions) == 1 and positions[0] > 0 and removal_index < positions[0] - 1
    assert native_rows[positions[0] - 1]['stage'] == guard_stage
assert guard_positions == sorted([next(i for i,row in enumerate(native_rows) if row['stage'] == stage) - 1 for stage in start_stages])
# The early driver keeps its original host binds. Five normal proof consumers
# use the same named volume RO at both Source roots, without a writable alias.
for proof in ['customer-prepare', 'paid-producer', 'fulfillment-normal', 'aftersale-review-normal', 'worker-normal']:
    made = r.json(run / 'evidence' / (proof + '-created.json'))
    for destination in ['/source', '/workspace']:
        mounts = [row for row in made['mounts'] if row['Destination'] == destination]
        assert len(mounts) == 1 and mounts[0]['Type'] == 'volume' and mounts[0]['Name'] == expected_context_tree['volume_name'] and mounts[0]['RW'] is False
    aliases = [row for row in made['mounts'] if row.get('Name') == expected_context_tree['volume_name'] or (type(row.get('Source')) is str and (row['Source'].rstrip('/') == source_volume_root or row['Source'].startswith(source_volume_root + '/')))]
    assert all(row['RW'] is False for row in aliases)

close_path = run / 'evidence/ms4-worker-normal-close-result.json'
closed = r.json(close_path)
assert closed['status'] == 'VERIFIED_MS4_WORKER_NORMAL_CLOSE_ZERO_RESIDUAL'
zero = loads(r.read(archive / (f"{commands[-1]['sequence']:03d}-worker-independent-zero.stdout")))
assert commands[-1]['label'] == 'worker-independent-zero'
assert zero['status'] == 'VERIFIED_MS4_WORKER_NORMAL_INDEPENDENT_SIX_QUERY_ZERO'
assert commands[-2]['label'] == 'worker-normal-close'
for value in (closed, zero):
    assert value['run_id'] == context['run_id'] and value['source_sha'] == context['source_sha']
    assert value['freeze_sha256'] == context['freeze_sha256'] and value['native_exit'] == 0
close_pin = fp(r.read(close_path))
assert zero['close_result_sha256'] == close_pin['sha256']
assert [(q['kind'], q['label']) for q in zero['queries']] == [(k, label) for k in ('container','network','volume') for label in ('project','run')]
assert all(q['native_exit'] == 0 and q['stdout_bytes'] == q['stderr_bytes'] == 0 and q['stdout_sha256'] == q['stderr_sha256'] == hashlib.sha256(b'').hexdigest() for q in zero['queries'])
assert closed['exact_nonforce_removed'] == {'containers': 4, 'networks': 1, 'volumes': 9}
assert closed['ordinary_stop_and_exact_nonforce_remove'] is True and closed['full_acceptance'] == 'INCOMPLETE'
assert all(values == [] for labels in closed['final_inventory'].values() for values in labels.values())
assert closed['reviewed_host_sha256'] == scripts['ms4-host.py']['sha256'] and closed['close_source_sha256'] == scripts['normal-close-ms4-fulfillment.py']['sha256']
assert closed['context_sha256'] == args.context_sha256 and zero['zero_source_sha256'] == scripts['verify-zero-ms4-fulfillment.py']['sha256']
before_volume_remove = r.json(run / 'evidence/ms4-worker-normal-close-before-eight-volumes-rm.json')
assert before_volume_remove['volumes'] == context['initial_volumes']
for label in ['project', 'run']:
    assert sorted(before_volume_remove['inventory']['volume'][label]) == sorted(row['name'] for row in context['initial_volumes'])
    assert before_volume_remove['inventory']['container'][label] == before_volume_remove['inventory']['network'][label] == []
global_archives = [closed['global_consumers'], before_volume_remove['global_consumers'], zero['global_consumers']['before'], zero['global_consumers']['after']]
for value in global_archives:
    assert value['unexpected_original_resource_consumers'] == 0 and value['allowed_original_consumers'] == []
    assert len(value['original_container_ids']) == 4 and len(value['operations']) == value['inspected_container_count'] + 2
    assert value['operations'][0]['argv'] == value['operations'][-1]['argv'] == ['ps', '-aq', '--no-trunc']
    for operation in value['operations']:
        assert operation['native_exit'] == 0 and operation['stderr_bytes'] == 0 and operation['stderr_sha256'] == hashlib.sha256(b'').hexdigest()

ended_ns = r.end()
report = {'status': 'PASS_INDEPENDENT_ACTUAL_B54_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY',
    'reader_source': {'path': Path(__file__).as_posix(), **reader_source_pin},
    'independently_reads_originals': True, 'target_or_source_module_imported': False,
    'actual_Run_freeze_equals_complete_frozen_Source': True,
    'run_id': context['run_id'], 'source_sha': context['source_sha'], 'freeze_sha256': context['freeze_sha256'],
    'original_outer_pid': args.outer_pid, 'original_prepare_pid': args.prepare_pid, 'original_outer_native_exit_wait': 0, 'Root_native_wrappers': len(commands),
    'initial_prepare_native_zero': True, 'all_original_stream_pins_match': True,
    'original_root_driver_matches_frozen_source': driver_pin, 'all9_original_container_proofs': proofs,
    'full_original_normal_stage_order': labels, 'normal_close_result': close_pin,
    'independent_zero_from_original_S': True, 'extra_close_or_restart_performed': False,
    'worker_scope': result['worker_scope'], 'full_business_acceptance': 'INCOMPLETE',
    'DOM_nonempty_history_due_finance_release': 'NOT RUN', 'finite_input_count': len(r.rows),
    'all_windows_closed': True, 'separate_author_input_rows': {key:len(value) for key,value in separate_author_maps.items()}, 'separate_author_intersections': author_intersections, 'current_warm_profile9_and_d0_finite_complete': True, 'historical24_warm_profile9_and_d0_finite_complete': True, 'current25_b54_profile9_bafb_finite420_and_Source33_input48_complete': True, 'M37_25stages_1160_native_qualified': True, 'all11_retirement_whole_objects_preserved': True,
    'SourceTree_complete_plan_and_actual_copy': copy, 'source_tree_writer_ordinary_removed_before_all6_guarded_starts': True,
    'source_tree_RO_normal_mounts_preserved': True, 'ordinary_close_exact_resources': closed['exact_nonforce_removed'],
    'Run_public_native_receipts': len(native_rows), 'foreign_resource_consumers_absent': True,
    'no_ENV_private_actor_or_app_child_stream_body_reads': True, 'ended_ns': ended_ns}
out = Path(__file__).resolve().parents[1] / 'actual-peer-readback-r1'
out.mkdir(exist_ok=False)
(out / 'REPORT.json').write_bytes(enc(report))
(out / 'INPUTS.json').write_bytes(enc({'files': list(r.rows.values()), 'all_windows_closed': True}))
print(json.dumps({'report': fp(enc(report)), 'Root_native_wrappers': len(commands), 'proofs': list(proofs),
    'scope': result['worker_scope'], 'full_business_acceptance': 'INCOMPLETE'}))
