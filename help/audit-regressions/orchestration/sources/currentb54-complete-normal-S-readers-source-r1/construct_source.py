from pathlib import Path
import ast, difflib, hashlib, json, os, re, time

ORG = Path('H:/天俪商城/artifacts/project-organization-20261023')
OUT = ORG / 'currentb54-complete-normal-S-readers-source-r1'
BEFORE = ORG / 'currentbafb-complete-normal-S-readers-source-r1'
DONOR = ORG / 'currentb54-materialization-readers-source-r2'
CORE = ORG / 'currentb54-next-normal-preparation-r3/normal-source'
ADM = ORG / 'current41c0-normal-root-admission-r1'
ROOT = ORG.parents[1]
INPUTS = {}

def digest(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}

def n9(s):
    return [s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns]

def read(path):
    path = Path(path)
    if path.as_posix() in INPUTS:
        return INPUTS[path.as_posix()]['raw']
    a=path.lstat()
    with path.open('rb') as f:
        fa=os.fstat(f.fileno());raw=f.read();fb=os.fstat(f.fileno())
        assert f.read(1)==b''
    b=path.lstat()
    assert n9(a)==n9(b) and n9(fa)==n9(fb)
    assert [n9(a)[i] for i in (0,1,6,7)]==[n9(fa)[i] for i in (0,1,6,7)]
    assert a.st_birthtime_ns==fa.st_birthtime_ns==b.st_birthtime_ns
    assert len(raw)==a.st_size
    INPUTS[path.as_posix()]={'path':path.as_posix(),**digest(raw),'named9':n9(a),'fd9':n9(fa),'birthtime_ns':a.st_birthtime_ns,'EOF':True,'closed':True,'raw':raw}
    return raw

def desc(path):
    path=Path(path)
    return {'path':path.as_posix(),**digest(read(path))}

def data(path):
    return json.loads(read(path))

def put(path, raw):
    path=Path(path);path.parent.mkdir(parents=True,exist_ok=True)
    if not isinstance(raw,bytes):raw=raw.encode('utf-8')
    if path.exists():
        if path.read_bytes()==raw:return
        assert not (OUT/'PINS.json').exists()
        assert path.is_relative_to(OUT) and (path.parent.name=='candidate' or path.name.endswith('.diff'))
        path.write_bytes(raw)
    else:
        with path.open('xb') as f:f.write(raw)

def jsonput(path,value):
    put(path,json.dumps(value,ensure_ascii=False,indent=2)+'\n')

def defs(text):
    lines=text.splitlines(keepends=True)
    return {n.name:''.join(lines[n.lineno-1:n.end_lineno]) for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}

