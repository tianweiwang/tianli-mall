"""Finite original startup Source assembly; never import or execute a target."""
from pathlib import Path
import ast, difflib, hashlib, json, os, re, time
OUT=Path(__file__).resolve().parent
BASE=OUT.parent
INPUTS=[]
STAT_KEYS=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
helper_path=BASE/'nonempty-history-pre37-fixed-preparation-components-source-r1/author.py'
helper_raw=helper_path.read_bytes(); tree=ast.parse(helper_raw)
helper_names={'sha','nine','read','new','js','php_functions','apply_unified'}
helpers=[node for node in tree.body if isinstance(node,ast.FunctionDef) and node.name in helper_names]
assert {node.name for node in helpers}==helper_names
exec(compile(ast.Module(body=helpers,type_ignores=[]),str(helper_path),'exec'))
assert read(helper_path)==helper_raw
old=BASE/'nonempty-history-normal-parent-components-source-r2'
pins=read(old/'PINS.json'); assert len(pins)==1681 and sha(pins)=='c822fb9206724d38455534bce4363f5be82e11274959d2d4282cee1dcbf3ff7d'
review=read(BASE/'nonempty-history-normal-parent-independent-r2/REPORT.json')
assert len(review)==1347 and sha(review)=='c56dccef0d05d261b00c6c342d3b2b1e41058275bed6d18a07b25281ebdde802'
source_map=read(BASE/'nonempty-history-normal-startup-source-map-r1/SOURCE-MAP.json')
assert len(source_map)==25565 and sha(source_map)=='fe067631668ec6cefe76b2467b910d128f1f30fef60ab7487b490cb6cb4bf695'
public_pins=read(BASE/'nonempty-history-normal-startup-public-source-r1/PINS.json')
assert len(public_pins)==926 and sha(public_pins)=='3b703f70daf26a2dda3c064bb3ecc25f0df935b6cc45c6ab04b16b88f15be2ed'
public_c=read(BASE/'nonempty-history-normal-startup-public-source-r1/upstream/sapi/cli/php_cli_server.c')
assert len(public_c)==85378 and sha(public_c)=='6843266e3a7458e89c74c6d133ec21ae715da8c0708e718965db65fa560d4e63'
before=read(old/'candidate/legacy-history-producer.php')
assert len(before)==1052008 and sha(before)=='6a01674bdabfdfb2ff40a7df915642889373f05ee948aeae204429f5e84be48a'
design=read(OUT/'DESIGN-TASKS.md'); fragment=read(OUT/'startup.fragment.php')
assert b'\r' not in fragment
def crlf(text):return text.replace('\n','\r\n').encode()
seams=[
 ('same_source_dependencies',
  "        // Named real Source gaps, before loading App, writing parent or connecting\n        // resources. They are intentionally absent in this not-yet-admitted Source.\n        foreach (['nhep_normal_startup_plan', 'nhep_normal_startup_accept'] as $dependency) {",
  "        // Complete fixed startup definitions must come from this same Source.\n        // Original direct/Host admission remains refused until its full binding.\n        foreach (['nhep_normal_startup_plan', 'nhep_normal_startup_ready', 'nhep_normal_startup_accept'] as $dependency) {"),
 ('independent_before_observer_inputs',
  '        $startupPlan = nhep_normal_startup_plan($qualified, $prepared);',
  '        $startupPlan = nhep_normal_startup_plan($qualified, $observer, $redis, $prepared);'),
 ('complete_first_line_before_whole_observation',
  "        nhep_child_start($qualified, $children, 'http');\n        $startupPrivate = [];",
  "        nhep_child_start($qualified, $children, 'http');\n        $startupReady = nhep_normal_startup_ready($qualified, $children, $startupPlan);\n        $startupPrivate = [];"),
 ('original_full_observation_to_independent_accept',
  '            $startupPlan, $startupActual, $startupPrivate);',
  '            $startupPlan, $startupReady, $startupActual, $startupPrivate);'),
 ('private_receipt_lifetime',
  '        unset($prepared, $startupPlan, $startupPrivate, $startupActual, $startupExpected, $oracles, $channel);',
  '        unset($prepared, $startupPlan, $startupReady, $startupPrivate, $startupActual, $startupExpected, $oracles, $channel);')]
after=before; transforms=[]
for name,old_text,new_text in seams:
 old_bytes,new_bytes=crlf(old_text),crlf(new_text)
 assert after.count(old_bytes)==1,(name,'unique')
 transforms.append({'name':name,'old':old_bytes.decode(),'replacement':new_bytes.decode()})
 after=after.replace(old_bytes,new_bytes)
tail=before[-169:]; assert b'NHE_PRODUCER_NOT_IMPLEMENTED' in tail and after[-169:]==tail
addition=fragment.replace(b'\n',b'\r\n')
after=after[:-169]+addition+after[-169:]
restored=after[:-169]
assert restored.endswith(addition)
restored=restored[:-len(addition)]+tail
for item in reversed(transforms):
 replacement=item['replacement'].encode(); original=item['old'].encode()
 assert restored.count(replacement)==1; restored=restored.replace(replacement,original)
