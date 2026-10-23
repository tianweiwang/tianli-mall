"""Ninth current28dc complete retirement Source author; no target/native calls.

Outcome: bind the admitted S1/paid1 failure to complete Root/peer readbacks,
M34/18/1078, full source mapping, nine profile, and all close93/zero20 receipts.
The immutable observation registries are whole metadata documents. Opaque paths
are never reopened. Run reads are exactly 252 already-reviewed public JSON
metadata files, never application/HTTP/SQL/environment/private/stream payloads.
"""
import ast
import collections
import hashlib
import json
from pathlib import Path
import re
import stat
import traceback
import source_reader as r

ORG=r.B.parent/'project-organization-20261023'
RUN_ID='202610231609185fbd23'
SOURCE='28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
FREEZE='fea1ea38344095ce5dfe84ad85ad3041fb3b10271fd2c99b396212886e927a1a'
RUN=ORG.parent/'ms4-aftersale-review-normal-runs'/RUN_ID
SRC=ORG/'current28dc-paid-failure-retirement-preparation-r1/candidate'
FAILURE=SRC.parent/'public-inputs-actual-r1'
CLOSE=r.B/'current28dc-paid-producer-failed-normal-close-r1'
ZERO=r.B/'current28dc-paid-producer-failed-independent-zero-r1'
PEER=ORG/'current28dc-failed-retirement-actual-independent-full-r1'
READERS=ORG/'current28dc-failed-retirement-readers-source-r1'
ROOT_FULL=ORG/'current28dc-failure-full-root-actual-readback-r1'
COLLECTOR=ORG/'current28dc-paid-failure-diagnostic-actual-r1'
PY='C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
DOCKER='C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
EMPTY=hashlib.sha256(b'').hexdigest()
CLASSES={}
SAFE_EXPECTED={}
RUN_ALLOWED=set()
ROOT_META={}
OPAQUE={}


def ordinary(path):
    path=Path(path)
    assert path.is_absolute() and '..' not in path.parts
    assert not path.is_relative_to(ORG.parent.parent/'worktrees'),'NO_W_VAL_BODY'
    for item in [*reversed(path.parents),path]:
        info=item.lstat()
        assert not stat.S_ISLNK(info.st_mode) and not getattr(info,'st_file_attributes',0)&0x400
        assert stat.S_ISREG(info.st_mode) if item==path else stat.S_ISDIR(info.st_mode)
    return path


def safe(path,category,expected=None):
    path=ordinary(path);key=path.as_posix()
    assert path.name not in {'php.env','.env','environment.json','php-environment.json'}
    assert 'php-env' not in path.parts and not any(x.startswith('private-') for x in path.parts)
    assert category in {'REVIEWED_SOURCE','PUBLIC_RECEIPT_JSON','OBSERVATION_METADATA_ONLY',
        'NATIVE_CREATED_TERMINAL','DECLARED_EMPTY_STDERR','PUBLIC_NATIVE_STDOUT',
        'PUBLIC_IMMUTABLE_PROFILE_SOURCE','PUBLIC_GIT_METADATA_STDOUT'}
    if path.is_relative_to(RUN):
        assert key in RUN_ALLOWED and path.suffix=='.json','RUN_METADATA_EXACT_ALLOWLIST_ONLY'
    if key in SAFE_EXPECTED:
        assert expected is None or r.compact(expected)==r.compact(SAFE_EXPECTED[key])
        expected=SAFE_EXPECTED[key]
    if key in ROOT_META:
        assert expected is None or r.compact(expected)==r.compact(ROOT_META[key])
        expected=ROOT_META[key]
    CLASSES.setdefault(key,set()).add(category)
    raw=r.fixed(path,expected) if expected is not None else r.read(path)
    if key in ROOT_META:assert r.READS[key]['path_before']==ROOT_META[key]['stat9']
    return raw


def loads(raw):
    def pairs(items):
        result={}
        for key,value in items:
            assert key not in result,('DUPLICATE_JSON_KEY',key)
            result[key]=value
        return result
    return json.loads(raw,object_pairs_hook=pairs)


def doc(path,category='PUBLIC_RECEIPT_JSON',expected=None):
    return loads(safe(path,category,expected))


def ptr(path):
    assert Path(path).as_posix() in CLASSES
    return r.pointer(path,True)


def metadata_pointer(row):
    pin=row.get('pin',row)
    assert type(row['path']) is str and type(pin['bytes']) is int and pin['bytes']>=0
    assert re.fullmatch('[a-f0-9]{64}',pin['sha256'])
    return {'path':row['path'],'bytes':pin['bytes'],'sha256':pin['sha256']}


def observed_pointer(path,expected):
    key=Path(path).as_posix();row=OPAQUE[key]
    assert metadata_pointer(row)=={'path':key,**r.compact(expected)}
    assert row['named_before']==row['named_after'] and row['fd_open']==row['fd_after']
    assert [row['named_before'][i] for i in [0,1,6,7]]==[row['fd_open'][i] for i in [0,1,6,7]]
    assert key in ROOT_META and metadata_pointer(ROOT_META[key])==metadata_pointer(row)
    assert ROOT_META[key]['stat9']==row['named_before']
    return metadata_pointer(row)


def literal_assignments(path,names):
    found={}
    for node in ast.parse(safe(path,'REVIEWED_SOURCE').decode()).body:
        if isinstance(node,ast.Assign):
            for target in node.targets:
                if isinstance(target,ast.Name) and target.id in names:
                    found[target.id]=ast.literal_eval(node.value)
    assert set(found)==set(names)
    return found


