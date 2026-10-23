"""Append only the fixed original37 parent/public seal definitions; targets are never executed."""
from pathlib import Path
import ast, difflib, hashlib, json, os, re, time
OUT=Path(__file__).resolve().parent
BASE=OUT.parent
INPUTS=[]
STAT_KEYS=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
helper_path=BASE/'nonempty-history-pre37-fixed-preparation-components-source-r1/author.py'
helper_raw=helper_path.read_bytes();tree=ast.parse(helper_raw)
names={'sha','nine','read','new','js','php_functions','apply_unified'}
defs=[n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name in names]
assert {n.name for n in defs}==names
exec(compile(ast.Module(body=defs,type_ignores=[]),str(helper_path),'exec'))
assert read(helper_path)==helper_raw
stage=BASE/'nonempty-history-normal-close-components-source-r1'
pins=read(stage/'PINS.json');assert len(pins)==3692 and sha(pins)=='e6af704e0c585f98d55d27c24cf9580ba409f52d5f406c245595c30b2f4c2087'
review=read(BASE/'nonempty-history-normal-close-independent-r1/REPORT.json')
assert len(review)==999 and sha(review)=='c90e38cf397d0cd8de7e1c933bfa86ea41ae55809d4414390c113491220e9509'
assert json.loads(review)['findings']==[]
overlay_before=json.loads(read(stage/'SOURCE-OVERLAY.json'))
oldpin=overlay_before['artifacts']['legacy-history-producer.php']
before=read(oldpin['source']);assert len(before)==oldpin['bytes'] and sha(before)==oldpin['sha256']
fragment=read(OUT/'parent.fragment.php').replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')+b'\r\n'
assert before[-169:].lstrip().startswith(b'if (realpath(') and b'NHE_PRODUCER_NOT_IMPLEMENTED' in before[-169:]
after=before[:-169]+fragment+before[-169:]
assert after[:len(before)-169]+after[-169:]==before
old=php_functions(before);candidate=php_functions(after)
assert len(old)==162 and len(candidate)==165
assert all(candidate[name]['raw']==row['raw'] for name,row in old.items())
added={'nhep_normal_history_seal','nhep_normal_history_publish','nhep_normal_history_main'}
assert set(candidate)-set(old)==added
seal=candidate['nhep_normal_history_seal']['raw'];publish=candidate['nhep_normal_history_publish']['raw'];main=candidate['nhep_normal_history_main']['raw']
ordered=[b'$accepted37 = $finish()',b'$identities = nhep_history_identities(',b'$close = nhep_normal_close_writers(',b'$normalClose = nhep_normal_close_public_view(',b'$seal = [']
assert [seal.index(x) for x in ordered]==sorted(seal.index(x) for x in ordered)
assert seal.count(b'$finish()')==1 and b"$reflection->getFileName() === __FILE__" in seal
assert b"'container_id' => $qualified['container_id']" in seal
assert b"/run/audit/evidence/audit-legacy-history-producer-source-binding.json" in seal and b"/run/audit/evidence/audit-legacy-history-producer-container-created.json" in seal
assert b"'expected_m4m' => $close['expected_m4m'], 'seal_redis_time' => $close['redis']['time']" in seal
assert b"$frames[] = ['sequence' => $sequence, 'step' => $step, 'complete_original_frame_verified' => true]" in seal
assert b"$normalClose" in seal and b"'private_native_streams'" not in seal and b"'raw' =>" not in seal
assert b"fopen($path, 'x+b')" in publish and b"stream_get_contents($stream)" in publish and b"feof($stream)" in publish
assert b"$root . '/legacy-history-producer-result.json'" in publish
assert b'nhe_file($path, $pin) === $raw' in publish
sequence=[b"foreach (['nhep_normal_startup_plan', 'nhep_normal_startup_accept']",b"require_once __DIR__ . '/legacy-app.php'",b'$qualified = nhe_qualify(',b'nhep_parent_write($qualified)',b'$observer = audit_db()',b'$prepared = nhep_pre37_fixed_preparation(',b'$startupPlan = nhep_normal_startup_plan(',b"nhep_child_start($qualified, $children, 'channel')",b'$channel = nhep_channel_open(',b'nhep_child_subscriber($children, $channel)',b"nhep_child_start($qualified, $children, 'http')",b'$startupExpected = nhep_normal_startup_accept(',b'$startupActual === $startupExpected',b'$oracles = nhep_auth_observers(',b'$http = nhe_history_http_run(',b'$seal = nhep_normal_history_seal(',b'nhep_normal_history_publish($qualified, $seal)']
assert [main.index(x) for x in sequence]==sorted(main.index(x) for x in sequence)
assert all(main.count(x)==1 for x in sequence)
assert 'nhep_normal_startup_plan' not in candidate and 'nhep_normal_startup_accept' not in candidate
assert b"$failure = $error" in main and b'if ($failure !== null)' in main
assert b'proc_terminate(' not in fragment and b'SIGKILL' not in fragment
fwd=''.join(difflib.unified_diff(before.decode().splitlines(keepends=True),after.decode().splitlines(keepends=True),fromfile='before/legacy-history-producer.php',tofile='candidate/legacy-history-producer.php')).encode()
rev=''.join(difflib.unified_diff(after.decode().splitlines(keepends=True),before.decode().splitlines(keepends=True),fromfile='candidate/legacy-history-producer.php',tofile='before/legacy-history-producer.php')).encode()
assert apply_unified(before,fwd)==after and apply_unified(after,rev)==before
for rel,raw in [('before/legacy-history-producer.php',before),('candidate/legacy-history-producer.php',after),('diff/legacy-history-producer.php.diff',fwd),('reverse/legacy-history-producer.php.diff',rev)]:new(rel,raw)
overlay={}
for name,previous in overlay_before['artifacts'].items():
 path=(OUT/'candidate'/name) if name=='legacy-history-producer.php' else Path(previous['source'])
 raw=read(path)
 if name!='legacy-history-producer.php':assert len(raw)==previous['bytes'] and sha(raw)==previous['sha256']
 overlay[name]={'source':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)}