assert (OUT/'README.md').is_file()
assert not (OUT/'PINS.json').exists()
before_pins=data(BEFORE/'PINS.json')
assert digest(read(BEFORE/'PINS.json'))=={'bytes':6198,'sha256':'9c642f23008f1ef245b90074ca15fc47e8922a9dd6cfd99138b580e299512a90'}
read(ADM/'R100-SUCCESS-S-READERS-SOURCE-ADMISSION.json')
read(ORG/'currentbafb-complete-normal-S-readers-independent-source-r1/REPORT.json')
read(DONOR/'PINS.json')
donor_text=read(DONOR/'root-reader.py').decode('utf-8')
donor_peer_text=read(DONOR/'peer-review.py').decode('utf-8')
donor_defs=defs(donor_text);donor_peer_defs=defs(donor_peer_text)
new_functions=['validate_current_b54_source','validate_current_b54_metadata','validate_current_b54_intersections','validate_current_b54_profile','validate_current_bafb_finite','validate_b54_actual_observation','derive_complete_b54_expected']
for name in new_functions[:-1]:assert donor_defs[name]==donor_peer_defs[name]
new_defs='\n\n'.join(donor_defs[name].rstrip('\n') for name in new_functions)+'\n\n'
corepins=data(CORE/'PINS.json')
assert len(corepins['files'])==33 and corepins['source_sha']=='b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
root_report=ROOT/'artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r2/REPORT.json'
peer_report=ORG/'currentb54-complete-materialization-independent-readback-r2/REPORT.json'
peer_pins=ORG/'currentb54-complete-materialization-independent-readback-r2/PINS.json'
accept_path=ADM/'R108-B54-M-DUAL-READERS-ACTUAL-ROOT-ACCEPTANCE.json'
accepted=data(accept_path)
assert accepted['status']=='ROOT_COMPLETE_ACTUAL_BOTH_FULL_M_READERS0_PUBLIC_OUTPUTS_AND_ALL_INPUT_WINDOWS_ACCEPTED'
assert accepted['Root_report']==desc(root_report) and accepted['peer_report']==desc(peer_report)
assert desc(peer_pins) in accepted['own_public_outputs']
assert accepted['actual_M_PID']==16792 and accepted['M_Source_complete_readback_accepted'] is True
callback_path=ADM/'R106-B54-CALLBACK-ACTUAL-ROOT-READBACK.json'
callback=data(callback_path)
assert callback['status']=='ROOT_ACTUAL_COMPLETE_B54_R3_CALLBACKS0_PUBLIC_READBACK0_ADMIT_FULL_M_ONLY'
observation=desc(ADM/'R106-B54-R3-M-ACTUAL-PUBLIC-OBSERVATION.json')
profile9={name: {k:corepins['files']['validation/actual-profile/'+name][k] for k in ('bytes','sha256')} for name in ['README.md','compose.yml','ms4-host.py','ms4-initialize.php','seed-source-inverse.json','interface-v1.json','source-derivation.json','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']}
for name,pin in profile9.items():assert digest(read(CORE/'validation/actual-profile'/name))==pin
driver_pin=digest(read(CORE/'validation/run.py'))
root_source_pin=digest(read(CORE/'validation/PINS.json'))
pub_pin=corepins['files']['validation/PROFILE-PUBLICATION.json'];profile_pin=corepins['files']['validation/PROFILE-PINS.json']
assert desc(pub_pin['path'])==pub_pin and desc(profile_pin['path'])==profile_pin

old_core='H:/天俪商城/artifacts/project-organization-20261023/currentbafb-next-normal-preparation-r3/normal-source'
old_root_report='H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentbafb-complete-materialization-root-readback-r1/REPORT.json'
old_peer_dir='H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-materialization-independent-readback-r1'
results={};transforms={}

