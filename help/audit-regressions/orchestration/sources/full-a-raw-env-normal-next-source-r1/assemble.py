"""Create the next immutable normal source; no Host/PHP/Docker or business execution."""
import ast,copy,json,re,time,hashlib
from pathlib import Path
import source_reader as r
import refresh_profile

OUT=r.OUT;OLD=r.OLD;B=r.B;HEAD=r.HEAD;PARENT=r.PARENT
ADOPT=B/'customer-form-official-initialize-env-main-adoption-r1'
RAW=B/'customer-form-official-initialize-env-raw-source-r1'
PEER=B/'customer-form-official-initialize-env-raw-independent-r1'
SYNTAX=B/'customer-form-official-initialize-env-raw-php-syntax-actual-r1'
RETIRE=B/'full-a-customer-prepare-failed-retirement-authority-r1'

def funcs(source):
    return {n.name:ast.get_source_segment(source,n) for n in ast.parse(source).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}

def make_eleventh():
    actual=r.obj(ADOPT/'COMMIT.json');manifest=r.obj(ADOPT/'SOURCE-MANIFEST.json')
    assert actual['head']==HEAD and actual['parent']==manifest['before_source']==PARENT
    files=[{'path':name,'before':value['before'],'after':value['candidate']} for name,value in sorted(manifest['members'].items())]
    snapshots={row['path']:{'before':r.pointer(ADOPT/'before'/row['path']),'after':r.pointer(ADOPT/'candidate'/row['path'])} for row in files}
    for row in files:
        for stage in ['before','after']:assert r.compact(snapshots[row['path']][stage])==row[stage]
    name='help/audit-regressions/isolated/lib/ms4-customer-http-normal.php'
    pins=r.obj(RAW/'PINS.json');r.fixed(RAW/'candidate'/name,pins['members']['candidate/'+name])
    syntax=r.obj(SYNTAX/'RESULT.json')
    receipts=[{kind:r.pointer(SYNTAX/(op['stem']+'.'+ext),True) for kind,ext in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]} for op in syntax['operations']]
    record={'schema':1,'source_sha':HEAD,'parent':PARENT,'commit_record':r.pointer(ADOPT/'COMMIT.json'),
        'source_manifest':r.pointer(ADOPT/'SOURCE-MANIFEST.json'),'files':files,'snapshots':snapshots,
        'code_source':{'repository_path':name,'authority':r.pointer(RAW/'PINS.json'),'candidate':r.pointer(RAW/'candidate'/name),'independent':r.pointer(PEER/'REPORT.json')},
        'actual_php_validation':r.pointer(SYNTAX/'RESULT.json'),'syntax_receipts':receipts,'future_M_A_B':'NOT RUN','full_acceptance':'INCOMPLETE'}
    assert syntax['source']==record['code_source']['candidate']
    r.put('CURRENT-ELEVENTH-COMMIT-AUTHORITY.json',record)
    return record,r.pointer(OUT/'CURRENT-ELEVENTH-COMMIT-AUTHORITY.json')