def four(folder,stem=None,expected=None,code=0,opaque_streams=False):
    paths={name:(folder/name if stem is None else Path(str(folder/stem)+'.'+name))
           for name in ['created.json','terminal.json','stdout','stderr']}
    made=doc(paths['created.json'],'NATIVE_CREATED_TERMINAL')
    ended=doc(paths['terminal.json'],'NATIVE_CREATED_TERMINAL')
    assert type(made['pid']) is int and made['pid']>0 and made['pid']==ended['pid']
    assert ended['native_exit']==ended['wait_returncode']==code
    if expected is not None:assert made=={'argv':expected['argv'],'pid':expected['pid']} and ended==expected
    pointers={'created':ptr(paths['created.json']),'terminal':ptr(paths['terminal.json'])};streams={}
    for name in ['stdout','stderr']:
        pin=ended.get(name)
        if type(pin) is not dict:pin={'bytes':ended[name+'_bytes'],'sha256':ended[name+'_sha256']}
        if opaque_streams:
            pointers[name]=observed_pointer(paths[name],pin)
        else:
            streams[name]=safe(paths[name],'DECLARED_EMPTY_STDERR' if name=='stderr' else 'PUBLIC_NATIVE_STDOUT',pin)
            assert name!='stderr' or streams[name]==b''
            pointers[name]=ptr(paths[name])
    return pointers,made,ended,streams


def inventory_equal(left,right):
    assert set(left)==set(right)
    assert {k:v for k,v in left.items() if k!='mounts'}=={k:v for k,v in right.items() if k!='mounts'}
    encode=lambda rows:sorted(json.dumps(x,sort_keys=True,separators=(',',':')) for x in rows)
    assert encode(left['mounts'])==encode(right['mounts'])


