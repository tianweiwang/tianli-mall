"""Whole nine-file normal profile refresh; only artifact generation, never PHP/Host execution."""
import ast,copy,json,re
from pathlib import Path
import source_reader as r
OUT,B,OLD=r.OUT,r.B,r.OLD
ORG=B.parent/'project-organization-20261023'
ENTRY='help/audit-regressions/isolated/ms4-customer-http-normal.php'
PHP4=[ENTRY,'help/audit-regressions/isolated/lib/ms4-ui-prepare-oracle.php','help/audit-regressions/isolated/lib/ms4-customer-h5-normal.php','help/audit-regressions/isolated/ms4-customer-h5.php']
def defs(raw):
 text=raw.decode();return {n.name:ast.get_source_segment(text,n)for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def main():
 pp=r.obj(OLD/'PINS.json');assert r.pin(r.read(OLD/'PINS.json'))=={'bytes':138143,'sha256':'2b7702c1d6aa6459c4044f17fa7af213fe92d958d5a855ffd1a98b1ce76ae4fa'}
 members=[n.removeprefix('validation/actual-profile/')for n in pp['source_members18']if n.startswith('validation/actual-profile/')];assert len(members)==9
 before={n:r.fixed(OLD/'validation/actual-profile'/n,pp['files']['validation/actual-profile/'+n])for n in members}
 stage=r.obj(OUT/'CURRENT-SOURCE-AUTHORITY.json');assert stage['source_sha']==r.HEAD and stage['parent']==r.PARENT and len(stage['files'])==8
 normal=ORG/'normal-public-upload-root-preparation-r1';h5=ORG/'h5-current-resource-contract-preparation-r1'
 np=r.obj(normal/'TWO-FILE-PINS.json');hp=r.obj(h5/'PHP-CANDIDATE-PINS.json')
 for root,doc,exact in [(normal,np,{'bytes':2945,'sha256':'58b837044bc480e0ed92a46ab8d65d52c5538ee5a64c2b1af2c0aff5713269ec'}),(h5,hp,{'bytes':2792,'sha256':'12e298c9c307a37174fd7bafcd10f07d8daf19aa1f3f4b85c5616515ca510ee1'})]:
  assert r.pin(r.read(root/('TWO-FILE-PINS.json'if root==normal else'PHP-CANDIDATE-PINS.json')))==exact
  assert type(doc['members'])is dict
  for name,pin in doc['members'].items():r.fixed(root/name,pin)
 paths={PHP4[0]:(normal,'candidate/ms4-customer-http-normal.php','before/ms4-customer-http-normal.php'),PHP4[1]:(normal,'candidate/lib/ms4-ui-prepare-oracle.php','before/lib/ms4-ui-prepare-oracle.php'),PHP4[2]:(h5,'candidate/'+PHP4[2],'before/'+PHP4[2]),PHP4[3]:(h5,'candidate/'+PHP4[3],'before/'+PHP4[3])}
 code={};code_before={};candidate_pointers={};old_pointers={}
 for name,(root,candidate,old)in paths.items():
  packet=np if root==normal else hp;raw=r.fixed(root/candidate,packet['members'][candidate]);oldraw=r.fixed(root/old,packet['members'][old])
  assert r.read(OUT/'git-blobs/after'/name)==raw and r.read(OUT/'git-blobs/before'/name)==oldraw
  code[name]=raw;code_before[name]=oldraw;candidate_pointers[name]=r.pointer(root/candidate);old_pointers[name]=r.pointer(root/old)
 npeer=ORG/'normal-public-upload-root-independent-r1/FINAL-PINS.json';hpeer=ORG/'h5-current-resource-php-independent-r1/FINAL-PINS.json'
 assert r.pin(r.read(npeer))=={'bytes':1778,'sha256':'48e0d43180f92a93b7a240e65a20d46fd93ca295f2cfc097ce8a4744a5285b8c'}
 assert r.pin(r.read(hpeer))=={'bytes':614,'sha256':'df32cef39a5a870c497657d2f289fefb0517e8c65dc66583b7a8c353283af8f0'}
 nreview=r.obj(npeer);hreview=r.obj(hpeer)
 for key in ['report','inputs','reader','rg_evidence']:r.fixed(nreview[key]['path'],nreview[key])
 for name,pin in hreview['members'].items():r.fixed(hpeer.parent/name,pin)
 syntax=ORG/'reviewed-four-php-candidates-syntax-actual-r1/RESULT.json';rootsyntax=ORG/'reviewed-four-php-syntax-root-actual-readback-r1/REPORT.json'
 assert r.pin(r.read(syntax))=={'bytes':13625,'sha256':'fc19c057d24a5acfa233b5daf7546cd9e2fff4055bc871b398545e0cd51e5a1a'}
 assert r.pin(r.read(rootsyntax))=={'bytes':858,'sha256':'96b405d89fe383be388cb4a63490a402298ec9a8e962afe04f7c023f3987096f'}
 proof={'schema':1,'scope':'ACTUAL28DC_FOUR_TRACKED_PHP_UPLOAD_ROOT_AND_RESOURCE_SOURCE_REVISIONS_NOT_FIRST_THROWABLE_OR_BUSINESS_ACCEPTANCE',
  'source_sha':r.HEAD,'parent':r.PARENT,'actual_source_step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),
  'normal_bundle':r.pointer(normal/'TWO-FILE-PINS.json'),'H5_bundle':r.pointer(h5/'PHP-CANDIDATE-PINS.json'),
  'normal_independent_review':r.pointer(npeer),'H5_independent_review':r.pointer(hpeer),
  'four_PHP_actual_syntax':r.pointer(syntax),'Root_actual_syntax_readback':r.pointer(rootsyntax),
  'candidate_files':candidate_pointers,'before_files':old_pointers,
  'normal_CU7_only_changed_path':ENTRY,'paid_donor_unchanged':True,'all_other_original_requests_assertions_and_business':'PRESERVED',
  'missing_public_uploads':'VERIFIED_SOURCE_PREREQUISITE_CONFLICT_NOT_ACTUAL_FIRST_THROWABLE','new_M_S_A0_business':'NOT RUN'}
 r.put('UPLOAD-ROOT-DELTA.json',proof);delta=r.pointer(OUT/'UPLOAD-ROOT-DELTA.json')
 interface=json.loads(before['interface-v1.json']);oldseven=copy.deepcopy(interface['customer_form_current_profile']['php_sources'])
 seven=[{'path':row['path'],**r.pin(code[ENTRY])}if row['path']==ENTRY else row for row in oldseven]
 assert len(seven)==7 and sum(a!=b for a,b in zip(oldseven,seven))==1
 interface['customer_form_current_profile']['php_sources']=seven
 interface['customer_form_current_profile']['owned_upload_root_current_source_successor']={'schema':1,'scope':'ACTUAL28DC_NORMAL_ENTRY_DESCRIPTOR_ONLY_OVER_FULL_DIAGNOSTIC_PROFILE',
  'source_sha':r.HEAD,'predecessor_source_sha':r.PARENT,'source_delta':delta,'unchanged_other_PHP_members':6,
  'complete_four_PHP_source_revisions':[{ 'path':name,**r.pin(code[name])}for name in PHP4],'normal_business':'NOT RUN'}
 after=dict(before);after['interface-v1.json']=(json.dumps(interface,ensure_ascii=False,indent=2)+'\n').encode()
 init=before['ms4-initialize.php'].decode();a=next(x for x in oldseven if x['path']==ENTRY);z=next(x for x in seven if x['path']==ENTRY)
 old="['path' => '"+ENTRY+"', 'bytes' => "+str(a['bytes'])+", 'sha256' => '"+a['sha256']+"']"
 new="['path' => '"+ENTRY+"', 'bytes' => "+str(z['bytes'])+", 'sha256' => '"+z['sha256']+"']"
 init=r.once(init,old,new);assert r.once(init,new,old).encode()==before['ms4-initialize.php'];after['ms4-initialize.php']=init.encode()
 tuples=[{'before':old,'candidate':new,'kind':'SINGLE_CU7_NORMAL_ENTRY_ONLY'}]
 for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
  assert list(r.constants(before[name].decode())['CUSTOMER_FORM_SOURCE_FILES'])==oldseven
  after[name]=r.const(before[name].decode(),'CUSTOMER_FORM_SOURCE_FILES',tuple(seven)).encode()
 def constant(name,key,value):after[name]=r.const(after[name].decode(),key,value).encode()
 constant('ms4-host.py','INTERFACE_SHA256',r.pin(after['interface-v1.json'])['sha256'])
 constant('normal-close-ms4-fulfillment.py','HOST_SHA256',r.pin(after['ms4-host.py'])['sha256'])
 for key,value in [('INTERFACE_SHA256',r.pin(after['interface-v1.json'])['sha256']),('INTERFACE_BYTES',len(after['interface-v1.json'])),('HOST_SHA256',r.pin(after['ms4-host.py'])['sha256']),('CLOSE_SHA256',r.pin(after['normal-close-ms4-fulfillment.py'])['sha256'])]:constant('verify-zero-ms4-fulfillment.py',key,value)
 pub=r.obj(OLD/'validation/PROFILE-PUBLICATION.json');order=[row['path']for row in pub['selected9']];assert set(order)==set(members)
 deriv=json.loads(before['source-derivation.json'])
 deriv['actual28dc_owned_upload_root_profile_refresh']={'schema':1,'scope':'COMPLETE_NINE_PROFILE_SINGLE_CU7_LITERAL_CHANGE_AFTER_SEVENTEENTH_SOURCE',
  'predecessor_publication':r.pointer(OLD/'validation/PROFILE-PUBLICATION.json'),'source_delta':delta,'current_seven_PHP':seven,'initializer_tuple_changes':tuples,
  'all_other_initializer_bytes':'PRESERVED','all_Python_function_bodies':'PRESERVED','new_initializer_actual_syntax':'PENDING_ROOT_ONE_FULL_PHP82_LINT','new_business':'NOT RUN'}
 deriv['current_nonself_artifacts']=[{'path':n,**r.pin(after[n])}for n in order if n!='source-derivation.json']
 after['source-derivation.json']=(json.dumps(deriv,ensure_ascii=False,indent=2)+'\n').encode()
 changed=sorted(n for n in members if before[n]!=after[n]);assert changed==sorted(['interface-v1.json','ms4-host.py','ms4-initialize.php','normal-close-ms4-fulfillment.py','source-derivation.json','verify-zero-ms4-fulfillment.py'])
 counts={}
 for n in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:assert defs(before[n])==defs(after[n]);counts[n]=len(defs(before[n]))
 assert sum(counts.values())==336
 for n in members:r.write('profile-source/before/'+n,before[n]);r.write('profile-source/candidate/'+n,after[n]);r.write('validation/actual-profile/'+n,after[n])
 forward,reverse=r.patch(before,after),r.patch(after,before);assert r.apply(before,forward)==after and r.apply(after,reverse)==before
 r.write('profile-source/DIFF.patch',forward.encode());r.write('profile-source/REVERSE.patch',reverse.encode())
 manifest={'schema':1,'scope':'COMPLETE_NINE_PROFILE_SINGLE_CURRENT_ENTRY_TUPLE_AND_FULL_HASH_DAG_SOURCE_ONLY','predecessor':r.pointer(OLD/'PINS.json'),
  'source_delta':delta,'before':{n:r.pin(b)for n,b in before.items()},'candidate':{n:r.pin(b)for n,b in after.items()},'selected9':[{'path':n,**r.pin(after[n])}for n in order],
  'old_seven_php':oldseven,'current_seven_php':seven,'initializer_tuples':tuples,'changed':changed,'current_eight_nonself':deriv['current_nonself_artifacts'],
  'all_Python_definitions_whole_identical':counts,'new_initializer_syntax':'PENDING_ROOT_PHP82_LINT','target':'NOT RUN'}
 r.put('profile-source/MANIFEST.json',manifest)
 names=['MANIFEST.json','DIFF.patch','REVERSE.patch']+['before/'+n for n in members]+['candidate/'+n for n in members]
 r.put('profile-source/PINS.json',{'schema':1,'scope':'IMMUTABLE28DC_COMPLETE_NINE_PROFILE_PRE_SYNTAX_SOURCE_ONLY','members':{n:r.pin(r.read(OUT/'profile-source'/n))for n in names},'candidate':manifest['candidate']})
 beforeone={'ms4-initialize.php':before['ms4-initialize.php']};afterone={'ms4-initialize.php':after['ms4-initialize.php']}
 f,iv=r.patch(beforeone,afterone),r.patch(afterone,beforeone);assert r.apply(beforeone,f)==afterone and r.apply(afterone,iv)==beforeone
 r.write('initializer-review/before.php',beforeone['ms4-initialize.php']);r.write('initializer-review/candidate.php',afterone['ms4-initialize.php'])
 r.write('initializer-review/FORWARD.patch',f.encode());r.write('initializer-review/REVERSE.patch',iv.encode())
 r.put('initializer-review/REPORT.json',{'schema':1,'scope':'ONE_COMPLETE_INITIALIZER_SINGLE_CU7_LITERAL_SOURCE_REVIEW_NOT_EXECUTED','source_sha':r.HEAD,'parent':r.PARENT,
  'before':r.pointer(OUT/'initializer-review/before.php'),'candidate':r.pointer(OUT/'initializer-review/candidate.php'),
  'profile_candidate':r.pointer(OUT/'profile-source/candidate/ms4-initialize.php'),'current_entry':{'path':ENTRY,**r.pin(code[ENTRY])},'tuple_changes':tuples,
  'whole_single_tuple_inverse_exact':True,'ordinary_forward_reverse_exact':True,'paid_donor_tuple_unchanged':True,'all_other_initializer_bytes_unchanged':True,
  'full_profile_PINS':r.pointer(OUT/'profile-source/PINS.json'),'actual_source_step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'actual_syntax':'PENDING_ROOT','initializer_or_business_execution':'NOT RUN'})
 leafnames=['before.php','candidate.php','FORWARD.patch','REVERSE.patch','REPORT.json']
 r.put('initializer-review/PINS.json',{'schema':1,'scope':'IMMUTABLE_SINGLE_FULL_INITIALIZER_LITERAL_SOURCE_ONLY','members':{n:r.pin(r.read(OUT/'initializer-review'/n))for n in leafnames},'actual_syntax':'NOT RUN'})
 print(json.dumps({'initializer_review_PINS':r.pointer(OUT/'initializer-review/PINS.json'),'initializer':r.pointer(OUT/'initializer-review/candidate.php'),
  'profile_PINS':r.pointer(OUT/'profile-source/PINS.json'),'changed_profile_files':changed,'tuple_changes':len(tuples),'new_PHP_syntax':'PENDING_ROOT'}))
if __name__=='__main__':main()