assert len(overlay)==13 and len([name for name in overlay if name.endswith('.php')])==9
controls={path:overlay[path.rsplit('/',1)[-1]] for path in overlay_before['bundle_control_files']}
current=overlay_before['unchanged_Root_owned_current_control_paths'];assert len(controls)==8 and len(current)==3
new('SOURCE-OVERLAY.json',js({'schema':1,'scope':'ACTUAL_THIRTEEN_COMPONENT_IDENTITIES_NOT_FUTURE_SOURCE_BINDING','artifacts':overlay,'actual_artifact_count':13,'actual_PHP_component_count':9,'bundle_control_files':controls,'unchanged_Root_owned_current_control_paths':current,'exact_control_count':11,'future_adoption':'No future Source/freeze/Run is generated. Root final current controls, startup effects, Host admission/current reader remain unbound; original direct guard stays.'}))
design=read(OUT/'DESIGN-TASKS.md');contract=read(stage/'PUBLIC-SEAL-CONTRACT.md')
host=read(BASE/'nonempty-history-host-handoff-components-source-r1/candidate/ms4-host.py')
assert len(host)==112277 and sha(host)=='ea1765feb55b01aabc81cffda84eddbe020824c62a157290d1a08a8dd4c22094'
hosttree=ast.parse(host)
assert any(isinstance(n,ast.FunctionDef) and n.name=='legacy_producer_seal' for n in hosttree.body)
closure={}
for name in ['nhep_parent_qualified','nhep_parent_write','nhep_pre37_fixed_preparation','nhep_child_start','nhep_channel_open','nhep_child_subscriber','nhep_observation','nhep_auth_observers','nhep_history_identities','nhep_normal_close_writers','nhep_normal_close_public_view','nhep_migration_snapshot_expected']:
 row=candidate[name];closure[name]={'offset':row['offset'],'bytes':len(row['raw']),'sha256':sha(row['raw'])}
new('TRANSFORM.json',js({'schema':1,'scope':'FINITE_APPEND_ONLY_FIXED_PARENT_SOURCE','insertion_offset':len(before)-169,'fragment':{'bytes':len(fragment),'sha256':sha(fragment)},'before':{'bytes':len(before),'sha256':sha(before)},'candidate':{'bytes':len(after),'sha256':sha(after)},'standard_forward_reverse_exact':True,'original169_tail_unchanged':True}))
new('SOURCE-INPUTS.json',js({'schema':1,'windows':INPUTS,'all_read_windows_closed':True,'ended_ns':time.time_ns()}))
new('MANIFEST.json',js({'schema':1,'scope':'ORIGINAL_FIXED37_PARENT_SEAL_SOURCE_ONLY','lineage':{'normal_close_PINS':{'bytes':len(pins),'sha256':sha(pins)},'normal_close_independent':{'bytes':len(review),'sha256':sha(review)},'adopted_exact13_contract':{'bytes':len(contract),'sha256':sha(contract)}},'candidate':{'bytes':len(after),'sha256':sha(after)},'original_definitions_unchanged':162,'new_complete_definitions':sorted(added),'named_actual_existing_call_closure':closure,'unbound_required_startup_functions':['nhep_normal_startup_plan','nhep_normal_startup_accept'],'actual13_artifacts9PHP11controls':True,'target_execution':'NOT RUN'}))
new('CHECKS.json',js({'schema':1,'status':'PASS_FINITE_APPEND_FIXED_ORIGINAL37_PARENT_AND_EXACT13_SOURCE_ONLY','all162_old_definitions_and_original169_tail_preserved':True,'standard_forward_reverse_exact':True,'named_full_existing_call_closure':True,'one_original_http37_one_original_finish_not_an_ack':True,'same_original_children_and_reference_subscriber':True,'original_complete_close_and_private_receipts_before_public_safety_view':True,'exact13_unique_adopted_schema_and_true_Source_binding_paths':True,'create_only_fixed_safe_file_complete_handle_readback':True,'startup_source_functions_missing_refuse_before_App_parent_write_DB_and_children':True,'actual13_artifacts9PHP11controls_no_future_source_pins':True,'not_run':['PHP syntax','target components','Host','Docker','App/PDO','Redis','HTTP','37 steps','four CLI','acceptance'],'still_required':['independent complete startup effects Source and admission','Host full exact13 review/adoption and final Source bindings','original four CLI genuine baseline correlation','current reader and true-enabled acceptance','full normal37 execution and final close/zero','user business acceptance'],'all_read_windows_closed':True,'ended_ns':time.time_ns()}))
members={}
for path in sorted(OUT.rglob('*')):
 if path.is_file() and path.name!='PINS.json':
  raw=path.read_bytes();members[path.relative_to(OUT).as_posix()]={'bytes':len(raw),'sha256':sha(raw)}
new('PINS.json',js({'schema':1,'scope':'ORIGINAL_FIXED37_PARENT_APPEND_SOURCE_ONLY_PINS','members':members}))
print(json.dumps({'status':'FIXED37_PARENT_SOURCE_ONLY_CANDIDATE_CREATED','candidate':{'bytes':len(after),'sha256':sha(after)},'PINS':{'bytes':(OUT/'PINS.json').stat().st_size,'sha256':sha((OUT/'PINS.json').read_bytes())},'target_execution':'NOT RUN'},ensure_ascii=False))