def main():
    global ROOT_META,OPAQUE,RUN_ALLOWED
    assert r.HEAD=='175f3cd6183894185656a2974579e1be39eed7a7' and r.PARENT==SOURCE
    assert r.OLD==r.B/'full-a-upload-root-current28dc-normal-next-source-r1'
    for name in ['retirement-author.py','retirement-checks.py.fragment','source_reader.py']:
        safe(r.OUT/name,'REVIEWED_SOURCE')
    safe(r.OLD/'retirement-author.py','REVIEWED_SOURCE',{'bytes':32834,'sha256':'6c2c04fe492bfb060574a02b33f23656c229b173dc668567e174fca1b4b6e229'})
    safe(r.OLD/'retirement-checks.py.fragment','REVIEWED_SOURCE',{'bytes':24983,'sha256':'1cfaf8a003108bef56e402038e1d04d161e456360852c0436a63964e81ebc0d2'})
    specs={
      'Root_final_admission':(ORG/'current28dc-form-quota-wire-fix-adoption-r1/RETIREMENT-ADMISSION.json',1608,'f0908b202e72422601abd064aee6df8c897921f62a59cfb6f546f482d8e604b6'),
      'Root_full':(ROOT_FULL/'REPORT.json',1272,'e72fdc670bda960f75ed400bda581bc21f6b2840216616dfd4e495e46f5db1d1'),
      'peer_full':(PEER/'REPORT.json',5453,'63001ae7ca961cbde6499b4393703404de14336975f6b5e5b47a9c7e495937dc'),
      'Root_full_inputs':(ROOT_FULL/'INPUTS.json',25465670,'9a945527687977a49e18dd4357302706ae96848b2eeb46eed6eed48e1e91b772'),
      'peer_observations':(PEER/'OBSERVATIONS.json',59868409,'18cb81f03832fe01f99e59423f4daf97d19bfffc458cb0cd980104e36559e3e3'),
      'close':(CLOSE/'RESULT.json',106564,'8e0f144e5f5982b060519ccd10450ffee3bb985f18a078dec25e84d9d97964cf'),
      'zero':(ZERO/'RESULT.json',27033,'89cd3c599161121569862e05ff1e27a603793a421986fb6d4122563ab0e8003d')}
    documents={};inputs={}
    for key,(path,size,digest) in specs.items():
        documents[key]=doc(path,'OBSERVATION_METADATA_ONLY' if key in ['Root_full_inputs','peer_observations'] else 'PUBLIC_RECEIPT_JSON',{'bytes':size,'sha256':digest})
        inputs[key]=ptr(path)
    admission,root,peer,close,zero=[documents[k] for k in ['Root_final_admission','Root_full','peer_full','close','zero']]
    observed=documents['peer_observations'];ROOT_META=documents['Root_full_inputs']
    assert len(ROOT_META)==44944 and all(name==row['path'] and set(row)=={'path','bytes','sha256','stat9'} and len(row['stat9'])==9 for name,row in ROOT_META.items())
    assert set(observed)=={'safe_files','opaque_files','directories','inventories','native_operations'}
    assert [len(observed[k]) for k in ['safe_files','opaque_files','directories','inventories','native_operations']]==[750,44478,6859,9,113]
    OPAQUE={x['path']:x for x in observed['opaque_files']};assert len(OPAQUE)==44478
    peer_map={x['path']:x for x in observed['safe_files']};assert len(peer_map)==750
    for row in [*observed['safe_files'],*observed['opaque_files']]:
        metadata_pointer(row)
        assert row['named_before']==row['named_after'] and row['fd_open']==row['fd_after']
        assert [row['named_before'][i] for i in [0,1,6,7]]==[row['fd_open'][i] for i in [0,1,6,7]]
    for key in ['run_id','source_sha']:
        assert admission[key]=={'run_id':RUN_ID,'source_sha':SOURCE}[key]
    assert admission['status']=='PASS_CURRENT28DC_MATCHING_FAILURE_RETIREMENT_ROOT_AND_PEER_FULL_ACTUAL_READBACK'
    assert admission['close_native_exit']==admission['zero_native_exit']==0
    assert (admission['close_operations'],admission['zero_operations'],admission['exact_mutations'])==(93,20,5)
    assert admission['failed_original_S_native_exit']==1 and admission['business_acceptance']=='INCOMPLETE' and admission['A0'] is None
    for key,target in [('root_actual_report','Root_full'),('independent_actual_report','peer_full'),('independent_observations','peer_observations')]:
        assert admission[key]==metadata_pointer(inputs[target])
    for result in [root,peer,close,zero]:
        assert result['run_id']==RUN_ID and result['source_sha']==SOURCE and result['freeze_sha256']==FREEZE
    assert root['status']=='PASS_ROOT_COMPLETE_ACTUAL_FULL_READBACK' and root['read_count']==44944
    assert root['preservation']=={'files':44471,'directories':6859,'all_bytes_stat9_namespaces_unchanged':True,'opaque_payload_retained_parsed_or_printed':False}
    assert root['all_actual_native_created_terminal_streams_PID_argv_wait0'] is root['complete_foreign_safe_facts_continuous'] is True
    assert root['new_native_calls']==0 and root['target_import_or_execution'] is False and root['original_S_native_exit']==root['paid_native_exit']==1
    assert peer['status']=='VERIFIED_FAILED_RUN_CLOSE_AND_INDEPENDENT_ZERO' and peer['mode']=='full' and peer['findings']==[] and peer['zero_admitted'] is True
    assert peer['original_S_native_wait']==[1,1] and peer['original_paid_native_restart']==[1,0]
    assert peer['full_business_acceptance']=='INCOMPLETE' and peer['opaque_payload_retained_or_decoded'] is False and peer['Docker_Git_Host_cleanup_invoked_by_reader'] is False
    for key in ['close','zero']:assert peer[key+'_result']==metadata_pointer(inputs[key])
    extra={
      'peer_reader_inputs':READERS/'INPUTS.json','reader_Source_PINS':READERS/'PINS.json',
      'retirement_Source_PINS':SRC/'PINS.json','actual_failure':FAILURE/'REPORT.json',
      'actual_failure_public_facts':FAILURE/'PUBLIC-EVIDENCE-FACTS.json',
      'M_result':r.OLD/'validation/materialize-freeze-assets-r1/RESULT.json',
      'collector':COLLECTOR/'REPORT.json','collector_created':COLLECTOR/'created.json','collector_terminal':COLLECTOR/'terminal.json'}
    for key,path in extra.items():
        expected=peer_map[path.as_posix()]['pin'] if path.as_posix() in peer_map else None
        if key=='reader_Source_PINS':expected={'bytes':3257,'sha256':'2a822252ab3f1e11cfb9c1e28b250109013485898ce0548ac6e914cc71c4bd52'}
        documents[key]=doc(path,expected=expected);inputs[key]=ptr(path)
    failure=documents['actual_failure'];facts=documents['actual_failure_public_facts'];materialized=documents['M_result']
    data=documents['peer_reader_inputs'];literals=data['cleanup_literals']
    assert literals['RUN_ID']==RUN_ID and literals['SOURCE']==SOURCE and literals['FREEZE']==FREEZE and len(literals['AUTHOR_PINS'])==528
    assert failure['root_native_command_count']==len(failure['root_native_commands'])==108 and len(failure['actual_archive_names'])==433
    assert failure['status']=='ACTUAL_CURRENT_FULL_A_PAID_FAILURE_PRESERVED_ONLY' and failure['Root_RESULT_exists'] is False and failure['A0'] is False
    assert failure['outer_S']['session']==59994 and failure['outer_S']['pid']==50220 and failure['outer_S']['native_exit']==failure['outer_S']['wait_returncode']==1
    assert failure['paid_container_exit']==1 and failure['paid_failure_code']=='M4C_FORM_EXACT_QUOTA_INCREMENT' and failure['paid_failure_phase']=='paid_real_confirmation'
    assert failure['normal_http_response_diagnostic'] is facts['normal_http_response_diagnostic'] is None
    assert len(materialized)==34 and list(materialized)==failure['M34_keys']==facts['current_M_result_keys']
    assert materialized['source_sha']==SOURCE and materialized['freeze_sha256']==FREEZE
    assert len(materialized['commit_chain'])==18 and len(materialized['committed_files'])==307 and len(materialized['native_operations'])==1078
    assert materialized['source_counts']==failure['source_counts']=={'driver':10,'customer':10865,'paid':10866,'fulfillment':10881,'aftersale_review':10885,'worker':10886}
    assert materialized['unique_source_files_actual_read']==10886 and len(materialized['selected9'])==9 and materialized['proof_resource_required'] is True
    # Exact original public Run metadata, taken from the reviewed peer finite map.
    evidence_names=['customer-prepare-created.json','customer-prepare-terminal.json','driver-created.json','driver-terminal.json',
      'files-bootstrap-created.json','files-bootstrap-terminal.json','fulfillment-migration-created.json','fulfillment-migration-terminal.json',
      'initializer-created.json','initializer-terminal.json','ms4-fulfillment-migration-container-created.json','ms5-payment-migration-container-created.json',
      'paid-producer-created.json','paid-producer-terminal.json','prepare-created.json','prepare-terminal.json',
      'register-customer-prepare-created.json','register-customer-prepare-terminal.json','register-driver-created.json','register-driver-terminal.json',
      'register-fulfillment-migration-created.json','register-fulfillment-migration-terminal.json','register-initializer-created.json','register-initializer-terminal.json',
      'register-paid-after-c3-created.json','register-paid-after-c3-terminal.json','register-paid-producer-created.json','register-paid-producer-terminal.json',
      'paid-producer-result.json','initializer-result.json','ms4-aftersale-early-migration-normal.json','ms5-payment-migration-normal.json','ms4-customer-prepare.json']
    RUN_ALLOWED={(RUN/name).as_posix() for name in ['actual-run-context.json','preparation-source-binding.json','manifest.json']}
    RUN_ALLOWED|={(RUN/'evidence'/name).as_posix() for name in evidence_names}
    RUN_ALLOWED|={row[k]['path'] for row in failure['root_native_commands'] for k in ['created','terminal']}
    assert len(RUN_ALLOWED)==252 and {name for name in peer_map if Path(name).is_relative_to(RUN)}==RUN_ALLOWED
    peer_safe=[]
    for row in observed['safe_files']:
        path=Path(row['path']);SAFE_EXPECTED[row['path']]=row['pin']
        category=('NATIVE_CREATED_TERMINAL' if path.name.endswith(('.created.json','.terminal.json')) or path.name in ['created.json','terminal.json'] else
          'DECLARED_EMPTY_STDERR' if path.name.endswith('.stderr') or path.name=='stderr' else
          'PUBLIC_NATIVE_STDOUT' if path.name.endswith('.stdout') or path.name=='stdout' else
          'REVIEWED_SOURCE' if path.suffix in ['.py','.md','.patch'] else 'PUBLIC_RECEIPT_JSON')
        raw=safe(path,category,row['pin']);assert category!='DECLARED_EMPTY_STDERR' or raw==b''
        assert r.READS[row['path']]['path_before']==row['named_before']
        peer_safe.append(ptr(path))
    for name,pin in documents['retirement_Source_PINS']['files'].items():safe(SRC/name,'REVIEWED_SOURCE',pin)
    for leaf in ['root-reader.py','reader.py']:
        pin=documents['reader_Source_PINS']['files'][leaf];safe(READERS/leaf,'REVIEWED_SOURCE',pin);inputs[leaf.replace('.py','_Source')]=ptr(READERS/leaf)
    assert metadata_pointer(root['reader'])==metadata_pointer(inputs['root-reader_Source'])
    assert literal_assignments(SRC/'normal-close.py',literals)==literal_assignments(SRC/'independent-zero.py',literals)==literals
    context=doc(RUN/'actual-run-context.json');manifest=doc(RUN/'manifest.json');frozen=doc(RUN/'preparation-source-binding.json')
    assert r.pin(r.CACHE[(RUN/'preparation-source-binding.json').as_posix()])['sha256']==FREEZE
    assert context['run_id']==RUN_ID and context['source_sha']==SOURCE and context['freeze_sha256']==FREEZE and context['network_id']==literals['NETWORK_ID']
    assert context['project']=='tianli-audit-'+RUN_ID and Path(context['prep_dir'])==r.OLD/'validation/actual-profile'
    assert manifest['run_id']==RUN_ID and manifest['commit_sha']==SOURCE and {k:v['id'] for k,v in manifest['services'].items()}==literals['ROLES']
    assert sorted(manifest['volumes'])==sorted(x['name'] for x in context['initial_volumes'])==sorted(context['project']+'_'+x for x in literals['VOLUME_SUFFIXES'])
    assert len(manifest['volumes'])==8 and context['initial_network']['Internal'] is True
    paid=doc(RUN/'evidence/paid-producer-terminal.json');paid_result=doc(RUN/'evidence/paid-producer-result.json')
    assert paid['status']=='exited' and paid['exit_code']==1 and paid['pid']==paid['restart_count']==0 and paid['id']==literals['ROLES']['audit-ms5-paid-producer']
    assert paid_result['status']=='INCOMPLETE_MS5_PAID_PRODUCER_INTERNAL_ONLY' and paid_result['failed_assertion']=='M4C_FORM_EXACT_QUOTA_INCREMENT'
    assert paid_result['full_acceptance']=='INCOMPLETE' and paid_result['native_exit'] is None and paid_result['native_exit_observation']=='HOST_NATIVE_REQUIRED'
    assert paid_result['customer_form_upload_child']==facts['customer_form_upload_child']==literals['FORM_UPLOAD_CHILD_PUBLIC']
    for child in [paid_result['child'],paid_result['customer_form_upload_child']]:
        assert child['stopped'] is True and child['remaining_members']==[] and child['listener_open'] is False and child['stop_signal']==15 and child['term_attempted'] is child['normal_only'] is True
    evidence_documents={name:doc(RUN/'evidence'/name) for name in evidence_names}
    for name in literals['CREATED_NAMES']:
        row=evidence_documents[name]
        assert row['run_id']==RUN_ID and row['source_sha']==SOURCE and row['labels']['com.docker.compose.project']==context['project'] and row['labels']['tianli.audit.run']==RUN_ID
        term=name.replace('-created.json','-terminal.json')
        if term in evidence_documents:
            ended=evidence_documents[term]
            assert ended['id']==row['id'] and ended['status']=='exited' and ended['pid']==ended['restart_count']==0
            assert ended['exit_code']==(1 if name=='paid-producer-created.json' else 0)
    init=evidence_documents['initializer-result.json'];early=evidence_documents['ms4-aftersale-early-migration-normal.json'];ms5=evidence_documents['ms5-payment-migration-normal.json'];customer=evidence_documents['ms4-customer-prepare.json']
    assert init['native_exit']==0 and len(init['children'])==9 and all(x['native_exit']==0 for x in init['children'])
    assert early['entry_return_code']==0 and early['termination']=='NORMAL_RETURN' and early['actual_command_counts']==literals['EARLY_COUNTS']
    assert len(early['children'])==4 and len(early['successor_children'])==16 and all(x['native_exit']==0 for x in early['children']+early['successor_children'])
    assert ms5['entry_return_code']==0 and ms5['termination']=='NORMAL_RETURN' and len(ms5['children'])==4 and all(x['native_exit']==0 for x in ms5['children'])
    assert customer['native_exit']==0 and customer['full_source_unchanged'] is True and customer['phase']=='final_source' and customer['child'] is None
    assert customer['business']['HTTP_executed'] is customer['business']['order_creation_executed'] is False
    baseline=doc(CLOSE/'PUBLIC-INPUT-PINS.json',expected=close['public_input_pins'])
    assert set(baseline)=={'files','directories'} and [len(baseline[k]) for k in ['files','directories']]==[44471,6859]
    for row in baseline['files']:
        assert metadata_pointer(row)==metadata_pointer(OPAQUE[row['path']]) and row==ROOT_META[row['path']]
        assert row['stat9']==OPAQUE[row['path']]['named_before']
    directories={row['path']:row for row in observed['directories']};assert len(directories)==6859
    assert all(directories[row['path']]==row for row in baseline['directories'])
    snapshots={}
    for key,count in [('root_source_snapshot',14),('complete_source_snapshot',31496)]:
        pointer=materialized[key];value=doc(pointer['path'],expected=pointer)
        assert len(value)==count and all(row==ROOT_META[name] for name,row in value.items())
        snapshots[key]={'pointer':ptr(pointer['path']),'records':count,'schema':['path','bytes','sha256','stat9']}
    for row in materialized['selected9']:safe(r.OLD/'validation/actual-profile'/row['path'],'PUBLIC_IMMUTABLE_PROFILE_SOURCE',row)
    m_native=[];m_opaque=[];m_safe=0;m_folder=r.OLD/'validation/materialize-freeze-assets-r1'
    for index,row in enumerate(materialized['native_operations'],1):
        assert row['stem'].startswith('%03d-'%index) and row['native_exit']==row['wait_returncode']==0
        made_path=m_folder/(row['stem']+'.created.json');ended_path=m_folder/(row['stem']+'.terminal.json')
        made=doc(made_path,'NATIVE_CREATED_TERMINAL');ended=doc(ended_path,'NATIVE_CREATED_TERMINAL')
        assert made=={'argv':row['argv'],'pid':row['pid']} and ended==row
        stderr_path=m_folder/(row['stem']+'.stderr');stdout_path=m_folder/(row['stem']+'.stdout')
        assert safe(stderr_path,'DECLARED_EMPTY_STDERR',{'bytes':row['stderr_bytes'],'sha256':row['stderr_sha256']})==b'' and row['stderr_sha256']==EMPTY
        expected={'bytes':row['stdout_bytes'],'sha256':row['stdout_sha256']};opaque=row['argv'][0]=='git' and row['argv'][5:6]==['show']
        if opaque:stdout=observed_pointer(stdout_path,expected);m_opaque.append(stdout)
        else:
            safe(stdout_path,'PUBLIC_GIT_METADATA_STDOUT',expected);stdout=ptr(stdout_path);m_safe+=1
        m_native.append({'index':index,'operation':row,'created':ptr(made_path),'terminal':ptr(ended_path),'stdout':stdout,'stderr':ptr(stderr_path),
          'created_document':made,'terminal_document':ended,'stdout_policy':'OPAQUE_HISTORICAL_METADATA_NO_BODY_READ' if opaque else 'PUBLIC_FULL_BYTES'})
    assert len(m_opaque)==763 and m_safe==315
    original_root=[];root_documents=[];root_stream_metadata={}
    root_rows=[failure['initial_prepare'],*failure['root_native_commands']];assert len(root_rows)==109
    for index,row in enumerate(root_rows,1):
        created=Path(row['created']['path']);stem=created.name.removesuffix('.created.json')
        native,made,ended,streams=four(created.parent,stem,code=0,opaque_streams=True)
        assert made['pid']==row['pid'] and Path(made['argv'][0])==Path(PY) and made['argv'][1:4]==['-B','-X','utf8']
        assert Path(made['argv'][4])==r.OLD/'validation/actual-profile/ms4-host.py'
        for key in ['created','terminal','stdout','stderr']:assert metadata_pointer(native[key])==row[key]
        command=made['argv'][5];assert command not in ['fulfillment-normal-start','aftersale-review-start','worker-start']
        if index==1:assert command=='prepare' and Path(made['argv'][7])==m_folder/'freeze-binding.json'
        else:assert made['argv'].count('--context')==1 and Path(made['argv'][made['argv'].index('--context')+1])==RUN/'actual-run-context.json'
        original_root.append(native)
        root_documents.append({'index':index,'command':command,'created':made,'terminal':ended,'stdout':native['stdout'],'stderr':native['stderr'],'streams_policy':'OPAQUE_ORIGINAL_METADATA_NO_BODY_READ'})
        root_stream_metadata.setdefault(command,[]).append({'index':index,'stdout':native['stdout'],'stderr':native['stderr']})
    receipts={};cleanup_documents={};inventories=[];foreign_previous=None;mutations_by_mode={};roles=literals['ROLES'];project=context['project']
    for mode,result,folder,count in [('close',close,CLOSE,93),('zero',zero,ZERO,20)]:
        assert result['failed_outer_native_exit']==result['paid_producer_native_exit']==1
        assert result['original_completed_native_zero']==108 and result['original_total_native_zero']==109 and result['original_initial_prepare_native_zero'] is True
        assert result['later_Task6_AR_Worker']=='NOT STARTED' and result['full_acceptance']=='INCOMPLETE'
        assert len(result['operations'])==count;receipts[mode]=[];cleanup_documents[mode]=[];mutations=[];pending=None;round_facts=[]
        for index,row in enumerate(result['operations'],1):
            assert row['index']==index and row['stem']=='%03d-'%index+re.sub('[^a-z0-9-]','-',row['label'].lower())
            native,made,ended,streams=four(folder,row['stem'],expected=row)
            receipts[mode].append({'index':index,'stem':row['stem'],**native})
            cleanup_documents[mode].append({'operation':row,'created':made,'terminal':ended})
            args=row['argv'][1:]
            if row['argv'][0]==DOCKER:
                if args[:1] in [['stop'],['rm']] or args[:2] in [['network','rm'],['volume','rm']]:mutations.append(args)
            else:
                assert row['argv'][0]=='git' and (args[-2:]==['rev-parse','HEAD'] or args[-3:]==['status','--porcelain=v1','--untracked-files=all'])
                assert streams['stdout']==((SOURCE+'\n').encode() if args[-1]=='HEAD' else b'')
            if row['label']=='global-cids-before':pending=streams['stdout'].decode().splitlines();assert len(set(pending))==len(pending)
            elif row['label']=='global-safe-consumer-facts':
                facts_now=[loads(line) for line in streams['stdout'].splitlines()]
                assert pending is not None and len(facts_now)==len(pending) and {x['id'] for x in facts_now}==set(pending)
                assert all(set(x)=={'id','mounts','network_mode','networks'} for x in facts_now)
                foreign={x['id']:x for x in facts_now if x['id'] not in roles.values()}
                if foreign_previous is None:foreign_previous=foreign
                assert set(foreign)==set(foreign_previous)
                for cid in foreign:inventory_equal(foreign_previous[cid],foreign[cid])
                inventories.append({'scope':mode,'operation_index':index,'facts':facts_now});round_facts.append(facts_now)
            elif row['label']=='global-cids-after':assert pending is not None and set(streams['stdout'].decode().splitlines())==set(pending);pending=None
        assert pending is None
        if mode=='close':
            assert mutations==[['stop','--timeout','-1',roles['audit-mysql']],['stop','--timeout','-1',roles['audit-redis']],['rm',*sorted(roles.values())],
                ['network','rm',literals['NETWORK_ID']],['volume','rm',*sorted(x['name'] for x in context['initial_volumes'])]]
        else:assert mutations==[] and result['resource_mutations']==0 and result['Host_imported'] is result['close_imported_or_invoked'] is False
        mutations_by_mode[mode]=mutations
        assert [(q['kind'],q['label']) for q in result['fresh_queries']]==[(kind,label) for kind in ['container','network','volume'] for label in ['project','run']]
        prefixes={'container':['ps','-aq','--no-trunc'],'network':['network','ls','-q'],'volume':['volume','ls','-q']}
        for q in result['fresh_queries']:
            row=result['operations'][q['operation_index']-1];label='com.docker.compose.project='+project if q['label']=='project' else 'tianli.audit.run='+RUN_ID
            assert row['argv']==[DOCKER,*prefixes[q['kind']],'--filter','label='+label] and row['stdout_bytes']==row['stderr_bytes']==0
        for leaf,facts_now in [('GLOBAL-BEFORE.json',round_facts[0]),('GLOBAL-AFTER.json',round_facts[-1])]:
            archive=doc(folder/leaf);assert archive['container_count']==len(archive['facts'])==len(facts_now)
            actual={x['id']:x for x in facts_now};saved={x['id']:x for x in archive['facts']};assert set(actual)==set(saved)
            for cid in actual:inventory_equal(actual[cid],saved[cid])
    assert len(inventories)==9
    for actual,saved in zip(inventories,observed['inventories']):
        assert saved['scope']==('current28dc-paid-producer-failed-normal-close-r1' if actual['scope']=='close' else 'current28dc-paid-producer-failed-independent-zero-r1')
        assert saved['operation_after']==actual['operation_index']+1 and cleanup_documents[actual['scope']][saved['operation_after']-1]['operation']['label']=='global-cids-after' and saved['safe_inventory']['container_count']==len(saved['safe_inventory']['facts'])==len(actual['facts'])
        actual_map={x['id']:x for x in actual['facts']};saved_map={x['id']:x for x in saved['safe_inventory']['facts']};assert set(actual_map)==set(saved_map)
        for cid in actual_map:inventory_equal(actual_map[cid],saved_map[cid])
    assert [x['operation'] for x in cleanup_documents['close']+cleanup_documents['zero']]==[{k:v for k,v in x.items() if k!='scope'} for x in observed['native_operations']]
    outer={};outer_documents={}
    for mode,folder,stem,code,pid,opaque in [
      ('M',r.OLD/'launcher','materialize',0,48108,True),('S',r.OLD/'launcher','normal-start',1,50220,True),
      ('close',ORG/'current28dc-paid-failure-retirement-launcher-source-r1/actual-close-r1',None,0,19528,False),
      ('zero',ORG/'current28dc-paid-failure-retirement-launcher-source-r2/actual-zero-r1',None,0,20716,False)]:
        native,made,ended,streams=four(folder,stem,code=code,opaque_streams=opaque)
        assert made['pid']==pid and Path(made['argv'][0])==Path(PY)
        outer[mode]=native;outer_documents[mode]={'created':made,'terminal':ended,'streams_policy':'OPAQUE_ORIGINAL_METADATA_NO_BODY_READ' if opaque else 'PUBLIC_FULL_BYTES'}
        if not opaque:
            outer_documents[mode]['stdout_json_lines']=[loads(line) for line in streams['stdout'].splitlines()]
            wrapper=peer[mode+'_wrapper'];assert wrapper['pid']==pid and wrapper['argv']==made['argv'] and wrapper['native_exit']==wrapper['wait_returncode']==0
            for key in ['created','terminal','stdout','stderr']:assert metadata_pointer(native[key])==wrapper[key]
    collector=documents['collector'];made=documents['collector_created'];ended=documents['collector_terminal']
    assert made['pid']==ended['pid'] and made['argv']==ended['argv']==[DOCKER,'logs',roles['audit-ms5-paid-producer']]
    assert ended['native_exit']==ended['wait_returncode']==collector['native_exit']==collector['wait_returncode']==0
    assert collector['container_id']==roles['audit-ms5-paid-producer'] and collector['business_terminal_exit']==1 and collector['business_acceptance']=='INCOMPLETE'
    assert collector['collection_zero_is_not_business_acceptance'] is True and collector['raw_streams_retained'] is False and collector['unprojected_line_count']==0
    assert collector['normal_http_response_diagnostic_present'] is True and collector['normal_http_response_diagnostic'] is None
    assert collector['first_throwable_location']==failure['first_throwable_location']==documents['actual_failure_public_facts']['first_throwable_location']
    assert collector['safe_records'][0]['projection']['failed_assertion']=='M4C_FORM_EXACT_QUOTA_INCREMENT'
    collector_path='help/audit-regressions/orchestration/collect_paid_failure.py'
    collector_steps=[step for step in materialized['commit_chain'] if any(row['path']==collector_path for row in step['files'])]
    assert len(collector_steps)==1
    collector_step=collector_steps[0];collector_change=next(row for row in collector_step['files'] if row['path']==collector_path)
    assert collector_change['after']==r.compact(collector['public_input_pins']['collector_source'])
    collector_source=Path(collector_step['commit']['path']).parent/'git-blobs/after'/collector_path
    safe(collector_source,'REVIEWED_SOURCE',collector['public_input_pins']['collector_source']);inputs['collector_original_frozen_Source']=ptr(collector_source)
    for key,path in [('actual_native_created',COLLECTOR/'created.json'),('actual_native_terminal',COLLECTOR/'terminal.json')]:assert r.pin(safe(path,'NATIVE_CREATED_TERMINAL'))==r.compact(collector[key])
    for folder,leaf in [(CLOSE,'normal-close.py'),(ZERO,'independent-zero.py')]:
        assert r.pin(safe(folder/'root-driver-source.py','REVIEWED_SOURCE'))==r.pin(safe(SRC/leaf,'REVIEWED_SOURCE'))
    previous=[key for key in materialized if key=='prior_retirement' or key.endswith('_failure_retirement')]
    assert len(previous)==8
    checks={'M34_fields':list(materialized),'M_commit_count':18,'M_committed_paths':307,'M_native_zero':1078,
      'M_safe_stdout_full':m_safe,'M_opaque_stdout_metadata_only':len(m_opaque),'original_Root_Host_native_zero':109,
      'original_Root_archive_commands':108,'native_close':93,'native_zero':20,'exact_close_mutations':mutations_by_mode['close'],
      'zero_mutations':[],'fresh_empty_queries_each':6,'foreign_inventories':9,'peer_safe_complete':750,
      'Root_complete_metadata_rows':44944,'original_public_files_metadata_only':44471,'original_public_directories_metadata_only':6859,
      'all_mapping_records_metadata_only':31496,'W_Val_old_body_reads':0,'opaque_payload_reads':0,'Run_public_metadata_JSON_reads':252,
      'Run_stream_body_reads':0,'target_native_calls':0,'initial_prepare_outside_archive_included_first':True,'collector_reexecuted':False}
    safe_public=[dict(r.READS[name])|{'classification':sorted(CLASSES[name])} for name in sorted(CLASSES)]
    safe_pointers=[{k:row[k] for k in ['path','bytes','sha256','native9']} for row in safe_public]
    value={'schema':1,'scope':'NINTH_CURRENT28DC_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY',
      'run_id':RUN_ID,'source_sha':SOURCE,'freeze_sha256':FREEZE,'inputs':inputs,'failed_context':context,
      'original_public_metadata_documents':{'manifest':manifest,'evidence':evidence_documents},
      'native_receipts':receipts,'original_root_native_receipts':original_root,'outer_native_receipts':outer,
      'Root_actual_complete_public_inputs':safe_pointers,'peer_original_safe_public_inputs':peer_safe,
      'original_root_native_documents':root_documents,'original_root_stdout_metadata_by_command':root_stream_metadata,
      'cleanup_native_documents':cleanup_documents,'outer_native_documents':outer_documents,
      'M_complete_native_receipts':m_native,'M_result_schema':list(materialized),'M_complete_source_snapshots':snapshots,
      'foreign_inventory_full_safe_facts':inventories,'ordinary_close_mutations':mutations_by_mode['close'],
      'fresh_queries':{'close':close['fresh_queries'],'zero':zero['fresh_queries']},
      'opaque_historical_observations':{'policy':'WHOLE_FIXED_METADATA_DOCUMENTS_NEVER_DEREFERENCE_OPAQUE_PATHS',
        'peer_observations':inputs['peer_observations'],'Root_observations':inputs['Root_full_inputs'],'original_baseline':ptr(CLOSE/'PUBLIC-INPUT-PINS.json'),
        'Root_rows':44944,'original_files':44471,'directories':6859,'peer_opaque_rows':44478,'body_reads':0,'mapping_rows':31496,
        'opaque_row_schema':list(observed['opaque_files'][0]),'directory_row_schema':['path','stat9']},
      'safe_read_policy':{'peer_finite_safe_inputs':750,'full_safe_reads':len(safe_pointers),'categories':dict(collections.Counter(c for row in safe_public for c in row['classification'])),
        'M_git_show_stdout_body_reads':0,'M_git_show_stdout_metadata_count':763,'environment_private_application_child_body_reads':0,'old_W_Val_body_reads':0,
        'Run_public_metadata_JSON_allowlist':sorted(RUN_ALLOWED),'Run_stream_body_reads':0,'original_Root_stream_policy':'OPAQUE_HISTORICAL_METADATA_NO_BODY_READ'},
      'historical_eight_retirements':{key:materialized[key] for key in previous},'M34_preserved':True,'M_native_count':1078,
      'M_complete_source_metadata_rows':31496,'M_commit_count':18,'M_source_files_committed':307,
      'original_S_native_exit':1,'paid_producer_native_exit':1,'original_paid_restart_count':0,'A0':False,
      'later_Task6_AR_Worker':'NOT STARTED','business_acceptance':'INCOMPLETE',
      'HTTP_failure':{'failed_assertion':'M4C_FORM_EXACT_QUOTA_INCREMENT','first_throwable_location':collector['first_throwable_location'],
        'normal_http_response_diagnostic_present':True,'normal_http_response_diagnostic':None,'raw_private_response_or_quota_read':False},
      'collector_original_once_only':True,'private_or_old_source_bodies_read':False,'new_source_M_S_business':'NOT RUN','checks':checks}
    r.put('CURRENT-28DC-RETIREMENT-COMPLETE.json',value);authority=r.pointer(r.OUT/'CURRENT-28DC-RETIREMENT-COMPLETE.json')
    report={'schema':1,'status':'PASS_SOURCE_ONLY_COMPLETE_NINTH_RETIREMENT_BINDING','authority':authority,'authority_fields':list(value),
      'inputs_fields':list(inputs),'checks':checks,'safety_classification':value['safe_read_policy'],
      'original_business_state':'S1_PAID1_NO_A0_LATER_NOT_STARTED','historical_baseline':'SAVED_ORIGINAL_METADATA_NOT_CURRENT_W_VAL',
      'original_collector_reexecution':False,'original_close_zero_reexecution':False,'original_author_or_reader_import_or_execution':False,
      'new_business_or_native_execution':False,'open_business_acceptance':True}
    r.put('retirement-source/REPORT.json',report)
    r.put('retirement-source/INPUTS.json',{'schema':1,'scope':'FULL_FINITE_SAFE_READ_WINDOWS_AND_COMPLETE_OPAQUE_METADATA_DOCUMENTS',
      'files':safe_public,'opaque_metadata_documents':[inputs['peer_observations'],inputs['Root_full_inputs']],'all_windows_ended':True})
    names=['retirement-author.py','retirement-checks.py.fragment','CURRENT-28DC-RETIREMENT-COMPLETE.json','retirement-source/REPORT.json','retirement-source/INPUTS.json']
    r.put('retirement-source/PINS.json',{'schema':1,'scope':'SOURCE_ONLY_NINTH_RETIREMENT_COMPLETE_PUBLIC_BINDING',
      'files':{name:r.pin(r.read(r.OUT/name)) for name in names},'authority':authority,'authority_fields':list(value),
      'safe_public_inputs':len(safe_pointers),'native_close_zero':[93,20],'Root_Host_native':109,'M_native':1078,'business':'INCOMPLETE'})
    print(json.dumps({'authority':authority,'pins':r.pointer(r.OUT/'retirement-source/PINS.json'),'safe_public_inputs':len(safe_pointers)},ensure_ascii=False))


if __name__=='__main__':
    try:main()
    except Exception:
        error=traceback.format_exc();folder=r.OUT/'retirement-source';folder.mkdir(exist_ok=True);index=1
        while (folder/('ERROR-%02d.json'%index)).exists():index+=1
        r.put('retirement-source/ERROR-%02d.json'%index,{'schema':1,'status':'OWN_SOURCE_AUTHOR_ERROR_PRESERVED','traceback':error,'source':r.pointer(Path(__file__)),'target_calls':0})
        r.write('retirement-source/ERROR-%02d-retirement-author.py'%index,r.read(Path(__file__)))
        raise