for name in ['root-reader.py','peer-reader.py']:
    original=read(BEFORE/'candidate'/name).decode('utf-8')
    expected={'root-reader.py':{'bytes':102076,'sha256':'1e35b5ba31eb2d1be9e0f4b431008a2a845da35da1a9800a94ee920d62feefd3'},'peer-reader.py':{'bytes':116766,'sha256':'2458f2fe26893ad943e63154381afaed32ff893c9b0f97845fd8f241c6ca3098'}}[name]
    assert digest(original.encode())==expected
    put(OUT/'before'/name,original)
    split=original.index('parser = argparse.ArgumentParser(')
    head=original[:split];body=original[split:];seams=[]

    def replace(old,new,expected_count=None):
        global body
        count=body.count(old)
        assert count and (expected_count is None or count==expected_count),(name,old[:100],count,expected_count)
        body=body.replace(old,new);seams.append({'old':old,'new':new,'count':count})

    def line(prefix,new):
        found=[s for s in body.splitlines(keepends=True) if s.startswith(prefix)]
        assert len(found)==1,(name,prefix,len(found))
        replace(found[0],new+'\n',1)

    replace(old_core,CORE.as_posix())
    replace(old_root_report,root_report.as_posix())
    replace(old_peer_dir,peer_report.parent.as_posix())
    replace('bafb6305062aeb6b6e945c21da572863062ca480','b54ad9c39ca95a1b8b15ed6724082b98b1370c53')
    replace('CURRENTBAFB','CURRENTB54')
    replace('current bafb r3 normal S receipts','current b54 r3 normal S receipts')
    replace("'20261024114233c6e65e'):","'20261024114233c6e65e', '20261024174817c66be6'):",1)
    for old,new in [(24,25),(1150,1160),(15750,15752),(31500,31504),(311,313),(32497,33091),(32491,33085),(11680434,11940693)]:
        pattern=r'(?<![A-Za-z0-9_])'+str(old)+r'(?![A-Za-z0-9_])'
        matches=list(re.finditer(pattern,body))
        if matches:
            prior=body;body=re.sub(pattern,str(new),body);seams.append({'numeric_main_only':old,'new':new,'count':len(matches)})
    line('SOURCE_PINS = ', 'SOURCE_PINS = '+repr(digest(read(CORE/'PINS.json'))))
    line('ROOT_SOURCE_PINS = ','ROOT_SOURCE_PINS = '+repr(root_source_pin))
    line('M_RESULT = ','M_RESULT = '+repr({k:accepted['actual_RESULT'][k] for k in ('bytes','sha256')}))
    line('FREEZE = ','FREEZE = '+repr({k:accepted['actual_freeze'][k] for k in ('bytes','sha256')}))
    line('M_ROOT_READBACK = ','M_ROOT_READBACK = '+repr(digest(read(root_report))))
    line('PROFILE_SOURCE_PINS9 = ','PROFILE_SOURCE_PINS9 = '+repr(profile9))
    line('publication = r.json(', 'publication = r.json(Path('+repr(pub_pin['path'])+'), '+repr(pub_pin)+')\nfull_expected = derive_complete_b54_expected(source_constants, publication, reader_bytes, '+('h.loads' if name.startswith('root') else 'loads')+', readback_check)')
    line('profile_pins = r.json(', 'profile_pins = r.json(Path('+repr(profile_pin['path'])+'), '+repr(profile_pin)+')')
    line('peer_M = r.json(', 'peer_M = r.json(Path('+repr(peer_report.as_posix())+'), '+repr(digest(read(peer_report)))+')')
    line('peer_M_PINS = r.json(', 'peer_M_PINS = r.json(Path('+repr(peer_pins.as_posix())+'), '+repr(digest(read(peer_pins)))+')')
    line("assert peer_M_PINS['files']['REPORT.json']", "assert peer_M_PINS['files']['REPORT.json'] == "+repr(digest(read(peer_report))))
    line('dual_M_qualification = r.json(', 'dual_M_qualification = r.json(Path('+repr(accept_path.as_posix())+'), '+repr(digest(read(accept_path)))+')')
    line('assert dual_M_qualification == ', 'assert dual_M_qualification == '+repr(accepted))
    line("assert dual_M_qualification['Root_REPORT']", "assert dual_M_qualification['Root_report'] == "+repr(desc(root_report))+" and dual_M_qualification['peer_report'] == "+repr(desc(peer_report))+" and "+repr(desc(peer_pins))+" in dual_M_qualification['own_public_outputs']")
    line('CALLBACK_ROOT_POINTER = ','CALLBACK_ROOT_POINTER = '+repr(desc(callback_path)))
    line('CALLBACK_ROOT_EXPECTED = ','CALLBACK_ROOT_EXPECTED = '+repr(callback))
    line('driver_pin = ','driver_pin = '+repr(driver_pin))
    decoder='h.loads' if name.startswith('root') else 'loads'
    checker='readback_check' if name.startswith('root') else 'need'
    replace('separate_author_maps = validate_reader_source_inheritance(source, source_pins, reader_bytes, '+decoder+', '+checker+')',
        "historical24_source = Path(source_pins['before_source']['path']).parent\nhistorical24_source_pins = r.json(Path(source_pins['before_source']['path']), source_pins['before_source'])\nseparate_author_maps = validate_reader_source_inheritance(historical24_source, historical24_source_pins, reader_bytes, "+decoder+", "+checker+")\ncurrent_author_maps = validate_current_b54_metadata(source, source_pins, reader_bytes, "+decoder+", "+checker+")",1)
    replace('historical23_values = dict(source_constants)',"historical24_values = dict(source_constants)\nhistorical24_values.update(source_constants['HISTORICAL_TWENTYFOUR_CONSTANTS'])\nhistorical24_publication = r.json(Path(source_constants['HISTORICAL_TWENTYFOUR_PUBLICATION']['path']), source_constants['HISTORICAL_TWENTYFOUR_PUBLICATION'])\nhistorical23_values = dict(historical24_values)",1)
    replace('assert validate_current_warm_profile(source_constants, publication, profile_bytes, '+decoder+', '+checker+')', 'assert validate_current_warm_profile(historical24_values, historical24_publication, profile_bytes, '+decoder+', '+checker+')',1)
    replace('assert validate_current_d0a5_finite(source_constants, publication, profile_bytes, '+decoder+', '+checker+')', 'assert validate_current_d0a5_finite(historical24_values, historical24_publication, profile_bytes, '+decoder+', '+checker+')\nassert validate_current_b54_source(source_constants, publication, historical24_publication, full_expected, profile_bytes, '+decoder+', '+checker+')\nassert validate_current_b54_profile(source_constants, publication, historical24_publication, full_expected, profile_bytes, '+decoder+', '+checker+')\nassert validate_current_bafb_finite(source_constants, publication, historical24_publication, profile_bytes, '+decoder+', '+checker+')\nassert validate_b54_actual_observation('+repr(observation)+', '+repr(accepted['actual_RESULT'])+', '+repr(accepted['actual_freeze'])+', 16792, '+repr(desc(CORE/'PINS.json'))+', reader_bytes, '+decoder+', '+checker+')',1)
    replace("len(source_pins['files']) == 29", "len(source_pins['files']) == 33",1)
    old='author_intersections = validate_separate_author_intersections(authority_snapshot, separate_author_maps, '+('readback_check' if name.startswith('root') else 'need')+')'
    replace(old,old+'\nauthor_intersections.update(validate_current_b54_intersections(authority_snapshot, current_author_maps, '+('readback_check' if name.startswith('root') else 'need')+'))',1)
    if 'M37_24stages_1150_native_qualified' in body:
        replace('M37_24stages_1150_native_qualified','M37_25stages_1160_native_qualified',1)
    if name.startswith('peer'):
        replace('worktrees/ms4-aftersale-normal-check','worktrees/ms4-language-cache-normal-check',1)
        replace('worktrees/ms4-asset-consumers','worktrees/ms4-language-cache-normal',1)
        replace('all1150_created_terminal_and_double_raw_streams_complete','all1160_created_terminal_and_double_raw_streams_complete',1)
        replace('PASS_INDEPENDENT_ACTUAL_BAFB_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY','PASS_INDEPENDENT_ACTUAL_B54_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY',1)
        line("r.read(Path('H:/天俪商城/artifacts/project-organization-20261023/currentbafb-complete-normal-S-readers-source-r1/candidate/root-reader.py')", 'r.read(Path('+repr((OUT/'candidate/root-reader.py').as_posix())+'), '+repr(results['root-reader.py']['candidate'])+')')
        replace("assert paths == sorted(set(paths)) and tree['directories'] == sorted(parents)","assert paths == sorted(set(paths)) and tree['directories'] == sorted(parents)\nassert {row['path']:row for row in tree['files']} == full_expected",1)
    else:
        replace("assert type(tree['file_count']) is int and tree['file_count'] == len(tree_paths) == m_readback['tracked_per_checkout'] == 15752", "assert type(tree['file_count']) is int and tree['file_count'] == len(tree_paths) == m_readback['tracked_per_checkout'] == 15752\nassert {row['path']:row for row in tree['files']} == full_expected",1)
    replace("'current_warm_profile9_and_d0_finite_complete': True,", "'current_warm_profile9_and_d0_finite_complete': True, 'historical24_warm_profile9_and_d0_finite_complete': True, 'current25_b54_profile9_bafb_finite420_and_Source33_input48_complete': True,",1)
    candidate=head+new_defs+body
    ast.parse(candidate)
    candidate_defs=defs(candidate);old_defs=defs(original)
    assert all(candidate_defs[k]==v for k,v in old_defs.items()),(name,'original function changed')
    assert all(candidate_defs[k]==donor_defs[k] for k in new_functions)
    assert set(candidate_defs)==set(old_defs)|set(new_functions)
    before_lines=original.splitlines(keepends=True);after_lines=candidate.splitlines(keepends=True)
    ops=[]
    for tag,a,b,c,d in difflib.SequenceMatcher(a=before_lines,b=after_lines,autojunk=False).get_opcodes():
        if tag!='equal':ops.append({'before_start':a,'before_end':b,'after_start':c,'after_end':d,'before':before_lines[a:b],'after':after_lines[c:d]})
    def apply(lines,reverse=False):
        rows=list(lines)
        for op in reversed(ops):
            a,b=(op['after_start'],op['after_end']) if reverse else (op['before_start'],op['before_end'])
            old,new=(op['after'],op['before']) if reverse else (op['before'],op['after'])
            assert rows[a:b]==old;rows[a:b]=new
        return ''.join(rows)
    assert apply(before_lines)==candidate and apply(after_lines,True)==original
    put(OUT/'candidate'/name,candidate)
    put(OUT/(name+'.FORWARD.diff'),''.join(difflib.unified_diff(before_lines,after_lines,fromfile='before/'+name,tofile='candidate/'+name)))
    put(OUT/(name+'.REVERSE.diff'),''.join(difflib.unified_diff(after_lines,before_lines,fromfile='candidate/'+name,tofile='before/'+name)))
    results[name]={'before':digest(original.encode()),'candidate':digest(candidate.encode()),'original_functions_and_classes_raw':list(old_defs),'new_functions_raw_from_actual0_M_reader':new_functions,'exact_full_module_forward_reverse':True,'diff_splices':len(ops),'recorded_seams':len(seams),'AST_parse_only':True}
    transforms[name]={'seams':seams,'line_splices':ops}

