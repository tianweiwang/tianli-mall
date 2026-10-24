"""Independent complete saved-M36 readback. No target imports, subprocesses, Git or resource APIs."""
import argparse,ast,hashlib,json,os,re,stat,time,traceback
from collections import Counter
from pathlib import Path

B=Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1')
SRC=B/'full-a-preflight-diagnostic-current6e3850-normal-next-source-r2'
OUT=Path(__file__).parent
ROOT=SRC/'validation';PROFILE=ROOT/'actual-profile';ACTUAL=ROOT/'materialize-freeze-assets-r1'
HEAD='6e3850fe210b2b1e99b2a33baa68123e34aefab6';PARENT='175f3cd6183894185656a2974579e1be39eed7a7'
PINS_EXPECTED={'bytes': 68413, 'sha256': 'd3e61099b79c564658d3c2a2d56407501b47583790c44c34bbdd3617da1b4f54'}
CALLBACK_REPORT={'path': 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-original-source-callback-actual-r2/REPORT.json', 'bytes': 5621, 'sha256': 'f2c5910fb4ba2dd3137133c5b51adccee5e98e1dbe8692b6a75288a4d4fdb1cf'}
CALLBACK_INPUTS={'path': 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-original-source-callback-actual-r2/INPUTS.json', 'bytes': 42084153, 'sha256': '7234db16fdd27ec298d059cfcfbddc9be1d1389ee308dd9155b1d3a9dde43954'}
CALLBACK_ROOT={'path': 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-source-callback-root-admission-r2/ACTUAL-READBACK.json', 'bytes': 1534, 'sha256': '1267c9428dcec57a7a11fe79cdc88372cfa917d23dc2e0f4350de4224b9a0477'}
CALLBACK_RETURN_PIN={'bytes': 96760186, 'sha256': 'a6c3c7ad75a16ea2493794b2bfc9181ddb518ce1e69d217f2e888a9731650ed3'}
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

def main():
    need(not (OUT/'PINS.json').exists(),'ONE_INDEPENDENT_REVIEW_ONLY')
    started=time.time_ns();read(OUT/'review.py');read(OUT/'DESIGN.md')
    own_source_pins=js(OUT/'SOURCE-PINS.json')
    for name,wanted in own_source_pins['files'].items():need(pin(read(OUT/name))==wanted,'OWN_FIXED_READER_SOURCE_WHOLE_PIN')
    source_pins=js(SRC/'PINS.json');need(pin(read(SRC/'PINS.json'))==PINS_EXPECTED,'FROZEN_CURRENT6E3850_SOURCE_EXACT_IDENTITY')
    need(len(source_pins['files'])==115,'FIXED_COMPLETE115_SOURCE_MEMBERS')
    for name,pointer in source_pins['files'].items():
        need(pointer['path']==(SRC/name).as_posix(),'SOURCE115_CANONICAL_MEMBER_PATH')
        pinned(pointer)
    # Actual complete original callbacks; metadata only, no target execution.
    callback_report=decode(pinned(CALLBACK_REPORT));callback_inputs=decode(pinned(CALLBACK_INPUTS));callback_root=decode(pinned(CALLBACK_ROOT))
    need(callback_report['status']=='PASS_ORIGINAL_COMPLETE_SOURCE_AUTHORITY_CALLBACKS_ONLY' and callback_report['source_PINS']=={'path':(SRC/'PINS.json').as_posix(),**PINS_EXPECTED}
        and callback_report['complete_return_objects_equal'] is True and callback_report['complete_canonical_return_pin']==CALLBACK_RETURN_PIN
        and callback_report['actual_input_paths']==25939 and callback_report['stages']==20 and callback_report['retirements']==10
        and callback_report['callback_native_calls']==0 and callback_report['M_body_or_S_main_or_App_business']=='NOT RUN' and callback_report['A0'] is False,'TRUE_COMPLETED_ORIGINAL_M_S_SOURCE_CALLBACKS_NOT_BUSINESS')
    need(set(callback_report['qualification'])=={'M','S'} and all(q['module']==label and q['qualification_namespace']=='ONLY_ACTUAL_DECLARED_MODULE_IMPORTS_AND_LITERAL_ASSIGNMENTS'
        and q['manual_dependency_injection'] is False and q['unbound_globals']==[] and q['pure_source_checks']==7904982 for label,q in callback_report['qualification'].items()),'BOTH_REAL_COMPLETE_DECLARED_NAMESPACES_AND_ALL_ORIGINAL_CHECKS')
    need(callback_root['status']=='PASS_ROOT_ACTUAL_COMPLETE_ORIGINAL_R2_SOURCE_CALLBACKS0_PUBLIC_RECORDS' and callback_root['actual_REPORT']==CALLBACK_REPORT and callback_root['actual_INPUTS']==CALLBACK_INPUTS
        and callback_root['native_pid']==52348 and callback_root['native_exit']==callback_root['wait_returncode']==0 and callback_root['whole_created_terminal_stdout_stderr_join'] is True
        and callback_root['complete_actual_M_and_S_calls_each_checks']==7904982 and callback_root['complete_return_objects_equal'] is True and callback_root['complete_canonical_return_pin']==CALLBACK_RETURN_PIN
        and all(callback_root[k] is True for k in ['genuine_fixed_r2_source_entries_and_declared_namespaces','all25939_original_first_EOF_own9_cross4_closed_windows_validated','original_cache_semantics_preserved_no_two_independent_disk_reads_claim','Root_did_not_reopen_all25939_bodies','current175f_original_outer_M_S_stream_bodies_absent','current175f_named_ENV_bodies_absent','Root_own_first_final_windows_ended'])
        and callback_root['callback_native_calls']==0 and callback_root['new_M_S_App_business']=='NOT RUN' and callback_root['A0'] is False,'ROOT_ACTUAL_CALLBACK_NATIVE0_AND_COMPLETE_PUBLIC_GRAPH_READBACK')
    need(set(callback_inputs)=={'files','cache_semantics','callback_invocations'} and callback_inputs['cache_semantics']=='ORIGINAL_FIRST_FULL_EOF_READ_ONCE_PER_PATH_THEN_CACHE'
        and callback_inputs['callback_invocations']==2 and type(callback_inputs['files']) is dict and len(callback_inputs['files'])==25939,'REAL_COMPLETE25939_FIRST_EOF_CACHE_GRAPH_NOT_STATIC_AUTHOR_SAMPLE')
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
    need({key for key in callback_authority_paths if key.startswith(SRC.as_posix()+'/')}=={(SRC/name).as_posix() for name in ['CURRENT-SOURCE-AUTHORITY.json','INITIALIZER-PREFLIGHT-DIAGNOSTIC-SYNTAX.json']},'STATICALLY_DECLARED_R2_AUTHORITY_PATHS_EXCLUDE_CALLER_BOOTSTRAP')
    members=source_pins['source_members18'];need(len(members)==18,'COMPLETE_ORIGINAL18_PROGRAM_FILES')
    frozen={name:pinned(source_pins['files'][name]) for name in members}
    m=frozen['materializer/prepare.py'];values=source_constants(m);launcher_values=source_constants(frozen['launcher/launch.py'])
    need(values['head']==HEAD and values['parent']==PARENT,'SOURCE_CURRENT_HEAD_AND_PARENT')
    pub=decode(frozen['validation/PROFILE-PUBLICATION.json']);context=pub['finite_contexts'];chain=values['COMMIT_CHAIN']
    need(pub['source_sha']==HEAD and pub['parent']==PARENT and chain==context['commit_chain'] and len(chain)==20,'CURRENT_SOURCE_PUBLICATION_AND_ALL20_STEPS')
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
    need(len(expected)==15748 and len(committed)==307 and len(requirements)==313 and len(additions)==262
        and changes==context['committed_changes'] and committed==context['committed_files'] and additions==values['NEW_TRACKED_PATHS']==context['new_tracked_paths']
        and pub['repository_Source_requirements']==[expected[name] for name in requirements],'INDEPENDENT_CURRENT15748_CUMULATIVE307_REQUIRED313_ADDITIONS262')
    need(pin(read(ACTUAL/'RESULT.json'))=={'bytes':ARGS.result_bytes,'sha256':ARGS.result_sha256},'ACTUAL_RESULT_ROOT_SUPPLIED_COMPLETE_PIN')
    result=js(ACTUAL/'RESULT.json');result_pointer=descriptor(ACTUAL/'RESULT.json')
    need(type(result) is dict and set(result)==set(values['CURRENT_M_RESULT_KEYS36']) and len(result)==36,'EXACT_CURRENT_M36_NOT_HISTORICAL35')
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
    need(set(frozen_value)==set(previous) and type(frozen_value['schema']) is int and result['freeze']==(ACTUAL/'freeze-binding.json').as_posix() and frozen_value['schema']==1 and frozen_value['status']=='CLEAN_SOURCE_FROZEN_ONLY'
        and frozen_value['source_sha']==HEAD and frozen_value['requested_repo_source']==valroot.as_posix() and frozen_value['artifact_files']==pub['selected9']
        and frozen_value['runtime_SQL_App']=='NOT RUN' and result['source_counts']==counts and result['unique_source_files_actual_read']==len(combined),'ACTUAL_FULL_FREEZE_RESULT_AND_ORIGINAL_NO_APP_SCOPE')
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
    need(len(plan)==len(result['native_operations'])==budget==1104,'COMPLETE_ORIGINAL_FORMULA_NATIVE1104_PLAN')
    outputs={};actual_names={'ROOT-SOURCE-SNAPSHOT.json','AUTHORITY-INPUT-SNAPSHOT.json','root-materialize-source.py','freeze-binding.json','COMPLETE-SOURCE-SNAPSHOT.json','RESULT.json'}
    opkeys={'label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','stem'}
    for index,((label,argv),operation) in enumerate(zip(plan,result['native_operations']),1):
        stem='%03d-'%index+label
        need(set(operation)==opkeys and operation['stem']==stem and operation['label']==label and operation['argv']==argv
            and type(operation['pid']) is int and operation['pid']>0 and type(operation['native_exit']) is int and type(operation['wait_returncode']) is int
            and operation['native_exit']==operation['wait_returncode']==0 and type(operation['stdout_bytes']) is int and type(operation['stderr_bytes']) is int,
            'ALL1104_EXACT_SOURCE_DERIVED_ORDER_ARGV_SAME_NATIVE_WAIT')
        created=js(ACTUAL/(stem+'.created.json'));terminal=js(ACTUAL/(stem+'.terminal.json'))
        need(set(created)=={'pid','argv'} and created=={key:operation[key] for key in ['pid','argv']} and terminal==operation,'ALL1104_ACTUAL_CREATED2_AND_TERMINAL10_COMPLETE')
        for stream in ['stdout','stderr']:
            raw=read(ACTUAL/(stem+'.'+stream));need(len(raw)==operation[stream+'_bytes'] and hashlib.sha256(raw).hexdigest()==operation[stream+'_sha256'],'ALL1104_COMPLETE_DOUBLE_RAW_STREAMS_HASH_JOIN')
            if stream=='stderr':need(raw==b'','ALL1104_EXACT_ZERO_BYTE_STDERR')
            else:outputs[label]=raw
        actual_names|={stem+'.'+ext for ext in ['created.json','terminal.json','stdout','stderr']}
    need({p.name for p in ACTUAL.iterdir()}==actual_names and len(actual_names)==4*budget+6 and all(p.is_file() for p in ACTUAL.iterdir()),'EXACT_COMPLETE4422_SUCCESS_OUTPUT_NAMESPACE_NO_FAILURE')
    for label in ['main','validation']:
        need(outputs[label+'-head-before']==outputs[label+'-head-after']==(HEAD+'\n').encode() and outputs[label+'-status-before']==outputs[label+'-status-after']==b''
            and outputs[label+'-parent']==(HEAD+' '+PARENT+'\n').encode(),'ACTUAL_SOURCE_HEAD_CLEAN_PARENT_BEFORE_AND_AFTER')
        names=outputs[label+'-complete-tracked-inventory'].decode('utf-8').split('\0');need(names[-1]=='' and sorted(names[:-1])==list(expected) and len(names[:-1])==15748,'BOTH_COMPLETE_ACTUAL_NATIVE_TRACKED_NAMESPACES')
    for index,step in enumerate(chain,1):
        label='source-step-'+str(index);need(outputs[label+'-parent']==(step['source_sha']+' '+step['parent']+'\n').encode()
            and sorted(outputs[label+'-paths'].decode().splitlines())==[row['path'] for row in step['files']],'ALL20_REAL_NATIVE_PARENT_AND_COMPLETE_PATHS')
        for position,row in enumerate(step['files'],1):
            mark=label+'-member-'+str(position);need(pin(outputs[mark+'-after'])==row['after'],'ALL20_COMPLETE_NATIVE_ACTUAL_AFTER_BLOBS')
            if row['before'] is None:need(outputs[mark+'-absent-before']==b'' and row['path'] in additions,'ALL_ACTUAL_NEW_FILES_ABSENT_FROM_REAL_PARENT')
            else:need(pin(outputs[mark+'-before'])==row['before'],'ALL20_COMPLETE_NATIVE_ACTUAL_BEFORE_BLOBS')
    need(sorted(outputs['cumulative-changed-paths'].decode().splitlines())==[row['path'] for row in committed],'ACTUAL_NATIVE_CUMULATIVE307_PATHS')
    for index,row in enumerate(committed,1):need(pin(outputs['committed-blob-'+str(index)])==compact(row),'ALL307_COMPLETE_CURRENT_NATIVE_COMMITTED_BLOBS')
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
    need(list(snapshot)==keys and len(snapshot)==31496,'EXACT31496_COMPLETE_DUAL_SOURCE_METADATA_NAMESPACES')
    for repo in [mainroot,valroot]:
        for name,row in expected.items():
            key=(repo/name).as_posix();held=snapshot[key];row_valid(key,held);need(compact(held)==compact(row),'EVERY_COMPLETE_TRACKED_SOURCE_METADATA_ROW_INDEPENDENT_EXPECTATION')
    authority=js(ACTUAL/'AUTHORITY-INPUT-SNAPSHOT.json');authorinputs=decode(pinned(source_pins['files']['AUTHOR-INPUTS.json']))['inputs']
    need(set(authority)==callback_authority_paths,'EXACT_COMPLETE_ACTUAL_M_AUTHORITY_GRAPH_FROM_ORIGINAL_CALLBACK_CHECKED_BOUNDARY')
    for key,row in authority.items():
        row_valid(key,row);need(key in callback_graph and compact(row)==compact(callback_graph[key]) and row['stat9']==callback_graph[key]['native9'],'EVERY_AUTHORITY_METADATA_ROW_MATCHES_REAL_COMPLETE_CALLBACK_QUALIFICATION_WINDOW')
        if key in authorinputs:need(compact(row)==compact(authorinputs[key]) and row['stat9']==authorinputs[key]['native9'],'EVERY_INTERSECTING_STATIC_AUTHOR_METADATA_ROW_RETAINS_ORIGINAL_PIN_NATIVE9_JOIN')
    need(len(authority)>10000 and len(authority)==len(callback_graph)-len(callback_bootstrap),'COMPLETE_ORIGINAL_AUTHORITY_INPUT_METADATA_NOT_SMALL_SAMPLE')
    # All eight original retirement facts and separately named ninth actual28dc failure remain full distinct objects.
    retirement_constants={'prior_retirement':'CURRENT_RETIREMENT','current_driver_failure_retirement':'CURRENT_DRIVER_FAILURE_RETIREMENT','current_early_failure_retirement':'CURRENT_EARLY_FAILURE_RETIREMENT',
        'current_customer_prepare_failure_retirement':'CURRENT_CUSTOMER_PREPARE_FAILURE_RETIREMENT','current_paid_producer_failure_retirement':'CURRENT_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_2e4_paid_producer_failure_retirement':'CURRENT_2E4_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_881_paid_producer_failure_retirement':'CURRENT_881_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_0c13_paid_producer_failure_retirement':'CURRENT_0C13_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_28dc_paid_producer_failure_retirement':'CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT',
        'current_175f_paid_producer_failure_retirement':'CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT'}
    for field,symbol in retirement_constants.items():need(result[field]==decode(pinned(values[symbol])),'ALL_TEN_DISTINCT_ORIGINAL_RETIREMENT_FACTS_FULL_OBJECT_JOIN')
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
        and ninth['historical_eight_retirements']=={field:result[field] for field in retirement_constants if field not in ['current_28dc_paid_producer_failure_retirement','current_175f_paid_producer_failure_retirement']},'NINTH_WHOLE_REAL_FAILURE_PRESERVES_ALL_EIGHT_PREDECESSOR_FACTS')
    tenth=result['current_175f_paid_producer_failure_retirement']
    need(context['current_175f_paid_producer_failure_retirement']==values['CURRENT_175F_PAID_PRODUCER_FAILURE_RETIREMENT']
        and tenth['scope']=='TENTH_CURRENT175F_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY' and tenth['run_id']=='20261023182634096f57' and tenth['source_sha']==PARENT
        and tenth['M35_preserved'] is True and tenth['M_native_count']==1088 and tenth['M_commit_count']==19 and tenth['M_complete_source_metadata_rows']==31496
        and tenth['original_S_native_exit']==tenth['paid_producer_native_exit']==1 and tenth['original_paid_restart_count']==0 and tenth['A0'] is False and tenth['business_acceptance']=='INCOMPLETE','TENTH_WHOLE_REAL175F_FAILURE_NOT_NEW_M_OR_BUSINESS_SUCCESS')
    old_nine={field:result[field] for field in retirement_constants if field!='current_175f_paid_producer_failure_retirement'}
    need(tenth['historical_nine_retirements']==old_nine and tenth['historical_nine_whole_encoded_pins']=={field:pin((json.dumps(held,ensure_ascii=False,indent=2)+'\n').encode('utf8')) for field,held in old_nine.items()},'ALL_NINE_PREDECESSOR_RETIREMENTS_AND_COMPLETE_ENCODED_PINS_PRESERVED')
    policy=tenth['safe_read_policy']
    need(policy['categories']['PUBLIC_GIT_METADATA_STDOUT']==317 and policy['M_git_show_stdout_metadata_count']==771
        and policy['M_git_show_stdout_body_reads']==policy['environment_private_application_child_body_reads']==policy['old_W_Val_body_reads']==policy['Run_stream_body_reads']==0,'TENTH_ORIGINAL_PUBLIC317_SHOW771_AND_PRIVATE_STREAM_METADATA_ONLY_POLICY')
    for mode in ['M','S']:
        for stream in ['stdout','stderr']:need(tenth['outer_native_receipts'][mode][stream]['path'] not in callback_graph,'ORIGINAL175F_OUTER_STREAM_BODIES_ABSENT_FROM_QUALIFICATION_GRAPH')
    for held in tenth['forbidden_environment_metadata']['rows']:need(held['path'] not in callback_graph,'ORIGINAL175F_ENV_NAMED_METADATA_ONLY_NO_BODY_IN_QUALIFICATION_GRAPH')
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
    need(source_namespace==expected_namespace and len(source_namespace)==len(source_pins['files'])+1+4+len(actual_names),'EXACT115_FIXED_SOURCE_PLUS_ACTUAL4422_AND_OUTER4_FILE_NAMESPACE')
    # All own finite read windows are held through the end, without reading worktree bodies.
    for key,window in WINDOWS.items():need(nine(Path(key).lstat())==window['named_after'],'ALL_READBACK_INPUT_NAMED_NATIVE9_HELD_THROUGH_END')
    ended=time.time_ns();put('INPUTS.json',{'schema':1,'finite_input_count':len(WINDOWS),'windows':WINDOWS,'all_finite_windows_closed':True,'started_ns':started,'ended_ns':ended})
    put('CHECKS.json',{'schema':1,'counts':dict(COUNTS),'total_checks':sum(COUNTS.values())})
    report={'schema':1,'status':'PASS_COMPLETE_ACTUAL_CURRENT6E3850_M36_SOURCE_MATERIALIZATION_READBACK_ONLY','source_sha':HEAD,'parent':PARENT,
        'actual_RESULT':result_pointer,'actual_freeze':freeze_pointer,'actual_M_native_operations':budget,'all1104_created_terminal_and_double_raw_streams_complete':True,'actual_outer_pid':terminal['pid'],'actual_outer_native_exit_wait':0,
        'source_program_members':18,'Root_source_full_bytes_and_native9':14,'M_source_full_bytes_and_native9':3,'complete_tracked_rows_per_tree':15748,'complete_dual_source_metadata_rows':31496,'authority_metadata_rows':len(authority),
        'all20_actual_commit_native_before_after_blobs_and_paths_verified':True,'cumulative307_required313_baseline_additions262':True,'six_full_source_groups':counts,'complete_normal_source_union':len(combined),'M_RESULT_current_fields':36,'M_RESULT_historical_fields_preserved':35,
        'ten_retirement_facts_whole_joined_without_overwriting_old_nine':True,'complete115_Source_bytes_native9_preserved':True,'Source_plus_actual_output_namespace_files':len(source_namespace),'original_source_clean_before_after_from_real_native_streams':True,'saved_output_namespace_files':len(actual_names),
        'old_or_current_worktree_bodies_reread':False,'target_module_or_source_authority_import_or_execution':False,'new_Git_Docker_resource_or_process_calls':0,
        'Run_created_by_M':False,'M_App_DB_HTTP_business':'NOT RUN','S_or_full_business_acceptance':'NOT ASSERTED_BY_THIS_M_READBACK','finite_input_count':len(WINDOWS),'all_finite_windows_closed':True,'complete_callback_REPORT':CALLBACK_REPORT,'complete_callback_INPUTS':CALLBACK_INPUTS,'Root_callback_actual_readback':CALLBACK_ROOT,'complete_callback_graph_rows':len(callback_graph),'exact_M_authority_projection_rows':len(authority),'static_author_input_rows':len(authorinputs),'ended_ns':ended}
    put('REPORT.json',report)
    put('PINS.json',{'schema':1,'scope':'INDEPENDENT_COMPLETE_SAVED_ACTUAL_M36_EVIDENCE_READBACK','files':{p.name:pin(p.read_bytes()) for p in sorted(OUT.iterdir()) if p.is_file() and p.name!='PINS.json'}})
    print(json.dumps({'REPORT':pin((OUT/'REPORT.json').read_bytes()),'PINS':pin((OUT/'PINS.json').read_bytes()),'finite_inputs':len(WINDOWS),'native':budget,'Source_only_M':True},ensure_ascii=False))

if __name__=='__main__':
    parser=argparse.ArgumentParser(description='Read completed exact current6e3850 saved M evidence only; no target calls.')
    for name in ['result-bytes','freeze-bytes','outer-pid']:parser.add_argument('--'+name,type=int,required=True)
    for name in ['result-sha256','freeze-sha256']:parser.add_argument('--'+name,required=True)
    ARGS=parser.parse_args()
    need(ARGS.result_bytes>0 and ARGS.freeze_bytes>0 and ARGS.outer_pid>0 and all(re.fullmatch('[a-f0-9]{64}',v) for v in [ARGS.result_sha256,ARGS.freeze_sha256]),'EXACT_REAL_COMPLETED_RESULT_FREEZE_AND_PID_ARGUMENTS')
    try:main()
    except Exception as error:
        index=len(list(OUT.glob('READER-FAILURE-*.json')))+1
        put('READER-FAILURE-'+str(index).zfill(2)+'.json',{'status':'OWN_READBACK_REFUSED_ORIGINAL_M_UNCHANGED','class':type(error).__name__,'message':str(error),'traceback':traceback.format_exc(),'all_target_or_resource_calls':0})
        raise
