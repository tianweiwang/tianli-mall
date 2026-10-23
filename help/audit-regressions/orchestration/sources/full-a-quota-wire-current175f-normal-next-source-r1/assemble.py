"""Complete actual175f quota-wire normal-A Source author; never invokes any runtime entry."""
import ast,copy,json,symtable,time,traceback
from pathlib import Path
import source_reader as r
from author_support import funcs,current_qualification,actual_namespace_qualification
OUT,B,OLD=r.OUT,r.B,r.OLD
ORG=B.parent/'project-organization-20261023'

def main():
    assert not (OUT/'PINS.json').exists()
    for name in ['DESIGN.md','assemble.py','source_reader.py','author_support.py','prepare-inputs.py','profile-author.py','retirement-author.py','authority-current.py.fragment']:
        r.read(OUT/name)
    prior=r.obj(OLD/'PINS.json');assert r.pin(r.read(OLD/'PINS.json'))=={'bytes':189060,'sha256':'0c2d0c6e68199e0c2b291fdaa764babb986b8d5ebe512903fbf06b6f7fea3650'}
    members=prior['source_members18'];assert len(members)==18
    before={name:r.fixed(OLD/name,prior['files'][name]) for name in members}
    for name,raw in before.items():r.write('before/'+name,raw)
    pub=json.loads(before['validation/PROFILE-PUBLICATION.json']);oldpub=copy.deepcopy(pub);context=pub['finite_contexts']
    oldm=before['materializer/prepare.py'].decode();olds=before['validation/run.py'].decode()
    oldfn=r.function(oldm,'source_authority');assert oldfn==r.function(olds,'source_authority')
    values=r.constants(oldm);oldvalues=copy.deepcopy(values)
    stage=r.obj(OUT/'CURRENT-SOURCE-AUTHORITY.json');stageptr=r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json')
    chain=copy.deepcopy(values['COMMIT_CHAIN'])+[{key:stage[key] for key in ['kind','source_sha','parent','files']}|{'commit':stageptr}]
    assert chain[-1]['source_sha']==r.HEAD and chain[-1]['parent']==r.PARENT and len(chain)==len(values['COMMIT_CHAIN'])+1
    profileptr=r.pointer(OUT/'profile-source/PINS.json');manifest=r.obj(OUT/'profile-source/MANIFEST.json')
    candidate9={name:r.fixed(OUT/'profile-source/candidate'/name,row) for name,row in manifest['candidate'].items()}
    assert len(candidate9)==9
    for name,raw in candidate9.items():assert r.read(OUT/'validation/actual-profile'/name)==raw
    # This file is created only from Root's forthcoming actual one-file syntax receipts.
    # No placeholder/future pointer is accepted and this author is not invoked until it exists.
    syntaxptr=r.pointer(OUT/'INITIALIZER-QUOTA-WIRE-SYNTAX.json');syntax=r.obj(syntaxptr['path'])
    assert syntax['source_profile_PINS']==profileptr and r.fixed(syntax['initializer']['path'],syntax['initializer'])==candidate9['ms4-initialize.php']
    assert syntax['status']=='PASS_COMPLETE_CURRENT175F_INITIALIZER_PHP82_SYNTAX_ONLY'
    inventory=[json.loads(line) for line in r.fixed(context['baseline']['inventory']['path'],context['baseline']['inventory']).splitlines()]
    original={row['path']:{key:row[key] for key in ['path','bytes','sha256']} for row in inventory};expected=dict(original);first={}
    for step in chain:
        for row in step['files']:
            name=row['path'];assert row['before']==(r.compact(expected[name]) if name in expected else None),name
            first.setdefault(name,r.compact(original[name]) if name in original else None);expected[name]={'path':name,**row['after']}
    expected=dict(sorted(expected.items()));changes=[{'path':name,'before':first[name],'after':r.compact(expected[name])} for name in sorted(first)]
    committed=[expected[row['path']] for row in changes];newtracked=sorted(set(expected)-set(original))
    requirements=sorted(set(values['SOURCE_REQUIREMENT_PATHS'])|{row['path'] for row in chain[-1]['files']})
    previous=json.loads(r.fixed(context['baseline']['previous_full_freeze']['path'],context['baseline']['previous_full_freeze']))
    groups={};combined={}
    for prefix in ['driver','customer','paid','fulfillment','aftersale_review','worker']:
        if prefix=='driver':names=[row['path'] for row in previous[prefix+'_source_files']]
        else:
            roots=previous[prefix+'_source_roots'];extras=sorted(set(previous[prefix+'_source_extra'])|({values['ASSET_CLI']} if prefix in ['fulfillment','aftersale_review','worker'] else set())|{'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'})
            names=sorted({name for name in expected if any(name.startswith(root+'/') for root in roots)}|set(extras))
        groups[prefix]=[expected[name] for name in names]
        for row in groups[prefix]:assert row['path'] not in combined or combined[row['path']]==row;combined[row['path']]=row
    counts={prefix:len(rows) for prefix,rows in groups.items()}
    budget=6+sum(2+2*len(step['files']) for step in chain)+1+len(committed)+2+3+1+4
    assert len(expected)==context['actual_complete_expected_tracked_count']
    assert set(original)<=set(expected) and set(row['path']for row in context['committed_changes'])<=set(row['path']for row in changes)
    assert set(values['SOURCE_REQUIREMENT_PATHS'])<=set(requirements)
    assert newtracked==values['NEW_TRACKED_PATHS']
    assert counts==context['source_counts'] and len(combined)==max(counts.values())
    deltaptr=r.pointer(OUT/'QUOTA-WIRE-DELTA.json');oldpubptr=r.pointer(OLD/'validation/PROFILE-PUBLICATION.json')
    composite={'scope':'ACTUAL175F_QUOTA_WIRE_LAYER_OVER_COMPLETE_EIGHTEENTH_COMPOSITE','source_sha':r.HEAD,
        'profile_PINS':profileptr,'profile_interface':r.pointer(OUT/'validation/actual-profile/interface-v1.json'),
        'predecessor_publication':oldpubptr,'predecessor_composite':copy.deepcopy(context['current_composite_customer_form_source']),
        'source_delta':deltaptr,'php_sources':manifest['current_seven_php']}
    retirement=r.pointer(OUT/'CURRENT-28DC-RETIREMENT-COMPLETE.json');retired=r.obj(retirement['path'])
    context.update(commit_chain=chain,committed_changes=changes,committed_files=committed,new_tracked_paths=newtracked,
        actual_complete_expected_tracked_count=len(expected),source_counts=counts,current_composite_customer_form_source=composite,
        historical_eighteenth_publication=oldpubptr,current_quota_wire_source_history=stageptr,current_quota_wire_delta=deltaptr,
        current_quota_wire_profile_source=profileptr,current_quota_wire_initializer_syntax=syntaxptr,
        current_28dc_paid_producer_failure_retirement=retirement,
        source_authoring_qualification_scope='ACTUAL_CURRENT175F_BOTH_FULL_REQUIRED_WORKTREES_CLEAN_HEAD_PARENT_AND_NAMESPACE_BEFORE_AFTER',
        current_published_source_scope='All18 historical stages and eight retirement authorities retained; real175f four modified files, exact single form helper tuple/full DAG and ninth completed current28dc failure retirement. New M/S/business NOT RUN.')
    pub.update(source_sha=r.HEAD,parent=r.PARENT,selected9=manifest['selected9'],repository_Source_requirements=[expected[name] for name in requirements])
    nodes=[node for node in ast.walk(ast.parse(oldm)) if isinstance(node,ast.Dict) and any(isinstance(key,ast.Constant) and key.value=='materialize_source_inputs' for key in node.keys)]
    assert len(nodes)==1 and all(isinstance(key,ast.Constant) for key in nodes[0].keys)
    oldkeys=[key.value for key in nodes[0].keys];assert oldkeys==values['CURRENT_M_RESULT_KEYS34']
    newfield='current_28dc_paid_producer_failure_retirement';assert newfield not in oldkeys
    currentkeys=oldkeys[:oldkeys.index('native_operations')]+[newfield]+oldkeys[oldkeys.index('native_operations'):]
    keyname='CURRENT_M_RESULT_KEYS'+str(len(currentkeys));oldkeyname='CURRENT_M_RESULT_KEYS'+str(len(oldkeys))
    table=symtable.symtable(oldm,'historical18_actual_module','exec');needed=set()
    def globals_used(scope):
        needed.update(symbol.get_name() for symbol in scope.get_symbols() if symbol.is_global() and symbol.is_referenced())
        for child in scope.get_children():globals_used(child)
    globals_used(next(child for child in table.get_children() if child.get_name()=='source_authority'))
    literals={key:oldvalues[key] for key in sorted(needed & set(oldvalues))}
    assert {'COMMIT_CHAIN','COMMITTED_PATHS','SOURCE_REQUIREMENT_PATHS','NEW_TRACKED_PATHS','CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE'}<=set(literals)
    added={'HISTORICAL_EIGHTEEN_M_SOURCE':r.pointer(OLD/'materializer/prepare.py'),'HISTORICAL_EIGHTEEN_PUBLICATION':oldpubptr,
        'HISTORICAL_EIGHTEEN_PACKAGE':r.pointer(OLD/'PINS.json'),'HISTORICAL_EIGHTEEN_CONSTANTS':literals,
        'HISTORICAL_EIGHTEEN_AUTHORITY_SOURCE':r.pin(oldfn.encode()),'CURRENT_QUOTA_WIRE_SOURCE_HISTORY':stageptr,
        'CURRENT_QUOTA_WIRE_DELTA':deltaptr,'CURRENT_QUOTA_WIRE_PROFILE_SOURCE':profileptr,'CURRENT_QUOTA_WIRE_INITIALIZER_SYNTAX':syntaxptr,
        'CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT':retirement,keyname:currentkeys}
    values.update(COMMIT_CHAIN=chain,COMMITTED_PATHS=[row['path'] for row in changes],SOURCE_REQUIREMENT_PATHS=requirements,
        NEW_TRACKED_PATHS=newtracked,CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=composite)
    nested='    def historical_eighteen(pub, checked, check):\n'+''.join('        '+key+' = HISTORICAL_EIGHTEEN_CONSTANTS['+repr(key)+']\n' for key in literals)
    nested+='\n'.join('    '+line for line in oldfn.splitlines()[1:])+'\n'
    fragment=r.read(OUT/'authority-current.py.fragment').decode();fn=r.once(fragment,'__HISTORICAL_VALIDATOR__\n',nested)
    fn=r.once(fn,'__INITIALIZER_SYNTAX_CHECKS__\n',r.read(OUT/'initializer-syntax-checks.py.fragment').decode())
    fn=r.once(fn,'__CURRENT_RETIREMENT_CHECKS__\n',r.read(OUT/'retirement-checks.py.fragment').decode()).rstrip('\n')
    nestednode=next(node for node in ast.parse(fn).body[0].body if isinstance(node,ast.FunctionDef) and node.name=='historical_eighteen')
    body=ast.get_source_segment(fn,nestednode).splitlines()[1+len(literals):]
    recovered=oldfn.splitlines()[0]+'\n'+'\n'.join(line[4:] if line.startswith('    ') else line for line in body)
    assert recovered==oldfn,'WHOLE_EIGHTEEN_ORIGINAL_AUTHORITY_BODY_INVERSE'
    def shared(text):
        text=r.once(text,r.function(text,'source_authority'),fn)
        for key in ['COMMIT_CHAIN','COMMITTED_PATHS','SOURCE_REQUIREMENT_PATHS','NEW_TRACKED_PATHS','CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']:text=r.const(text,key,values[key])
        return r.once(text,'def source_authority(',''.join(key+' = '+repr(value)+'\n' for key,value in added.items())+'\ndef source_authority(')
    r.put('validation/PROFILE-PUBLICATION.json',pub)
    profilepins=json.loads(before['validation/PROFILE-PINS.json']);profilepins['members']={row['path']:r.compact(row) for row in manifest['selected9']}
    profilepins['members']['PROFILE-PUBLICATION.json']=r.pin(r.read(OUT/'validation/PROFILE-PUBLICATION.json'));r.put('validation/PROFILE-PINS.json',profilepins)
    note=('\n\nActual175f quota-wire successor '+r.HEAD+' parent '+r.PARENT+'. Complete original18 whole authorities/all used literals/eight historical retirements preserved; full tracked '+str(len(expected))+' cumulative '+str(len(changes))+' requirements '+str(len(requirements))+' dual metadata '+str(2*len(expected))+' derived native '+str(budget)+' strict M'+str(len(currentkeys))+'. Ninth actual28dc failed retirement is not A0. Complete original normal business/resources/INI/nine profiles/native lifecycle/Task6/AR/non-due Worker/normal close/independent zero retained. New M/S/business NOT RUN.\n').encode()
    r.write('validation/DESIGN-TASKS.md',before['validation/DESIGN-TASKS.md']+note)
    s=shared(olds);s=r.const(s,'SOURCE',r.HEAD);s=r.const(s,'PROFILE_PUBLICATION',r.pin(r.read(OUT/'validation/PROFILE-PINS.json')))
    s=r.const(s,'PINS',{name:r.pin(candidate9[name])['sha256'] for name in r.constants(olds)['PINS']})
    oldguard="    require(set(actual_setup) == set(CURRENT_M_RESULT_KEYS34) and len(actual_setup) == 34, 'EXACT_CURRENT_UPLOAD_ROOT_M_WITH_EIGHTH_NAMED_RETIREMENT')\n"
    newguard="    require(set(actual_setup) == set("+keyname+") and len(actual_setup) == "+str(len(currentkeys))+", 'EXACT_CURRENT_QUOTA_WIRE_M_WITH_NINTH_NAMED_RETIREMENT')\n"
    s=r.once(s,oldguard,newguard)
    oldjoin="and actual_setup['current_0c13_paid_producer_failure_retirement'] == qualified['current_0c13_paid_producer_failure_retirement'],"
    newjoin="and actual_setup['current_0c13_paid_producer_failure_retirement'] == qualified['current_0c13_paid_producer_failure_retirement']\n        and actual_setup['"+newfield+"'] == qualified['"+newfield+"'],"
    s=r.once(s,oldjoin,newjoin);r.write('validation/run.py',s.encode())
    rootpins=json.loads(before['validation/PINS.json']);rootpins['members']={name:r.pin(r.read(OUT/'validation'/name)) for name in ['DESIGN-TASKS.md','run.py']};r.put('validation/PINS.json',rootpins)
    m=shared(oldm);m=r.const(m,'head',r.HEAD);m=r.const(m,'parent',r.PARENT)
    for key,folder in [('root','validation'),('tool','materializer')]:m=r.once(m,key+"=Path('"+(OLD/folder).as_posix()+"')",key+"=Path('"+(OUT/folder).as_posix()+"')")
    m=r.once(m,"need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')",
        "need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(r.read(OUT/'validation/PINS.json')))+",'REVIEWED_ROOT_SOURCE_PINS')")
    m=r.once(m,"'current_0c13_paid_producer_failure_retirement':qualified['current_0c13_paid_producer_failure_retirement'],'native_operations':ops,",
        "'current_0c13_paid_producer_failure_retirement':qualified['current_0c13_paid_producer_failure_retirement'],'"+newfield+"':qualified['"+newfield+"'],'native_operations':ops,")
    r.write('materializer/prepare.py',m.encode());r.write('materializer/PLAN.md',before['materializer/PLAN.md']+note)
    inverse=json.loads(before['materializer/INVERSE.json']);inverse.update(source_sha=r.HEAD,parent=r.PARENT,commit_chain=chain,cumulative_changed_paths=changes)
    inverse['actual175f_quota_wire_successor']={'step':stageptr,'profile':profileptr,'source_delta':deltaptr,'initializer_syntax':syntaxptr,
        'ninth_retirement':retirement,'M_result_keys':currentkeys,'native_budget':budget,'business':'NOT RUN'};r.put('materializer/INVERSE.json',inverse)
    launcher=before['launcher/launch.py'].decode()
    launcher=r.once(launcher,"ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')")
    launcher=r.once(launcher,(OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix())
    launcher=r.once(launcher,r.pin(before['validation/PINS.json'])['sha256'],r.pin(r.read(OUT/'validation/PINS.json'))['sha256'])
    launcher=r.once(launcher,"value['source_sha']=='"+r.PARENT+"'","value['source_sha']=='"+r.HEAD+"'")
    extra="current_28dc_pointer = "+repr(retirement)+"\nassert publication['finite_contexts']['current_28dc_paid_producer_failure_retirement'] == current_28dc_pointer\nassert descriptor(Path(current_28dc_pointer['path'])) == current_28dc_pointer\ncurrent_28dc = read(Path(current_28dc_pointer['path']))\nassert current_28dc['original_S_native_exit'] == current_28dc['paid_producer_native_exit'] == 1 and current_28dc['business_acceptance'] == 'INCOMPLETE'\n"+keyname+' = '+repr(currentkeys)+'\n\n'
    launcher=r.once(launcher,"if mode=='materialize':\n",extra+"if mode=='materialize':\n")
    launcher=r.once(launcher," assert set(value)==set(CURRENT_M_RESULT_KEYS34) and len(value)==34\n"," assert set(value)==set("+keyname+") and len(value)=="+str(len(currentkeys))+"\n")
    launcher=r.once(launcher," assert value['current_0c13_paid_producer_failure_retirement']==current_0c13\n"," assert value['current_0c13_paid_producer_failure_retirement']==current_0c13\n assert value['"+newfield+"']==current_28dc\n")
    r.write('launcher/launch.py',launcher.encode())
    for name,body in funcs(oldm).items():
        if name!='source_authority':assert funcs(m)[name]==body,name
    for name,body in funcs(olds).items():
        if name not in ['source_authority','main']:assert funcs(s)[name]==body,name
    assert funcs(s)['main'].replace(newguard,oldguard).replace(newjoin,oldjoin)==funcs(olds)['main']
    assert funcs(launcher)==funcs(before['launcher/launch.py'])
    suffix="    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')"
    assert s[s.index(suffix):]==olds[olds.index(suffix):] and r.function(m,'source_authority')==r.function(s,'source_authority')
    result_nodes=[node for node in ast.walk(ast.parse(m)) if isinstance(node,ast.Dict) and any(isinstance(key,ast.Constant) and key.value=='materialize_source_inputs' for key in node.keys)]
    assert len(result_nodes)==1 and [key.value for key in result_nodes[0].keys]==currentkeys
    for name in members:
        raw=r.read(OUT/name)
        if name.endswith('.py'):ast.parse(raw)
        elif name.endswith('.json'):json.loads(raw)
    qm,nm=actual_namespace_qualification(m.encode(),'M',pub);qs,ns=actual_namespace_qualification(s.encode(),'S',pub)
    assert qm==qs and qm['all']==expected and qm['groups']==groups and qm['committed']==committed and qm['commit_chain']==chain
    r.put('ACTUAL-MODULE-NAMESPACE-CHECKS.json',{'M':nm,'S':ns,'whole_eighteen_authority_body_inverse':True,'all_runtime_entries':'NOT RUN'})
    after={name:r.read(OUT/name) for name in members};forward,reverse=r.patch(before,after),r.patch(after,before)
    assert r.apply(before,forward)==after and r.apply(after,reverse)==before;r.write('DIFF.patch',forward.encode());r.write('REVERSE.patch',reverse.encode())
    original_before={name:r.read(OLD/'original-before'/name) for name in members}
    for name,raw in original_before.items():r.write('original-before/'+name,raw)
    forward,reverse=r.patch(original_before,after),r.patch(after,original_before)
    assert r.apply(original_before,forward)==after and r.apply(after,reverse)==original_before
    r.write('FROM-ORIGINAL.patch',forward.encode());r.write('TO-ORIGINAL.patch',reverse.encode())
    qualification=current_qualification(pub,expected,values)
    checks={'schema':1,'status':'COMPLETE_ACTUAL175F_NORMAL_A_SOURCE_ONLY_NOT_M_S_EXECUTED','steps':len(chain),'tracked':len(expected),
        'cumulative':len(changes),'requirements':len(requirements),'new_baseline_members':len(newtracked),'dual_snapshot_rows':2*len(expected),
        'group_counts':counts,'normal_union':len(combined),'M_native_budget':budget,'M_RESULT_current_fields':len(currentkeys),'M_RESULT_historical_fields':len(oldkeys),
        'nine_retirements':True,'whole_eighteen_body_inverse_exact':True,'historical_eighteen_all_used_literals_fixed':True,
        'all_non_authority_M_functions_exact':True,'S_main_only_two_exact_preRun_consumer_seams':True,'full_original_business_suffix_exact':True,
        'four_complete_standard_patch_directions_replayed':True,'source_qualification':qualification,'initializer_actual_syntax':syntaxptr,'runtime':'NOT RUN'}
    r.put('AUTHOR-CHECKS.json',checks)
    ended=time.time_ns();assert all(row['closed'] and row['EOF'] and row['ended_ns']<=ended for row in r.READS.values())
    r.put('AUTHOR-INPUTS.json',{'schema':1,'input_count':len(r.READS),'all_input_windows_ended':True,'ended_ns':ended,'inputs':r.READS,
        'target_runtime':'NOT RUN','current_Main_and_Val_working_trees_asserted':True})
    files={path.relative_to(OUT).as_posix():{'path':path.as_posix(),**r.pin(path.read_bytes()),'native9':r.n9(path.stat())}
        for path in sorted(OUT.rglob('*')) if path.is_file() and path!=OUT/'PINS.json'}
    r.put('PINS.json',{'schema':1,'status':checks['status'],'source_sha':r.HEAD,'parent':r.PARENT,'source_members18':members,'files':files,
        'Root14_M3_contract':True,'before_source':r.pointer(OLD/'PINS.json'),'profile_source':profileptr,'current_source_history':stageptr,
        'ninth_retirement':retirement,'initializer_syntax':syntaxptr,'current_source_qualification':qualification,'pure_authority':checks,
        'all_input_windows_ended':True,'input_count':len(r.READS),'ended_ns':ended,'new_M_S_Host_PHP_Docker_App_business':'NOT RUN'})
    print(json.dumps({'PINS':r.pointer(OUT/'PINS.json'),'M':r.pin(after['materializer/prepare.py']),'S':r.pin(after['validation/run.py']),
        'Launcher':r.pin(after['launcher/launch.py']),'steps':len(chain),'tracked':len(expected),'derived_budget_not_executed':budget,'runtime':'NOT RUN'}))

if __name__=='__main__':
    try:main()
    except Exception as error:
        name='AUTHORING-FAILURE-'+str(len(list(OUT.glob('AUTHORING-FAILURE-*.json')))+1).zfill(2)+'.json'
        (OUT/name).write_text(json.dumps({'status':'OWN_SOURCE_AUTHOR_REFUSED_NOT_TARGET_RUNTIME','class':type(error).__name__,
            'message':str(error),'traceback':traceback.format_exc(),'target_runtime':'NOT RUN'},ensure_ascii=False,indent=2)+'\n','utf8')
        raise
