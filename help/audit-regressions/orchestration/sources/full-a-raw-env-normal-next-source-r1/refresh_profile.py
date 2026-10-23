"""Own nine-profile Source derivation only; never imports a candidate or Host."""
import ast,copy,json
from pathlib import Path
import source_reader as r

ENTRY='help/audit-regressions/isolated/lib/ms4-customer-http-normal.php'
BASE=r.B/'full-a-initializer-customer-form-mount-next-source-r1'
DELTA=r.B/'customer-form-official-initialize-env-raw-source-r1'
MEMBERSHIP=r.B/'full-a-normal-initializer-membership-source-r1'
ADOPTION=r.B/'customer-form-official-initialize-env-main-adoption-r1'
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
 assert r.HEAD=='53e6d521b387b19a4cf370e0e381670b762d325e' and r.PARENT=='2fa872e6fda7a8bf46c55f88dd946b972ec79102' and r.OLD==BASE
 pp=r.obj(BASE/'profile-source/PINS.json');before={}
 assert set(pp['candidate'])==set(NAMES)
 for name in NAMES:
  before[name]=r.fixed(BASE/'validation/actual-profile'/name,pp['candidate'][name])
  assert before[name]==r.read(BASE/'profile-source/candidate'/name)
  r.write('profile-source/before/'+name,before[name])
 iface=json.loads(before['interface-v1.json']);deriv=json.loads(before['source-derivation.json'])
 assert enc(iface)==before['interface-v1.json'] and enc(deriv)==before['source-derivation.json']
 oldrows=iface['customer_form_current_profile']['php_sources'];oldrow=next(row for row in oldrows if row['path']==ENTRY)
 assert len(oldrows)==7 and len({row['path'] for row in oldrows})==7
 assert oldrow=={'path':ENTRY,'bytes':65044,'sha256':'90317d0dd07d075251e6e306cd0adae524fe5455a89b0eb147880a232fda8d8e'}
 dp=r.obj(DELTA/'PINS.json');delta_before=r.fixed(DELTA/'before'/ENTRY,dp['members']['before/'+ENTRY]);delta_candidate=r.fixed(DELTA/'candidate'/ENTRY,dp['members']['candidate/'+ENTRY]);newpin=r.pin(delta_candidate)
 assert r.pin(delta_before)==r.compact(oldrow) and newpin=={'bytes':65119,'sha256':'925314fc7050d8e1e0928aa056f680fed88662cf8f4b2ae2f87d070b9ae6de2d'}
 rawdiff=r.read(DELTA/'DIFF.patch').decode('utf8');rawreverse=r.read(DELTA/'REVERSE.patch').decode('utf8')
 reverse_headers=('--- b/'+ENTRY+'\n+++ a/'+ENTRY+'\n').encode();reader_headers=('--- a/'+ENTRY+'\n+++ b/'+ENTRY+'\n').encode()
 rawreverse_for_reader=once(rawreverse.encode(),reverse_headers,reader_headers).decode()
 assert r.apply({ENTRY:delta_before},rawdiff)=={ENTRY:delta_candidate} and r.apply({ENTRY:delta_candidate},rawreverse_for_reader)=={ENTRY:delta_before}
 adoption=r.obj(ADOPTION/'SOURCE-MANIFEST.json');actual=r.obj(ADOPTION/'COMMIT.json')
 assert actual['head']==r.HEAD and actual['parent']==adoption['before_source']==r.PARENT and actual['Main_Val_clean_and_same_head'] is True
 assert actual['files']==adoption['members'] and len(actual['files'])==3 and actual['files'][ENTRY]=={'before':r.compact(oldrow),'candidate':newpin}
 assert r.read(ADOPTION/'before'/ENTRY)==delta_before and r.read(ADOPTION/'candidate'/ENTRY)==delta_candidate
 authority_paths={'raw_source_PINS':DELTA/'PINS.json','raw_source_peer':r.B/'customer-form-official-initialize-env-raw-independent-r1/REPORT.json','raw_syntax_RESULT':r.B/'customer-form-official-initialize-env-raw-php-syntax-actual-r1/RESULT.json','membership_source_PINS':MEMBERSHIP/'PINS.json','membership_source_peer':r.B/'full-a-normal-initializer-membership-independent-r1/REPORT.json','membership_syntax_RESULT':r.B/'full-a-normal-initializer-membership-php-syntax-actual-r1/RESULT.json'}
 authorities={name:r.pointer(path) for name,path in authority_paths.items()}
 raw_peer=r.obj(authority_paths['raw_source_peer']);membership_peer=r.obj(authority_paths['membership_source_peer'])
 assert raw_peer['findings']==[] and raw_peer['source_PINS']==authorities['raw_source_PINS'] and membership_peer['findings']==[] and membership_peer['Source_PINS']==authorities['membership_source_PINS']
 mp=r.obj(MEMBERSHIP/'PINS.json');mchecks=r.fixed(MEMBERSHIP/'CHECKS.json',mp['members']['CHECKS.json']);seam_source=json.loads(mchecks)
 init_before=r.fixed(MEMBERSHIP/'before.php',mp['members']['before.php']);init_mid=r.fixed(MEMBERSHIP/'candidate.php',mp['members']['candidate.php'])
 assert init_before==before['ms4-initialize.php'] and r.pin(init_before)=={'bytes':78547,'sha256':'1d8143de58920e5b48dcd2a713bef0895142f8f8012bc527425d4e6f256467e1'}
 assert r.pin(init_mid)=={'bytes':78955,'sha256':'7e2db8b37a680ad558f40fd648cf7e868aadaf60fa4d42961c83f37f71d0600b'}
 assert r.apply({'ms4-initialize.php':init_before},r.read(MEMBERSHIP/'DIFF.patch').decode('utf8'))=={'ms4-initialize.php':init_mid}
 assert r.apply({'ms4-initialize.php':init_mid},r.read(MEMBERSHIP/'REVERSE.patch').decode('utf8'))=={'ms4-initialize.php':init_before}
 initial_stages=[];work=init_before
 assert len(seam_source['seams'])==2 and [row['variable'] for row in seam_source['seams']]==['customerPaths','oldPaths']
 for row in seam_source['seams']:
  old=row['original'].encode('utf8');new=row['replacement'].encode('utf8');offset=work.index(old);changed=once(work,old,new)
  initial_stages.append({'kind':'EXACT_APPROVED_MEMBERSHIP_SEAM','variable':row['variable'],'offset':offset,'before_payload':row['original'],'after_payload':row['replacement'],'whole_before':r.pin(work),'whole_after':r.pin(changed)});work=changed
 assert work==init_mid
 for role,source_pin in [('raw',{'path':(DELTA/'candidate'/ENTRY).as_posix(),**newpin}),('membership',{'path':(MEMBERSHIP/'candidate.php').as_posix(),**r.pin(init_mid)})]:
  syntax=r.obj(authority_paths[role+'_syntax_RESULT'])
  assert syntax['status']=='PASS_CANDIDATE_PHP82_SYNTAX_ONLY' and syntax['source']==source_pin and syntax['candidate_Source_PINS']==authorities[role+'_source_PINS'] and syntax['php_l_count']==1 and syntax['container_native_exit']==0 and syntax['ordinary_own_container_removed'] is True and syntax['all_input_windows_ended'] is True and len(syntax['operations'])==8
 old_composite=copy.deepcopy(iface['customer_form_current_profile']['current_composite_php_source_authority'])
 composite={'scope':'FROZEN_PREDECESSOR_SEVEN_PLUS_SINGLE_RAW_ENV_LIBRARY_DELTA_ONLY','status':'SOURCE_BOUND_CURRENT_LIBRARY_DELTA_WITH_SEPARATE_ACTUAL_ADOPTION',
  'predecessor_profile_PINS':r.pointer(BASE/'profile-source/PINS.json'),'predecessor_profile_interface':r.pointer(BASE/'validation/actual-profile/interface-v1.json'),'predecessor_composite':old_composite,
  'delta_authorities':authorities,'delta_repository_path':ENTRY,'delta_candidate':r.pointer(DELTA/'candidate'/ENTRY),'previous_descriptor':oldrow,'current_descriptor':{'path':ENTRY,**newpin},
  'actual_adoption':{'commit':r.pointer(ADOPTION/'COMMIT.json'),'source_manifest':r.pointer(ADOPTION/'SOURCE-MANIFEST.json')},'actual_source_sha':r.HEAD,'runtime':'NOT RUN',
  'base_authorities_semantics':'The entire original seven-source/tenth composite is unchanged history; only the exact current raw Env library replaces its previous descriptor. Six other selected members remain byte-identical. The historical pre-adoption Source manifest is not re-signed as actual adoption; the real clean COMMIT supplies current identity.'}
 newrows=[({'path':ENTRY,**newpin} if row['path']==ENTRY else copy.deepcopy(row)) for row in oldrows]
 assert [row for row in newrows if row['path']!=ENTRY]==[row for row in oldrows if row['path']!=ENTRY]
 candidate=dict(before)
 for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
  assert literal(candidate[name],'CUSTOMER_FORM_SOURCE_FILES')==oldrows
  candidate[name]=constant(candidate[name],'CUSTOMER_FORM_SOURCE_FILES',newrows)
 oldline=("['path' => '"+ENTRY+"', 'bytes' => "+str(oldrow['bytes'])+", 'sha256' => '"+oldrow['sha256']+"']").encode()
 newline=("['path' => '"+ENTRY+"', 'bytes' => "+str(newpin['bytes'])+", 'sha256' => '"+newpin['sha256']+"']").encode()
 candidate['ms4-initialize.php']=once(init_mid,oldline,newline)
 assert once(candidate['ms4-initialize.php'],newline,oldline)==init_mid and len(candidate['ms4-initialize.php'])==78955
 initial_stages.append({'kind':'ONLY_CURRENT_LIBRARY_DESCRIPTOR','offset':init_mid.index(oldline),'before_payload':oldline.decode(),'after_payload':newline.decode(),'whole_before':r.pin(init_mid),'whole_after':r.pin(candidate['ms4-initialize.php'])})
 rollback=candidate['ms4-initialize.php']
 for row in reversed(initial_stages):
  pos=row['offset'];old=row['before_payload'].encode();new=row['after_payload'].encode();assert r.pin(rollback)==row['whole_after'] and rollback[pos:pos+len(new)]==new
  rollback=rollback[:pos]+old+rollback[pos+len(new):];assert r.pin(rollback)==row['whole_before']
 assert rollback==init_before
 assert [line for line in candidate['ms4-initialize.php'].splitlines() if b'm4i_sourceFile(' in line]==[line for line in init_before.splitlines() if b'm4i_sourceFile(' in line]
 initializer_proof={'schema':1,'status':'SOURCE_ONLY_TWO_APPROVED_MEMBERSHIP_SEAMS_THEN_SINGLE_LIBRARY_DESCRIPTOR','before':r.pin(init_before),'intermediate':r.pin(init_mid),'final':r.pin(candidate['ms4-initialize.php']),'stages':initial_stages,'complete_inverse_to_original78547':True,'approved_source_seams':r.pointer(MEMBERSHIP/'CHECKS.json'),'all_original_sourceFile_calls_exact':True,'pre_rebind_PHP82_syntax_RESULT':authorities['membership_syntax_RESULT'],'final_descriptor_successor_PHP82_syntax':'NOT RUN','actual_manifest_or_performance_execution':'NOT RUN'}
 r.put('profile-source/INITIALIZER-SEAMS.json',initializer_proof)
 iface['customer_form_current_profile']['php_sources']=newrows;iface['customer_form_current_profile']['current_composite_php_source_authority']=composite;candidate['interface-v1.json']=enc(iface)
 refresh=deriv['customer_form_eight_volume_current_refresh'];assert refresh['new_php_bindings']==oldrows and refresh['current_composite_php_source_authority']==old_composite
 refresh['new_php_bindings']=newrows;refresh['current_composite_php_source_authority']=copy.deepcopy(composite)
 historical_tenth=copy.deepcopy(deriv['initializer_mount_current_profile_refresh'])
 field='raw_env_current_profile_refresh';assert field not in deriv
 deriv[field]={'status':'SOURCE_ONLY_ACTUAL_ELEVENTH_ADOPTION_SEPARATE_NOT_RUNTIME','source_sha':r.HEAD,'parent':r.PARENT,'before_profile_PINS':r.pointer(BASE/'profile-source/PINS.json'),'previous_library':oldrow,'current_library':{'path':ENTRY,**newpin},'current_composite_php_source_authority':composite,'unchanged_CU_source_members':6,'initializer_seams':r.pointer(r.OUT/'profile-source/INITIALIZER-SEAMS.json'),'historical_tenth_subtree':'PRESERVED_FULL_ORIGINAL_OBJECT','all_original_Python_function_bytes':'UNCHANGED','initializer_change':'EXACT_TWO_APPROVED_MEMBERSHIP_SEAMS_PLUS_SINGLE_LIBRARY_DESCRIPTOR','business_and_acceptance':'NOT RUN'}
 candidate['ms4-host.py']=constant(candidate['ms4-host.py'],'INTERFACE_SHA256',r.pin(candidate['interface-v1.json'])['sha256'])
 candidate['normal-close-ms4-fulfillment.py']=constant(candidate['normal-close-ms4-fulfillment.py'],'HOST_SHA256',r.pin(candidate['ms4-host.py'])['sha256'])
 for name,value in [('INTERFACE_SHA256',r.pin(candidate['interface-v1.json'])['sha256']),('INTERFACE_BYTES',len(candidate['interface-v1.json'])),('HOST_SHA256',r.pin(candidate['ms4-host.py'])['sha256']),('CLOSE_SHA256',r.pin(candidate['normal-close-ms4-fulfillment.py'])['sha256'])]:candidate['verify-zero-ms4-fulfillment.py']=constant(candidate['verify-zero-ms4-fulfillment.py'],name,value)
 deriv['current_nonself_artifacts']=[{'path':name,**r.pin(candidate[name])} for name in NAMES if name!='source-derivation.json'];candidate['source-derivation.json']=enc(deriv)
 assert len(deriv['current_nonself_artifacts'])==8 and deriv['initializer_mount_current_profile_refresh']==historical_tenth
 for name in ['README.md','compose.yml','seed-source-inverse.json']:assert candidate[name]==before[name]
 for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:assert funcs(candidate[name])==funcs(before[name])
 inv_iface=copy.deepcopy(iface);inv_iface['customer_form_current_profile']['php_sources']=oldrows;inv_iface['customer_form_current_profile']['current_composite_php_source_authority']=old_composite;assert enc(inv_iface)==before['interface-v1.json']
 inv_deriv=copy.deepcopy(deriv);del inv_deriv[field];inv_deriv['customer_form_eight_volume_current_refresh']['new_php_bindings']=oldrows;inv_deriv['customer_form_eight_volume_current_refresh']['current_composite_php_source_authority']=old_composite;inv_deriv['current_nonself_artifacts']=json.loads(before['source-derivation.json'])['current_nonself_artifacts'];assert enc(inv_deriv)==before['source-derivation.json']
 for name,raw in candidate.items():r.write('profile-source/candidate/'+name,raw)
 forward=r.patch(before,candidate);reverse=r.patch(candidate,before);assert r.apply(before,forward)==candidate and r.apply(candidate,reverse)==before
 r.write('profile-source/DIFF.patch',forward.encode());r.write('profile-source/REVERSE.patch',reverse.encode())
 manifest={'schema':1,'status':'SOURCE_ONLY_CURRENT_PROFILE_NINE_RAW_ENV_LIBRARY_DELTA_AND_EXACT_INITIALIZER_SEAMS','source_sha':r.HEAD,'parent':r.PARENT,'before':{name:r.pin(raw) for name,raw in before.items()},'candidate':{name:r.pin(raw) for name,raw in candidate.items()},'all9_full_forward_reverse_replayed':True,'all_original_Python_functions_exact':True,'original_three_whole_artifacts_exact':True,'all_other_interface_and_derivation_bytes_reconstructed':True,'historical_tenth_subtree_exact':True,'current_seven':newrows,'composite_source_authority':composite,'initializer_seams':r.pointer(r.OUT/'profile-source/INITIALIZER-SEAMS.json'),'final_initializer':r.pin(candidate['ms4-initialize.php']),'all_five_SHA_edges_and_interface_size_closed':True,'nonself_count':8,'runtime':'NOT RUN'}
 r.put('profile-source/MANIFEST.json',manifest)
 r.put('profile-source/PINS.json',{'schema':1,'status':'SOURCE_ONLY_NOT_EXECUTED','candidate':manifest['candidate'],'MANIFEST':r.pin(r.read(r.OUT/'profile-source/MANIFEST.json')),'before_profile_PINS':r.pointer(BASE/'profile-source/PINS.json'),'DIFF':r.pin(forward.encode()),'REVERSE':r.pin(reverse.encode()),'INITIALIZER_SEAMS':r.pin(r.read(r.OUT/'profile-source/INITIALIZER-SEAMS.json')),'final_initializer':manifest['final_initializer']})
 return candidate,r.pointer(r.OUT/'profile-source/PINS.json'),composite
