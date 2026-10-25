"""Author-only stdlib full-reader Source successor. Never imports/runs readers or authority."""
from pathlib import Path
import ast,builtins,copy,difflib,hashlib,json,os,re,stat,symtable
P=Path(__file__).resolve().parent;O=P.parent;A=O/'current41c0-normal-root-admission-r1'
OLD=O/'currentbafb-materialization-readers-source-r1';N=O/'currentb54-next-normal-preparation-r3/normal-source'
READS={};LOG={}
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(x):return (json.dumps(x,ensure_ascii=False,indent=2)+'\n').encode()
def read(path,expected=None):
 path=Path(path);a=path.lstat();ks=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');nine=lambda v:[getattr(v,k) for k in ks]
 assert stat.S_ISREG(a.st_mode) and not path.is_symlink() and not getattr(a,'st_file_attributes',0)&1024
 with path.open('rb') as f:b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
 z=path.lstat();assert nine(a)==nine(z) and nine(b)==nine(c) and a.st_birthtime_ns==z.st_birthtime_ns==b.st_birthtime_ns==c.st_birthtime_ns and all(nine(a)[i]==nine(b)[i] for i in (0,1,6,7))
 if expected is not None:assert pin(raw)=={k:expected[k] for k in ('bytes','sha256')}
 row={'path':path.as_posix(),**pin(raw),'named9':nine(z),'fd9':nine(c),'birthtime_ns':z.st_birthtime_ns,'EOF':True,'closed':True}
 assert path.as_posix() not in READS or READS[path.as_posix()]==row;READS[path.as_posix()]=row;return raw
def obj(path,expected=None):return json.loads(read(path,expected))
def ref(path):return {'path':Path(path).as_posix(),**pin(read(path))}
def save(name,data):
 q=P/name;q.parent.mkdir(parents=True,exist_ok=True)
 if q.exists():assert q.read_bytes()==data
 else:
  with q.open('xb') as f:assert f.write(data)==len(data)
def constants(raw):
 d={}
 for t in ast.parse(raw).body:
  if isinstance(t,ast.Assign) and len(t.targets)==1 and isinstance(t.targets[0],ast.Name):
   try:d[t.targets[0].id]=ast.literal_eval(t.value)
   except (ValueError,TypeError):pass
 return d
def defs(s):
 ls=s.splitlines(True);return {n.name:''.join(ls[n.lineno-1:n.end_lineno]) for n in ast.parse(s).body if isinstance(n,ast.FunctionDef)}
def once(s,was,new,label):
 assert s.count(was)==1,(label,s.count(was),was[:100]);LOG.setdefault(label,[]).append({'before':was,'candidate':new});return s.replace(was,new,1)
def assign(s,key,value,label):
 node=next(n for n in ast.parse(s).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id==key)
 return once(s,ast.get_source_segment(s,node),key+' = '+repr(value),label)
def patch(a,b,name):return ''.join(difflib.unified_diff(a.splitlines(True),b.splitlines(True),fromfile='a/'+name,tofile='b/'+name)).encode()
def replay(src,patchraw):
 ls=patchraw.decode().splitlines(True);i=2;original=src.splitlines(True);out=[];pos=0;hunks=0
 assert ls[0].startswith('--- a/') and ls[1].startswith('+++ b/')
 while i<len(ls):
  m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n',ls[i]);assert m;i+=1;hunks+=1
  no,nn=int(m[2] or 1),int(m[4] or 1);start=int(m[1])-(no!=0);assert pos<=start;out+=original[pos:start];pos=start;a=b=0
  while i<len(ls) and not ls[i].startswith('@@ '):
   line=ls[i];i+=1;assert line[:1] in (' ','-','+')
   if line[0] in ' -':assert original[pos]==line[1:];pos+=1;a+=1
   if line[0] in ' +':out.append(line[1:]);b+=1
  assert (a,b)==(no,nn)
 out+=original[pos:];return ''.join(out),hunks
def missing(s):
 table=symtable.symtable(s,'Source-only','exec');bound={x.get_name() for x in table.get_symbols() if x.is_assigned() or x.is_imported() or x.is_namespace()}|set(dir(builtins))|{'__file__'};bad=set()
 def visit(t):
  for x in t.get_symbols():
   if x.is_global() and x.is_referenced() and x.get_name() not in bound:bad.add(x.get_name())
  for child in t.get_children():visit(child)
 visit(table);return sorted(bad)

