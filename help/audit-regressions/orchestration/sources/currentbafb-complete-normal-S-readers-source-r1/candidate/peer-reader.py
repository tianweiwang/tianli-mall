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


parser = argparse.ArgumentParser(description='Read only the genuinely completed successful current bafb r3 normal S receipts')
parser.add_argument('--run-id', required=True)
parser.add_argument('--outer-pid', required=True, type=positive_pid)
parser.add_argument('--prepare-pid', required=True, type=positive_pid)
parser.add_argument('--context-bytes', required=True, type=int)
parser.add_argument('--context-sha256', required=True)
parser.add_argument('--authorize-completed-s0', action='store_true', help='Root has authorized readback after original S native0/wait0')
args = parser.parse_args()
if re.fullmatch(r'[0-9]{14}[a-f0-9]{6}', args.run_id) is None or args.run_id in ('20261023124755f45a9e', '202610231609185fbd23', '20261023182634096f57', '2026102321491304a643', '2026102402025362a539', '2026102403521460e674', '2026102406240181bf85', '20261024114233c6e65e'):
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
source = Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source')
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
r.read(Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-normal-S-readers-source-r1/candidate/root-reader.py'), {'bytes': 102076, 'sha256': '1e35b5ba31eb2d1be9e0f4b431008a2a845da35da1a9800a94ee920d62feefd3'})

SOURCE_PINS = {'bytes': 13638, 'sha256': '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}
ROOT_SOURCE_PINS = {'bytes': 358, 'sha256': 'e40e95ba18acbdd3d42843129535be813f471b5bb1b45e018ba3f453466cd920'}
M_RESULT = {'bytes': 264985213, 'sha256': '06291afb846c2043a4ab425a5184c962e3e2fc5e180f5c369bfbd075ca2e9e0a'}
FREEZE = {'bytes': 14518656, 'sha256': '7d4a8c79a358009aff3716ea305d34ea69e8e79de17bb2972973ee384c2c2bb1'}
M_ROOT_READBACK = {'bytes': 4618, 'sha256': 'c885b90870a49500cce43527e2f690c4b3e418b9ced7d5e7c97ff7cf3936ee93'}
PROFILE_SOURCE_PINS9 = {'README.md': {'bytes': 92239, 'sha256': '8ef95e9271dc027b08592f84f05ecc57668f2de5f0656bb28c35cbf37cdabd88'}, 'compose.yml': {'bytes': 13115, 'sha256': '22aed47453c67499c361efa57b24c954433fb5a68863a07ede5896f845451b7b'}, 'ms4-host.py': {'bytes': 357359, 'sha256': 'e8d27a350bccc7c7c1f607d46b6dabbeababb2f62e3ea0a0b36b5bb7890e87ed'}, 'ms4-initialize.php': {'bytes': 91460, 'sha256': '1e5a882975311d431993127940f02ac14934ca9e46d30742108a9c8a607afe98'}, 'seed-source-inverse.json': {'bytes': 639, 'sha256': 'c35433231ddcff315931e270d737a022a14818f8aac8c05ed67c3b155e88e14a'}, 'interface-v1.json': {'bytes': 110526, 'sha256': 'c536e9c0bb90b357d87dc266ae7cf7723093db6eec608bf80391cfd28f6a521d'}, 'source-derivation.json': {'bytes': 633891, 'sha256': '73d057d68590dfd8db2d03b8dbfcd71830f6b1d69a7edd6c656433585f8b4587'}, 'normal-close-ms4-fulfillment.py': {'bytes': 48793, 'sha256': '2582823071eb1acfcd7ed37c16b2d0c379f57328f27c5c803d8587e3ecf9040d'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 302705, 'sha256': 'dd615834d3ff3a314de0288546a7a210d6b32130333822632dc95cb6f85542d6'}}
actual_source_pins = r.json(source / 'PINS.json', SOURCE_PINS)
assert actual_source_pins['source_sha'] == 'bafb6305062aeb6b6e945c21da572863062ca480'
actual_root_pins = r.json(source / 'validation/PINS.json', ROOT_SOURCE_PINS)
actual_M = r.json(source / 'validation/materialize-freeze-assets-r1/RESULT.json', M_RESULT)
assert actual_M['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
assert actual_M['source_sha'] == 'bafb6305062aeb6b6e945c21da572863062ca480'
assert actual_M['repo'] == (workspace / 'worktrees/ms4-aftersale-normal-check').as_posix()
assert actual_M['profile'] == (source / 'validation/actual-profile').as_posix()
assert actual_M['freeze'] == (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix()
assert actual_M['freeze_sha256'] == FREEZE['sha256']
m_readback = r.json(Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentbafb-complete-materialization-root-readback-r1/REPORT.json'), M_ROOT_READBACK)
assert m_readback['status'] == 'PASS_ACTUAL_FULL_CURRENTBAFB_MATERIALIZATION_ONLY' and m_readback['source_sha'] == 'bafb6305062aeb6b6e945c21da572863062ca480'
assert m_readback['actual_RESULT'] == {'path': (source / 'validation/materialize-freeze-assets-r1/RESULT.json').as_posix(), **M_RESULT}
assert m_readback['actual_freeze'] == {'path': (source / 'validation/materialize-freeze-assets-r1/freeze-binding.json').as_posix(), **FREEZE}
CURRENT_M_RESULT_KEYS36 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement', 'current_175f_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']
CURRENT_M_RESULT_KEYS37 = CURRENT_M_RESULT_KEYS36 + ['current_6e3850_paid_producer_failure_retirement']
assert set(actual_M) == set(CURRENT_M_RESULT_KEYS37) and len(actual_M) == 37
assert len(actual_M['commit_chain']) == 24 and len(actual_M['native_operations']) == 1150
assert m_readback['M_RESULT_fields'] == 37 and m_readback['historical_M_RESULT_fields'] == 36 and m_readback['steps'] == 24 and m_readback['native_operations'] == 1150

for name, expected in PROFILE_SOURCE_PINS9.items():
    r.read(source / 'validation/actual-profile' / name, expected)

# Full actual M/source/metadata qualification; no path in its metadata maps is dereferenced.
source_pins = actual_source_pins
reader_bytes = lambda pointer: r.read(Path(pointer['path']), pointer)
separate_author_maps = validate_reader_source_inheritance(source, source_pins, reader_bytes, loads, need)
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
historical23_values = dict(source_constants)
historical23_values.update(source_constants['HISTORICAL_TWENTYTHREE_CONSTANTS'])
historical23_publication = r.json(Path(source_constants['HISTORICAL_TWENTYTHREE_PUBLICATION']['path']), source_constants['HISTORICAL_TWENTYTHREE_PUBLICATION'])
historical_chain = historical23_values['COMMIT_CHAIN'][:-2]
assert len(historical_chain) == 21 and result['commit_chain'] == source_constants['COMMIT_CHAIN'] and historical23_values['COMMIT_CHAIN'] == result['commit_chain'][:23]
publication = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/validation/PROFILE-PUBLICATION.json'), {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/validation/PROFILE-PUBLICATION.json', 'bytes': 1463213, 'sha256': '569a51661c2e2ed48d0737f87b1c2c4f525d56db08ca2123852500e331b88732'})
profile_pins = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/validation/PROFILE-PINS.json'), {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/validation/PROFILE-PINS.json', 'bytes': 1518, 'sha256': '8448447770a15bce89821f57e84b875e709c04475df40fce651d8742b27fdb15'})
assert set(actual_root_pins['members']) == {'run.py', 'DESIGN-TASKS.md'} and len(profile_pins['members']) == 10
for name, wanted in actual_root_pins['members'].items():
    r.read(source / 'validation' / name, wanted)
for name, wanted in profile_pins['members'].items():
    r.read(source / 'validation' / name if name == 'PROFILE-PUBLICATION.json' else source / 'validation/actual-profile' / name, wanted)
assert m_readback['root_source_members'] == 14 and m_readback['complete_source_snapshot_rows'] == 31500 and m_readback['tracked_per_checkout'] == 15750 and m_readback['cumulative'] == 311 and m_readback['source_group_union'] == 10886
assert m_readback['all_prior_nine_whole_encoded_objects_preserved'] is True and m_readback['tenth_actual_failed_A_whole_pinned_authority_and_result_join_preserved'] is True
peer_M = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1/REPORT.json'), {'bytes': 3537273, 'sha256': 'fda54130456e944f32b21a8d874a0fe806c8dcc9dc93b80b87bf35c0b0e944a5'})
peer_M_PINS = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1/PINS.json'), {'bytes': 508, 'sha256': '5cdfe6bada88124644a8b8b5724f7fffefd45996d1592088e40eba7505373db0'})
assert peer_M_PINS['files']['REPORT.json'] == {'bytes': 3537273, 'sha256': 'fda54130456e944f32b21a8d874a0fe806c8dcc9dc93b80b87bf35c0b0e944a5'}
assert peer_M['status'] == 'PASS_COMPLETE_ACTUAL_CURRENTBAFB_M37_SOURCE_MATERIALIZATION_READBACK_ONLY' and peer_M['source_sha'] == result['source_sha']
assert peer_M['actual_RESULT'] == m_readback['actual_RESULT'] and peer_M['actual_freeze'] == m_readback['actual_freeze']
assert peer_M['actual_M_native_operations'] == 1150 and peer_M['M_RESULT_current_fields'] == 37 and peer_M['M_RESULT_historical_fields_preserved'] == 36
assert peer_M['source_program_members'] == 18 and peer_M['Root_source_full_bytes_and_native9'] == 14 and peer_M['M_source_full_bytes_and_native9'] == 3 and peer_M['complete_dual_source_metadata_rows'] == 31500
assert peer_M['complete_normal_source_union'] == 10886 and peer_M['eleven_retirement_facts_whole_joined_without_overwriting_old_ten'] is True and peer_M['all_finite_windows_closed'] is True
assert peer_M['all1150_created_terminal_and_double_raw_streams_complete'] is True and m_readback['all_original_native_created_terminal_and_streams_join'] is True
assert peer_M['actual_outer_pid'] == m_readback['outer_pid'] == 22536 and peer_M['actual_outer_native_exit_wait'] == m_readback['outer_native_exit_wait'] == 0
dual_M_qualification = r.json(Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R100-BOTH-M-READERS-ACTUAL-ROOT-READBACK.json'), {'bytes': 2791, 'sha256': '7aed3fc03af85053d6efc83dd9380f533b21eed870494371215301b23c72087e'})
assert dual_M_qualification == {'schema': 1, 'status': 'PASS_BOTH_COMPLETED_CURRENTBAFB_M_READERS_ACTUAL_ROOT_JOIN_ONLY', 'source_sha': 'bafb6305062aeb6b6e945c21da572863062ca480', 'actual_M_PID': 22536, 'actual_M': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/validation/materialize-freeze-assets-r1/RESULT.json', 'bytes': 264985213, 'sha256': '06291afb846c2043a4ab425a5184c962e3e2fc5e180f5c369bfbd075ca2e9e0a'}, 'actual_freeze': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source/validation/materialize-freeze-assets-r1/freeze-binding.json', 'bytes': 14518656, 'sha256': '7d4a8c79a358009aff3716ea305d34ea69e8e79de17bb2972973ee384c2c2bb1'}, 'Root_REPORT': {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentbafb-complete-materialization-root-readback-r1/REPORT.json', 'bytes': 4618, 'sha256': 'c885b90870a49500cce43527e2f690c4b3e418b9ced7d5e7c97ff7cf3936ee93'}, 'peer_REPORT': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1/REPORT.json', 'bytes': 3537273, 'sha256': 'fda54130456e944f32b21a8d874a0fe806c8dcc9dc93b80b87bf35c0b0e944a5'}, 'peer_PINS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1/PINS.json', 'bytes': 508, 'sha256': '5cdfe6bada88124644a8b8b5724f7fffefd45996d1592088e40eba7505373db0'}, 'original_reader_PIDs': {'root': 1660, 'peer': 34708}, 'both_native_and_wait_exit': 0, 'complete_saved_input_windows_validated': {'root': 5991, 'peer': 6037}, 'complete_original_M_native_operations': 1150, 'complete_source_metadata_rows': 31500, 'source_tree_manifest_sha256': '5f148c27ab346bf3ebc0337481b95e6de4f8f256022153787325adc226e285ed', 'complete_authority_inputs': 32491, 'readonly_failed_attempt_preserved': 'R100-BOTH-M-READERS-ROOT-AUDIT-ATTEMPT1.json', 'stdout_join_correction': 'Original Root stdout exact REPORT/finite_reads/M/freeze; INPUTS independently sealed and fully read.', 'actual_readers_rerun': False, 'original_M_or_source_corpus_reread_by_this_audit': False, 'target_import_or_execution': False, 'Git_Docker_App_DB_HTTP_calls': 0, 'S_A0_full_business_UI_or_new83daa_qualification': 'NOT ASSERTED', 'INPUTS': {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R100-BOTH-M-READERS-ACTUAL-ROOT-INPUTS.json', 'bytes': 19427, 'sha256': 'c39e9d32a4fe86106e02a8386aec763f994e32f17dc7374941749c2d652dd951'}, 'completed_ns': 1792863626402147900}
assert dual_M_qualification['Root_REPORT'] == {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentbafb-complete-materialization-root-readback-r1/REPORT.json', 'bytes': 4618, 'sha256': 'c885b90870a49500cce43527e2f690c4b3e418b9ced7d5e7c97ff7cf3936ee93'} and dual_M_qualification['peer_REPORT'] == {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1/REPORT.json', 'bytes': 3537273, 'sha256': 'fda54130456e944f32b21a8d874a0fe806c8dcc9dc93b80b87bf35c0b0e944a5'} and dual_M_qualification['peer_PINS'] == {'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1/PINS.json', 'bytes': 508, 'sha256': '5cdfe6bada88124644a8b8b5724f7fffefd45996d1592088e40eba7505373db0'}
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
assert validate_current_warm_profile(source_constants, publication, profile_bytes, loads, need)
assert validate_current_d0a5_finite(source_constants, publication, profile_bytes, loads, need)
assert m_readback['current_profile_revision_and_baseline9_inverse_verified'] is peer_M['current_profile_revision_and_baseline9_inverse_verified'] is True
assert m_readback['finite_profile_attempt_not_twelfth_whole'] is peer_M['finite_profile_attempt_not_twelfth_whole'] is True



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
assert callback_root['M_and_S_each_full_checks'] == 11680434
assert set(callback_report['qualification']) == {'M', 'S'}
for label, item in callback_report['qualification'].items():
    assert item['module'] == label and item['manual_dependency_injection'] is False and item['unbound_globals'] == [] and item['pure_source_checks'] == 11680434
assert set(callback_inputs) == {'files', 'cache_semantics', 'callback_invocations'} and callback_inputs['callback_invocations'] == 2
assert callback_inputs['cache_semantics'] == 'ORIGINAL_FIRST_FULL_EOF_READ_ONCE_PER_PATH_THEN_CACHE'
callback_files = callback_inputs['files']
assert len(callback_files) == callback_report['actual_input_paths'] == 32497
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
assert len(authority_names) == 32491
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
assert len(source_pins['files']) == 29 and len(source_pins['source_members18']) == 18
assert {path for path in authority_names if path.startswith(source.as_posix()+'/')} == {(source/'validation/actual-profile'/row['path']).as_posix() for row in publication['selected9']}
author_intersections = validate_separate_author_intersections(authority_snapshot, separate_author_maps, need)
assert callback_report['group_counts'] == result['source_counts']

assert m_readback['M_complete_authority_inputs'] == peer_M['exact_M_authority_projection_rows'] == len(authority_snapshot) == 32491
assert m_readback['callback_complete_inputs'] == peer_M['complete_callback_graph_rows'] == len(callback_files) == 32497
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
assert type(tree['file_count']) is int and tree['file_count'] == len(tree['files']) == 15750
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
manifest_raw = json.dumps({'files': tree['files'], 'directories': tree['directories']}, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
assert tree['manifest_sha256'] == hashlib.sha256(manifest_raw).hexdigest()
assert peer_M['source_tree_complete_freeze_plan'] == tree and peer_M['source_tree_copy_or_volume_created_by_M'] is False
assert m_readback['complete_tracked_source_tree_verified'] == {'file_count': tree['file_count'], 'directory_count': tree['directory_count'], 'manifest_sha256': tree['manifest_sha256'], 'scope': tree['scope'], 'Val_origin': actual_M['repo']}
dual_pointer = actual_M['complete_source_snapshot']
dual = r.json(Path(dual_pointer['path']), dual_pointer)
tree_roots = [workspace / 'worktrees/ms4-asset-consumers', Path(actual_M['repo'])]
assert list(dual) == [(repo / name).as_posix() for repo in tree_roots for name in paths] and len(dual) == 31500
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
driver_pin = {'bytes': 23709336, 'sha256': '66f0618dc90ee149d6242be72b47203c95ce39e88fe6260892e646645b759c42'}
r.read(source / 'validation/run.py', driver_pin)
r.read(archive / 'root-driver-source.py', driver_pin)
assert actual_root_pins['members']['run.py'] == driver_pin
assert {key: actual_source_pins['files']['validation/run.py'][key] for key in ('bytes', 'sha256')} == driver_pin
context = r.json(run / 'actual-run-context.json', {'bytes': args.context_bytes, 'sha256': args.context_sha256})
manifest = r.json(run / 'manifest.json')
assert context['run_id'] == manifest['run_id'] == args.run_id
assert context['source_sha'] == manifest['commit_sha'] == 'bafb6305062aeb6b6e945c21da572863062ca480'
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
scripts = {'ms4-host.py': {'bytes': 357359, 'sha256': 'e8d27a350bccc7c7c1f607d46b6dabbeababb2f62e3ea0a0b36b5bb7890e87ed'}, 'normal-close-ms4-fulfillment.py': {'bytes': 48793, 'sha256': '2582823071eb1acfcd7ed37c16b2d0c379f57328f27c5c803d8587e3ecf9040d'}, 'verify-zero-ms4-fulfillment.py': {'bytes': 302705, 'sha256': 'dd615834d3ff3a314de0288546a7a210d6b32130333822632dc95cb6f85542d6'}}
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
report = {'status': 'PASS_INDEPENDENT_ACTUAL_BAFB_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY',
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
    'all_windows_closed': True, 'separate_author_input_rows': {key:len(value) for key,value in separate_author_maps.items()}, 'separate_author_intersections': author_intersections, 'current_warm_profile9_and_d0_finite_complete': True, 'M37_24stages_1150_native_qualified': True, 'all11_retirement_whole_objects_preserved': True,
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
