"""Compose only the named Host Source seams. No candidate/target import or execution."""
from pathlib import Path
import ast,os,json,hashlib,time,stat,difflib,re
OUT=Path(__file__).resolve().parent;B=OUT.parent
BEFORE=B/'nonempty-history-private-prepare-components-source-r2/candidate/ms4-host.py'
CONTRACT=B/'nonempty-history-normal-close-components-source-r1/PUBLIC-SEAL-CONTRACT.md'
PLAN=B/'nonempty-history-upgrade-design-r1/outer37-finish-and-four-cli-source-design-r2.md'
WINS=[]
def s9(s):return {k:getattr(s,'st_'+k) for k in ['dev','ino','mode','nlink','uid','gid','size','mtime_ns','ctime_ns']}
def read(p):
 p=Path(p);started=time.time_ns();a=s9(p.lstat());assert stat.S_ISREG(a['mode']) and not p.is_symlink()
 with p.open('rb') as f:
  b=s9(os.fstat(f.fileno()));raw=f.read();assert f.read(1)==b'';c=s9(os.fstat(f.fileno()))
 d=s9(p.lstat());assert a==d and b==c and len(raw)==a['size'] and all(a[k]==b[k] for k in a if k!='ctime_ns')
 WINS.append({'path':p.as_posix(),'started_ns':started,'ended_ns':time.time_ns(),'named_before':a,'fd_open':b,'fd_after':c,'named_after':d,'EOF':True,'closed':True});return raw