def main():
 assert not (P/'PINS.json').exists()
 obs_path=A/'R106-B54-R3-M-ACTUAL-PUBLIC-OBSERVATION.json';obs=obj(obs_path,{'bytes':2928,'sha256':'497e6066de86516dae075d6a1f5a9ff1f404dca6e5b4165cb98dd03145800cbd'})
 cbpath=A/'R106-B54-CALLBACK-ACTUAL-ROOT-READBACK.json';cbroot=obj(cbpath,{'bytes':4131,'sha256':'2426a231bff2d7655b8dede264be984629a1a28ef1e5edbf3f502bede4445d63'})
 cbreport=obj(cbroot['actual_REPORT']['path'],cbroot['actual_REPORT'])
 cbinputs=obj(cbroot['actual_INPUTS']['path'],cbroot['actual_INPUTS'])  # Explicit Root-qualified Source metadata; never follow row bodies.
 core_raw=read(N/'PINS.json',{'bytes':14595,'sha256':'36aae1201b16d0d268e64d9eab53e1f9c86e38cec156d0882d4b6bb7b06c3c1c'});core=json.loads(core_raw);core_ref={'path':(N/'PINS.json').as_posix(),**pin(core_raw)}
 assert obs['actual_M_PID']==16792 and obs['native_exit_wait']==0 and obs['Core_PINS']==core_ref and cbroot['normal_Source_PINS']==core_ref
 assert obs['actual_RESULT']['bytes']==265006271 and obs['actual_freeze']['bytes']==14519075
 assert cbroot['actual_source_paths']==len(cbinputs['files'])==33091 and cbroot['M_and_S_each_full_checks']==11940693 and cbroot['actual_PID']==44748
 assert len(core['files'])==33
 for name,row in core['files'].items():read(N/name,row)
 mvalues=constants(read(N/'materializer/prepare.py'));needed={k for k in mvalues if k.startswith(('CURRENT_FIX_','CURRENT_BAFB_','HISTORICAL_TWENTYFOUR_'))}|{'MAIN_SOURCE','VALIDATION_SOURCE','SOURCE_REQUIREMENT_PATHS','NEW_TRACKED_PATHS','BASELINE_AUTHORITY'}
 oldroot=read(OLD/'root-reader.py',{'bytes':94270,'sha256':'f1e4bcb5aa96cb9677e6488596926071deb733df8744067159409ad90eae5540'}).decode();oldpeer=read(OLD/'peer-review.py',{'bytes':109306,'sha256':'1dd8faf5aa337af0dfbc3f1f27431be5d6d75f8ceab77234ad6f52d1b4d2d7a3'}).decode()
 for name in ['root-reader.py','peer-review.py','CLI.json','PINS.json','SOURCE-PINS.json','metadata-inheritance-checks.py.fragment','current-warm-profile-checks.py.fragment','current-d0a5-finite-checks.py.fragment','SOURCE-INHERITANCE.json']:
  save('before/'+name,read(OLD/name))
 for name,path in [('CALLBACK-ROOT.json',cbpath),('CALLBACK-REPORT.json',Path(cbroot['actual_REPORT']['path'])),('M-OBSERVATION.json',obs_path),('RUNTIME-PINS.json',N/'PINS.json'),('ORIGINAL-READONLY-HELPER.py',OLD/'inputs/ORIGINAL-READONLY-HELPER.py')]:save('inputs/'+name,read(path))
 common_names=['current-b54-source-checks.py.fragment','current-source-metadata.py.fragment','current-b54-profile-checks.py.fragment','current-bafb-finite-checks.py.fragment','actual-observation-and-native-plan.py.fragment']
 common='\n\n'.join(read(P/'author-fragments'/name).decode().rstrip() for name in common_names)+'\n\n'
 derive=read(P/'author-fragments/derive-complete-expected.py.fragment').decode()
 r=oldroot;p=oldpeer
 for key,value in [('CALLBACK_EXPECTED_CHECKS',11940693),('CALLBACK_INPUT_COUNT',33091),('CALLBACK_NATIVE_PID',44748)]:r=assign(r,key,value,'root-constants');p=assign(p,key,value,'peer-constants')
 r=assign(r,'CALLBACK_ROOT_POINTER',ref(cbpath),'root-callback');r=assign(r,'CALLBACK_ROOT_EXPECTED',cbroot,'root-callback')
 for key,value in [('CALLBACK_REPORT',cbroot['actual_REPORT']),('CALLBACK_INPUTS',cbroot['actual_INPUTS']),('CALLBACK_ROOT',ref(cbpath)),('CALLBACK_ROOT_EXPECTED',cbroot),('CALLBACK_RETURN_PIN',cbroot['canonical_return_pin']),('PINS_EXPECTED',pin(core_raw))]:p=assign(p,key,value,'peer-callback')
 p=assign(p,'HEAD',core['source_sha'],'peer-identity');p=assign(p,'PARENT',core['parent'],'peer-identity')
 r=once(r,"SEALED_SOURCE_PINS = {'bytes': 13638, 'sha256': '7fd721b1c4b763ccdd7c798451fabe1d963e7908d3b67de04083362bf76b1279'}",'SEALED_SOURCE_PINS = '+repr(pin(core_raw)),'root-identity')
 r=once(r,"source = Path('"+(O/'currentbafb-next-normal-preparation-r3/normal-source').as_posix()+"')","source = Path('"+N.as_posix()+"')",'root-identity')
 p=once(p,"SRC=Path('"+(O/'currentbafb-next-normal-preparation-r3/normal-source').as_posix()+"')","SRC=Path('"+N.as_posix()+"')",'peer-identity')
 p=once(p,"OUT=Path('"+(O/'currentbafb-complete-materialization-independent-readback-r1').as_posix()+"')","OUT=Path('"+(O/'currentb54-complete-materialization-independent-readback-r1').as_posix()+"')",'peer-identity')
 r=once(r,"out = B / 'currentbafb-complete-materialization-root-readback-r1'","out = B / 'currentb54-complete-materialization-root-readback-r1'",'root-identity')
 r=once(r,"parser = argparse.ArgumentParser()",common+derive+"parser = argparse.ArgumentParser()",'root-new-validators')
 peer_common=common.replace(defs(common)['derive_b54_native_plan']+'\n','')
 p=once(p,'def main():',peer_common+'def main():','peer-new-validators')
 r=once(r,'separate_author_maps = validate_reader_source_inheritance(source, source_pins, reader_bytes, h.loads, readback_check)',"historical24_source = Path(source_pins['before_source']['path']).parent\nhistorical24_source_pins = r.json(Path(source_pins['before_source']['path']), source_pins['before_source'])\nseparate_author_maps = validate_reader_source_inheritance(historical24_source, historical24_source_pins, reader_bytes, h.loads, readback_check)\ncurrent_author_maps = validate_current_b54_metadata(source, source_pins, reader_bytes, h.loads, readback_check)",'root-metadata')
 p=once(p,'separate_author_maps=validate_reader_source_inheritance(SRC,source_pins,pinned,decode,need)',"historical24_source=Path(source_pins['before_source']['path']).parent;historical24_source_pins=decode(pinned(source_pins['before_source']))\n    separate_author_maps=validate_reader_source_inheritance(historical24_source,historical24_source_pins,pinned,decode,need)\n    current_author_maps=validate_current_b54_metadata(SRC,source_pins,pinned,decode,need)",'peer-metadata')
 r=once(r,"assert result['source_sha'] == source_pins['source_sha'] == 'bafb6305062aeb6b6e945c21da572863062ca480'","assert result['source_sha'] == source_pins['source_sha'] == '"+core['source_sha']+"'",'root-identity')
 r=once(r,"assert result['parent'] == source_pins['parent'] == 'd0a5d5398546e223122b5c2a2f115e1908f78ced'","assert result['parent'] == source_pins['parent'] == '"+core['parent']+"'",'root-identity')
 line=next(x for x in r.splitlines(True) if x.startswith('source_constants = {node.targets'))
 oldset=ast.literal_eval(line[line.index(' in {')+4:line.rindex('}}')+1]);newset=oldset|needed
 newline=line[:line.index(' in {')+4]+('{'+', '.join(repr(x) for x in sorted(newset))+'}')+line[line.rindex('}}')+1:]
 r=once(r,line,newline,'root-declared-constants')
 r=once(r,'historical23_values = dict(source_constants)',"historical24_values = dict(source_constants)\nhistorical24_values.update(source_constants['HISTORICAL_TWENTYFOUR_CONSTANTS'])\nhistorical24_publication = r.json(Path(source_constants['HISTORICAL_TWENTYFOUR_PUBLICATION']['path']), source_constants['HISTORICAL_TWENTYFOUR_PUBLICATION'])\nhistorical23_values = dict(historical24_values)",'root-history-frame')
 p=once(p,'historical23_values=dict(values);historical23_values.update(values[\'HISTORICAL_TWENTYTHREE_CONSTANTS\'])',"historical24_values=dict(values);historical24_values.update(values['HISTORICAL_TWENTYFOUR_CONSTANTS'])\n    historical24_publication=decode(pinned(values['HISTORICAL_TWENTYFOUR_PUBLICATION']))\n    historical23_values=dict(historical24_values);historical23_values.update(values['HISTORICAL_TWENTYTHREE_CONSTANTS'])",'peer-history-frame')
 # Only current main-region counters are changed; helper definitions/history assertions remain raw.
 rt=r.index('parser = argparse.ArgumentParser()');pt=p.index('def main():');rp,rm=r[:rt],r[rt:];pp,pm=p[:pt],p[pt:]
 for was,new in [('== 1150','== 1160'),('== 31500','== 31504'),('== 15750','== 15752'),('== 316','== 318'),('== 311','== 313'),('== 24','== 25')]:rm=rm.replace(was,new)
 for was,new in [('==1150','==1160'),('==31500','==31504'),('==15750','==15752'),('==316','==318'),('==311','==313'),('==264','==266'),('==24','==25'),('==32491','==33085'),("len(source_pins['files'])==29","len(source_pins['files'])==33")]:pm=pm.replace(was,new)
 rm=rm.replace("len(source_pins['files']) == 29","len(source_pins['files']) == 33")
 rm=rm.replace("source_constants['CURRENT_WARM_SOURCE_QUALIFICATION']","source_constants['CURRENT_FIX_SOURCE_QUALIFICATION']").replace("source_constants['CURRENT_WARM_SOURCE_HISTORY']","source_constants['CURRENT_FIX_SOURCE_HISTORY']")
 rm=rm.replace("'H:/天俪商城/worktrees/ms4-asset-consumers'","'H:/天俪商城/worktrees/ms4-language-cache-normal'").replace("'H:/天俪商城/worktrees/ms4-aftersale-normal-check'","'H:/天俪商城/worktrees/ms4-language-cache-normal-check'")
 rm=once(rm,"assert result['commit_chain'][-1]['files'] == current_source_authority['files'] and len(current_source_authority['files']) == 2","assert result['commit_chain'][-1]['files'] == current_source_authority['files'] and len(current_source_authority['files']) == 3",'root-stage25')
 rm=once(rm,"assert [row['before'] is None for row in result['commit_chain'][-1]['files']] == [False, True]","assert [row['before'] is None for row in result['commit_chain'][-1]['files']] == [True, False, True]",'root-stage25')
 oldhistory="assert result['commit_chain'][-2]['source_sha'] == result['parent'] and len(result['commit_chain'][-2]['files']) == 3\nassert all(row['before'] is not None for row in result['commit_chain'][-2]['files'])\nassert len(result['commit_chain'][-3]['files']) == 5 and len([row for row in result['commit_chain'][-3]['files'] if row['before'] is None]) == 1"
 newhistory="assert historical24_values['COMMIT_CHAIN'] == result['commit_chain'][:-1] and historical24_values['COMMIT_CHAIN'][-1]['source_sha'] == result['parent']\nassert len(historical24_values['COMMIT_CHAIN'][-2]['files']) == 3 and all(row['before'] is not None for row in historical24_values['COMMIT_CHAIN'][-2]['files'])\nassert len(historical24_values['COMMIT_CHAIN'][-3]['files']) == 5 and len([row for row in historical24_values['COMMIT_CHAIN'][-3]['files'] if row['before'] is None]) == 1"
 rm=once(rm,oldhistory,newhistory,'root-historical24-index-frame')
 rm=once(rm,"publication = r.json(source / 'validation/PROFILE-PUBLICATION.json', source_pins['files']['validation/PROFILE-PUBLICATION.json'])","publication = r.json(source / 'validation/PROFILE-PUBLICATION.json', source_pins['files']['validation/PROFILE-PUBLICATION.json'])\nfull_expected = derive_complete_b54_expected(source_constants, publication, reader_bytes, h.loads, readback_check)",'root-full-derived-map')
 rm=once(rm,'assert validate_current_warm_profile(source_constants, publication, profile_bytes, h.loads, readback_check)\nassert validate_current_d0a5_finite(source_constants, publication, profile_bytes, h.loads, readback_check)',"assert validate_current_warm_profile(historical24_values, historical24_publication, profile_bytes, h.loads, readback_check)\nassert validate_current_d0a5_finite(historical24_values, historical24_publication, profile_bytes, h.loads, readback_check)\nassert validate_current_b54_source(source_constants, publication, historical24_publication, full_expected, profile_bytes, h.loads, readback_check)\nassert validate_current_b54_profile(source_constants, publication, historical24_publication, full_expected, profile_bytes, h.loads, readback_check)\nassert validate_current_bafb_finite(source_constants, publication, historical24_publication, profile_bytes, h.loads, readback_check)",'root-current-and-historical-validators')
 pm=once(pm,"need(validate_current_warm_profile(values,pub,pinned,decode,need),'CURRENT24_FULL_WARM_PROFILE9')\n    need(validate_current_d0a5_finite(values,pub,pinned,decode,need),'CURRENT_D0A5_FULL_FINITE8_AND_EXACT420_OWN_LEAVES')","need(validate_current_warm_profile(historical24_values,historical24_publication,pinned,decode,need),'HISTORICAL24_FULL_WARM_PROFILE9')\n    need(validate_current_d0a5_finite(historical24_values,historical24_publication,pinned,decode,need),'HISTORICAL24_D0A5_FULL_FINITE8_AND_EXACT420_OWN_LEAVES')",'peer-history-validators')
 pm=once(pm,"    need(pin(read(ACTUAL/'RESULT.json'))=={'bytes':ARGS.result_bytes,'sha256':ARGS.result_sha256},'ACTUAL_RESULT_ROOT_SUPPLIED_COMPLETE_PIN')","    need(sum(row['bytes'] for row in expected.values())==341961431,'ALL15752_EXPECTED_COMPLETE341961431_SOURCE_BYTES')\n    need(validate_current_b54_source(values,pub,historical24_publication,expected,pinned,decode,need),'CURRENT25_COMPLETE_B54_SOURCE_EXPORT')\n    need(validate_current_b54_profile(values,pub,historical24_publication,expected,pinned,decode,need),'CURRENT25_FULL_B54_PROFILE9')\n    need(validate_current_bafb_finite(values,pub,historical24_publication,pinned,decode,need),'CURRENT_BAFB_FULL_FINITE8_AND_EXACT420_OWN_LEAVES')\n    need(pin(read(ACTUAL/'RESULT.json'))=={'bytes':ARGS.result_bytes,'sha256':ARGS.result_sha256},'ACTUAL_RESULT_ROOT_SUPPLIED_COMPLETE_PIN')",'peer-current-validators')
 rm=once(rm,'    assert actual_names == names','    assert actual_names == names\n    for relative in names:\n        assert {key:snapshot[prefix+relative][key] for key in (\'bytes\',\'sha256\')} == {key:full_expected[relative][key] for key in (\'bytes\',\'sha256\')}', 'root-all-current-tree-pins')
 rm=once(rm,"assert tree['manifest_sha256'] == hashlib.sha256(source_tree_raw).hexdigest()","assert tree['manifest_sha256'] == hashlib.sha256(source_tree_raw).hexdigest()\nassert source_tree_rows == list(full_expected.values()) and sum(row['bytes'] for row in source_tree_rows) == 341961431",'root-full-SourceTree-derived-pins')
 rm=once(rm,'for operation in ops:',"launcher_values = {node.targets[0].id:ast.literal_eval(node.value) for node in ast.parse(r.read(source/'launcher/launch.py',source_pins['files']['launcher/launch.py'])).body if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name) and node.targets[0].id=='PY'}\noriginal_plan = derive_b54_native_plan(source_constants, launcher_values, source/'validation/actual-profile', actual, result['proof_php_ini']['source_root'])\nassert len(original_plan) == len(ops) == 1160\nfor operation_index,operation in enumerate(ops):\n    assert set(operation) == {'label','argv','pid','native_exit','wait_returncode','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256','stem'}\n    assert (operation['label'],operation['argv']) == original_plan[operation_index]",'root-complete-native-argv-plan')
 observation_ref=ref(obs_path)
 rm=once(rm,"assert result['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'","assert result['status'] == 'ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY'\nassert validate_b54_actual_observation("+repr(observation_ref)+", {'path':(actual/'RESULT.json').as_posix(),'bytes':args.result_bytes,'sha256':args.result_sha256}, {'path':(actual/'freeze-binding.json').as_posix(),'bytes':args.freeze_bytes,'sha256':args.freeze_sha256}, args.outer_pid, {'path':(source/'PINS.json').as_posix(),**SEALED_SOURCE_PINS}, reader_bytes, h.loads, readback_check)",'root-M-observation')
 pm=once(pm,"    source_pins=js(SRC/'PINS.json');need(pin(read(SRC/'PINS.json'))==PINS_EXPECTED,'FROZEN_CURRENT41C0_R3_SOURCE_EXACT_IDENTITY')","    source_pins=js(SRC/'PINS.json');need(pin(read(SRC/'PINS.json'))==PINS_EXPECTED,'FROZEN_CURRENT_B54_R3_SOURCE_EXACT_IDENTITY')\n    need(validate_b54_actual_observation("+repr(observation_ref)+", {'path':(ACTUAL/'RESULT.json').as_posix(),'bytes':ARGS.result_bytes,'sha256':ARGS.result_sha256}, {'path':(ACTUAL/'freeze-binding.json').as_posix(),'bytes':ARGS.freeze_bytes,'sha256':ARGS.freeze_sha256}, ARGS.outer_pid, {'path':(SRC/'PINS.json').as_posix(),**PINS_EXPECTED}, pinned, decode, need),'ROOT_TRUE_M_OBSERVATION_ONLY_NOT_ACCEPTANCE')",'peer-M-observation')
 rm=once(rm,'author_intersections = validate_separate_author_intersections(authority_snapshot, separate_author_maps, readback_check)','author_intersections = validate_separate_author_intersections(authority_snapshot, separate_author_maps, readback_check)\nauthor_intersections.update(validate_current_b54_intersections(authority_snapshot, current_author_maps, readback_check))','root-all-intersections')
 pm=once(pm,'author_intersections=validate_separate_author_intersections(authority,separate_author_maps,need)','author_intersections=validate_separate_author_intersections(authority,separate_author_maps,need)\n    author_intersections.update(validate_current_b54_intersections(authority,current_author_maps,need))','peer-all-intersections')
 # Future output fields describe the current complete work, while keeping every historical assertion above.
 for was,new in [('CURRENTBAFB','CURRENTB54'),("'complete_source_snapshot_rows': 31500","'complete_source_snapshot_rows': 31504"),("'tracked_per_checkout': 15750","'tracked_per_checkout': 15752"),("'cumulative': 311","'cumulative': 313"),('both_checkouts_all316_required','both_checkouts_all318_required'),('current_stage24_one_modified_one_added','current_stage25_two_added_one_modified'),('new31500_snapshot','new31504_snapshot')]:rm=rm.replace(was,new)
 rm=rm.replace("'current_warm_profile9_and_d0_finite_complete': True,","'historical24_warm_profile9_and_d0_finite_complete': True, 'current25_b54_profile9_and_bafb_finite420_complete': True, 'current33_Source_and48_input_provenance_separate': True, 'complete_tracked_Source_bytes':341961431,")
 for was,new in [('CURRENTBAFB','CURRENTB54'),('all1150_created','all1160_created'),("'complete_tracked_rows_per_tree':15750","'complete_tracked_rows_per_tree':15752"),("'complete_dual_source_metadata_rows':31500","'complete_dual_source_metadata_rows':31504"),('all24_actual_commit','all25_actual_commit'),('cumulative311_required316_baseline_additions264','cumulative313_required318_baseline_additions266'),('complete29_current_and355_21_fixed_history','complete33_current_and29_355_21_fixed_history')]:pm=pm.replace(was,new)
 pm=pm.replace("'current_warm_profile9_and_d0_finite_complete':True,","'historical24_warm_profile9_and_d0_finite_complete':True,'current25_b54_profile9_and_bafb_finite420_complete':True,'current33_Source_and48_input_provenance_separate':True,'complete_tracked_Source_bytes':341961431,")
 # Source-time literals describing old sample counts in error labels are not assertion substitutions.
 r=rp+rm;p=pp+pm
 beforedefs={'root-reader.py':defs(oldroot),'peer-review.py':defs(oldpeer)}
 programs={'root-reader.py':r,'peer-review.py':p};proof={}
 for name,s in programs.items():
  compile(s,name,'exec');bad=missing(s);assert not bad,(name,bad)
  nd=defs(s);unchanged=[n for n in beforedefs[name] if n!='main']
  assert all(nd[n]==beforedefs[name][n] for n in unchanged),(name,[n for n in unchanged if nd[n]!=beforedefs[name][n]])
  old=oldroot if name=='root-reader.py' else oldpeer;f=patch(old,s,name);v=patch(s,old,name);assert replay(old,f)[0]==s and replay(s,v)[0]==old
  save(name,s.encode());save(name+'.FORWARD.diff',f);save(name+'.REVERSE.diff',v)
  proof[name]={'before':pin(old.encode()),'candidate':pin(s.encode()),'original_nonmain_functions_whole_raw':unchanged,'added_functions':[n for n in nd if n not in beforedefs[name]],'forward_hunks':replay(old,f)[1],'reverse_hunks':replay(s,v)[1],'whole_module_forward_and_inverse_exact':True,'AST_compile_only':True,'missing_globals':bad}
 # Exact callback-source maps only; no row body is followed. Counts are measured, never copied from old actual.
 oldcore=obj(core['before_source']['path'],core['before_source']);oldinherit=obj(oldcore['files']['SOURCE-INHERITANCE.json']['path'],oldcore['files']['SOURCE-INHERITANCE.json'])
 oldouter=obj(oldinherit['full_r1_FINAL_PINS']['path'],oldinherit['full_r1_FINAL_PINS'])
 hm=obj(oldouter['files']['normal-source/predecessor-source/AUTHOR-INPUTS.json']['path'],oldouter['files']['normal-source/predecessor-source/AUTHOR-INPUTS.json'])['files'];wm=obj(oldouter['files']['normal-source/AUTHOR-INPUTS.json']['path'],oldouter['files']['normal-source/AUTHOR-INPUTS.json'])['files']
 historicwindows=obj(oldcore['files']['SOURCE-WINDOWS.json']['path'],oldcore['files']['SOURCE-WINDOWS.json'])['files'];cm={x['path']:x for x in historicwindows}
 curinputs=obj(N/'SOURCE-WINDOWS.json')['files'];current={name:{'path':name,**{k:row[k] for k in ('bytes','sha256')},'native9':row['named9']} for name,row in curinputs.items()}
 currentcore={row['path']:{'path':row['path'],**{k:row[k] for k in ('bytes','sha256')},'native9':READS[row['path']]['named9']} for row in core['files'].values()}
 bootstrap={(N/'PINS.json').as_posix()}|{(N/x).as_posix() for x in cbreport['entries']};assert len(bootstrap)==6
 authority={k:v for k,v in cbinputs['files'].items() if k not in bootstrap};assert len(authority)==33085
 maps={'historical693':hm,'warm390':wm,'current28':cm,'b54_input48':current,'b54_currentCore33':currentcore};intersections={}
 for label,mapping in maps.items():
  overlap=set(mapping)&set(authority)
  for key in overlap:assert {k:mapping[key][k] for k in ('bytes','sha256','native9')}=={k:authority[key][k] for k in ('bytes','sha256','native9')},(label,key)
  intersections[label]=len(overlap)
 for key in maps:assert len(maps[key])=={'historical693':693,'warm390':390,'current28':28,'b54_input48':48,'b54_currentCore33':33}[key]
 save('STATIC-DEPENDENCIES.json',enc({'programs':proof,'Source_metadata_rows':{k:len(v) for k,v in maps.items()},'actual_source_graph33091_authority33085_intersections':intersections,'all_intersecting_pins_and_true_native9_joined':True,'all_maps_body_follow':False,'candidate_or_helper_executed':False}))
 save('TRANSFORM.json',enc({'schema':1,'exact_unique_text_seams':LOG,'ordinary_patches_and_whole_module_inverse':proof}))
 save('SOURCE-PROOF.json',enc({'schema':1,'status':'AUTHOR_SOURCE_STATIC_ONLY_COMPLETE_TWO_READERS_NOT_RUN','modules':proof,'profile_raw7999_and_bafb_raw21143_preserved_with_explicit_function_aliases':True,'metadata_historic29_28_693_390_vs_current33_48_separate':True,'Source_child_authors_not_fresh_review':True,'M_RESULT_freeze_raw_stdio_or_WValbody_read':False,'actual_M_pins':obs,'callback_Source_graph_only_intersections':intersections}))
 save('CALLBACK-BINDING.json',enc({'callback_Root':ref(cbpath),'callback_Root_exact_value':cbroot,'callback_REPORT':cbroot['actual_REPORT'],'callback_INPUTS':cbroot['actual_INPUTS'],'checks_each':11940693,'input_rows':33091,'bootstrap':6,'authority_rows':33085,'callback_PID':44748,'actual_M_observation':ref(obs_path),'actual_RESULT':obs['actual_RESULT'],'actual_freeze':obs['actual_freeze'],'actual_M_PID':16792,'external_launcher_PID_not_outer_pid_CLI':40920,'organized_wrapper_PID_not_outer_pid_CLI':33232}))
 argvbase=['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-I','-B','-X','utf8'];cli=[]
 for name in ['root-reader.py','peer-review.py']:
  argv=argvbase+[(P/name).as_posix(),'--result-bytes',str(obs['actual_RESULT']['bytes']),'--result-sha256',obs['actual_RESULT']['sha256'],'--freeze-bytes',str(obs['actual_freeze']['bytes']),'--freeze-sha256',obs['actual_freeze']['sha256'],'--outer-pid','16792']
  if name=='root-reader.py':argv+=['--source-pins-bytes','14595','--source-pins-sha256',pin(core_raw)['sha256']]
  cli.append({'program':name,'argv':argv})
 save('CLI.json',enc({'schema':1,'scope':'ROOT_ONLY_AFTER_ROOT_AND_NONAUTHOR_COMPLETE_SOURCE_ADMISSION','programs':cli,'Root_output':'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r1','peer_output':(O/'currentb54-complete-materialization-independent-readback-r1').as_posix(),'actual_reader_PIDs_results':'UNBOUND_NOT_RUN','S_or_business_admission':False}))
 save('INTERFACE.md',('Both complete original M readers retain every historical24/23/22/21 helper and whole11. Current25 uses distinct b54 stage/profile/bafb finite and current33/input48 metadata schemas. Full1160 native plans/created2/terminal10/full dual streams, Root14/M3,15752/31504/full SourceTree/parents/bytes341961431 and callback33091/authority33085/all provenance intersections are checked without target authority execution or worktree-body rereads. --outer-pid16792 is the actual M child, not launcher40920 or organized33232. SourceTree is copied/frozen only, not installed. New complete reader outputs/PIDs/native/wait and S/business remain UNBOUND.\n').encode())
 for path in list(READS):read(path)
 save('INPUT-WINDOWS.json',enc({'schema':1,'files':READS,'all_first_final_complete_EOF_named9_FD9_cross4_birth_closed':True,'candidate_or_authority_executed':False,'no_M_RESULT_freeze_Run_private_WValbody_or_rawM_S_read':True}))
 save('REPORT.json',enc({'schema':1,'status':'AUTHOR_COMPLETE_B54_DUAL_M_READER_SOURCE_ONLY_PENDING_NONAUTHOR_REVIEW','programs':proof,'before_original_bafb_readers_full':True,'runtime_Core':core_ref,'true_M_PID':16792,'M_observation':ref(obs_path),'M_RESULT_and_freeze_body_read':False,'M37_stages25_native1160_complete_original_scope':True,'all25_Git_and_tree15752_31504_required318_cumulative313_additions266_bytes341961431':True,'Root14_M3_whole_source_and_SourceTree_copy_only_semantics':True,'all11whole_and_historical24_warm_d0_and23_22_21_frames':True,'current25_profile9_and_bafb_compact16_420_138controls_distinct':True,'callback_Source_metadata_only_graph33091_authority33085_all_intersections':intersections,'source_file_inputs':len(READS),'candidate_or_helper_or_target_executed':False,'original_Run_private_ENV_actor_M_Sraw_or_WValbody_read':False,'Root_and_fresh_Source_review':'PENDING','actual_readers_results':'UNBOUND_NOT_RUN','S_business_admitted':False}))
 save('DESIGN.md',('Complete original dual M37 reader Source successor; see DESIGN.json for requirements. All historical helpers remain raw in their true historical24/23/22/21 frames. Current b54 stage25/profile/finite and33/48 metadata adapters are distinct. Actual complete readers and S/business remain NOT_RUN.\n').encode())
 # Peer verifies the whole immutable reader Source contract. No circular self/outer PINS claims.
 source_files={q.relative_to(P).as_posix():pin(q.read_bytes()) for q in sorted(P.rglob('*')) if q.is_file() and q.relative_to(P).as_posix() not in ('PINS.json','SOURCE-PINS.json')}
 save('SOURCE-PINS.json',enc({'schema':1,'scope':'COMPLETE_FIXED_B54_DUAL_M_READER_SOURCE_ONLY_NOT_ACTUAL_RESULT','files':source_files}))
 allfiles={q.relative_to(P).as_posix():pin(q.read_bytes()) for q in sorted(P.rglob('*')) if q.is_file()}
 save('PINS.json',enc({'schema':1,'scope':'B54_COMPLETE_DUAL_M_READER_SOURCE_ONLY','files':allfiles,'member_count':len(allfiles),'namespace_files_including_self':len(allfiles)+1,'actual_readers':'UNBOUND_NOT_RUN'}))
 print(json.dumps({'Root':pin(r.encode()),'peer':pin(p.encode()),'PINS':pin((P/'PINS.json').read_bytes()),'SOURCE_PINS':pin((P/'SOURCE-PINS.json').read_bytes()),'REPORT':pin((P/'REPORT.json').read_bytes()),'intersections':intersections,'actual_targets':'NOT_EXECUTED'},ensure_ascii=False))

if __name__=='__main__':main()