assert restored==before
old_defs=php_functions(before);new_defs=php_functions(after)
new_names={'nhep_normal_startup_ui','nhep_normal_startup_plan','nhep_normal_startup_ready','nhep_normal_startup_accept'}
assert len(old_defs)==165 and len(new_defs)==169 and set(new_defs)-set(old_defs)==new_names
assert {name for name in old_defs if old_defs[name]['raw']!=new_defs[name]['raw']}=={'nhep_normal_history_main'}
assert all(old_defs[name]['raw']==new_defs[name]['raw'] for name in old_defs if name!='nhep_normal_history_main')
assert after[-169:]==tail
main=new_defs['nhep_normal_history_main']['raw']
order=[b'$qualified = nhe_qualify(',b"require_once '/run/nonempty/legacy-normal-config.php'",b"require_once '/run/nonempty/legacy-actors.php'",b"require_once '/run/nonempty/legacy-history-http.php'",b'nhe_includes($qualified)',b'nhep_parent_write($qualified)',b'$observer = audit_db()',b'$prepared = nhep_pre37_fixed_preparation(',b'$startupPlan = nhep_normal_startup_plan(',b"nhep_child_start($qualified, $children, 'channel')",b'$channel = nhep_channel_open(',b'nhep_child_subscriber(',b"nhep_child_start($qualified, $children, 'http')",b'$startupReady = nhep_normal_startup_ready(',b'$startupPrivate = []; $startupActual = nhep_observation(',b'$startupExpected = nhep_normal_startup_accept(',b'$startupActual === $startupExpected',b'$oracles = nhep_auth_observers(',b'$http = nhe_history_http_run(',b'$seal = nhep_normal_history_seal(',b'nhep_normal_history_publish(']
positions=[main.index(call) for call in order]
assert positions==sorted(positions) and all(main.count(call)==1 for call in order)
for name in new_names-{'nhep_normal_startup_ui'}:
 body=new_defs[name]['raw'];assert body.index(b"ini_get('zend.exception_ignore_args') === '1'")<body.index(b'nhep_observation_parent($qualified)')
 assert b'#[SensitiveParameter]' in body
 assert all(word not in body for word in [b'echo ',b'print(',b'json_encode(',b'file_put_contents(',b'proc_open(',b'curl_exec(',b'fwrite('])
assert b"$packet === ['channel' => 'nhe-readiness:'" in new_defs['nhep_normal_startup_accept']['raw']
assert b"array_keys($packet) === ['channel', 'data']" in new_defs['nhep_channel_frame']['raw']
assert b'fseek(' not in fragment and b'rewind(' not in fragment
assert b'$actual7 === $expected7' in fragment and b'$expectedFiles = $plan[\'files\']' in fragment
fwd=''.join(difflib.unified_diff(before.decode().splitlines(keepends=True),after.decode().splitlines(keepends=True),fromfile='before/legacy-history-producer.php',tofile='candidate/legacy-history-producer.php')).encode()
rev=''.join(difflib.unified_diff(after.decode().splitlines(keepends=True),before.decode().splitlines(keepends=True),fromfile='candidate/legacy-history-producer.php',tofile='before/legacy-history-producer.php')).encode()
assert apply_unified(before,fwd)==after and apply_unified(after,rev)==before
for rel,raw in [('before/legacy-history-producer.php',before),('candidate/legacy-history-producer.php',after),('diff/legacy-history-producer.php.diff',fwd),('reverse/legacy-history-producer.php.diff',rev)]:new(rel,raw)
prior=json.loads(read(old/'SOURCE-OVERLAY.json'));overlay={}
for name,row in prior['artifacts'].items():
 path=(OUT/'candidate'/name) if name=='legacy-history-producer.php' else Path(row['source'])
 raw=read(path)
 if name!='legacy-history-producer.php':assert len(raw)==row['bytes'] and sha(raw)==row['sha256']
 overlay[name]={'source':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)}