helper=read(BEFORE/'candidate/finite-helper.py')
assert digest(helper)=={'bytes':33935,'sha256':'a84cb0edd5beaa998a26f8b96819b765e8cfe83e52a62619846b431c417855c0'}
put(OUT/'before/finite-helper.py',helper);put(OUT/'candidate/finite-helper.py',helper)
jsonput(OUT/'TRANSFORMS.json',transforms)
jsonput(OUT/'SOURCE-REVIEW.json',{'status':'AUTHOR_SOURCE_ONLY_AST_AND_EXACT_TRANSFORMS_NOT_ROOT_OR_FRESH_REVIEW','programs':results,'helper_full_raw':digest(helper),'no_candidate_import_or_execution':True,'Run_private_ENV_original_stream_or_WVal_body_reads':0,'no_actual_success_readback_admission':True,'whole_retirement_count':11,'runtime':'NOT_RUN','created_ns':time.time_ns()})
for row in INPUTS.values():
    p=Path(row['path']);s=p.lstat();assert n9(s)==row['named9'] and s.st_birthtime_ns==row['birthtime_ns']
jsonput(OUT/'SOURCE-INPUTS.json',{'scope':'FINITE_SOURCE_AND_ROOT_QUALIFIED_PUBLIC_EVIDENCE_ONLY','files':[{k:v for k,v in row.items() if k!='raw'} for row in INPUTS.values()],'all_input_windows_ended':True})
readme=OUT/'README.md';readme_text=readme.read_text('utf-8')
readme_text=readme_text.replace('DESIGN_RECORDED_SOURCE_IMPLEMENTATION_NOT_YET_CONSTRUCTED','AUTHOR_COMPLETE_SOURCE_ONLY_AWAIT_ROOT_AND_FRESH_REVIEW')
readme_text=readme_text.replace('- [ ] 构造完整候选；','- [x] 构造完整候选；').replace('- [ ] 静态 AST/闭包/参数/断言保留与 namespace/pins；','- [x] 静态 AST/参数/原函数保留与完整正逆复原；')
readme_text+='\n作者静态构造说明：首次 peer 文本匹配误用了 Root 的 readback_check，已按原 peer need 签名修正；只是本地文本构造，候选/目标从未执行。原件和 sealed 源未覆写。Root/fresh 审查及实际读回均未完成；PINS 不授运行准入。\n'
readme.write_text(readme_text,'utf-8',newline='\n')
members=sorted(p for p in OUT.rglob('*') if p.is_file())
jsonput(OUT/'PINS.json',{'schema':1,'status':'SOURCE_ONLY_AWAIT_ROOT_AND_FRESH_REVIEW_NOT_RUNTIME_ADMISSION','files':{p.relative_to(OUT).as_posix():digest(p.read_bytes())for p in members},'member_count':len(members),'actual_S_readers':'NOT_RUN'})
print(json.dumps({'directory':OUT.as_posix(),'programs':results,'source_inputs':len(INPUTS),'members_including_PINS':len(members)+1},ensure_ascii=False))
