# Embedded Source fragment only. The old R40 adapters remain complete historical
# definitions in the derived Root M. No function in this fragment is executed by
# the Source author. Missing future receipts are rejected before any mutation.
def current_known_normal_root(inputs):
    authority = inputs['normal_root']
    contract = CURRENT_M_SOURCE_CONTRACT
    assert type(contract['final_eligible_A_Source']) is dict and contract['final_eligible_A_Source'] == contract['A_source_PINS'], 'WAIT_FINAL_A_SOURCE_AFTER_SOURCE_FINDINGS'
    assert contract['source_sha'] == HEAD and contract['parent'] == COMMIT_PARENT
    assert contract['status'] == 'SOURCE_CONTRACT_ACTUAL_CURRENT_M0_BOUND_A_POSTA_PENDING'
    assert type(contract['complete_tracked_paths15504']) is list and len(contract['complete_tracked_paths15504']) == 15504
    assert contract['complete_tracked_paths15504'] == sorted(set(contract['complete_tracked_paths15504']))
    assert type(contract['M_result']) is dict and set(contract['M_result']) == {'path','bytes','sha256'}, 'WAIT_ACTUAL_SAME_HEAD_CURRENT_M_RESULT'
    assert type(contract['M_outcome']) is dict and set(contract['M_outcome']) == {'created','terminal','stdout','stderr','created_object','terminal_object'}, 'WAIT_ACTUAL_CURRENT_M_OUTER_NATIVE_ZERO'
    outcome = contract['M_outcome']
    created = json.loads(file_pin(Path(outcome['created']['path']),outcome['created']))
    terminal = json.loads(file_pin(Path(outcome['terminal']['path']),outcome['terminal']))
    assert created == outcome['created_object'] and terminal == outcome['terminal_object']
    assert set(created) == {'pid','argv','started_ns'}
    assert set(terminal) == {'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'}
    assert type(created['pid']) is int and created['pid'] > 0 and terminal['pid'] == created['pid']
    assert created['argv'] == contract['M_launcher_argv'] and type(created['argv']) is list
    assert type(created['started_ns']) is int and type(terminal['finished_ns']) is int
    assert 0 < created['started_ns'] < terminal['finished_ns']
    assert type(terminal['native_exit']) is int and type(terminal['wait_returncode']) is int
    assert terminal['native_exit'] == terminal['wait_returncode'] == 0
    for suffix in ['stdout','stderr']:
        descriptor = terminal[suffix]
        assert type(descriptor) is dict and set(descriptor) == {'path','bytes','sha256'}
        assert descriptor == outcome[suffix]
        assert descriptor['path'] == (NORMAL_SOURCE.parent/'launcher'/('materialize.'+suffix)).as_posix()
        assert type(descriptor['bytes']) is int and descriptor['bytes'] >= 0
        assert type(descriptor['sha256']) is str and re.fullmatch('[a-f0-9]{64}',descriptor['sha256'])
        raw = file_pin(Path(outcome[suffix]['path']),outcome[suffix])
        assert len(raw) == descriptor['bytes'] and sha(raw) == descriptor['sha256']
        if suffix == 'stderr': assert raw == b''
    root = ordinary(Path(authority['root']),True)
    assert root == NORMAL_SOURCE
    manifest = json.loads(file_pin(root/'PINS.json',authority['PINS']))
    assert manifest == {'schema':1,'scope':'FULL_A_ASSET_NORMAL_DRIVER_SOURCE_PINS','members':NORMAL_SOURCE_PINS}
    assert set(manifest['members']) == {'DESIGN-TASKS.md','run.py'}
    for name,row in manifest['members'].items(): file_pin(root/name,row)
    setup_path = root/'materialize-freeze-assets-r1/RESULT.json'
    assert contract['M_result']['path'] == setup_path.as_posix()
    setup = json.loads(file_pin(setup_path,contract['M_result']))
    assert set(setup) == set(contract['result_fields_exact30']) and len(setup) == 30
    assert setup['schema'] == 1 and setup['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'
    assert setup['source_sha'] == HEAD and setup['parent'] == COMMIT_PARENT
    assert setup['source_patch_base'] == contract['source_patch_base']
    assert setup['commit_chain'] == contract['commit_chain'] and len(setup['commit_chain']) == 11
    assert [r['path'] for r in setup['committed_files']] == contract['committed_paths62']
    assert len(setup['committed_files']) == 62 and setup['selected9'] == contract['selected9']
    assert setup['source_counts'] == contract['source_counts'] and setup['unique_source_files_actual_read'] == 10886
    assert setup['Run_created'] is False and setup['App_DB_HTTP_business'] == 'NOT RUN'
    assert setup['repo'] == REPO.as_posix() and setup['profile'] == OLD_PROFILE.as_posix()
    assert setup['root_source_pins'] == authority['PINS']
    assert setup['profile_publication'] == {'root':inputs['normal_profile_publication']['root'],
        'pins':inputs['normal_profile_publication']['PINS'],'publication':inputs['normal_profile_publication']['PUBLICATION']}
    # These remain four distinct actual historical failure retirements. None is
    # substituted for future successful current A close/zero.
    assert setup['prior_retirement'] == contract['prior_retirement_expected']
    assert setup['current_driver_failure_retirement'] == contract['driver_failure_retirement_expected']
    assert setup['current_early_failure_retirement'] == contract['early_failure_retirement_expected']
    assert json.loads(file_pin(Path(contract['early_failure_retirement_source']['path']),contract['early_failure_retirement_source'])) == contract['early_failure_retirement_expected']
    assert setup['current_customer_prepare_failure_retirement'] == contract['customer_prepare_failure_retirement_expected']
    assert json.loads(file_pin(Path(contract['customer_prepare_failure_retirement_source']['path']),contract['customer_prepare_failure_retirement_source'])) == contract['customer_prepare_failure_retirement_expected']
    assert setup['proof_resource_required'] is True and type(setup['proof_php_ini']) is dict and len(setup['proof_php_ini']) == 7
    assert authority['known_A_identity'] == {'scope':'IDENTITY_ONLY_NOT_SUCCESS','run_id':OLD_RUN,
        'source_sha':HEAD,'freeze_sha256':OLD_FREEZE,'freeze_bytes':OLD_FREEZE_BYTES,
        'context':{'path':(OLD_ROOT/'actual-run-context.json').as_posix(),**OLD_CONTEXT_PIN}}
    file_pin(OLD_ROOT/'actual-run-context.json',OLD_CONTEXT_PIN)
    return authority,setup


def current_tracked_source_trees(snapshot):
    """Join requirements to both full tracked trees, independently of role union."""
    assert type(snapshot) is dict and len(snapshot) == 31008
    trees = {'main':{},'validation':{}}
    for name,row in snapshot.items():
        assert type(row) is dict and set(row) == {'path','bytes','sha256','stat9'} and row['path'] == name
        assert type(row['bytes']) is int and row['bytes'] >= 0 and type(row['sha256']) is str
        assert re.fullmatch('[a-f0-9]{64}',row['sha256']) and len(row['stat9']) == 9 and all(type(v) is int for v in row['stat9'])
        path = Path(name); matches = [(label,base) for label,base in [('main',MAIN),('validation',REPO)] if path.is_relative_to(base)]
        assert len(matches) == 1 and path.is_absolute()
        label,base = matches[0]; relative = path.relative_to(base).as_posix()
        assert relative and not relative.startswith('/') and '\\' not in relative and ':' not in relative and '\0' not in relative
        assert all(part not in ['', '.', '..'] for part in relative.split('/')) and relative not in trees[label]
        trees[label][relative] = {'path':relative,'bytes':row['bytes'],'sha256':row['sha256']}
    expected = CURRENT_M_SOURCE_CONTRACT['complete_tracked_paths15504']
    assert type(expected) is list and len(expected) == 15504 and expected == sorted(set(expected))
    assert all(len(rows) == 15504 and sorted(rows) == expected for rows in trees.values())
    assert trees['main'] == trees['validation']
    return trees


def current_consume_materialization(inputs):
    authority,setup = current_known_normal_root(inputs)
    contract = CURRENT_M_SOURCE_CONTRACT
    folder = NORMAL_SOURCE/'materialize-freeze-assets-r1'
    tool = NORMAL_SOURCE.parent/'materializer'
    assert {p.name for p in tool.iterdir()} == {'PLAN.md','prepare.py','INVERSE.json'}
    assert set(setup['materialize_source_inputs']) == {(tool/name).as_posix() for name in ['PLAN.md','prepare.py','INVERSE.json']}
    copied = file_pin(Path(setup['materialize_source']['path']),setup['materialize_source'])
    assert Path(setup['materialize_source']['path']) == folder/'root-materialize-source.py'
    assert copied == file_pin(Path(contract['M_Source']['path']),contract['M_Source'])
    for name,row in setup['materialize_source_inputs'].items():
        assert set(row) == {'path','bytes','sha256','stat9'} and row['path'] == name
        raw = file_pin(Path(name),row)
        assert len(row['stat9']) == 9 and all(type(v) is int for v in row['stat9'])
        facts = Path(name).lstat()
        assert [getattr(facts,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']] == row['stat9']
        if Path(name).name == 'prepare.py': assert raw == copied
    text = copied.decode('utf-8')
    assert text.index("write(out/'root-materialize-source.py'") < text.index("phase='native_git_and_help'")
    rows = setup['native_operations']; commands = contract['native_commands']
    derived = 6+sum(2+2*len(step['files']) for step in contract['commit_chain'])+1+len(contract['committed_paths62'])+2+3+1+4
    assert derived == len(rows) == len(commands) == contract['derived_native_count'] == 277
    seen = set()
    for index,(row,command) in enumerate(zip(rows,commands),1):
        assert set(row) == {'label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','stem'}
        assert {'label':row['label'],'argv':row['argv']} == command
        assert row['stem'] == '%03d-'%index+row['label'] and row['label'] not in seen
        seen.add(row['label']); stem = folder/row['stem']
        assert json.loads(ordinary(stem.with_suffix('.created.json')).read_bytes()) == {'argv':row['argv'],'pid':row['pid']}
        assert json.loads(ordinary(stem.with_suffix('.terminal.json')).read_bytes()) == row
        assert type(row['pid']) is int and row['pid'] > 0
        assert type(row['native_exit']) is int and type(row['wait_returncode']) is int
        assert row['native_exit'] == row['wait_returncode'] == 0
        streams = {}
        for suffix in ['stdout','stderr']:
            path = stem.with_suffix('.'+suffix)
            streams[suffix] = file_pin(path,{'bytes':row[suffix+'_bytes'],'sha256':row[suffix+'_sha256']})
            OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(streams[suffix]),'sha256':sha(streams[suffix])})
        assert streams['stderr'] == b''
        label = row['label']; raw = streams['stdout']
        if label in {'main-head-before','validation-head-before','main-head-after','validation-head-after'}: assert raw == (HEAD+'\n').encode()
        elif label in {'main-parent','validation-parent'}: assert raw == (HEAD+' '+COMMIT_PARENT+'\n').encode()
        elif 'status' in label: assert raw == b''
        elif label.startswith('source-step-'):
            bits=label.split('-'); step=contract['commit_chain'][int(bits[2])-1]
            if bits[3]=='parent': assert raw == (step['source_sha']+' '+step['parent']+'\n').encode()
            elif bits[3]=='paths': assert sorted(raw.decode().splitlines()) == [r['path'] for r in step['files']]
            else:
                member=step['files'][int(bits[4])-1]
                if label.endswith('absent-before'): assert member['before'] is None and raw == b''
                else: assert {'bytes':len(raw),'sha256':sha(raw)} == member['after' if label.endswith('-after') else 'before']
        elif label=='cumulative-changed-paths': assert sorted(raw.decode().splitlines()) == contract['committed_paths62']
        elif label.startswith('committed-blob-'):
            r=setup['committed_files'][int(label.rsplit('-',1)[1])-1]
            assert {'bytes':len(raw),'sha256':sha(raw)} == {k:r[k] for k in ['bytes','sha256']}
            file_pin(MAIN/r['path'],r);file_pin(REPO/r['path'],r)
        elif label.endswith('-complete-tracked-inventory'):
            names=raw.decode().split('\0');assert names[-1]=='' and len(names)-1==15504 and sorted(names[:-1])==contract['complete_tracked_paths15504']
        elif label=='freeze': assert json.loads(raw)=={'status':'CLEAN_SOURCE_FROZEN_ONLY','source_sha':HEAD,'freeze_sha256':setup['freeze_sha256'],'runtime_SQL_App':'NOT RUN'}
        else: assert label in {'help-ms4-host-py','help-normal-close-ms4-fulfillment-py','help-verify-zero-ms4-fulfillment-py'} and raw
        for suffix in ['created.json','terminal.json']:
            path=stem.with_suffix('.'+suffix);raw=ordinary(path).read_bytes();OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    snapshots={}
    for key,count in [('root_source_snapshot',14),('complete_source_snapshot',31008)]:
        descriptor=setup[key];snapshot=json.loads(file_pin(Path(descriptor['path']),descriptor));assert len(snapshot)==count
        for name,row in snapshot.items():
            assert row['path']==name and set(row)=={'path','bytes','sha256','stat9'} and len(row['stat9'])==9 and all(type(x) is int for x in row['stat9'])
            file_pin(Path(name),row);facts=Path(name).lstat()
            assert [getattr(facts,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']]==row['stat9']
        snapshots[key]=snapshot
    expected_root14={(NORMAL_SOURCE/name).as_posix() for name in ['DESIGN-TASKS.md','run.py','PINS.json','PROFILE-PINS.json','PROFILE-PUBLICATION.json']}
    expected_root14|={(OLD_PROFILE/r['path']).as_posix() for r in contract['selected9']}
    assert set(snapshots['root_source_snapshot'])==expected_root14 and len(expected_root14)==14
    authority_descriptor=contract['M_authority_input_snapshot']
    assert type(authority_descriptor) is dict and set(authority_descriptor)=={'path','bytes','sha256'}
    assert authority_descriptor['path']==(folder/'AUTHORITY-INPUT-SNAPSHOT.json').as_posix()
    authority_snapshot=json.loads(file_pin(Path(authority_descriptor['path']),authority_descriptor))
    assert type(authority_snapshot) is dict and authority_snapshot
    for name,row in authority_snapshot.items():
        assert row['path']==name and set(row)=={'path','bytes','sha256','stat9'}
        assert len(row['stat9'])==9 and all(type(x) is int for x in row['stat9'])
        file_pin(Path(name),row);facts=Path(name).lstat()
        assert [getattr(facts,k) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']]==row['stat9']
    for base in [MAIN,REPO]:
        actual=sorted(Path(name).relative_to(base).as_posix() for name in snapshots['complete_source_snapshot'] if Path(name).is_relative_to(base))
        assert actual==contract['complete_tracked_paths15504'] and len(actual)==15504
    tracked=current_tracked_source_trees(snapshots['complete_source_snapshot'])
    frozen_raw=file_pin(Path(setup['freeze']),{'bytes':setup['freeze_bytes'],'sha256':setup['freeze_sha256']});frozen=json.loads(frozen_raw)
    assert Path(setup['freeze'])==folder/'freeze-binding.json' and frozen['source_sha']==HEAD and frozen['requested_repo_source']==REPO.as_posix()
    assert frozen['artifact_files']==setup['selected9'] and frozen['proof_php_ini']==setup['proof_php_ini']
    combined={}
    for group,count in contract['source_counts'].items():
        rows=frozen[group+'_source_files'];assert len(rows)==len({r['path'] for r in rows})==count
        for r in rows:
            assert r['path'] not in combined or combined[r['path']]==r
            combined[r['path']]=r
            for base in [MAIN,REPO]:assert {k:snapshots['complete_source_snapshot'][(base/r['path']).as_posix()][k] for k in ['path','bytes','sha256']}=={'path':(base/r['path']).as_posix(),'bytes':r['bytes'],'sha256':r['sha256']}
    assert len(combined)==10886
    assert len(contract['source_requirements68'])==68 and len({r['path'] for r in contract['source_requirements68']})==68
    for r in contract['source_requirements68']:
        assert set(r)=={'path','bytes','sha256'}
        assert tracked['main'][r['path']]==tracked['validation'][r['path']]==r
    for name in ['RESULT.json','root-materialize-source.py','freeze-binding.json','ROOT-SOURCE-SNAPSHOT.json','COMPLETE-SOURCE-SNAPSHOT.json','AUTHORITY-INPUT-SNAPSHOT.json']:
        path=folder/name;raw=ordinary(path).read_bytes();OLD_NATIVE_PINS.append({'path':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)})
    # Final exact repeat after all cross-file/source/native joins.
    assert current_known_normal_root(inputs)[1]==setup
    return setup


def current_verify_sources():
    inputs=source_inputs(); _,setup=current_known_normal_root(inputs)
    normal_root,normal_manifest,normal_pub,normal_state=publication_source(inputs['normal_profile_publication'],
        'FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_PINS','FULL_A_ASSET_NORMAL_NINE_METADATA_SOURCE_ONLY',10,
        {'schema','scope','source_sha','parent','base_profile','selected9','repository_Source_requirements','finite_contexts','target_execution'})
    assert normal_root==OLD_PROFILE and setup['selected9']==normal_pub['selected9']
    assert len(normal_pub['repository_Source_requirements'])==68
    ui_root,ui_manifest,pub,ui_state=publication_source(inputs['ui_profile_publication'],
        'R40_UI17_METADATA_SOURCE_PINS_NOT_RUNTIME_SEAL','R40_UI17_KNOWN_SOURCE_METADATA_ONLY',19,
        {'schema','scope','source_sha','parent','base_source','base_profile','selected17','repository_Source_requirements','finite_contexts','target_execution'})
    assert ui_root==PROFILE_SOURCE and [r['path'] for r in pub['selected17']]==ARTIFACTS
    assert pub['repository_Source_requirements']==normal_pub['repository_Source_requirements']
    finite=pub['finite_contexts']
    assert finite['repository_committed_files']==COMMITTED_FILES and len(COMMITTED_FILES)==3
    assert finite['normal9_authority']=={'root':inputs['normal_profile_publication']['root'],'profile_root':inputs['normal_profile_publication']['profile_root'],
        'PROFILE_PINS':inputs['normal_profile_publication']['PINS'],'PROFILE_PUBLICATION':inputs['normal_profile_publication']['PUBLICATION']}
    authority=json.loads(file_pin(Path(inputs['current_core_aux_authority']['path']),inputs['current_core_aux_authority']))
    assert set(authority)=={'schema','scope','source_sha','core_sources','browser_manifest','base_source'}
    assert authority['schema']==1 and authority['source_sha']==HEAD and authority['base_source']==pub['base_source']
    assert finite['current_core_aux_authority']==inputs['current_core_aux_authority'] and finite['current_Core3']==authority['core_sources']
    auxiliary=package(AUX_SOURCE,authority['browser_manifest'],8)
    manifest=json.loads(file_pin(AUX_SOURCE/'PINS.json',authority['browser_manifest']))
    assert list(manifest['members'])==TASK2_BROWSER_NAMES_CURRENT and len(manifest['members'])==8
    assert manifest['members']=={name:auxiliary['files'][name] for name in TASK2_BROWSER_NAMES_CURRENT}
    assert finite['ordered_auxiliary_members']==[{'path':name,**manifest['members'][name]} for name in TASK2_BROWSER_NAMES_CURRENT]
    assert json.loads(ordinary(AUX_SOURCE/'ui-business-consumer-contract.json').read_bytes())['php_sources']=={r['path']:{k:r[k] for k in ['bytes','sha256']} for r in authority['core_sources']}
    proof_names=ARTIFACTS+['browser-v1/'+name for name in TASK2_BROWSER_NAMES_CURRENT]+['browser-v1/PINS.json']
    proofs=finite['whole_forward_inverse_files'];assert [r['name'] for r in proofs]==proof_names and len(proofs)==26
    for proof in proofs:
        before=file_pin(Path(proof['before']['path']),proof['before']);after=file_pin(Path(proof['after']['path']),proof['after']);raw=before
        if proof['name'] in ARTIFACTS: assert Path(proof['after']['path'])==PROFILE_SOURCE/proof['name']
        else: assert Path(proof['after']['path'])==AUX_SOURCE/proof['name'][len('browser-v1/'):]
        for seam in proof['stages']:
            old,new=seam['before_utf8'].encode(),seam['after_utf8'].encode();offset=seam['stage_utf8_offset']
            assert {'bytes':len(raw),'sha256':sha(raw)}==seam['whole_before'] and raw[offset:offset+len(old)]==old
            raw=raw[:offset]+new+raw[offset+len(old):]
            assert {'bytes':len(raw),'sha256':sha(raw)}==seam['whole_after']
        assert raw==after
        for seam in reversed(proof['stages']):
            old,new=seam['before_utf8'].encode(),seam['after_utf8'].encode();offset=seam['stage_utf8_offset']
            assert raw[offset:offset+len(new)]==new;raw=raw[:offset]+old+raw[offset+len(new):]
        assert raw==before
        for chunk in proof['unchanged_chunks']:
            a=before[chunk['before_utf8_offset']:chunk['before_utf8_offset']+chunk['bytes']];b=after[chunk['after_utf8_offset']:chunk['after_utf8_offset']+chunk['bytes']]
            assert a==b and {'bytes':len(a),'sha256':sha(a)}=={k:chunk[k] for k in ['bytes','sha256']}
    assert json.loads(ordinary(PROFILE_SOURCE/'source-derivation.json').read_bytes())['current_nonself_artifacts']==[r for r in pub['selected17'] if r['path']!='source-derivation.json']
    joined={r['path']:r for r in pub['repository_Source_requirements']}
    for r in authority['core_sources']:
        assert r['path'] not in joined or joined[r['path']]==r
        joined[r['path']]=r
    assert len(joined)==69 and list(joined.values())==finite['SourceRows69'] and joined==SOURCE_ROWS
    tracked=current_tracked_source_trees(json.loads(file_pin(Path(setup['complete_source_snapshot']['path']),setup['complete_source_snapshot'])))
    for path,row in joined.items():assert tracked['main'][path]==tracked['validation'][path]==row
    for path,row in joined.items():file_pin(MAIN/path,row);file_pin(REPO/path,row)
    base_root=Path(pub['base_source']['root']);base=package(base_root,pub['base_source']['PINS'],59)
    original_final=json.loads(file_pin(base_root/'FINAL-CANDIDATES.json',pub['base_source']['FINAL']))
    assert original_final['task2_observer_tools']==finite['unchanged_tools3']
    for row in finite['unchanged_tools3']:file_pin(Path(row['source']),row)
    assert tree(TOOL_SOURCE)=={'files':dict(sorted((r['path'],{k:r[k] for k in ['bytes','sha256']}) for r in finite['unchanged_tools3'])),'directories':[]}
    if SOURCE_REVIEW_AUTHORITY is not None:file_pin(Path(SOURCE_REVIEW_AUTHORITY['path']),SOURCE_REVIEW_AUTHORITY)
    final={'files':[{'artifact':r['path'],**r} for r in pub['selected17']],
        'task2_observer_tools':finite['unchanged_tools3'],'publication':pub,'current_core_aux_authority':authority}
    return {'normal_source_pins':inputs['normal_root']['PINS'],'normal_publication':normal_state,'ui_source':ui_state,
        'base_source':base,'auxiliary':auxiliary,'current_core_aux_authority':inputs['current_core_aux_authority'],
        'review_authority':SOURCE_REVIEW_AUTHORITY},final


# The original functions remain exact historical Source. Active calls throughout
# the original materializer now dispatch to the strict current adapters.
known_normal_root=current_known_normal_root
consume_materialization=current_consume_materialization
verify_sources=current_verify_sources
