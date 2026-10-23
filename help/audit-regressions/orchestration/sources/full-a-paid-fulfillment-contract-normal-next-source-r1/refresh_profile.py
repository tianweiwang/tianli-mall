"""Current N9 descriptor derivation only; never imports a candidate or Host."""
import ast,copy,json
from pathlib import Path
import source_reader as r

BASE=r.B/'full-a-raw-env-normal-next-source-r1'
DELTA=r.B/'paid-fulfillment-current-source-contract-repair-source-r2'
ADOPTION=r.B/'paid-fulfillment-current-contract-main-adoption-r1'
ENTRIES=['help/audit-regressions/isolated/ms5-paid-consumer-normal.php','help/audit-regressions/isolated/ms4-fulfillment-normal.php']
NAMES=['README.md','compose.yml','ms4-host.py','ms4-initialize.php','seed-source-inverse.json','interface-v1.json','source-derivation.json','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']
def enc(value):return (json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode('utf8')
def funcs(raw):
 text=raw.decode('utf8');return {n.name:ast.get_source_segment(text,n) for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def literal(raw,name):return r.constants(raw.decode('utf8'))[name]
def constant(raw,name,value):return r.const(raw.decode('utf8'),name,value).encode('utf8')
def once(raw,before,after):
 assert raw.count(before)==1,(before[:100],raw.count(before));return raw.replace(before,after,1)

def main():
 assert not (r.OUT/'PINS.json').exists()
 assert r.HEAD=='76efa7807c6137bc430e314e2faa78691cf60431' and r.PARENT=='53e6d521b387b19a4cf370e0e381670b762d325e' and r.OLD==BASE
 pp=r.obj(BASE/'profile-source/PINS.json');before={}
 assert set(pp['candidate'])==set(NAMES)
 for name in NAMES:
  before[name]=r.fixed(BASE/'profile-source/candidate'/name,pp['candidate'][name])
  assert before[name]==r.fixed(BASE/'validation/actual-profile'/name,pp['candidate'][name])
  r.write('profile-source/before/'+name,before[name])
 iface=json.loads(before['interface-v1.json']);deriv=json.loads(before['source-derivation.json'])
 assert enc(iface)==before['interface-v1.json'] and enc(deriv)==before['source-derivation.json']
 oldrows=iface['customer_form_current_profile']['php_sources'];old_composite=copy.deepcopy(iface['customer_form_current_profile']['current_composite_php_source_authority'])
 assert len(oldrows)==7 and len({row['path'] for row in oldrows})==7
 previous=[next(row for row in oldrows if row['path']==name) for name in ENTRIES]
 assert previous==[{'path':ENTRIES[0],'bytes':108005,'sha256':'ee59d99ea8db768b0a45727d9cdffe276b7f0c11f7e07cea5cc10d10a449f4b7'},
  {'path':ENTRIES[1],'bytes':128280,'sha256':'b877330ac652d1d0bc63fd72d84fb34174cac83144637f58c090c0f7c13034ce'}]
 dp=r.obj(DELTA/'PINS.json');dm=r.obj(DELTA/'SOURCE-MANIFEST.json');newrows_by_path={};candidate_paths=[]
 assert r.pin(r.read(DELTA/'PINS.json'))=={'bytes':4254,'sha256':'4a3233283f4ea5cc4da3ee696b337be49725aa63b5553e4edbbd68fe283b7618'}
 delta_before={};delta_after={}
 for index,name in enumerate(ENTRIES):
  change=next(row for row in dm['transforms'] if row['repository_path']==name)
  assert change['before'] in dp['members'] and change['before']['path']==(DELTA/'before'/name).as_posix()
  old=r.fixed(Path(change['before']['path']),change['before']);new=r.fixed(Path(change['candidate']['path']),change['candidate'])
  assert change['candidate'] in dp['members'] and r.pin(old)==r.compact(previous[index])
  delta_before[name]=old;delta_after[name]=new;newrows_by_path[name]={'path':name,**r.pin(new)};candidate_paths.append(r.pointer(Path(change['candidate']['path'])))
  forward=r.read(Path(change['forward']['path']));reverse=r.read(Path(change['reverse']['path']))
  forward=once(forward,('--- before/'+name+'\n+++ candidate/'+name+'\n').encode(),('--- a/'+name+'\n+++ b/'+name+'\n').encode())
  reverse=once(reverse,('--- candidate/'+name+'\n+++ before/'+name+'\n').encode(),('--- a/'+name+'\n+++ b/'+name+'\n').encode())
  assert r.apply({name:old},forward.decode())=={name:new} and r.apply({name:new},reverse.decode())=={name:old}
 current=[newrows_by_path[name] for name in ENTRIES]
 assert [r.compact(row) for row in current]==[{'bytes':109531,'sha256':'67450a4ab293d4b59f9ee90b02d35e2532c09b420c378ae5c643f6f39070f2f6'},
  {'bytes':128359,'sha256':'4d38c7ac0d8afbc72a0000319765e155fbd8a116656c43652e54cd824dc992b7'}]
 adoption=r.obj(ADOPTION/'SOURCE-MANIFEST.json');actual=r.obj(ADOPTION/'COMMIT.json')
 assert actual['commit_sha']==r.HEAD and actual['parent_sha']==adoption['parent_sha']==r.PARENT and actual['working_repos_clean'] is True and actual['Val_detached_same_source'] is True
 assert actual['scope']==adoption['scope']==ENTRIES+['docs/multi-merchant/README.md','docs/multi-merchant/09-plan-ms4-ms6.md'] and adoption['Source_PINS']==r.pointer(DELTA/'PINS.json')
 for index,name in enumerate(ENTRIES):
  assert adoption['files'][name]=={'before':r.compact(previous[index]),'candidate':r.compact(current[index])}
  assert r.read(ADOPTION/'before'/name)==delta_before[name]
 authority_paths={'caller_source_PINS':DELTA/'PINS.json','caller_source_manifest':DELTA/'SOURCE-MANIFEST.json',
  'caller_syntax_RESULT':r.B/'paid-fulfillment-current-contract-php-syntax-actual-r1/RESULT.json',
  'caller_syntax_job_PINS':r.B/'paid-fulfillment-current-contract-php-syntax-source-r2/PINS.json',
  'root_actual_readback':r.B/'full-a-paid-producer-failed-close-zero-and-syntax-root-readback-r1/REPORT.json'}
 authorities={name:r.pointer(path) for name,path in authority_paths.items()}
 syntax=r.obj(authority_paths['caller_syntax_RESULT']);root=r.obj(authority_paths['root_actual_readback'])
 assert syntax['status']=='PASS_CANDIDATE_PHP82_SYNTAX_ONLY' and syntax['sources']==candidate_paths and syntax['candidate_Source_PINS']==authorities['caller_source_PINS'] and syntax['candidate_Source_manifest']==authorities['caller_source_manifest']
 assert syntax['php_l_count_required']==2 and syntax['container_native_exit']==0 and syntax['ordinary_own_container_removed'] is True and syntax['own_label_before_after_empty'] is True and syntax['all_input_windows_ended'] is True and len(syntax['operations'])==8
 assert root['status']=='ACTUAL_PAID_FAILURE_NORMAL_CLOSE_INDEPENDENT_ZERO_AND_TWO_PHP_SYNTAX_VERIFIED' and root['closed_native_commands_verified']==121
 assert root['results']['paid-fulfillment-current-contract-php-syntax-actual-r1']=={**authorities['caller_syntax_RESULT'],'operations':8}
 composite={'scope':'FROZEN_PREDECESSOR_SEVEN_PLUS_TWO_CALLER_CONTRACT_DELTAS_ONLY',
  'status':'SOURCE_BOUND_TWO_CALLER_CONTRACT_DELTAS_WITH_SEPARATE_ACTUAL_ADOPTION',
  'predecessor_profile_PINS':r.pointer(BASE/'profile-source/PINS.json'),'predecessor_profile_interface':r.pointer(BASE/'profile-source/candidate/interface-v1.json'),
  'predecessor_composite':old_composite,'delta_authorities':authorities,'delta_repository_paths':ENTRIES,
  'delta_candidates':candidate_paths,'previous_descriptors':previous,'current_descriptors':current,
  'actual_adoption':{'commit':r.pointer(ADOPTION/'COMMIT.json'),'source_manifest':r.pointer(ADOPTION/'SOURCE-MANIFEST.json')},
  'actual_source_sha':r.HEAD,'runtime':'NOT RUN',
  'base_authorities_semantics':'The complete predecessor seven-source raw-Env composite stays historical. Only the two exact paid/fulfillment caller descriptors change after the actual four-path local commit; the five other selected sources, existing initializer membership semantics and all normal business functions remain unchanged. Previous results are not current business success.'}
 newrows=[copy.deepcopy(newrows_by_path.get(row['path'],row)) for row in oldrows]
 assert len([row for row in newrows if row['path'] not in ENTRIES])==5 and [row for row in newrows if row['path'] not in ENTRIES]==[row for row in oldrows if row['path'] not in ENTRIES]
 candidate=dict(before)
 for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
  assert literal(candidate[name],'CUSTOMER_FORM_SOURCE_FILES')==oldrows
  candidate[name]=constant(candidate[name],'CUSTOMER_FORM_SOURCE_FILES',newrows)
 stages=[];work=before['ms4-initialize.php']
 for oldrow,newrow in zip(previous,current):
  def line(row):return ("['path' => '"+row['path']+"', 'bytes' => "+str(row['bytes'])+", 'sha256' => '"+row['sha256']+"']").encode()
  old,new=line(oldrow),line(newrow);offset=work.index(old);changed=once(work,old,new)
  stages.append({'kind':'ONLY_CURRENT_CALLER_DESCRIPTOR','repository_path':oldrow['path'],'offset':offset,'before_payload':old.decode(),'after_payload':new.decode(),'whole_before':r.pin(work),'whole_after':r.pin(changed)});work=changed
 candidate['ms4-initialize.php']=work;assert len(work)==len(before['ms4-initialize.php'])==78955
 rollback=work
 for row in reversed(stages):
  at=row['offset'];old=row['before_payload'].encode();new=row['after_payload'].encode();assert r.pin(rollback)==row['whole_after'] and rollback[at:at+len(new)]==new
  rollback=rollback[:at]+old+rollback[at+len(new):];assert r.pin(rollback)==row['whole_before']
 assert rollback==before['ms4-initialize.php']
 assert [line for line in work.splitlines() if b'm4i_sourceFile(' in line]==[line for line in before['ms4-initialize.php'].splitlines() if b'm4i_sourceFile(' in line]
 initializer_proof={'schema':1,'status':'SOURCE_ONLY_TWO_CURRENT_CALLER_DESCRIPTORS_NO_ALGORITHM_CHANGE',
  'before':r.pin(before['ms4-initialize.php']),'final':r.pin(work),'stages':stages,'full_inverse_to_predecessor':True,
  'predecessor_initializer_seams':r.pointer(BASE/'profile-source/INITIALIZER-SEAMS.json'),
  'existing_membership_lookup_seams_preserved_by_full_inverse':True,'all_original_sourceFile_calls_exact':True,
  'final_descriptor_successor_PHP82_syntax':'NOT RUN','actual_manifest_or_performance_execution':'NOT RUN'}
 r.put('profile-source/INITIALIZER-SEAMS.json',initializer_proof)
 iface['customer_form_current_profile']['php_sources']=newrows;iface['customer_form_current_profile']['current_composite_php_source_authority']=composite;candidate['interface-v1.json']=enc(iface)
 refresh=deriv['customer_form_eight_volume_current_refresh'];assert refresh['new_php_bindings']==oldrows and refresh['current_composite_php_source_authority']==old_composite
 refresh['new_php_bindings']=newrows;refresh['current_composite_php_source_authority']=copy.deepcopy(composite)
 history=copy.deepcopy(deriv['raw_env_current_profile_refresh']);field='paid_fulfillment_contract_current_profile_refresh';assert field not in deriv
 deriv[field]={'status':'SOURCE_ONLY_ACTUAL_TWELFTH_ADOPTION_SEPARATE_NOT_RUNTIME','source_sha':r.HEAD,'parent':r.PARENT,
  'before_profile_PINS':r.pointer(BASE/'profile-source/PINS.json'),'previous_callers':previous,'current_callers':current,
  'current_composite_php_source_authority':composite,'unchanged_CU_source_members':5,
  'initializer_seams':r.pointer(r.OUT/'profile-source/INITIALIZER-SEAMS.json'),'historical_raw_env_subtree':'PRESERVED_FULL_ORIGINAL_OBJECT',
  'all_original_Python_function_bytes':'UNCHANGED','initializer_change':'ONLY_TWO_CURRENT_CALLER_DESCRIPTORS','business_and_acceptance':'NOT RUN'}
 candidate['ms4-host.py']=constant(candidate['ms4-host.py'],'INTERFACE_SHA256',r.pin(candidate['interface-v1.json'])['sha256'])
 candidate['normal-close-ms4-fulfillment.py']=constant(candidate['normal-close-ms4-fulfillment.py'],'HOST_SHA256',r.pin(candidate['ms4-host.py'])['sha256'])
 for name,value in [('INTERFACE_SHA256',r.pin(candidate['interface-v1.json'])['sha256']),('INTERFACE_BYTES',len(candidate['interface-v1.json'])),('HOST_SHA256',r.pin(candidate['ms4-host.py'])['sha256']),('CLOSE_SHA256',r.pin(candidate['normal-close-ms4-fulfillment.py'])['sha256'])]:candidate['verify-zero-ms4-fulfillment.py']=constant(candidate['verify-zero-ms4-fulfillment.py'],name,value)
 deriv['current_nonself_artifacts']=[{'path':name,**r.pin(candidate[name])} for name in NAMES if name!='source-derivation.json'];candidate['source-derivation.json']=enc(deriv)
 assert len(deriv['current_nonself_artifacts'])==8 and deriv['raw_env_current_profile_refresh']==history
 for name in ['README.md','compose.yml','seed-source-inverse.json']:assert candidate[name]==before[name]
 for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:assert funcs(candidate[name])==funcs(before[name])
 for name,allowed in [('ms4-host.py',['CUSTOMER_FORM_SOURCE_FILES','INTERFACE_SHA256']),('normal-close-ms4-fulfillment.py',['HOST_SHA256']),('verify-zero-ms4-fulfillment.py',['CUSTOMER_FORM_SOURCE_FILES','INTERFACE_SHA256','INTERFACE_BYTES','HOST_SHA256','CLOSE_SHA256'])]:
  restored=candidate[name]
  for key in allowed:restored=constant(restored,key,literal(before[name],key))
  assert restored==before[name],('ALL_NONCONSTANT_PYTHON_BYTES_EXACT',name)
 inv_iface=copy.deepcopy(iface);inv_iface['customer_form_current_profile']['php_sources']=oldrows;inv_iface['customer_form_current_profile']['current_composite_php_source_authority']=old_composite;assert enc(inv_iface)==before['interface-v1.json']
 inv_deriv=copy.deepcopy(deriv);del inv_deriv[field];inv_deriv['customer_form_eight_volume_current_refresh']['new_php_bindings']=oldrows;inv_deriv['customer_form_eight_volume_current_refresh']['current_composite_php_source_authority']=old_composite;inv_deriv['current_nonself_artifacts']=json.loads(before['source-derivation.json'])['current_nonself_artifacts'];assert enc(inv_deriv)==before['source-derivation.json']
 for name,raw in candidate.items():r.write('profile-source/candidate/'+name,raw)
 forward=r.patch(before,candidate);reverse=r.patch(candidate,before);assert r.apply(before,forward)==candidate and r.apply(candidate,reverse)==before
 r.write('profile-source/DIFF.patch',forward.encode());r.write('profile-source/REVERSE.patch',reverse.encode())
 manifest={'schema':1,'status':'SOURCE_ONLY_CURRENT_PROFILE_NINE_TWO_CALLER_DESCRIPTOR_DELTAS','source_sha':r.HEAD,'parent':r.PARENT,
  'before':{name:r.pin(raw) for name,raw in before.items()},'candidate':{name:r.pin(raw) for name,raw in candidate.items()},
  'all9_full_forward_reverse_replayed':True,'all_original_Python_functions_exact':True,'original_three_whole_artifacts_exact':True,
  'all_other_interface_and_derivation_bytes_reconstructed':True,'historical_raw_env_subtree_exact':True,'current_seven':newrows,
  'composite_source_authority':composite,'initializer_seams':r.pointer(r.OUT/'profile-source/INITIALIZER-SEAMS.json'),
  'final_initializer':r.pin(work),'all_five_SHA_edges_and_interface_size_closed':True,'nonself_count':8,'runtime':'NOT RUN'}
 r.put('profile-source/MANIFEST.json',manifest)
 r.put('profile-source/PINS.json',{'schema':1,'status':'SOURCE_ONLY_NOT_EXECUTED','candidate':manifest['candidate'],
  'MANIFEST':r.pin(r.read(r.OUT/'profile-source/MANIFEST.json')),'before_profile_PINS':r.pointer(BASE/'profile-source/PINS.json'),
  'DIFF':r.pin(forward.encode()),'REVERSE':r.pin(reverse.encode()),'INITIALIZER_SEAMS':r.pin(r.read(r.OUT/'profile-source/INITIALIZER-SEAMS.json')),'final_initializer':manifest['final_initializer']})
 return candidate,r.pointer(r.OUT/'profile-source/PINS.json'),composite
