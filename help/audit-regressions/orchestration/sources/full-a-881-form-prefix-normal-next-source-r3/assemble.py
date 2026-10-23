"""Complete normal-A current881 Source author. Never execute original modules, M/S or resources."""
import ast,copy,json,time,traceback
from pathlib import Path
import source_reader as r
from author_support import funcs,current_qualification,actual_namespace_qualification
OUT=r.OUT;B=r.B;OLD=r.OLD
SRC=B/'full-a-881-form-prefix-normal-next-source-r2'

def main():
    assert not (OUT/'PINS.json').exists()
    for name in ['DESIGN.md','assemble.py','source_reader.py','author_support.py','prepare-inputs.py','profile-author.py','retirement-author.py','authority-current.py.fragment']:r.read(OUT/name)
    prior=r.obj(OLD/'PINS.json');assert r.pin(r.read(OLD/'PINS.json'))=={'bytes':65050,'sha256':'8790b416b343e2e0d97e0bade11ab4b46f6534ac7e352edd91198d31ec1d69e2'}
    members=prior['source_members18'];assert len(members)==18
    before={name:r.fixed(OLD/name,prior['files'][name]) for name in members}
    for name,data in before.items():r.write('before/'+name,data)
    pub=json.loads(before['validation/PROFILE-PUBLICATION.json']);oldpub=copy.deepcopy(pub);context=pub['finite_contexts']
    oldm=before['materializer/prepare.py'].decode();olds=before['validation/run.py'].decode();oldfn=r.function(oldm,'source_authority');assert oldfn==r.function(olds,'source_authority')
    values=r.constants(oldm);oldvalues=copy.deepcopy(values)
    oldchain=copy.deepcopy(values['COMMIT_CHAIN']);stage=r.obj(SRC/'SIXTEENTH-SOURCE-AUTHORITY.json')
    chain=oldchain+[{key:stage[key] for key in ['kind','source_sha','parent','files']}|{'commit':r.pointer(SRC/'SIXTEENTH-SOURCE-AUTHORITY.json')}]
    assert len(chain)==16 and chain[15]['source_sha']==r.HEAD and chain[15]['parent']==r.PARENT
    pp=r.obj(SRC/'profile-source/PINS.json');profile_pointer=r.pointer(SRC/'profile-source/PINS.json')
    manifest=r.obj(SRC/'profile-source/MANIFEST.json');candidate9={name:r.fixed(SRC/'profile-source/candidate'/name,row) for name,row in manifest['candidate'].items()}
    for name,raw in candidate9.items():assert r.read(OUT/'validation/actual-profile'/name)==raw
    selected9=manifest['selected9'];assert len(selected9)==9
    syntaxpath=B/'current881-form-prefix-initializer-php-syntax-actual-r1/RESULT.json';syntax=r.obj(syntaxpath)
    assert r.pin(r.read(syntaxpath))=={'bytes':8564,'sha256':'076e3b7b109ac3cb0523927dee5223b1966932e7fbdbe5cf288902664c9afe66'}
    assert syntax['candidate_Source_PINS']==profile_pointer and syntax['candidate_Source_manifest']==r.pointer(SRC/'profile-source/MANIFEST.json')
    assert syntax['status']=='PASS_CANDIDATE_PHP82_SYNTAX_ONLY' and syntax['container_native_exit']==0 and syntax['ordinary_own_container_removed'] is True
    root_syntax=B/'current881-form-prefix-initializer-php-syntax-root-r1/REPORT.json'
    r.put('INITIALIZER-FORM-SYNTAX.json',{'schema':1,'status':'PASS_COMPLETE_CURRENT881_INITIALIZER_PHP82_SYNTAX_ONLY','source_profile_PINS':profile_pointer,
      'initializer':r.pointer(SRC/'profile-source/candidate/ms4-initialize.php'),'actual_RESULT_pointer':r.pointer(syntaxpath),'actual_result':syntax,
      'Root_readback':r.pointer(root_syntax),'App_business':'NOT RUN'})
    syntax_pointer=r.pointer(OUT/'INITIALIZER-FORM-SYNTAX.json')
    # Derive the complete current namespace from the original independent inventory and all actual16 steps.
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
    assert (len(expected),len(changes),len(requirements),len(newtracked),2*len(expected),budget)==(15746,305,311,260,31492,1046)
    assert list(counts.values())==[10,10865,10866,10881,10885,10886] and len(combined)==10886
    form_delta=r.pointer(SRC/'FORM-DELTA.json');historical_pub_pointer=r.pointer(OLD/'validation/PROFILE-PUBLICATION.json')
    composite={'scope':'CURRENT881_EXACT_FORM_LABEL_LAYER_OVER_FULL_HISTORICAL_FIFTEENTH_COMPOSITE','source_sha':r.HEAD,'profile_PINS':profile_pointer,
      'profile_interface':r.pointer(OUT/'validation/actual-profile/interface-v1.json'),'predecessor_publication':historical_pub_pointer,
      'predecessor_composite':copy.deepcopy(context['current_composite_customer_form_source']),'form_delta':form_delta,'php_sources':manifest['current_seven_php']}
    alignment=r.pointer(B/'current881-validation-alignment-r1/RESULT.json');retirement=r.pointer(SRC/'CURRENT-2E4-RETIREMENT-COMPLETE.json')
    context.update(commit_chain=chain,committed_changes=changes,committed_files=committed,new_tracked_paths=newtracked,actual_complete_expected_tracked_count=len(expected),source_counts=counts,
      current_composite_customer_form_source=composite,historical_fifteenth_publication=historical_pub_pointer,current_sixteenth_source_history=r.pointer(SRC/'SIXTEENTH-SOURCE-AUTHORITY.json'),
      current881_validation_alignment=alignment,current_form_assertion_prefix_source=form_delta,current_form_profile_source=profile_pointer,current_initializer_form_syntax=syntax_pointer,
      current_2e4_paid_producer_failure_retirement=retirement,source_authoring_qualification_scope='ACTUAL_CURRENT_881_BOTH_FULL_REQUIRED_WORKTREES_AND_CLEAN_HEAD_PARENT_BEFORE_AFTER',
      current_published_source_scope='All fifteen original stages and five retirements remain exact; only actual881 helper60label and2docs form step16, plus separately named actual2e4 sixth retirement. M32 strict; business NOT RUN.')
    pub.update(source_sha=r.HEAD,parent=r.PARENT,selected9=selected9,repository_Source_requirements=[expected[name] for name in requirements])
    results=[node for node in ast.walk(ast.parse(oldm)) if isinstance(node,ast.Dict) and any(isinstance(key,ast.Constant) and key.value=='materialize_source_inputs' for key in node.keys)]
    assert len(results)==1 and len(results[0].keys)==31;oldkeys=[k.value for k in results[0].keys]
    currentkeys=oldkeys[:oldkeys.index('native_operations')]+['current_2e4_paid_producer_failure_retirement']+oldkeys[oldkeys.index('native_operations'):]
    old_literals={key:oldvalues[key] for key in ['COMMIT_CHAIN','COMMITTED_PATHS','SOURCE_REQUIREMENT_PATHS','NEW_TRACKED_PATHS','CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']}
    added={'HISTORICAL_FIFTEEN_M_SOURCE':r.pointer(OLD/'materializer/prepare.py'),'HISTORICAL_FIFTEEN_PUBLICATION':historical_pub_pointer,
      'HISTORICAL_FIFTEEN_PACKAGE':r.pointer(OLD/'PINS.json'),'HISTORICAL_FIFTEEN_CONSTANTS':old_literals,'HISTORICAL_FIFTEEN_AUTHORITY_SOURCE':r.pin(oldfn.encode()),
      'SIXTEENTH_SOURCE_HISTORY':r.pointer(SRC/'SIXTEENTH-SOURCE-AUTHORITY.json'),'CURRENT_881_VALIDATION_ALIGNMENT':alignment,
      'CURRENT_FORM_DELTA':form_delta,'CURRENT_FORM_PROFILE_SOURCE':profile_pointer,'CURRENT_INITIALIZER_FORM_SYNTAX':syntax_pointer,
      'CURRENT_2E4_PAID_PRODUCER_FAILURE_RETIREMENT':retirement,'CURRENT_M_RESULT_KEYS32':currentkeys}
    values.update(COMMIT_CHAIN=chain,COMMITTED_PATHS=[row['path'] for row in changes],SOURCE_REQUIREMENT_PATHS=requirements,NEW_TRACKED_PATHS=newtracked,CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=composite)
    # Embed the full original function body, with its five immutable historical literal inputs explicitly bound.
    nested='    def historical_fifteen(pub, reader, check):\n'+''.join('        '+key+' = HISTORICAL_FIFTEEN_CONSTANTS['+repr(key)+']\n' for key in old_literals)
    nested+='\n'.join('    '+line for line in oldfn.splitlines()[1:])+'\n'
    fragment=r.read(OUT/'authority-current.py.fragment').decode();fn=r.once(fragment,'__HISTORICAL_VALIDATOR__\n',nested).rstrip('\n')
    nestednode=next(n for n in ast.parse(fn).body[0].body if isinstance(n,ast.FunctionDef) and n.name=='historical_fifteen')
    nestedtext=ast.get_source_segment(fn,nestednode);body=nestedtext.splitlines()[1+len(old_literals):]
    recovered=oldfn.splitlines()[0]+'\n'+'\n'.join(line[4:] if line.startswith('    ') else line for line in body)
    assert recovered==oldfn,'FULL_ORIGINAL_HISTORICAL_AUTHORITY_BODY_INVERSE'
    def shared(text):
        text=r.once(text,r.function(text,'source_authority'),fn)
        for key in ['COMMIT_CHAIN','COMMITTED_PATHS','SOURCE_REQUIREMENT_PATHS','NEW_TRACKED_PATHS','CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']:text=r.const(text,key,values[key])
        return r.once(text,'def source_authority(',''.join(key+' = '+repr(value)+'\n' for key,value in added.items())+'\ndef source_authority(')
    r.put('validation/PROFILE-PUBLICATION.json',pub)
    pins=json.loads(before['validation/PROFILE-PINS.json']);pins['members']={row['path']:r.compact(row) for row in selected9};pins['members']['PROFILE-PUBLICATION.json']=r.pin(r.read(OUT/'validation/PROFILE-PUBLICATION.json'));r.put('validation/PROFILE-PINS.json',pins)
    note=('\n\nCurrent881 complete normal Source successor '+r.HEAD+' parent '+r.PARENT+'. Full15 historical validator/body/literals/publication and original five retirement authorities retained. '
      'Actual step16: fixed60 assertion labels plus2docs only; independent15746 tracked/305 cumulative/311requirements/260 baseline additions/31492 dual snapshot/native budget1046. '
      'Six exact profile changes include initializer single form tuple; new full78955 PHP82 syntax actualPASS bound separately, no initializer/App entry executed. '
      'Strict current M32 adds current_2e4_paid_producer_failure_retirement while historical M31/current_paid_producer_failure_retirement=53e stay exact. Current2e4 M1037/S1 and93close20zero remain failed/incomplete business facts. '
      'Current Source authority/namespace and full Main/Val current qualification only. New M/S/Host/App/business NOT RUN.\n').encode()
    r.write('validation/DESIGN-TASKS.md',before['validation/DESIGN-TASKS.md']+note)
    s=shared(olds);s=r.const(s,'SOURCE',r.HEAD);s=r.const(s,'PROFILE_PUBLICATION',r.pin(r.read(OUT/'validation/PROFILE-PINS.json')))
    oldspins=r.constants(olds)['PINS'];s=r.const(s,'PINS',{name:r.pin(candidate9[name])['sha256'] for name in oldspins})
    exact32="    require(set(actual_setup) == set(CURRENT_M_RESULT_KEYS32) and len(actual_setup) == 32, 'EXACT_CURRENT881_M32_WITH_SIXTH_NAMED_RETIREMENT')\n"
    s=r.once(s,"    actual_setup = json.loads(SETUP.read_bytes(),object_pairs_hook=pairs)\n","    actual_setup = json.loads(SETUP.read_bytes(),object_pairs_hook=pairs)\n"+exact32)
    oldjoin="and actual_setup['current_paid_producer_failure_retirement'] == qualified['paid_producer_failure_retirement'],"
    newjoin="and actual_setup['current_paid_producer_failure_retirement'] == qualified['paid_producer_failure_retirement']\n        and actual_setup['current_2e4_paid_producer_failure_retirement'] == qualified['current_2e4_paid_producer_failure_retirement'],"
    s=r.once(s,oldjoin,newjoin);r.write('validation/run.py',s.encode())
    rp=json.loads(before['validation/PINS.json']);rp['members']={name:r.pin(r.read(OUT/'validation'/name)) for name in ['DESIGN-TASKS.md','run.py']};r.put('validation/PINS.json',rp)
    m=shared(oldm);m=r.const(m,'head',r.HEAD);m=r.const(m,'parent',r.PARENT)
    for key,folder in [('root','validation'),('tool','materializer')]:m=r.once(m,key+"=Path('"+(OLD/folder).as_posix()+"')",key+"=Path('"+(OUT/folder).as_posix()+"')")
    m=r.once(m,"need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')",
      "need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(r.read(OUT/'validation/PINS.json')))+",'REVIEWED_ROOT_SOURCE_PINS')")
    m=r.once(m,"'current_paid_producer_failure_retirement':qualified['paid_producer_failure_retirement'],'native_operations':ops,",
      "'current_paid_producer_failure_retirement':qualified['paid_producer_failure_retirement'],'current_2e4_paid_producer_failure_retirement':qualified['current_2e4_paid_producer_failure_retirement'],'native_operations':ops,")
    r.write('materializer/prepare.py',m.encode());r.write('materializer/PLAN.md',before['materializer/PLAN.md']+note)
    inverse=json.loads(before['materializer/INVERSE.json']);inverse.update(source_sha=r.HEAD,parent=r.PARENT,commit_chain=chain,cumulative_changed_paths=changes)
    inverse['current_sixteenth_form_prefix_successor']={'step':added['SIXTEENTH_SOURCE_HISTORY'],'profile':profile_pointer,'helper_delta':form_delta,'new_initializer_syntax':syntax_pointer,'sixth_retirement':retirement,'M32':currentkeys,'native_budget':budget,'business':'NOT RUN'};r.put('materializer/INVERSE.json',inverse)
    launcher=before['launcher/launch.py'].decode();launcher=r.once(launcher,"ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')")
    launcher=r.once(launcher,(OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix());launcher=r.once(launcher,r.pin(before['validation/PINS.json'])['sha256'],r.pin(r.read(OUT/'validation/PINS.json'))['sha256'])
    launcher=r.once(launcher,"value['source_sha']=='"+r.PARENT+"'","value['source_sha']=='"+r.HEAD+"'")
    extra="current_2e4_pointer = "+repr(retirement)+"\nassert publication['finite_contexts']['current_2e4_paid_producer_failure_retirement'] == current_2e4_pointer\nassert descriptor(Path(current_2e4_pointer['path'])) == current_2e4_pointer\ncurrent_2e4 = read(Path(current_2e4_pointer['path']))\nassert current_2e4['original_S_native_exit'] == current_2e4['paid_producer_native_exit'] == 1 and current_2e4['business_acceptance'] == 'INCOMPLETE'\nCURRENT_M_RESULT_KEYS32 = "+repr(currentkeys)+"\n\n"
    launcher=r.once(launcher,"if mode=='materialize':\n",extra+"if mode=='materialize':\n")
    launcher=r.once(launcher," setup=ROOT/'materialize-freeze-assets-r1/RESULT.json';value=read(setup)\n"," setup=ROOT/'materialize-freeze-assets-r1/RESULT.json';value=read(setup)\n assert set(value)==set(CURRENT_M_RESULT_KEYS32) and len(value)==32\n assert value['current_2e4_paid_producer_failure_retirement']==current_2e4\n")
    r.write('launcher/launch.py',launcher.encode())
    # All changes outside the new authority are exact finite named seams.
    for name,body in funcs(oldm).items():
        if name!='source_authority':assert funcs(m)[name]==body,name
    for name,body in funcs(olds).items():
        if name not in ['source_authority','main']:assert funcs(s)[name]==body,name
    restored_main=funcs(s)['main'].replace(exact32,'').replace(newjoin,oldjoin);assert restored_main==funcs(olds)['main']
    assert funcs(launcher)==funcs(before['launcher/launch.py'])
    suffix="    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')"
    assert s[s.index(suffix):]==olds[olds.index(suffix):] and r.function(m,'source_authority')==r.function(s,'source_authority')
    results=[node for node in ast.walk(ast.parse(m)) if isinstance(node,ast.Dict) and any(isinstance(key,ast.Constant) and key.value=='materialize_source_inputs' for key in node.keys)]
    assert len(results)==1 and [k.value for k in results[0].keys]==currentkeys and len(currentkeys)==32
    for name in members:
        data=r.read(OUT/name)
        if name.endswith('.py'):ast.parse(data)
        elif name.endswith('.json'):json.loads(data)
    # Only the actual-declared stdlib imports/literals and complete pure authority run here.
    qm,nm=actual_namespace_qualification(m.encode(),'M',pub);qs,ns=actual_namespace_qualification(s.encode(),'S',pub)
    assert qm==qs and qm['all']==expected and qm['groups']==groups and qm['committed']==committed and qm['commit_chain']==chain
    r.put('ACTUAL-MODULE-NAMESPACE-CHECKS.json',{'M':nm,'S':ns,'complete_historical_body_inverse':True,'all_runtime_entries':'NOT RUN'})
    after={name:r.read(OUT/name) for name in members};forward=r.patch(before,after);reverse=r.patch(after,before)
    assert r.apply(before,forward)==after and r.apply(after,reverse)==before;r.write('DIFF.patch',forward.encode());r.write('REVERSE.patch',reverse.encode())
    original={name:r.read(OLD/'original-before'/name) for name in members}
    for name,data in original.items():r.write('original-before/'+name,data)
    forward=r.patch(original,after);reverse=r.patch(after,original);assert r.apply(original,forward)==after and r.apply(after,reverse)==original
    r.write('FROM-ORIGINAL.patch',forward.encode());r.write('TO-ORIGINAL.patch',reverse.encode())
    qualification=current_qualification(pub,expected,values)
    checks={'schema':1,'status':'COMPLETE_CURRENT881_SOURCE_ONLY_NOT_M_S_EXECUTED','steps':len(chain),'tracked':len(expected),'cumulative':len(changes),'requirements':len(requirements),'new_baseline_members':len(newtracked),'dual_snapshot_rows':2*len(expected),
      'group_counts':counts,'normal_union':len(combined),'M_native_budget':budget,'M_RESULT_current_fields':32,'M_RESULT_historical_fields':31,'six_retirements':True,
      'historical_fifteen_body_inverse_exact':True,'historical_fifteen_constants_and_publication_fixed':True,'all_non_authority_M_functions_exact':True,'S_main_only_two_exact_preRun_consumer_seams':True,'full_original_business_suffix_exact':True,
      'four_complete_standard_patch_directions_replayed':True,'source_qualification':qualification,'initializer_actual_syntax':syntax_pointer,'runtime':'NOT RUN'}
    r.put('AUTHOR-CHECKS.json',checks)
    ended=time.time_ns();assert all(row['closed'] and row['EOF'] and row['ended_ns']<=ended for row in r.READS.values())
    r.put('AUTHOR-INPUTS.json',{'schema':1,'input_count':len(r.READS),'all_input_windows_ended':True,'ended_ns':ended,'inputs':r.READS,'target_runtime':'NOT RUN','current_Main_and_Val_working_trees_asserted':True})
    files={p.relative_to(OUT).as_posix():{'path':p.as_posix(),**r.pin(p.read_bytes()),'native9':r.n9(p.stat())} for p in sorted(OUT.rglob('*')) if p.is_file() and p!=OUT/'PINS.json'}
    r.put('PINS.json',{'schema':1,'status':checks['status'],'source_sha':r.HEAD,'parent':r.PARENT,'source_members18':members,'files':files,'Root14_M3_contract':True,
      'before_source':r.pointer(OLD/'PINS.json'),'profile_source':profile_pointer,'sixteenth_source_history':added['SIXTEENTH_SOURCE_HISTORY'],'sixth_retirement':retirement,'initializer_syntax':syntax_pointer,
      'current_source_qualification':qualification,'pure_authority':checks,'all_input_windows_ended':True,'input_count':len(r.READS),'ended_ns':ended,'new_M_S_Host_PHP_Docker_App_business':'NOT RUN'})
    print(json.dumps({'PINS':r.pointer(OUT/'PINS.json'),'M':r.pin(after['materializer/prepare.py']),'S':r.pin(after['validation/run.py']),'Launcher':r.pin(after['launcher/launch.py']),'steps':len(chain),'tracked':len(expected),'budget':budget,'runtime':'NOT RUN'},ensure_ascii=False))

if __name__=='__main__':
    try:main()
    except Exception as error:
        count=len(list(OUT.glob('AUTHORING-FAILURE-*.json')))+1
        path=OUT/('AUTHORING-FAILURE-'+str(count).zfill(2)+'.json')
        path.write_text(json.dumps({'status':'OWN_SOURCE_COMPOSITION_OR_QUALIFICATION_REFUSED_NOT_A_TARGET_RUN','class':type(error).__name__,'message':str(error),'traceback':traceback.format_exc(),'target_runtime':'NOT RUN'},ensure_ascii=False,indent=2)+'\n','utf-8')
        raise
