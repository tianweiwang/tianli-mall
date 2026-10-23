"""Create a source-only successor. No Host, PHP, Docker, Git mutation or business execution."""
import ast,copy,json,time,hashlib,re
from pathlib import Path
import source_reader as r

OUT=r.OUT;OLD=r.OLD;B=r.B;HEAD=r.HEAD;PARENT=r.PARENT
PROFILE=B/'full-a-paid-fulfillment-contract-normal-next-source-r1'
PUBLISHED=B/'published-current-handoff-source-authority-r2'
ADOPT=B/'paid-fulfillment-current-contract-main-adoption-r1'
REPAIR=B/'paid-fulfillment-current-source-contract-repair-source-r2'
RETIRE=B/'full-a-paid-producer-failed-retirement-authority-r1'
INIT_SYNTAX=B/'normal-next-paid-fulfillment-initializer-php-syntax-actual-r1'

def funcs(source):
 return {n.name:ast.get_source_segment(source,n) for n in ast.parse(source).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}

def syntax_receipts(folder,result):
 return [{kind:r.pointer(folder/(op['stem']+'.'+ext),True) for kind,ext in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]} for op in result['operations']]

def main():
 assert not (OUT/'PINS.json').exists()
 for name in ['source_reader.py','build_helper.py','assemble.py','DESIGN.md','three-current-commit-branches.py.fragment',
  'current-caller-composite-branch.py.fragment','paid-producer-retirement-branch.py.fragment']:r.read(OUT/name)
 oldpins=r.obj(OLD/'PINS.json');members=oldpins['source_members18'];assert len(members)==18
 before={name:r.fixed(OLD/name,oldpins['files'][name]) for name in members}
 for name,raw in before.items():r.write('before/'+name,raw)
 publication=json.loads(before['validation/PROFILE-PUBLICATION.json']);context=publication['finite_contexts']
 values=r.constants(before['materializer/prepare.py'].decode());oldchain=copy.deepcopy(values['COMMIT_CHAIN']);assert len(oldchain)==11
 published=r.obj(PUBLISHED/'SOURCE-AUTHORITY.json');published_pointer=r.pointer(PUBLISHED/'SOURCE-AUTHORITY.json')
 assert r.compact(published_pointer)=={'bytes':335345,'sha256':'a85f0bef7f62f275962e27d6c638a2ef39db56ca4b683201dd7e2bfb8c29511c'}
 assert published['main_head']==HEAD and len(published['steps'])==2 and published['native_count']==145
 pp=r.obj(PROFILE/'profile-source/PINS.json');candidate9={name:r.fixed(PROFILE/'profile-source/candidate'/name,row) for name,row in pp['candidate'].items()}
 assert len(candidate9)==9
 for name,raw in candidate9.items():r.write('validation/actual-profile/'+name,raw)
 profile_pointer=r.pointer(PROFILE/'profile-source/PINS.json');iface=json.loads(candidate9['interface-v1.json'])['customer_form_current_profile']
 actual=r.obj(ADOPT/'COMMIT.json');adoption=r.obj(ADOPT/'SOURCE-MANIFEST.json');repair=r.obj(REPAIR/'PINS.json')
 assert actual['commit_sha']=='76efa7807c6137bc430e314e2faa78691cf60431' and actual['parent_sha']==adoption['parent_sha']==oldchain[-1]['source_sha']
 files=sorted([{'path':name,'before':row['before'],'after':row['candidate']} for name,row in adoption['files'].items()],key=lambda row:row['path'])
 snapshots={};code_sources=[]
 for row in files:
  name=row['path'];old=r.read(ADOPT/'before'/name);assert r.pin(old)==row['before']
  if name.startswith('help/'):
   new=r.read(REPAIR/'candidate'/name);code_sources.append({'repository_path':name,'authority':r.pointer(REPAIR/'PINS.json'),'candidate':r.pointer(REPAIR/'candidate'/name)})
  elif name=='docs/multi-merchant/README.md':new=r.fixed(published['steps'][0]['snapshots'][name]['before']['path'],published['steps'][0]['snapshots'][name]['before'])
  else:new=r.read(Path(values['MAIN_SOURCE'])/name)
  assert r.pin(new)==row['after'];r.write('twelfth-snapshots/before/'+name,old);r.write('twelfth-snapshots/after/'+name,new)
  snapshots[name]={'before':r.pointer(OUT/'twelfth-snapshots/before'/name),'after':r.pointer(OUT/'twelfth-snapshots/after'/name)}
 syntaxfolder=B/'paid-fulfillment-current-contract-php-syntax-actual-r1';syntax=r.obj(syntaxfolder/'RESULT.json')
 twelfth={'schema':1,'source_sha':actual['commit_sha'],'parent':actual['parent_sha'],'commit_record':r.pointer(ADOPT/'COMMIT.json'),
  'source_manifest':r.pointer(ADOPT/'SOURCE-MANIFEST.json'),'files':files,'snapshots':snapshots,'code_sources':code_sources,
  'independent_source_review':r.pointer(B/'paid-fulfillment-current-source-contract-repair-root-review-r2/REPORT.json'),
  'actual_php_validation':r.pointer(syntaxfolder/'RESULT.json'),'syntax_receipts':syntax_receipts(syntaxfolder,syntax),
  'historical_native_schema':'ORIGINAL18_ARGV_NATIVE_EXIT_AND_STREAM_DESCRIPTORS_WITHOUT_PID_OR_WAIT','full_business':'NOT RUN'}
 r.put('CURRENT-TWELFTH-COMMIT-AUTHORITY.json',twelfth);twelfth_pointer=r.pointer(OUT/'CURRENT-TWELFTH-COMMIT-AUTHORITY.json')
 chain=oldchain+[{'kind':'paid_fulfillment_contract_l2','source_sha':twelfth['source_sha'],'parent':twelfth['parent'],'commit':twelfth_pointer,'files':files}]
 for held in published['steps']:
  chain.append({key:held[key] for key in ['kind','source_sha','parent','files']}|{'commit':published_pointer})
 assert len(chain)==14 and chain[-1]['source_sha']==HEAD and chain[-1]['parent']==PARENT
 inventory=[json.loads(line) for line in r.fixed(context['baseline']['inventory']['path'],context['baseline']['inventory']).splitlines()]
 original={row['path']:{k:row[k] for k in ['path','bytes','sha256']} for row in inventory};expected=dict(original);first={}
 for step in chain:
  for row in step['files']:
   name=row['path'];held=expected.get(name);assert row['before']==(None if held is None else r.compact(held)),name
   first.setdefault(name,None if name not in original else r.compact(original[name]));expected[name]={'path':name,**row['after']}
 expected=dict(sorted(expected.items()));changes=[{'path':name,'before':first[name],'after':r.compact(expected[name])} for name in sorted(first)]
 committed=[expected[row['path']] for row in changes];newtracked=sorted(set(expected)-set(original))
 requirements=sorted(set(values['SOURCE_REQUIREMENT_PATHS'])|{row['path'] for step in chain[11:] for row in step['files']})
 assert (len(expected),len(changes),len(requirements),len(newtracked))==(15629,188,194,143)
 previous=json.loads(r.fixed(context['baseline']['previous_full_freeze']['path'],context['baseline']['previous_full_freeze']))
 groups={};combined={}
 for prefix in ['driver','customer','paid','fulfillment','aftersale_review','worker']:
  if prefix=='driver':names=[row['path'] for row in previous[prefix+'_source_files']]
  else:
   roots=previous[prefix+'_source_roots'];extras=sorted(set(previous[prefix+'_source_extra'])|({values['ASSET_CLI']} if prefix in ['fulfillment','aftersale_review','worker'] else set())|{'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'})
   names=sorted({name for name in expected if any(name.startswith(root+'/') for root in roots)}|set(extras))
  groups[prefix]=[expected[name] for name in names]
  for row in groups[prefix]:assert row['path'] not in combined or combined[row['path']]==row;combined[row['path']]=row
 counts={prefix:len(rows) for prefix,rows in groups.items()};assert list(counts.values())==[10,10865,10866,10881,10885,10886] and len(combined)==10886
 native_count=6+sum(2+2*len(step['files']) for step in chain)+1+len(committed)+2+3+1+4;assert native_count==677
 selected=[{'path':row['path'],**r.pin(candidate9[row['path']])} for row in publication['selected9']]
 oldcomposite=copy.deepcopy(context['current_composite_customer_form_source']);oldselected=copy.deepcopy(publication['selected9'])
 composite={'scope':'PUBLISHED_CURRENT_SOURCE_WITH_ACTUAL_TWELFTH_TWO_CALLER_DELTA_ONLY','source_sha':HEAD,
  'profile_PINS':profile_pointer,'profile_interface':r.pointer(OUT/'validation/actual-profile/interface-v1.json'),
  'base_authorities':iface['frozen_php_authorities'],'profile_composite':iface['current_composite_php_source_authority'],'php_sources':iface['php_sources'],'new_runtime':'NOT RUN'}
 retired_pointer=r.pointer(RETIRE/'AUTHORITY.json');assert r.compact(retired_pointer)=={'bytes':483703,'sha256':'aa6e3d41eaad35640f9607c74556478e1766c57602f179f3912a40a04851a7fb'}
 initializer=r.obj(INIT_SYNTAX/'RESULT.json');init_auth={'schema':1,'result':r.pointer(INIT_SYNTAX/'RESULT.json'),'receipts':syntax_receipts(INIT_SYNTAX,initializer),'scope':'ACTUAL_INITIALIZER_SYNTAX_ONLY_NO_APP'}
 r.put('CURRENT-INITIALIZER-SYNTAX-AUTHORITY.json',init_auth);init_pointer=r.pointer(OUT/'CURRENT-INITIALIZER-SYNTAX-AUTHORITY.json')
 publication.update(source_sha=HEAD,parent=PARENT,selected9=selected,repository_Source_requirements=[expected[name] for name in requirements])
 context.update(commit_chain=chain,committed_changes=changes,committed_files=committed,source_counts=counts,new_tracked_paths=newtracked,
  actual_complete_expected_tracked_count=len(expected),current_paid_fulfillment_contract_step=chain[11],published_commit_history=published_pointer,
  historical_eleventh_composite_customer_form_source=oldcomposite,historical_eleventh_selected9=oldselected,
  current_composite_customer_form_source=composite,current_profile_frozen_source=profile_pointer,current_paid_producer_failure_retirement=retired_pointer,
  current_initializer_syntax_authority=init_pointer,current_published_source_scope='Actual caller repair76e plus real documentation/source-archive commits dc72 and584. No new business run. All original histories retained.')
 values.update(COMMIT_CHAIN=chain,COMMITTED_PATHS=[row['path'] for row in changes],SOURCE_REQUIREMENT_PATHS=requirements,NEW_TRACKED_PATHS=newtracked,
  CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE=composite,HISTORICAL_ELEVENTH_COMPOSITE_CUSTOMER_FORM_SOURCE=oldcomposite,
  CURRENT_PUBLISHED_COMMIT_HISTORY=published_pointer,CURRENT_PAID_PRODUCER_FAILURE_RETIREMENT=retired_pointer,CURRENT_INITIALIZER_SYNTAX_AUTHORITY=init_pointer)
 oldm=before['materializer/prepare.py'].decode();olds=before['validation/run.py'].decode();fn=r.function(oldm,'source_authority');assert fn==r.function(olds,'source_authority')
 fn=r.once(fn,"check(chain == COMMIT_CHAIN and len(chain) == 11, 'EXACT_ELEVEN_WITH_ORIGINAL_TEN_AND_ACTUAL_RAW_ENV_STEP')",
  "check(chain == COMMIT_CHAIN and len(chain) == 14, 'EXACT_FOURTEEN_WITH_ORIGINAL_ELEVEN_AND_ACTUAL_CALLER_AND_PUBLISHED_STEPS')")
 fn=r.once(fn,'    expected = dict(sorted(expected.items()))',r.read(OUT/'three-current-commit-branches.py.fragment').decode()+'    expected = dict(sorted(expected.items()))')
 start=fn.index('    # The original seven-member authority is historical; exactly one shared lib now changes.')
 stop=fn.index("    previous = decode(read(context['baseline']['previous_full_freeze']))",start)
 oldblock=fn[start:stop]
 held=oldblock.replace("context['current_composite_customer_form_source']","context['historical_eleventh_composite_customer_form_source']").replace('CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE','HISTORICAL_ELEVENTH_COMPOSITE_CUSTOMER_FORM_SOURCE').replace("composite['source_sha'] == pub['source_sha']","composite['source_sha'] == chain[10]['source_sha']").replace("pub['selected9']","context['historical_eleventh_selected9']").replace('expected[','historical_eleventh_expected[')
 fn=r.once(fn,oldblock,held+r.read(OUT/'current-caller-composite-branch.py.fragment').decode())
 fn=r.once(fn,'    protected = {}',r.read(OUT/'paid-producer-retirement-branch.py.fragment').decode()+'    protected = {}')
 fn=r.once(fn,"'customer_prepare_failure_retirement': customer_prepare_retirement}","'customer_prepare_failure_retirement': customer_prepare_retirement, 'paid_producer_failure_retirement': paid_producer_retirement}")
 namespace=dict(values);namespace.update(ast=ast,hashlib=hashlib,json=json,re=re,Path=Path)
 exec(compile(ast.Module(body=[ast.parse(fn).body[0]],type_ignores=[]),'<pure-published-current-source-authority-only>','exec'),namespace)
 def checked(pointer):
  raw=r.fixed(pointer['path'],pointer)
  if 'native9' in pointer:assert r.READS[Path(pointer['path']).as_posix()]['native9']==pointer['native9']
  return raw
 def check(ok,code):assert ok,code
 qualified=namespace['source_authority'](publication,checked,check)
 assert qualified['all']==expected and qualified['groups']==groups and qualified['committed']==committed and qualified['paid_producer_failure_retirement']==r.obj(RETIRE/'AUTHORITY.json')
 for root in [Path(values['MAIN_SOURCE']),Path(values['VALIDATION_SOURCE'])]:
  for row in publication['repository_Source_requirements']:assert r.pin(r.read(root/row['path']))==r.compact(row)
 r.put('validation/PROFILE-PUBLICATION.json',publication)
 pins=json.loads(before['validation/PROFILE-PINS.json']);pins['members']={row['path']:r.compact(row) for row in selected};pins['members']['PROFILE-PUBLICATION.json']=r.pin(r.read(OUT/'validation/PROFILE-PUBLICATION.json'));r.put('validation/PROFILE-PINS.json',pins)
 note=('\n\nPublished current source '+HEAD+' parent '+PARENT+'. Preserve original11; actual12th caller repair and two authorized published non-business deltas. '
  '14 steps, 15629 tracked, 188 cumulative, 194 requirements, 143 baseline additions; derived native677. Groups10/10865/10866/10881/10885/10886 union10886. '
  'M RESULT31 includes fifth named paid-producer failure retirement. Original business suffix unchanged. New M/S/business NOT RUN.\n').encode()
 r.write('validation/DESIGN-TASKS.md',before['validation/DESIGN-TASKS.md']+note)
 extras=['HISTORICAL_ELEVENTH_COMPOSITE_CUSTOMER_FORM_SOURCE','CURRENT_PUBLISHED_COMMIT_HISTORY','CURRENT_PAID_PRODUCER_FAILURE_RETIREMENT','CURRENT_INITIALIZER_SYNTAX_AUTHORITY']
 def shared(text):
  text=r.once(text,r.function(text,'source_authority'),fn)
  for key in ['COMMIT_CHAIN','COMMITTED_PATHS','SOURCE_REQUIREMENT_PATHS','NEW_TRACKED_PATHS','CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE']:text=r.const(text,key,values[key])
  return r.once(text,'def source_authority(',''.join(key+' = '+repr(values[key])+'\n' for key in extras)+'\ndef source_authority(')
 s=shared(olds);s=r.const(s,'SOURCE',HEAD);s=r.const(s,'PINS',{name:r.pin(candidate9[name])['sha256'] for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']});s=r.const(s,'PROFILE_PUBLICATION',r.pin(r.read(OUT/'validation/PROFILE-PINS.json')))
 sb="and actual_setup['current_customer_prepare_failure_retirement'] == qualified['customer_prepare_failure_retirement'],"
 sa="and actual_setup['current_customer_prepare_failure_retirement'] == qualified['customer_prepare_failure_retirement']\n        and actual_setup['current_paid_producer_failure_retirement'] == qualified['paid_producer_failure_retirement'],"
 s=r.once(s,sb,sa);r.write('validation/run.py',s.encode())
 rp=json.loads(before['validation/PINS.json']);rp['members']={name:r.pin(r.read(OUT/'validation'/name)) for name in ['DESIGN-TASKS.md','run.py']};r.put('validation/PINS.json',rp)
 m=shared(oldm);m=r.const(m,'head',HEAD);m=r.const(m,'parent',PARENT)
 m=r.once(m,"root=Path('"+(OLD/'validation').as_posix()+"')","root=Path('"+(OUT/'validation').as_posix()+"')")
 m=r.once(m,"tool=Path('"+(OLD/'materializer').as_posix()+"')","tool=Path('"+(OUT/'materializer').as_posix()+"')")
 m=r.once(m,"need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')",
  "need(pin((root/'PINS.json').read_bytes())=="+repr(r.pin(r.read(OUT/'validation/PINS.json')))+",'REVIEWED_ROOT_SOURCE_PINS')")
 m=r.once(m,"'current_customer_prepare_failure_retirement':qualified['customer_prepare_failure_retirement'],'native_operations':ops,","'current_customer_prepare_failure_retirement':qualified['customer_prepare_failure_retirement'],'current_paid_producer_failure_retirement':qualified['paid_producer_failure_retirement'],'native_operations':ops,")
 r.write('materializer/prepare.py',m.encode());r.write('materializer/PLAN.md',before['materializer/PLAN.md']+note)
 inverse=json.loads(before['materializer/INVERSE.json']);inverse.update(source_sha=HEAD,parent=PARENT,commit_chain=chain,cumulative_changed_paths=changes)
 inverse['current_published_fourteen_step_refresh']={'actual_twelfth_authority':twelfth_pointer,'actual_published_history':published_pointer,'current_profile_source':profile_pointer,
  'paid_producer_retirement':retired_pointer,'initializer_syntax':init_pointer,'native_budget':native_count,'all_original_histories_preserved':True,'new_business':'NOT RUN'};r.put('materializer/INVERSE.json',inverse)
 launcher=before['launcher/launch.py'].decode();launcher=r.once(launcher,"ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')")
 launcher=r.once(launcher,(OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix());launcher=r.once(launcher,r.pin(before['validation/PINS.json'])['sha256'],r.pin(r.read(OUT/'validation/PINS.json'))['sha256'])
 launcher=r.once(launcher,"value['source_sha']=='"+oldchain[-1]['source_sha']+"'","value['source_sha']=='"+HEAD+"'")
 precheck="current_paid_pointer = "+repr(retired_pointer)+"\nassert publication['finite_contexts']['current_paid_producer_failure_retirement'] == current_paid_pointer\nassert descriptor(Path(current_paid_pointer['path'])) == current_paid_pointer\ncurrent_paid = read(Path(current_paid_pointer['path']))\nassert current_paid['full_acceptance'] == 'INCOMPLETE' and current_paid['original_failure_truth']['failed_outer_native_exit'] == 1 and current_paid['original_failure_truth']['original_S_terminal'] == 'PRESENT'\n"
 launcher=r.once(launcher,"if mode=='materialize':",precheck+"\nif mode=='materialize':");launcher=r.once(launcher," assert read(BASE/'materialize.terminal.json')['native_exit']==0"," assert value['current_paid_producer_failure_retirement']==current_paid\n assert read(BASE/'materialize.terminal.json')['native_exit']==0")
 r.write('launcher/launch.py',launcher.encode())
 for label,text,old in [('M',m,oldm),('S',s,olds)]:
  for name,body in funcs(old).items():
   if name=='source_authority':continue
   held=funcs(text)[name];assert (r.once(held,sa,sb) if label=='S' and name=='main' else held)==body,(label,name)
 assert funcs(launcher)==funcs(before['launcher/launch.py'].decode())
 suffix="    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')"
 assert s[s.index(suffix):]==olds[olds.index(suffix):] and r.function(m,'source_authority')==r.function(s,'source_authority')
 results=[node for node in ast.walk(ast.parse(m)) if isinstance(node,ast.Dict) and any(isinstance(key,ast.Constant) and key.value=='materialize_source_inputs' for key in node.keys)]
 assert len(results)==1 and len(results[0].keys)==31
 after={name:r.read(OUT/name) for name in members};forward=r.patch(before,after);reverse=r.patch(after,before);assert r.apply(before,forward)==after and r.apply(after,reverse)==before
 r.write('DIFF.patch',forward.encode());r.write('REVERSE.patch',reverse.encode())
 original18={name:r.read(OLD/'original-before'/name) for name in members}
 for name,raw in original18.items():r.write('original-before/'+name,raw)
 fwd=r.patch(original18,after);rev=r.patch(after,original18);assert r.apply(original18,fwd)==after and r.apply(after,rev)==original18;r.write('FROM-ORIGINAL.patch',fwd.encode());r.write('TO-ORIGINAL.patch',rev.encode())
 checks={'status':'SOURCE_READY_NOT_M_S_EXECUTED','source_sha':HEAD,'parent':PARENT,'steps':len(chain),'tracked':len(expected),'cumulative':len(changes),'requirements':len(requirements),
  'new_baseline_members':len(newtracked),'group_counts':counts,'normal_union':len(combined),'M_native_count':native_count,'M_RESULT_fields':31,
  'all_original_eleven_history_steps_preserved':chain[:11]==oldchain,'original_business_suffix_exact':True,'all_other_functions_exact_except_single_S_setup_guard':True,
  'four_complete_patch_directions_replayed':True,'fixed_profile9_original_bytes_preserved':True,'all_input_windows_ended':True,'new_business':'NOT RUN'};r.put('AUTHOR-CHECKS.json',checks)
 ended=time.time_ns();assert all(row['closed'] and row['EOF'] and row['ended_ns']<=ended for row in r.READS.values())
 r.put('AUTHOR-INPUTS.json',{'schema':1,'input_count':len(r.READS),'all_input_windows_ended':True,'ended_ns':ended,'inputs':r.READS,'target_native_execution':'NOT RUN'})
 files={p.relative_to(OUT).as_posix():{'path':p.as_posix(),**r.pin(p.read_bytes()),'native9':r.n9(p.stat())} for p in sorted(OUT.rglob('*')) if p.is_file() and p!=OUT/'PINS.json'}
 r.put('PINS.json',{'schema':1,'status':'FROZEN_SOURCE_READY_NOT_EXECUTED','source_sha':HEAD,'parent':PARENT,'source_members18':members,'files':files,
  'Root14_M3_contract':True,'before_source':r.pointer(OLD/'PINS.json'),'profile_source':profile_pointer,'actual_twelfth_commit_authority':twelfth_pointer,
  'published_history':published_pointer,'current_paid_producer_failure_retirement':retired_pointer,'initializer_syntax':init_pointer,'pure_authority':checks,
  'all_input_windows_ended':True,'input_count':len(r.READS),'ended_ns':ended,'M_S_Host_PHP_Docker_business_execution':'NOT RUN'})
 print(json.dumps({'status':'FROZEN_SOURCE_READY_NOT_EXECUTED','PINS':r.pin((OUT/'PINS.json').read_bytes()),'M':r.pin(after['materializer/prepare.py']),
  'S':r.pin(after['validation/run.py']),'rootPINS':r.pin(after['validation/PINS.json']),'native_count':native_count,'cumulative':len(changes),'tracked':len(expected),'input_count':len(r.READS)},ensure_ascii=False))

if __name__=='__main__':
 try:main()
 except Exception as error:
  p=OUT/'AUTHORING-FAILURE-01.json'
  if not p.exists():p.write_text(json.dumps({'status':'SOURCE_AUTHORING_FAILED_WITHOUT_TARGET_EXECUTION','class':type(error).__name__,'message':str(error)},ensure_ascii=False,indent=2)+'\n',encoding='utf8')
  raise