assert len(overlay)==13 and len([name for name in overlay if name.endswith('.php')])==9
controls={path:overlay[path.rsplit('/',1)[-1]] for path in prior['bundle_control_files']}
current=prior['unchanged_Root_owned_current_control_paths'];assert len(controls)==8 and len(current)==3
new('SOURCE-OVERLAY.json',js({'schema':1,'scope':'ACTUAL_THIRTEEN_COMPONENT_IDENTITIES_NOT_FUTURE_SOURCE_BINDING','artifacts':overlay,'actual_artifact_count':13,'actual_PHP_component_count':9,'bundle_control_files':controls,'unchanged_Root_owned_current_control_paths':current,'exact_control_count':11,'future_adoption':'Startup Source successor only; final current Source/Host/current-reader/freeze/Run remain unbound and original direct169/Host total guards remain.'}))
new('TRANSFORM.json',js({'schema':1,'scope':'FIVE_EXACT_MAIN_SEAMS_AND_FOUR_COMPLETE_STARTUP_DEFINITIONS','before':{'bytes':len(before),'sha256':sha(before)},'candidate':{'bytes':len(after),'sha256':sha(after)},'finite_main_seams':transforms,'addition_before_original169tail':{'source':'startup.fragment.php','LF_bytes':len(fragment),'LF_sha256':sha(fragment),'CRLF_bytes':len(addition),'CRLF_sha256':sha(addition)},'standard_forward_reverse_exact':True}))
new('CALL-CLOSURE.json',js({'schema':1,'scope':'FIXED_COMPLETE_ORIGINAL_MAIN_AND_STARTUP_SOURCE_CALL_SEAMS','main_order':[call.decode() for call in order],'new_definitions':sorted(new_names),'reused_original_helpers':['nhep_observation_parent','nhep_database_capture','nhep_redis_capture','nhep_redis_expected','nhep_file_tree','nhep_auth_files_tree','nhep_auth_files_streams','nhep_file_stat','nhep_child_listener','nhep_child_observation','nhep_http_time_reference','nhep_http_asctime','nhep_close_private_file','nheh_snapshot','nhe_file','nhe_keys','audit_app_proc_stat'],'fixed_ready_echo_exact_original_two_keys':['channel','data'],'original_all_three_loaded_controls':True,'original_same_writer_handles_never_seek':True,'expected_from':'Original prepared plus complete independently recaptured BEFORE; fixed Source bodies; original owned native identities; strictly constrained native inode/directory/time variants; complete actual7 equality. No wholesale AFTER-as-model.'}))
new('MANIFEST.json',js({'schema':1,'scope':'ORIGINAL_NORMAL37_COMPLETE_STARTUP_EFFECTS_SOURCE_ONLY','lineage':{'LOAD01_R2_PINS':{'bytes':len(pins),'sha256':sha(pins)},'LOAD01_R2_independent_review':{'bytes':len(review),'sha256':sha(review)},'startup_source_map':{'bytes':len(source_map),'sha256':sha(source_map)},'official_PHP82_Source_PINS':{'bytes':len(public_pins),'sha256':sha(public_pins)},'official_PHP82_C':{'bytes':len(public_c),'sha256':sha(public_c)}},'candidate':{'bytes':len(after),'sha256':sha(after)},'old_definitions':165,'old_unchanged_definitions':164,'only_changed_old_definition':'nhep_normal_history_main','new_complete_definitions':sorted(new_names),'original169tail_preserved':True,'full_original_scope_preserved':['37 ordered real HTTP steps','four original business frames','four original migration CLIs','complete164 tables','whole Redis namespace and natural expiry','all five trees','normal same-handle close and no-extra','Root-adopted exact13 safe seal','current reader/true-enabled/final Source and actual outer-zero admission'],'new_runtime_or_target_execution':'NOT RUN','new_PHP82_syntax':'NOT RUN; Root owns necessary formal pure-l verification','private_data':'None read or produced. Runtime comparison bytes/body hashes/plans remain solely private RAM.'}))
new('CHECKS.json',js({'schema':1,'status':'PASS_FINITE_SOURCE_ASSEMBLY_NOT_RUNTIME','unique_before_and_five_finite_main_seams':True,'four_complete_new_definitions_and_original164_other_defs':True,'original169_direct_refusal_and_Host_total_refusal_preserved':True,'standard_forward_reverse_exact':True,'plan_before_children_ready_before_whole_observation_accept_before37':True,'original_same_load01_controls_and_original37_factories_finish_close_seal_unchanged':True,'independent_complete_database_redis_five_trees_native_model':True,'actual13_artifacts9PHP11controls_no_future_pins':True,'all_private_comparison_bodies_and_hashes_RAM_only':True,'pending':['independent full startup Source review','Root necessary PHP82 syntax for this successor','final13/9/11 current Source binding and Host adoption','current-reader/true-enabled','actual original full37/fourCLI/normal close-zero and business acceptance']}))
new('SOURCE-INPUTS.json',js({'schema':1,'windows':INPUTS,'all_read_windows_closed':True,'ended_ns':time.time_ns()}))
members={}
for path in sorted(OUT.rglob('*')):
 if path.is_file() and path.name!='PINS.json':
  raw=path.read_bytes();members[path.relative_to(OUT).as_posix()]={'bytes':len(raw),'sha256':sha(raw)}
new('PINS.json',js({'schema':1,'scope':'NORMAL_CHILD_STARTUP_COMPLETE_SOURCE_ONLY_NOT_RUNTIME_ADMISSION','members':members}))
print(json.dumps({'status':'STARTUP_SOURCE_ONLY_R1_CREATED','candidate':{'bytes':len(after),'sha256':sha(after)},'PINS':{'bytes':(OUT/'PINS.json').stat().st_size,'sha256':sha((OUT/'PINS.json').read_bytes())},'new_target_execution':'NOT RUN'},ensure_ascii=False))
