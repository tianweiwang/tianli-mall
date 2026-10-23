"""Finite complete profile successor for actual normal diagnostic source; source only."""
import ast, copy, json
from pathlib import Path
import source_reader as r

OUT, B, OLD = r.OUT, r.B, r.OLD
ORG = B.parent / 'project-organization-20261023'
FORM = 'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'
PAID = 'help/audit-regressions/isolated/ms5-paid-consumer-normal.php'
COLLECTOR = 'help/audit-regressions/orchestration/collect_paid_failure.py'

def main():
    oldpins = r.obj(OLD/'PINS.json')
    assert r.pin(r.read(OLD/'PINS.json')) == {'bytes':83304,'sha256':'7eb48d6201a073984aa00f88ead5d38aeb5cee216de58b73cf4735c6e4aed6e1'}
    members = [name.removeprefix('validation/actual-profile/') for name in oldpins['source_members18'] if name.startswith('validation/actual-profile/')]
    before = {name:r.fixed(OLD/'validation/actual-profile'/name,oldpins['files']['validation/actual-profile/'+name]) for name in members}
    delta_source = ORG/'current881-normal-http-response-diagnostic-preparation-r2'
    dp = r.obj(delta_source/'PINS.json')
    assert r.pin(r.read(delta_source/'PINS.json')) == {'bytes':3012,'sha256':'0a9523d4002cb1d2c787b32fcac36b6224ec7f83dafdb0e3e68235cf06d1c7f0'}
    stage = r.obj(OUT/'SEVENTEENTH-SOURCE-AUTHORITY.json')
    code = {}
    for name in [FORM,PAID,COLLECTOR]:
        raw = r.fixed(delta_source/'candidate'/name,dp['files']['candidate/'+name])
        assert r.read(OUT/'git-blobs/after'/name) == raw
        code[name] = raw
    peer = ORG/'normal-http-response-diagnostic-three-file-independent-r1/REPORT.json'
    assert r.pin(r.read(peer)) == {'bytes':75495,'sha256':'ac08b63384f786a53fee2cf0ca25718738ffb30054c25d6da8e9dbd732cf3683'}
    syntax = ORG/'normal-http-response-diagnostic-two-php-syntax-actual-r1/RESULT.json'
    root_syntax = ORG/'normal-http-response-diagnostic-two-php-syntax-root-r1/REPORT.json'
    assert r.pin(r.read(root_syntax)) == {'bytes':1272,'sha256':'579646ffe126519d73e22838f4d45cd7e345d8b9031bf05b68752e8217f7d1e2'}
    assert r.obj(syntax)['container_native_exit'] == 0
    proof = {'schema':1,'scope':'ACTUAL_SEVENTEENTH_THREE_CODE_DIAGNOSTIC_LAYER_NOT_ROOT_CAUSE_REPAIR',
        'Source_PINS':r.pointer(delta_source/'PINS.json'),'Source_peer':r.pointer(peer),
        'two_PHP_syntax':r.pointer(syntax),'Root_two_PHP_syntax':r.pointer(root_syntax),
        'actual_adoption':r.pointer(OUT/'SEVENTEENTH-SOURCE-AUTHORITY.json'),
        'candidate_files':{name:r.pointer(delta_source/'candidate'/name) for name in code},
        'old_PHP_files':{name:r.pointer(delta_source/'before'/name) for name in [FORM,PAID]},
        'forward':r.pointer(delta_source/'FORWARD.patch'),'inverse':r.pointer(delta_source/'INVERSE.patch'),
        'original_assertions_preserved':406,'new_collector_actual_main':'NOT RUN','new_six_field_runtime':'NOT RUN',
        'HTTP_root_cause':'UNKNOWN','business':'NOT RUN'}
    r.put('DIAGNOSTIC-DELTA.json',proof)
    delta_ptr = r.pointer(OUT/'DIAGNOSTIC-DELTA.json')
    interface = json.loads(before['interface-v1.json'])
    old_seven = copy.deepcopy(interface['customer_form_current_profile']['php_sources'])
    seven = [{'path':row['path'],**r.pin(code[row['path']])} if row['path'] in [FORM,PAID] else row for row in old_seven]
    assert len(seven) == 7 and sum(a!=b for a,b in zip(old_seven,seven)) == 2
    interface['customer_form_current_profile']['php_sources'] = seven
    interface['customer_form_current_profile']['normal_http_diagnostic_successor'] = {
        'scope':'ACTUAL_NORMAL_HTTP_DIAGNOSTIC_SOURCE_NOT_BUSINESS_ACCEPTANCE','source_sha':r.HEAD,
        'predecessor_source_sha':r.PARENT,'source_delta':delta_ptr,'unchanged_other_PHP_members':5,
        'collector':{'path':COLLECTOR,**r.pin(code[COLLECTOR])},'new_diagnostic_runtime':'NOT RUN'}
    after = dict(before)
    after['interface-v1.json'] = (json.dumps(interface,ensure_ascii=False,indent=2)+'\n').encode()
    initializer = before['ms4-initialize.php'].decode()
    tuples = []
    for name in [FORM,PAID]:
        a = next(row for row in old_seven if row['path']==name)
        z = next(row for row in seven if row['path']==name)
        old = "['path' => '"+name+"', 'bytes' => "+str(a['bytes'])+", 'sha256' => '"+a['sha256']+"']"
        new = "['path' => '"+name+"', 'bytes' => "+str(z['bytes'])+", 'sha256' => '"+z['sha256']+"']"
        initializer = r.once(initializer,old,new)
        tuples.append({'before':old,'candidate':new,'kind':'CU7'})
    a = next(row for row in old_seven if row['path']==PAID)
    z = next(row for row in seven if row['path']==PAID)
    old = '['+str(a['bytes'])+", '"+a['sha256']+"']"
    new = '['+str(z['bytes'])+", '"+z['sha256']+"']"
    initializer = r.once(initializer,old,new)
    tuples.append({'before':old,'candidate':new,'kind':'STANDALONE_PAID_DONOR'})
    restored = initializer
    for row in reversed(tuples):restored = r.once(restored,row['candidate'],row['before'])
    assert restored.encode() == before['ms4-initialize.php']
    after['ms4-initialize.php'] = initializer.encode()
    for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
        text = before[name].decode()
        assert list(r.constants(text)['CUSTOMER_FORM_SOURCE_FILES']) == old_seven
        for key,value in [('CUSTOMER_FORM_SOURCE_FILES',tuple(seven)),('PAID_ENTRY_BYTES',z['bytes']),('PAID_ENTRY_SHA256',z['sha256'])]:
            text = r.const(text,key,value)
        after[name] = text.encode()
    def constant(name,key,value):after[name] = r.const(after[name].decode(),key,value).encode()
    constant('ms4-host.py','INTERFACE_SHA256',r.pin(after['interface-v1.json'])['sha256'])
    constant('normal-close-ms4-fulfillment.py','HOST_SHA256',r.pin(after['ms4-host.py'])['sha256'])
    for key,value in [('INTERFACE_SHA256',r.pin(after['interface-v1.json'])['sha256']),('INTERFACE_BYTES',len(after['interface-v1.json'])),
        ('HOST_SHA256',r.pin(after['ms4-host.py'])['sha256']),('CLOSE_SHA256',r.pin(after['normal-close-ms4-fulfillment.py'])['sha256'])]:
        constant('verify-zero-ms4-fulfillment.py',key,value)
    publication = r.obj(OLD/'validation/PROFILE-PUBLICATION.json')
    order = [row['path'] for row in publication['selected9']]
    derivation = json.loads(before['source-derivation.json'])
    derivation['normal_http_response_diagnostic_profile_refresh'] = {'schema':1,
        'scope':'SEVENTEENTH_TWO_PHP_DIAGNOSTIC_LAYER_AFTER_COMPLETE_HISTORICAL_PROFILE9',
        'predecessor_publication':r.pointer(OLD/'validation/PROFILE-PUBLICATION.json'),'diagnostic_delta':delta_ptr,
        'current_seven_php_sources':seven,'initializer_tuple_changes':tuples,'all_other_initializer_bytes':'PRESERVED',
        'all_Python_function_bodies':'PRESERVED','business':'NOT RUN'}
    derivation['current_nonself_artifacts'] = [{'path':name,**r.pin(after[name])} for name in order if name!='source-derivation.json']
    after['source-derivation.json'] = (json.dumps(derivation,ensure_ascii=False,indent=2)+'\n').encode()
    changed = sorted(name for name in members if before[name]!=after[name])
    assert changed == sorted(['interface-v1.json','ms4-host.py','ms4-initialize.php','normal-close-ms4-fulfillment.py','source-derivation.json','verify-zero-ms4-fulfillment.py'])
    for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:
        def defs(raw):
            text=raw.decode();return {node.name:ast.get_source_segment(text,node) for node in ast.parse(text).body if isinstance(node,(ast.FunctionDef,ast.ClassDef))}
        assert defs(before[name])==defs(after[name])
    for name in members:
        r.write('profile-source/before/'+name,before[name]);r.write('profile-source/candidate/'+name,after[name]);r.write('validation/actual-profile/'+name,after[name])
    forward,reverse = r.patch(before,after),r.patch(after,before)
    assert r.apply(before,forward)==after and r.apply(after,reverse)==before
    r.write('profile-source/DIFF.patch',forward.encode());r.write('profile-source/REVERSE.patch',reverse.encode())
    manifest = {'schema':1,'scope':'FULL_NINE_PROFILE_SIX_EXACT_DIAGNOSTIC_SOURCE_EDITS_NOT_RUNTIME',
        'predecessor':r.pointer(OLD/'PINS.json'),'diagnostic_delta':delta_ptr,'before':{name:r.pin(raw) for name,raw in before.items()},
        'candidate':{name:r.pin(raw) for name,raw in after.items()},'selected9':[{'path':name,**r.pin(after[name])} for name in order],
        'old_seven_php':old_seven,'current_seven_php':seven,'initializer_tuples':tuples,'changed':changed,
        'current_eight_nonself':derivation['current_nonself_artifacts'],'new_initializer_syntax':'PENDING_ROOT_PHP82_LINT','target':'NOT RUN'}
    r.put('profile-source/MANIFEST.json',manifest)
    names=['MANIFEST.json','DIFF.patch','REVERSE.patch']+['before/'+name for name in members]+['candidate/'+name for name in members]
    r.put('profile-source/PINS.json',{'schema':1,'scope':'IMMUTABLE_NORMAL_HTTP_DIAGNOSTIC_FULL_PROFILE_SOURCE_ONLY',
        'members':{name:r.pin(r.read(OUT/'profile-source'/name)) for name in names},'candidate':manifest['candidate']})
    print(json.dumps({'profile_PINS':r.pointer(OUT/'profile-source/PINS.json'),'initializer':r.pointer(OUT/'profile-source/candidate/ms4-initialize.php'),
        'initializer_tuple_changes':len(tuples),'six_changes':changed,'PHP_syntax':'PENDING_ROOT'},ensure_ascii=False))

if __name__=='__main__':main()
