"""Full nine-file normal175f profile refresh; no target/PHP execution."""
import ast,copy,json,re
from pathlib import Path
import source_reader as r
OUT,B,OLD=r.OUT,r.B,r.OLD
ORG=B.parent/'project-organization-20261023'
ENTRY='help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'
def defs(raw):
 text=raw.decode();return {n.name:ast.get_source_segment(text,n)for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def main():
 pp=r.obj(OLD/'PINS.json');assert r.pin(r.read(OLD/'PINS.json'))=={'bytes':189060,'sha256':'0c2d0c6e68199e0c2b291fdaa764babb986b8d5ebe512903fbf06b6f7fea3650'}
 members=[n.removeprefix('validation/actual-profile/')for n in pp['source_members18']if n.startswith('validation/actual-profile/')];assert len(members)==9
 before={n:r.fixed(OLD/'validation/actual-profile'/n,pp['files']['validation/actual-profile/'+n])for n in members}
 stage=r.obj(OUT/'CURRENT-SOURCE-AUTHORITY.json');assert stage['source_sha']==r.HEAD and stage['parent']==r.PARENT and len(stage['files'])==4
 packet=ORG/'current28dc-form-quota-wire-fix-source-r1';pinpath=packet/'PINS.json';pins=r.obj(pinpath)
 assert r.pin(r.read(pinpath))=={'bytes':1767,'sha256':'2210f6e368d96bbbe00309b8fc37290c3208c3e1d98a973edcccf58ba8daa637'}
 for name,pin in pins['files'].items():r.fixed(packet/name,pin)
 raw=r.fixed(packet/'candidate/ms4-customer-form-normal.php',pins['files']['candidate/ms4-customer-form-normal.php']);oldraw=r.fixed(packet/'before/ms4-customer-form-normal.php',pins['files']['before/ms4-customer-form-normal.php'])
 assert r.read(OUT/'git-blobs/after'/ENTRY)==raw and r.read(OUT/'git-blobs/before'/ENTRY)==oldraw
 peerpath=ORG/'current28dc-form-quota-wire-fix-independent-r1/FINAL-PINS.json';peer=r.obj(peerpath)
 assert r.pin(r.read(peerpath))=={'bytes':1522,'sha256':'88dac3ec886ee68565bfcba7536f5f16005db2906c1ca21f6107da06f595d94a'}
 for pin in peer['files'].values():r.fixed(pin['path'],pin)
 assert r.obj(peer['files']['REPORT.json']['path'])['findings']==[]
 syntax=ORG/'current28dc-form-quota-wire-fix-adoption-r1/actual-syntax-r1/RESULT.json';sv=r.obj(syntax)
 assert r.pin(r.read(syntax))=={'bytes':4155,'sha256':'16dc72db1628732abb7827e430f16ab538b2a725097d9b7431419e1c73ca775d'}
 assert sv['source']==r.pointer(packet/'candidate/ms4-customer-form-normal.php') and sv['status']=='PASS_EXACT_CANDIDATE_PHP82_SYNTAX_ONLY'
 assert len(sv['operations'])==3 and all(o['native_exit']==o['wait_returncode']==0 for o in sv['operations']) and sv['container_before_after_empty']is sv['Source_unchanged']is True and sv['App_DB_Redis_business_executed']is False
 for op in sv['operations']:
  for k in ['stdout','stderr']:r.fixed(op[k]['path'],op[k])
 proof={'schema':1,'scope':'ACTUAL175F_SINGLE_FORM_HELPER_CANONICAL_QUOTA_DECIMAL_WIRE_NOT_BUSINESS_ACCEPTANCE','source_sha':r.HEAD,'parent':r.PARENT,'actual_source_step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'candidate_bundle':r.pointer(pinpath),'independent_review':r.pointer(peerpath),'helper_actual_syntax':r.pointer(syntax),'candidate_file':r.pointer(packet/'candidate/ms4-customer-form-normal.php'),'before_file':r.pointer(packet/'before/ms4-customer-form-normal.php'),'changed_CU7_path':ENTRY,'unchanged_other_PHP_members':6,'only_original_two_quota_wire_seams':True,'all_other_original_assertions_and_business':'PRESERVED','new_M_S_A0_business':'NOT RUN'}
 r.put('QUOTA-WIRE-DELTA.json',proof);delta=r.pointer(OUT/'QUOTA-WIRE-DELTA.json')
 interface=json.loads(before['interface-v1.json']);oldseven=copy.deepcopy(interface['customer_form_current_profile']['php_sources']);seven=[{'path':row['path'],**r.pin(raw)}if row['path']==ENTRY else row for row in oldseven]
 assert len(seven)==7 and sum(a!=b for a,b in zip(oldseven,seven))==1
 interface['customer_form_current_profile']['php_sources']=seven
 interface['customer_form_current_profile']['quota_wire_current_source_successor']={'schema':1,'scope':'ACTUAL175F_FORM_HELPER_DESCRIPTOR_ONLY_OVER_COMPLETE_CURRENT28DC_PROFILE','source_sha':r.HEAD,'predecessor_source_sha':r.PARENT,'source_delta':delta,'unchanged_other_PHP_members':6,'normal_business':'NOT RUN'}
 after=dict(before);after['interface-v1.json']=(json.dumps(interface,ensure_ascii=False,indent=2)+'\n').encode()
 init=before['ms4-initialize.php'].decode();a=next(x for x in oldseven if x['path']==ENTRY);z=next(x for x in seven if x['path']==ENTRY)
 old="['path' => '"+ENTRY+"', 'bytes' => "+str(a['bytes'])+", 'sha256' => '"+a['sha256']+"']"
 new="['path' => '"+ENTRY+"', 'bytes' => "+str(z['bytes'])+", 'sha256' => '"+z['sha256']+"']"
 init=r.once(init,old,new);assert r.once(init,new,old).encode()==before['ms4-initialize.php'];after['ms4-initialize.php']=init.encode()
 tuples=[{'before':old,'candidate':new,'kind':'SINGLE_CU7_FORM_HELPER_ONLY'}]
 for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
  assert list(r.constants(before[name].decode())['CUSTOMER_FORM_SOURCE_FILES'])==oldseven
  after[name]=r.const(after[name].decode(),'CUSTOMER_FORM_SOURCE_FILES',tuple(seven)).encode()
 def constant(name,key,value):after[name]=r.const(after[name].decode(),key,value).encode()
 constant('ms4-host.py','INTERFACE_SHA256',r.pin(after['interface-v1.json'])['sha256'])
 constant('normal-close-ms4-fulfillment.py','HOST_SHA256',r.pin(after['ms4-host.py'])['sha256'])
 for key,value in [('INTERFACE_SHA256',r.pin(after['interface-v1.json'])['sha256']),('INTERFACE_BYTES',len(after['interface-v1.json'])),('HOST_SHA256',r.pin(after['ms4-host.py'])['sha256']),('CLOSE_SHA256',r.pin(after['normal-close-ms4-fulfillment.py'])['sha256'])]:constant('verify-zero-ms4-fulfillment.py',key,value)
 pub=r.obj(OLD/'validation/PROFILE-PUBLICATION.json');order=[row['path']for row in pub['selected9']];assert set(order)==set(members)
 deriv=json.loads(before['source-derivation.json'])
 deriv['actual175f_form_quota_wire_profile_refresh']={'schema':1,'scope':'COMPLETE_NINE_PROFILE_SINGLE_CU7_FORM_HELPER_LITERAL_CHANGE_AFTER_EIGHTEENTH_SOURCE','predecessor_publication':r.pointer(OLD/'validation/PROFILE-PUBLICATION.json'),'source_delta':delta,'current_seven_PHP':seven,'initializer_tuple_changes':tuples,'all_other_initializer_bytes':'PRESERVED','all_Python_function_bodies':'PRESERVED','new_initializer_actual_syntax':'PENDING_ROOT_ONE_FULL_PHP82_LINT','new_business':'NOT RUN'}
 deriv['current_nonself_artifacts']=[{'path':n,**r.pin(after[n])}for n in order if n!='source-derivation.json'];after['source-derivation.json']=(json.dumps(deriv,ensure_ascii=False,indent=2)+'\n').encode()
 changed=sorted(n for n in members if before[n]!=after[n]);assert changed==sorted(['interface-v1.json','ms4-host.py','ms4-initialize.php','normal-close-ms4-fulfillment.py','source-derivation.json','verify-zero-ms4-fulfillment.py'])
 counts={}
 for n in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:assert defs(before[n])==defs(after[n]);counts[n]=len(defs(before[n]))
 assert sum(counts.values())==336
 for n in members:r.write('profile-source/before/'+n,before[n]);r.write('profile-source/candidate/'+n,after[n]);r.write('validation/actual-profile/'+n,after[n])
 forward,reverse=r.patch(before,after),r.patch(after,before);assert r.apply(before,forward)==after and r.apply(after,reverse)==before
 r.write('profile-source/DIFF.patch',forward.encode());r.write('profile-source/REVERSE.patch',reverse.encode())
 manifest={'schema':1,'scope':'COMPLETE_NINE_PROFILE_SINGLE_CURRENT_FORM_HELPER_TUPLE_AND_FULL_HASH_DAG_SOURCE_ONLY','predecessor':r.pointer(OLD/'PINS.json'),'source_delta':delta,'before':{n:r.pin(b)for n,b in before.items()},'candidate':{n:r.pin(b)for n,b in after.items()},'selected9':[{'path':n,**r.pin(after[n])}for n in order],'old_seven_php':oldseven,'current_seven_php':seven,'initializer_tuples':tuples,'changed':changed,'current_eight_nonself':deriv['current_nonself_artifacts'],'all_Python_definitions_whole_identical':counts,'new_initializer_syntax':'PENDING_ROOT_PHP82_LINT','target':'NOT RUN'}
 r.put('profile-source/MANIFEST.json',manifest)
 names=['MANIFEST.json','DIFF.patch','REVERSE.patch']+['before/'+n for n in members]+['candidate/'+n for n in members]
 r.put('profile-source/PINS.json',{'schema':1,'scope':'IMMUTABLE175F_COMPLETE_NINE_PROFILE_PRE_SYNTAX_SOURCE_ONLY','members':{n:r.pin(r.read(OUT/'profile-source'/n))for n in names},'candidate':manifest['candidate']})
 r.write('initializer-review/before.php',before['ms4-initialize.php']);r.write('initializer-review/candidate.php',after['ms4-initialize.php'])
 r.write('initializer-review/DIFF.patch',r.patch({'ms4-initialize.php':before['ms4-initialize.php']},{'ms4-initialize.php':after['ms4-initialize.php']}).encode());r.write('initializer-review/REVERSE.patch',r.patch({'ms4-initialize.php':after['ms4-initialize.php']},{'ms4-initialize.php':before['ms4-initialize.php']}).encode())
 r.put('initializer-review/MANIFEST.json',{'schema':1,'scope':'COMPLETE_INITIALIZER_ONE_FORM_HELPER_TUPLE_ONLY','before':r.pointer(OUT/'initializer-review/before.php'),'candidate':r.pointer(OUT/'initializer-review/candidate.php'),'profile_candidate':r.pointer(OUT/'profile-source/candidate/ms4-initialize.php'),'current_form_helper':{'path':ENTRY,**r.pin(raw)},'tuple_changes':tuples,'whole_inverse_original':True,'full_profile_PINS':r.pointer(OUT/'profile-source/PINS.json'),'actual_source_step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'actual_syntax':'PENDING_ROOT','initializer_or_business_execution':'NOT RUN'})
 leafnames=['MANIFEST.json','before.php','candidate.php','DIFF.patch','REVERSE.patch']
 r.put('initializer-review/PINS.json',{'schema':1,'scope':'IMMUTABLE_SINGLE_FULL_INITIALIZER_LITERAL_SOURCE_ONLY','members':{n:r.pin(r.read(OUT/'initializer-review'/n))for n in leafnames},'actual_syntax':'NOT RUN'})
 print(json.dumps({'initializer_review_PINS':r.pointer(OUT/'initializer-review/PINS.json'),'initializer':r.pointer(OUT/'initializer-review/candidate.php'),'profile_PINS':r.pointer(OUT/'profile-source/PINS.json'),'changed_profile_files':changed,'tuple_changes':len(tuples),'new_PHP_syntax':'PENDING_ROOT'}))
if __name__=='__main__':main()
