"""Finite six-member profile Source successor; no target parsing execution or PHP."""
import ast,copy,json,time
from pathlib import Path
import source_reader as r
OUT=r.OUT;B=r.B;OLD=r.OLD
FORM='help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'

def main():
    oldpins=r.obj(OLD/'PINS.json');assert r.pin(r.read(OLD/'PINS.json'))=={'bytes':65050,'sha256':'8790b416b343e2e0d97e0bade11ab4b46f6534ac7e352edd91198d31ec1d69e2'}
    members=[x.removeprefix('validation/actual-profile/') for x in oldpins['source_members18'] if x.startswith('validation/actual-profile/')];assert len(members)==9
    before={name:r.fixed(OLD/'validation/actual-profile'/name,oldpins['files']['validation/actual-profile/'+name]) for name in members}
    prefix=B/'customer-form-assertion-prefix-source-r2';p=r.obj(prefix/'PINS.json')
    a=r.fixed(prefix/'before/ms4-customer-form-normal.php',p['members']['before/ms4-customer-form-normal.php']);b=r.fixed(prefix/'candidate/ms4-customer-form-normal.php',p['members']['candidate/ms4-customer-form-normal.php'])
    transform=r.obj(prefix/'TRANSFORM.json');assert transform['repository_path']==FORM and len(transform['changes'])==60
    expected=bytearray();last=0
    for c in transform['changes']:
        offset=c['offset'];old=c['old'].encode();new=c['new'].encode()
        assert offset>=last and a[offset:offset+len(old)]==old and old.startswith(b"'M4CF_") and new==b"'M4C_FORM_"+old[len(b"'M4CF_"):]
        expected.extend(a[last:offset]);expected.extend(new);last=offset+len(old)
    expected.extend(a[last:]);assert bytes(expected)==b and len(b)-len(a)==240
    step=r.obj(OUT/'SIXTEENTH-SOURCE-AUTHORITY.json');assert next(x for x in step['files'] if x['path']==FORM)=={'path':FORM,'before':r.pin(a),'after':r.pin(b)}
    proof={'schema':1,'scope':'FORM_ASSERTION_LABEL_ONLY_SIXTEENTH_CURRENT_881_SOURCE_LAYER','repository_path':FORM,'before':r.pointer(prefix/'before/ms4-customer-form-normal.php'),'candidate':r.pointer(prefix/'candidate/ms4-customer-form-normal.php'),
       'source_PINS':r.pointer(prefix/'PINS.json'),'transform':r.pointer(prefix/'TRANSFORM.json'),'Source_peer':r.pointer(B/'customer-form-assertion-prefix-independent-r1/REPORT.json'),
       'actual_helper_syntax':r.pointer(B/'customer-form-assertion-prefix-php-syntax-actual-r1/RESULT.json'),'actual_adoption':r.pointer(OUT/'SIXTEENTH-SOURCE-AUTHORITY.json'),
       'changes':60,'all_other_bytes_identical':True,'business':'NOT RUN'}
    r.put('FORM-DELTA.json',proof);proofptr=r.pointer(OUT/'FORM-DELTA.json')
    interface=json.loads(before['interface-v1.json']);oldform=copy.deepcopy(interface['customer_form_current_profile']['php_sources']);newform=copy.deepcopy(oldform)
    assert len(oldform)==7 and oldform[0]=={'path':FORM,**r.pin(a)};newform[0]={'path':FORM,**r.pin(b)}
    interface['customer_form_current_profile']['php_sources']=newform
    interface['customer_form_current_profile']['assertion_prefix_successor']={'scope':'CURRENT_881_FORM_LABEL_ONLY_SOURCE_NOT_BUSINESS_ACCEPTANCE','source_sha':r.HEAD,'predecessor_source_sha':r.PARENT,'source_delta':proofptr,'unchanged_other_six_php_members':6}
    after=dict(before);after['interface-v1.json']=(json.dumps(interface,ensure_ascii=False,indent=2)+'\n').encode()
    oldtuple="['path' => '"+FORM+"', 'bytes' => "+str(len(a))+", 'sha256' => '"+r.pin(a)['sha256']+"']"
    newtuple="['path' => '"+FORM+"', 'bytes' => "+str(len(b))+", 'sha256' => '"+r.pin(b)['sha256']+"']"
    after['ms4-initialize.php']=r.once(before['ms4-initialize.php'].decode(),oldtuple,newtuple).encode()
    assert after['ms4-initialize.php'].replace(newtuple.encode(),oldtuple.encode(),1)==before['ms4-initialize.php']
    for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
        s=before[name].decode();values=r.constants(s);rows=copy.deepcopy(values['CUSTOMER_FORM_SOURCE_FILES']);assert list(rows)==oldform
        s=r.const(s,'CUSTOMER_FORM_SOURCE_FILES',tuple(newform));after[name]=s.encode()
    def constant(name,symbol,value):after[name]=r.const(after[name].decode(),symbol,value).encode()
    constant('ms4-host.py','INTERFACE_SHA256',r.pin(after['interface-v1.json'])['sha256'])
    constant('normal-close-ms4-fulfillment.py','HOST_SHA256',r.pin(after['ms4-host.py'])['sha256'])
    constant('verify-zero-ms4-fulfillment.py','INTERFACE_SHA256',r.pin(after['interface-v1.json'])['sha256'])
    constant('verify-zero-ms4-fulfillment.py','INTERFACE_BYTES',len(after['interface-v1.json']))
    constant('verify-zero-ms4-fulfillment.py','HOST_SHA256',r.pin(after['ms4-host.py'])['sha256'])
    constant('verify-zero-ms4-fulfillment.py','CLOSE_SHA256',r.pin(after['normal-close-ms4-fulfillment.py'])['sha256'])
    pub=r.obj(OLD/'validation/PROFILE-PUBLICATION.json');order=[x['path'] for x in pub['selected9']]
    derivation=json.loads(before['source-derivation.json']);derivation['form_assertion_prefix_current_profile_refresh']={'schema':1,'scope':'CURRENT_881_SINGLE_HELPER_LAYER_AFTER_COMPLETE_HISTORICAL_PROFILE9','predecessor_publication':r.pointer(OLD/'validation/PROFILE-PUBLICATION.json'),'form_delta':proofptr,'current_seven_php_sources':newform,'initializer_tuple_changes':1,'all_other_PHP_bytes':'PRESERVED','all_Python_function_bodies':'PRESERVED','business':'NOT RUN'}
    derivation['current_nonself_artifacts']=[{'path':name,**r.pin(after[name])} for name in order if name!='source-derivation.json']
    after['source-derivation.json']=(json.dumps(derivation,ensure_ascii=False,indent=2)+'\n').encode()
    assert sorted(name for name in members if before[name]!=after[name])==sorted(['ms4-initialize.php','ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py','source-derivation.json','interface-v1.json'])
    for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
        def defs(raw):
            text=raw.decode();return {n.name:ast.get_source_segment(text,n) for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
        assert defs(before[name])==defs(after[name])
    for name in members:
        r.write('profile-source/before/'+name,before[name]);r.write('profile-source/candidate/'+name,after[name]);r.write('validation/actual-profile/'+name,after[name])
    forward=r.patch(before,after);reverse=r.patch(after,before);assert r.apply(before,forward)==after and r.apply(after,reverse)==before
    r.write('profile-source/DIFF.patch',forward.encode());r.write('profile-source/REVERSE.patch',reverse.encode())
    manifest={'schema':1,'scope':'FULL_NINE_PROFILE_SIX_EXACT_SOURCE_EDITS_NOT_RUNTIME','predecessor':r.pointer(OLD/'PINS.json'),'form_delta':proofptr,'before':{n:r.pin(before[n]) for n in members},'candidate':{n:r.pin(after[n]) for n in members},'selected9':[{'path':n,**r.pin(after[n])} for n in order],
      'old_seven_php':oldform,'current_seven_php':newform,'initializer_tuple':{'before':oldtuple,'candidate':newtuple},'changed':sorted(n for n in members if before[n]!=after[n]),'current_eight_nonself':derivation['current_nonself_artifacts'],'new_initializer_syntax':'PENDING_ROOT_PHP82_LINT','target':'NOT RUN'}
    r.put('profile-source/MANIFEST.json',manifest)
    names=['MANIFEST.json','DIFF.patch','REVERSE.patch']+['before/'+n for n in members]+['candidate/'+n for n in members]
    r.put('profile-source/PINS.json',{'schema':1,'scope':'IMMUTABLE_CURRENT881_FULL_PROFILE_SOURCE_ONLY','members':{n:r.pin(r.read(OUT/'profile-source'/n)) for n in names},'candidate':manifest['candidate']})
    print(json.dumps({'profile_PINS':r.pointer(OUT/'profile-source/PINS.json'),'initializer':r.pointer(OUT/'profile-source/candidate/ms4-initialize.php'),'six_changes':manifest['changed'],'PHP_syntax':'PENDING_ROOT'},ensure_ascii=False))
if __name__=='__main__':main()