def pin(p,raw=None):
 p=Path(p);raw=read(p) if raw is None else raw
 return {'path':p.as_posix(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def write(p,raw):
 p=Path(p);p.parent.mkdir(parents=True,exist_ok=True);assert not p.exists(),p
 with p.open('xb') as f:f.write(raw)
 return pin(p,raw)
def jw(p,v):return write(p,(json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode())
def once(raw,old,new):assert raw.count(old)==1,(old[:100],raw.count(old));return raw.replace(old,new,1)
def functions(raw):
 ls=raw.decode().splitlines(keepends=True)
 return {n.name:''.join(ls[n.lineno-1:n.end_lineno]).encode() for n in ast.parse(raw.decode()).body if isinstance(n,ast.FunctionDef)}
def apply(raw,patch):
 s=raw.decode().splitlines(keepends=True);ls=patch.decode().splitlines(keepends=True);out=[];pos=0;i=2
 while i<len(ls):
  m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@\n',ls[i]);assert m
  at=int(m[1])-1;assert at>=pos;out.extend(s[pos:at]);pos=at;i+=1
  while i<len(ls) and not ls[i].startswith('@@ '):
   line=ls[i]
   if line[0] in ' -':assert s[pos]==line[1:];pos+=1
   if line[0] in ' +':out.append(line[1:])
   i+=1
 out.extend(s[pos:]);return ''.join(out).encode()
before=read(BEFORE);contract=read(CONTRACT);plan=read(PLAN);fragment=read(OUT/'handoff.fragment.py');design=read(OUT/'DESIGN-TASKS.md')
assert len(before)==74958 and hashlib.sha256(before).hexdigest()=='ba005f51fea2ab4ffdb7a8c88bdfd48ad4a23e39f446bbb440501d646a119e6a'
assert '顶层exact13键'.encode() in contract and b'Root' in contract and b'legacy-history-producer-result.json' in contract
steps=['admin_login','admin_identity','platform_self_merchant_review','platform_self_merchant_accept_orders','customer_login','customer_identity','platform_system_balance_add','funded_balance_read','refund_family_cart','refund_family_confirm','refund_family_create','refund_family_unpaid_read','refund_family_pay','refund_family_paid_read','review_family_cart','review_family_confirm','review_family_create','review_family_unpaid_read','review_family_pay','review_family_paid_read','two_paid_balance_read','unshipped_refund_apply','owned_refund_identity','platform_refund_approve','owned_refund_completed_read','owned_refunded_root_read','refunded_balance_read','platform_manual_courier','owned_shipped_read','customer_receipt','owned_received_read','customer_purchased_item_comment','platform_pending_review_identity','platform_review_approve','platform_review_reply','platform_approved_replied_read','owned_commented_completed_read']
leaves=['manifest.json','network.json','containers.jsonl','volumes.txt','volumes.jsonl','actual-run-context.json','preparation-source-binding.json',
 'evidence/legacy-history-producer-created.json','evidence/legacy-history-producer-terminal.json','evidence/audit-legacy-history-producer-container-created.json','evidence/audit-legacy-history-producer-source-binding.json','evidence/legacy-history-producer-result.json','evidence/register-legacy-history-producer-created.json','evidence/register-legacy-history-producer-terminal.json','evidence/register-legacy-history-producer-result.json','evidence/legacy-producer-create-compose-native.json','evidence/register-legacy-history-producer-create-compose-native.json']
bindings={name:None for name in ['producer','library','artifacts','control_files','startup','reader','consumer']}
constants=("LEGACY_HANDOFF_BINDINGS = "+repr(bindings)+"\nLEGACY_HTTP_STEPS = "+repr(steps)+"\nLEGACY_PRODUCER_PUBLIC = "+repr(leaves)+"\n").encode()
candidate=once(before,b"DRIVER = [",constants+b"DRIVER = [")
candidate=once(candidate,b'\ndef prepare(binding_file, run_root):',b'\n'+fragment+b'\n\ndef prepare(binding_file, run_root):')
old_archive=b"    expect(phase in ['before-legacy-files', 'legacy-files-complete'], 'FINITE_HISTORY_ARCHIVE_PHASE')"
new_archive=b"    expect(phase in ['before-legacy-files', 'legacy-files-complete', 'legacy-producer-complete'], 'FINITE_HISTORY_ARCHIVE_PHASE')"
candidate=once(candidate,old_archive,new_archive)
old_else=b"    else:\n        leaves += ['evidence/legacy-files-bootstrap-created.json'"
new_else=b"    elif phase == 'legacy-files-complete':\n        leaves += ['evidence/legacy-files-bootstrap-created.json'"
candidate=once(candidate,old_else,new_else)
anchor=b"    parent = root / 'evidence/nonempty-history-phases'"
extra=b"    else:\n        leaves += LEGACY_PRODUCER_PUBLIC[7:] + ['evidence/' + path.name for path in sorted((root / 'evidence').glob('native-*.json'))]\n"
candidate=once(candidate,anchor,extra+anchor)
old_driver=b'''    if name == 'driver-create':
        raise RuntimeError('NONEMPTY_HISTORY_PRODUCER_HANDOFF_NOT_IMPLEMENTED')
        expect(not (evidence / 'driver-created.json').exists(), 'DRIVER_ALREADY_CREATED')
        old = load(evidence / 'initializer-created.json'); terminal = inspect(context, old['id'], 'initializer-terminal-required')
        result = load(evidence / 'initializer-result.json')
        expect(terminal['status'] == 'exited' and terminal['exit_code'] == 0 and result['status'] == 'VERIFIED_MS4_PREREQUISITES_ONLY'
               and result['native_exit'] == 0 and result['private_cleanup_verified'] is True and len(result['children']) == 6
               and result['binding']['container_id'] == old['id'], 'TRUE_INITIALIZER_COMPLETE')
        remove_finished(context, old['id'], 'audit-ms4-initialize', 'initializer', base | {old['id']: 'audit-ms4-initialize'})
        collect(context, base)
'''
new_driver=b'''    if name == 'driver-create':
        completed = legacy_producer_complete(context, False)
        expect(not (evidence / 'driver-created.json').exists(), 'DRIVER_ALREADY_CREATED')
        history_archive(context, 'legacy-producer-complete')
        cid = completed['container_id']
        remove_finished(context, cid, 'audit-legacy-history-producer', 'legacy-history-producer', base | {cid: 'audit-legacy-history-producer'})
        archived = legacy_producer_complete(context, True)
        expect(legacy_seal_equal(completed['seal'], archived['seal']), 'PRODUCER_ORIGINAL_COMPLETE_SEAL_HELD_THROUGH_NORMAL_RETIREMENT')
        collect(context, base)
'''
candidate=once(candidate,old_driver,new_driver)
authorize=b"        return helper(context, 'authorize-cleanup', 'authorize-cleanup', base | {cid: 'audit-ms4-migration'})"
candidate=once(candidate,authorize,b"        legacy_four_cli_handoff(context, result)\n"+authorize)
ast.parse(candidate.decode())
rollback=once(candidate,b"        legacy_four_cli_handoff(context, result)\n"+authorize,authorize)
rollback=once(rollback,new_driver,old_driver);rollback=once(rollback,extra+anchor,anchor);rollback=once(rollback,new_else,old_else);rollback=once(rollback,new_archive,old_archive)
rollback=once(rollback,b'\n'+fragment+b'\n\ndef prepare(binding_file, run_root):',b'\ndef prepare(binding_file, run_root):')
rollback=once(rollback,constants+b'DRIVER = [',b'DRIVER = [');assert rollback==before
oldf=functions(before);newf=functions(candidate);added=set(newf)-set(oldf)
assert set(oldf)-set(newf)==set() and {name for name in oldf if oldf[name]!=newf[name]}=={'history_archive','stage'}
assert added==set(functions(fragment)) and len(added)==6
assert oldf['prepare']==newf['prepare'] and oldf['main']==newf['main'] and b"raise RuntimeError('NONEMPTY_HISTORY_RUNTIME_NOT_IMPLEMENTED')" in newf['main']
assert b"initializer-terminal-required" not in newf['stage'] and b'legacy_four_cli_handoff(context, result)' in newf['stage']
assert b"'UNBOUND_LEGACY_HANDOFF_'" in newf['legacy_handoff_source'] and all(x is None for x in bindings.values())
assert b"legacy_seal_equal(result['snapshots']['before'], seal['expected_m4m'])" in newf['legacy_four_cli_handoff']
assert newf['stage'].index(b'completed = legacy_producer_complete(context, False)')<newf['stage'].index(b"history_archive(context, 'legacy-producer-complete')")<newf['stage'].index(b"remove_finished(context, cid, 'audit-legacy-history-producer'")<newf['stage'].index(b'archived = legacy_producer_complete(context, True)')
write(OUT/'before/ms4-host.py',before);cp=write(OUT/'candidate/ms4-host.py',candidate)
forward=''.join(difflib.unified_diff(before.decode().splitlines(keepends=True),candidate.decode().splitlines(keepends=True),fromfile='before/ms4-host.py',tofile='candidate/ms4-host.py',n=4)).encode()
reverse=''.join(difflib.unified_diff(candidate.decode().splitlines(keepends=True),before.decode().splitlines(keepends=True),fromfile='candidate/ms4-host.py',tofile='before/ms4-host.py',n=4)).encode()
assert apply(before,forward)==candidate and apply(candidate,reverse)==before
fp=write(OUT/'DIFF.patch',forward);rp=write(OUT/'REVERSE.patch',reverse)
for path,raw in [(BEFORE,before),(CONTRACT,contract),(PLAN,plan),(OUT/'handoff.fragment.py',fragment),(OUT/'DESIGN-TASKS.md',design)]:assert read(path)==raw
closed=time.time_ns()
manifest={'schema':1,'scope':'OUTER37_ORIGINAL_PRODUCER_TO_FOUR_CLI_HOST_COMPONENT_SOURCE_ONLY','before':pin(BEFORE,before),'candidate':cp,
 'adopted_public_contract':pin(CONTRACT,contract),'existing_outer37_plan':pin(PLAN,plan),'fragment':pin(OUT/'handoff.fragment.py',fragment),
 'whole_complete_forward_reverse':True,'complete_inverse_to_original':True,'changed_original_functions':['history_archive','stage'],
 'unchanged_original_functions':{name:hashlib.sha256(value).hexdigest() for name,value in oldf.items() if name not in ['history_archive','stage']},
 'new_functions':{name:hashlib.sha256(newf[name]).hexdigest() for name in sorted(added)},'original_artifacts':13,'original_PHP_artifacts':9,'original_controls':11,
 'nonself_hard_binding_artifacts':12,'all_final_Source_bindings':bindings,'prepare_main_guards':'ORIGINAL_BYTES_REMAIN_REJECTING',
 'driver_create':'full producer qualify/archive/original sameCID ordinary retire then original driver create/register/bind',
 'four_CLI_join':'type and order strict snapshots.before equals independent full accepted37 m4m seal',
 'stale_initializer_second_retirement':'REMOVED_FROM_UNREACHABLE_DRIVER_CREATE_BRANCH',
 'native_stream_privacy':'Original Host native summaries remain summaries. Private producer streams/hash stay RAM. Only adopted safe public views consumed.',
 'target_PHP_Host_App_Docker_resources':'NOT RUN','runtime_eligible':False,'actual_business_or_Run_result':'NOT CREATED',
 'forward':fp,'reverse':rp,'finite_windows':len(WINS),'all_EOF_closed':True,'closed_ns':closed}
jw(OUT/'MANIFEST.json',manifest);jw(OUT/'FINITE-READS.json',{'schema':1,'windows':WINS,'all_closed':True,'closed_ns':closed})
jw(OUT/'PINS.json',{'schema':1,'scope':'SOURCE_COMPONENT_ONLY_UNBOUND_NO_EXECUTION','members':[pin(p) for p in sorted(OUT.rglob('*')) if p.is_file() and p.name!='PINS.json']})
print(json.dumps({'candidate':cp,'PINS':pin(OUT/'PINS.json'),'manifest':pin(OUT/'MANIFEST.json'),'new_functions':len(added),'original_functions_unchanged':len(oldf)-2,'closed_ns':closed},ensure_ascii=True))