def main():
    assert not (OUT/'PINS.json').exists()
    for name in ['source_reader.py','assemble.py','refresh_profile.py','DESIGN.md','eleventh-commit-branch.py.fragment',
        'current-composite-source-branch.py.fragment','customer-prepare-retirement-branch.py.fragment']:r.read(OUT/name)
    op=r.obj(OLD/'PINS.json');members=op['source_members18'];assert len(members)==18
    before={name:r.fixed(OLD/name,op['files'][name]) for name in members}
    for name,raw in before.items():r.write('before/'+name,raw)
    authority,authority_pointer=make_eleventh()
    candidate9,profile_pointer,profile_composite=refresh_profile.main()
    for name,raw in candidate9.items():r.write('validation/actual-profile/'+name,raw)
    retired=r.fixed(RETIRE/'AUTHORITY.json',{'bytes':438001,'sha256':'b545f4deb09b36190934a2b184b3705511b3cf67f512411e76c9d6c005ae02ea'})
    retired_pointer=r.pointer(RETIRE/'AUTHORITY.json')
    oldm=before['materializer/prepare.py'].decode('utf-8');olds=before['validation/run.py'].decode('utf-8')
    fn_before=r.function(oldm,'source_authority');assert fn_before==r.function(olds,'source_authority')
    values=r.constants(oldm);oldchain=values['COMMIT_CHAIN'];assert len(oldchain)==10
    step={'kind':'raw_environment_preservation_l2','source_sha':HEAD,'parent':PARENT,'commit':authority_pointer,'files':authority['files']}
    chain=copy.deepcopy(oldchain)+[step]
    pub=json.loads(before['validation/PROFILE-PUBLICATION.json']);context=pub['finite_contexts']
    inventory=[json.loads(row) for row in r.fixed(context['baseline']['inventory']['path'],context['baseline']['inventory']).splitlines()]
    original={row['path']:{k:row[k] for k in ['path','bytes','sha256']} for row in inventory};expected=dict(original);first={}
    for branch in chain:
        for row in branch['files']:
            name=row['path'];held=expected.get(name);assert row['before']==(None if held is None else r.compact(held)),name
            first.setdefault(name,None if name not in original else r.compact(original[name]));expected[name]={'path':name,**row['after']}
    expected=dict(sorted(expected.items()));changes=[{'path':name,'before':first[name],'after':r.compact(expected[name])} for name in sorted(first)]
    committed=[expected[row['path']] for row in changes];requirements=sorted(set(values['SOURCE_REQUIREMENT_PATHS'])|{row['path'] for row in step['files']})
    assert set(expected)-set(original)==set(values['NEW_TRACKED_PATHS'])
    previous=json.loads(r.fixed(context['baseline']['previous_full_freeze']['path'],context['baseline']['previous_full_freeze']))
    groups={};combined={}
    for prefix in ['driver','customer','paid','fulfillment','aftersale_review','worker']:
        if prefix=='driver':names=[row['path'] for row in previous[prefix+'_source_files']]
        else:
            roots=previous[prefix+'_source_roots']
            extras=sorted(set(previous[prefix+'_source_extra'])|({values['ASSET_CLI']} if prefix in ['fulfillment','aftersale_review','worker'] else set())|{'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'})
            names=sorted({name for name in expected if any(name.startswith(root+'/') for root in roots)}|set(extras))
        groups[prefix]=[expected[name] for name in names]
        for row in groups[prefix]:assert row['path'] not in combined or combined[row['path']]==row;combined[row['path']]=row
    counts={prefix:len(rows) for prefix,rows in groups.items()}
    native_count=6+sum(2+2*len(branch['files']) for branch in chain)+1+len(committed)+2+3+1+4
    selected=[{'path':row['path'],**r.pin(candidate9[row['path']])} for row in pub['selected9']]
    oldcomposite=copy.deepcopy(context['current_composite_customer_form_source'])
    cu=r.obj(OUT/'validation/actual-profile/interface-v1.json')['customer_form_current_profile']
    composite={'scope':'ACTUAL_ELEVENTH_CURRENT_SEVEN_WITH_EXACT_RAW_ENV_LIB_DELTA_SOURCE_ONLY','source_sha':HEAD,
        'profile_PINS':profile_pointer,'profile_interface':r.pointer(OUT/'validation/actual-profile/interface-v1.json'),
        'base_authorities':cu['frozen_php_authorities'],'profile_composite':profile_composite,'php_sources':cu['php_sources'],'new_runtime':'NOT RUN'}
    pub.update(source_sha=HEAD,parent=PARENT,selected9=selected,repository_Source_requirements=[expected[name] for name in requirements])
    context.update(commit_chain=chain,committed_changes=changes,committed_files=committed,source_counts=counts,
        current_raw_environment_step=step,historical_tenth_composite_customer_form_source=oldcomposite,
        current_composite_customer_form_source=composite,current_customer_prepare_failure_retirement=retired_pointer,current_profile_frozen_source=profile_pointer)
    context['current_raw_environment_scope']='Actual one test lib and two status docs only. Old customer-prepare1, HTTP0, original SUNKNOWN and resume1 remain failed and retired. New business NOT RUN.'
    values.update(COMMIT_CHAIN=chain,COMMITTED_PATHS=[row['path'] for row in changes],SOURCE_REQUIREMENT_PATHS=requirements,
        CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=composite,HISTORICAL_TENTH_COMPOSITE_CUSTOMER_FORM_SOURCE=oldcomposite,
        CURRENT_CUSTOMER_PREPARE_FAILURE_RETIREMENT=retired_pointer)
    fn=r.once(fn_before,"check(chain == COMMIT_CHAIN and len(chain) == 10, 'EXACT_TEN_WITH_ORIGINAL_NINE_AND_ACTUAL_INITIALIZER_MOUNT_STEP')",
        "check(chain == COMMIT_CHAIN and len(chain) == 11, 'EXACT_ELEVEN_WITH_ORIGINAL_TEN_AND_ACTUAL_RAW_ENV_STEP')")
    fn=r.once(fn,'    expected = dict(sorted(expected.items()))',r.read(OUT/'eleventh-commit-branch.py.fragment').decode()+'    expected = dict(sorted(expected.items()))')
    historical=r.read(OLD/'composite-source-branch.py.fragment').decode()
    held=historical.replace("context['current_composite_customer_form_source']","context['historical_tenth_composite_customer_form_source']").replace('CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE','HISTORICAL_TENTH_COMPOSITE_CUSTOMER_FORM_SOURCE').replace("composite['source_sha'] == pub['source_sha']","composite['source_sha'] == chain[9]['source_sha']").replace('expected[','historical_tenth_expected[')
    fn=r.once(fn,historical,held+r.read(OUT/'current-composite-source-branch.py.fragment').decode())
    fn=r.once(fn,'    protected = {}',r.read(OUT/'customer-prepare-retirement-branch.py.fragment').decode()+'    protected = {}')
    fn=r.once(fn,"'early_failure_retirement': early_retirement}","'early_failure_retirement': early_retirement, 'customer_prepare_failure_retirement': customer_prepare_retirement}")
    namespace={key:value for key,value in values.items()};namespace.update(ast=ast,hashlib=hashlib,json=json,re=re,Path=Path)
    exec(compile(ast.Module(body=[ast.parse(fn).body[0]],type_ignores=[]),'<pure-new-source-authority-only>','exec'),namespace)
    def checked(pointer):
        raw=r.fixed(pointer['path'],pointer)
        if 'native9' in pointer:assert r.READS[Path(pointer['path']).as_posix()]['native9']==pointer['native9']
        return raw
    def check(ok,code):assert ok,code
    qualified=namespace['source_authority'](pub,checked,check)
    assert qualified['all']==expected and qualified['groups']==groups and qualified['committed']==committed
    assert qualified['customer_prepare_failure_retirement']==json.loads(retired)
    for root in [Path(values['MAIN_SOURCE']),Path(values['VALIDATION_SOURCE'])]:
        for row in pub['repository_Source_requirements']:assert r.pin(r.read(root/row['path']))==r.compact(row)
    r.put('validation/PROFILE-PUBLICATION.json',pub)
    pp=json.loads(before['validation/PROFILE-PINS.json']);pp['members']={row['path']:r.compact(row) for row in selected}
    pp['members']['PROFILE-PUBLICATION.json']=r.pin(r.read(OUT/'validation/PROFILE-PUBLICATION.json'));r.put('validation/PROFILE-PINS.json',pp)
    note=('\n\nActual eleventh local Source '+HEAD+' parent '+PARENT+'. Exact one test lib plus two status documents; prior ten steps and all retirements retained. '
        'Normal initializer uses two exact membership seams plus one current lib binding; original business suffix unchanged. '
        'Counts derived from actual history: '+str(len(expected))+' tracked, '+str(len(changes))+' cumulative, '+str(len(requirements))+' requirements; M '+str(native_count)+' native commands. '
        'M RESULT adds current_customer_prepare_failure_retirement, 30 fields. All new M/A/B/UI/history/finance/release are NOT RUN.\n').encode()
    r.write('validation/DESIGN-TASKS.md',before['validation/DESIGN-TASKS.md']+note)
    def shared(text):
        text=r.once(text,r.function(text,'source_authority'),fn)
        for key in ['COMMIT_CHAIN','COMMITTED_PATHS','SOURCE_REQUIREMENT_PATHS','CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']:text=r.const(text,key,values[key])
        return r.once(text,'def source_authority(',"HISTORICAL_TENTH_COMPOSITE_CUSTOMER_FORM_SOURCE = "+repr(oldcomposite)+"\nCURRENT_CUSTOMER_PREPARE_FAILURE_RETIREMENT = "+repr(retired_pointer)+"\n\ndef source_authority(")
    s=shared(olds);s=r.const(s,'SOURCE',HEAD)
    s=r.const(s,'PINS',{name:r.pin(candidate9[name])['sha256'] for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']})
    s=r.const(s,'PROFILE_PUBLICATION',r.pin(r.read(OUT/'validation/PROFILE-PINS.json')))
    sb="and actual_setup['current_early_failure_retirement'] == qualified['early_failure_retirement'],"
    sa="and actual_setup['current_early_failure_retirement'] == qualified['early_failure_retirement']\n        and actual_setup['current_customer_prepare_failure_retirement'] == qualified['customer_prepare_failure_retirement'],"
    s=r.once(s,sb,sa);r.write('validation/run.py',s.encode())
    rp=json.loads(before['validation/PINS.json']);rp['members']={name:r.pin(r.read(OUT/'validation'/name)) for name in ['DESIGN-TASKS.md','run.py']};r.put('validation/PINS.json',rp)
    m=shared(oldm);m=r.const(m,'head',HEAD);m=r.const(m,'parent',PARENT)
    m=r.once(m,"root=Path('"+(OLD/'validation').as_posix()+"')","root=Path('"+(OUT/'validation').as_posix()+"')")
    m=r.once(m,"tool=Path('"+(OLD/'materializer').as_posix()+"')","tool=Path('"+(OUT/'materializer').as_posix()+"')")
    m=r.once(m,repr(r.pin(before['validation/PINS.json'])),repr(r.pin(r.read(OUT/'validation/PINS.json'))))
    m=r.once(m,"'current_early_failure_retirement':qualified['early_failure_retirement'],'native_operations':ops,","'current_early_failure_retirement':qualified['early_failure_retirement'],'current_customer_prepare_failure_retirement':qualified['customer_prepare_failure_retirement'],'native_operations':ops,")
    r.write('materializer/prepare.py',m.encode());r.write('materializer/PLAN.md',before['materializer/PLAN.md']+note)
    inverse=json.loads(before['materializer/INVERSE.json']);inverse.update(source_sha=HEAD,parent=PARENT,commit_chain=chain,cumulative_changed_paths=changes)
    inverse['current_raw_environment_refresh']={'actual_commit_authority':authority_pointer,'current_profile_source':profile_pointer,
        'customer_prepare_retirement':retired_pointer,'native_budget':native_count,'all_original_histories_preserved':True,'new_business':'NOT RUN'}
    r.put('materializer/INVERSE.json',inverse)
    launcher=before['launcher/launch.py'].decode()
    launcher=r.once(launcher,"ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')")
    launcher=r.once(launcher,(OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix())
    launcher=r.once(launcher,r.pin(before['validation/PINS.json'])['sha256'],r.pin(r.read(OUT/'validation/PINS.json'))['sha256'])
    launcher=r.once(launcher,"value['source_sha']=='"+PARENT+"'","value['source_sha']=='"+HEAD+"'")
    precheck="current_customer_pointer = "+repr(retired_pointer)+"\nassert publication['finite_contexts']['current_customer_prepare_failure_retirement'] == current_customer_pointer\nassert descriptor(Path(current_customer_pointer['path'])) == current_customer_pointer\ncurrent_customer = read(Path(current_customer_pointer['path']))\nassert current_customer['full_acceptance'] == 'INCOMPLETE' and current_customer['original_failure_truth']['failed_outer_native_exit'] == 'UNKNOWN'\n"
    launcher=r.once(launcher,"if mode=='materialize':",precheck+"\nif mode=='materialize':")
    launcher=r.once(launcher," assert read(BASE/'materialize.terminal.json')['native_exit']==0"," assert value['current_customer_prepare_failure_retirement']==current_customer\n assert read(BASE/'materialize.terminal.json')['native_exit']==0")
    r.write('launcher/launch.py',launcher.encode())
    la=ast.parse(launcher);branch=next(n for n in la.body if isinstance(n,ast.If) and ast.unparse(n.test)=="mode == 'materialize'")
    argnodes=[next(n.value for n in body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='args' for t in n.targets)) for body in [branch.body,branch.orelse]]
    em=ast.parse("[PY,'-B','-X','utf8',str(Path("+repr((OUT/'materializer/prepare.py').as_posix())+"))]",mode='eval').body
    es=ast.parse("[PY,'-B','-X','utf8',str(ROOT/'run.py'),'--root-source-pins-sha256',"+repr(r.pin(r.read(OUT/'validation/PINS.json'))['sha256'])+",'--setup-sha256',descriptor(setup)['sha256']]",mode='eval').body
    assert ast.dump(argnodes[0])==ast.dump(em) and ast.dump(argnodes[1])==ast.dump(es)
    pops=[n for n in ast.walk(la) if isinstance(n,ast.Call) and isinstance(n.func,ast.Attribute) and n.func.attr=='Popen']
    assert len(pops)==1 and isinstance(pops[0].args[0],ast.Name) and pops[0].args[0].id=='args'
    for label,text,old in [('M',m,oldm),('S',s,olds)]:
        for name,body in funcs(old).items():
            if name=='source_authority':continue
            held=funcs(text)[name]
            assert (r.once(held,sa,sb) if label=='S' and name=='main' else held)==body,(label,name)
    assert funcs(launcher)==funcs(before['launcher/launch.py'].decode())
    suffix="    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')"
    assert s[s.index(suffix):]==olds[olds.index(suffix):]
    assert r.function(m,'source_authority')==r.function(s,'source_authority')
    result=[n for n in ast.walk(ast.parse(m)) if isinstance(n,ast.Dict) and any(isinstance(k,ast.Constant) and k.value=='materialize_source_inputs' for k in n.keys)]
    assert len(result)==1 and len(result[0].keys)==30
    after={name:r.read(OUT/name) for name in members};forward=r.patch(before,after);reverse=r.patch(after,before)
    assert r.apply(before,forward)==after and r.apply(after,reverse)==before
    r.write('DIFF.patch',forward.encode());r.write('REVERSE.patch',reverse.encode())
    original={name:r.read(OLD/'original-before'/name) for name in members}
    for name,raw in original.items():r.write('original-before/'+name,raw)
    ff=r.patch(original,after);fr=r.patch(after,original);assert r.apply(original,ff)==after and r.apply(after,fr)==original
    r.write('FROM-ORIGINAL.patch',ff.encode());r.write('TO-ORIGINAL.patch',fr.encode())
    checks={'status':'SOURCE_READY_NOT_M_S_EXECUTED','source_sha':HEAD,'parent':PARENT,'steps':len(chain),'tracked':len(expected),
        'cumulative':len(changes),'requirements':len(requirements),'group_counts':counts,'normal_union':len(combined),'M_native_count':native_count,
        'M_RESULT_fields':30,'all_original_ten_history_steps_preserved':chain[:10]==oldchain,'original_business_suffix_exact':True,
        'all_other_functions_exact_except_single_S_setup_guard':True,'four_complete_patch_directions_replayed':True,'launcher_actual_argv_closed':True,
        'all_input_windows_ended':True,'new_business':'NOT RUN'}
    r.put('AUTHOR-CHECKS.json',checks)
    ended=time.time_ns();assert all(row['closed'] and row['EOF'] and row['ended_ns']<=ended for row in r.READS.values())
    r.put('AUTHOR-INPUTS.json',{'schema':1,'input_count':len(r.READS),'all_input_windows_ended':True,'ended_ns':ended,'inputs':r.READS,'target_native_execution':'NOT RUN'})
    files={p.relative_to(OUT).as_posix():{'path':p.as_posix(),**r.pin(p.read_bytes()),'native9':r.n9(p.stat())} for p in sorted(OUT.rglob('*')) if p.is_file() and p!=OUT/'PINS.json'}
    r.put('PINS.json',{'schema':1,'status':'FROZEN_SOURCE_READY_NOT_EXECUTED','source_sha':HEAD,'parent':PARENT,'source_members18':members,'files':files,
        'Root14_M3_contract':True,'before_source':r.pointer(OLD/'PINS.json'),'profile_source':profile_pointer,'actual_eleventh_commit_authority':authority_pointer,
        'current_customer_prepare_failure_retirement':retired_pointer,'pure_authority':checks,'all_input_windows_ended':True,'input_count':len(r.READS),
        'ended_ns':ended,'M_S_Host_PHP_Docker_business_execution':'NOT RUN'})
    print(json.dumps({'status':'FROZEN_SOURCE_READY_NOT_EXECUTED','PINS':r.pin((OUT/'PINS.json').read_bytes()),'M':r.pin(after['materializer/prepare.py']),
        'S':r.pin(after['validation/run.py']),'rootPINS':r.pin(after['validation/PINS.json']),'initializer':r.pin(candidate9['ms4-initialize.php']),
        'native_count':native_count,'cumulative':len(changes),'input_count':len(r.READS)}))

if __name__=='__main__':main()
