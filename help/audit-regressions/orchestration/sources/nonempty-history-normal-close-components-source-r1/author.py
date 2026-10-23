"""Finite normal-close Source assembly. Never loads or executes any target component."""
from pathlib import Path
import ast, difflib, hashlib, json, os, re, time
OUT=Path(__file__).resolve().parent
BASE=OUT.parent
INPUTS=[]
STAT_KEYS=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
helper_path=BASE/'nonempty-history-pre37-fixed-preparation-components-source-r1/author.py'
helper_raw=helper_path.read_bytes()
helper_ast=ast.parse(helper_raw)
helper_names={'sha','nine','read','new','js','php_functions','apply_unified'}
helper_defs=[node for node in helper_ast.body if isinstance(node,ast.FunctionDef) and node.name in helper_names]
assert {node.name for node in helper_defs}==helper_names
exec(compile(ast.Module(body=helper_defs,type_ignores=[]),str(helper_path),'exec'))
read(helper_path)
stage=BASE/'nonempty-history-pre37-fixed-preparation-components-source-r1'
pins=read(stage/'PINS.json')
assert len(pins)==2448 and sha(pins)=='9cd33729c97be5f5b5873b8823e3b8c2b30692ddee5a91c3d85aa23b6f31d78d'
review=read(BASE/'pre37-fixed-preparation-independent-r1/REPORT.json')
assert len(review)==2557 and sha(review)=='fa1dd96d355422545420082c26e0abb03abf123c53efc750ddd25fff1bc8fdb0'
assert json.loads(review)['findings']==[]
overlay_before=json.loads(read(stage/'SOURCE-OVERLAY.json'))
before={name:read(Path(overlay_before['artifacts'][name]['source'])) for name in ['legacy-history-channel.php','legacy-history-producer.php']}
for name,raw in before.items():
 pin=overlay_before['artifacts'][name]
 assert len(raw)==pin['bytes'] and sha(raw)==pin['sha256']
fragments={name:read(OUT/name) for name in ['close.fragment.php','progress-model.fragment.php','public-native-view.fragment.php','channel-initialize.fragment.php','channel-stop-metadata.fragment.php']}
close_defs=php_functions(fragments['close.fragment.php'])
progress_defs=php_functions(fragments['progress-model.fragment.php'])
view_defs=php_functions(fragments['public-native-view.fragment.php'])
assert set(close_defs)=={'nhep_close_private_file','nhep_normal_close_files_expected','nhep_normal_close_writers'}
assert set(progress_defs)=={'nhep_normal_close_files_expected'}
assert set(view_defs)=={'nhep_normal_close_public_view'}
added=[close_defs['nhep_close_private_file']['raw'],progress_defs['nhep_normal_close_files_expected']['raw'],
       close_defs['nhep_normal_close_writers']['raw'],view_defs['nhep_normal_close_public_view']['raw']]
fragment=b'\r\n\r\n'.join(raw.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n') for raw in added)+b'\r\n\r\n'
p=before['legacy-history-producer.php']
assert p[-169:].lstrip().startswith(b'if (realpath(') and b'NHE_PRODUCER_NOT_IMPLEMENTED' in p[-169:]
after={'legacy-history-producer.php':p[:-169]+fragment+p[-169:]}
assert after['legacy-history-producer.php'][:len(p)-169]+after['legacy-history-producer.php'][-169:]==p
c=before['legacy-history-channel.php']
class_old=b"            'Workerman\\\\Events\\\\Select' => 'crmeb/crmeb/compat/workerman/Events/Select.php',\n"
class_new=class_old+b"            'Workerman\\\\Connection\\\\ConnectionInterface' => 'crmeb/vendor/workerman/workerman/Connection/ConnectionInterface.php',\n            'Workerman\\\\Connection\\\\TcpConnection' => 'crmeb/vendor/workerman/workerman/Connection/TcpConnection.php',\n"
init_old=b"        $runtime = '/legacy-runtime'; clearstatcache(true, $runtime); $root = lstat($runtime);"
init_new=fragments['channel-initialize.fragment.php'].replace(b'\r\n',b'\n')+init_old
stop_old=b"            && \\Workerman\\Worker::$daemonize === false, 'NHE_CHANNEL_ORIGINAL_SERVER_ONE_WORKER');\n        nhe_includes($state);"
stop_new=stop_old.replace(b'        nhe_includes($state);',fragments['channel-stop-metadata.fragment.php'].replace(b'\r\n',b'\n')+b'        nhe_includes($state);')
seams=[(class_old,class_new),(init_old,init_new),(stop_old,stop_new)]
for old,new_value in seams:
 assert c.count(old)==1
 c=c.replace(old,new_value)
restored=c
for old,new_value in reversed(seams):
 assert restored.count(new_value)==1
 restored=restored.replace(new_value,old)
assert restored==before['legacy-history-channel.php']
after['legacy-history-channel.php']=c
old_defs={name:php_functions(raw) for name,raw in before.items()}
new_defs={name:php_functions(raw) for name,raw in after.items()}
assert len(old_defs['legacy-history-producer.php'])==158 and len(new_defs['legacy-history-producer.php'])==162
assert all(row['raw']==new_defs['legacy-history-producer.php'][name]['raw'] for name,row in old_defs['legacy-history-producer.php'].items())
assert set(new_defs['legacy-history-producer.php'])-set(old_defs['legacy-history-producer.php'])==set(close_defs)|set(view_defs)
assert set(old_defs['legacy-history-channel.php'])==set(new_defs['legacy-history-channel.php'])=={'nhe_channel_main'}
meta=fragments['channel-stop-metadata.fragment.php']
assert meta.count(b'fwrite(STDERR, $line)')==1 and b'ConnectionInterface::$statistics' in meta
assert b'use ($masterPid, $runId, &$metadataWritten)' in meta and b'\n        $worker->onWorkerStop = $onStop;' in meta
assert b'pcntl_async_signals() === false' in meta and b"'utc_second' => time()" in meta
assert c.index(b'pcntl_async_signals() === false')<c.index(b'$server = new \\Channel\\Server')<c.index(b'$worker->onWorkerStop = $onStop;')<c.index(b'\\Workerman\\Worker::runAll();')
progress=progress_defs['nhep_normal_close_files_expected']['raw']
assert progress.index(b'$count = $meta[\'connection_count\'];')<progress.index(b'$orders = []')<progress.index(b"$newOutput = $afterStreams")
assert b'$logPosition >= max(0, $stdoutPosition - 1)' in progress
assert b"['legacy-channel.stderr'] = $append" in progress
assert b"['native_after']['channel']" in progress and b"['rows']['audit_sentinel'][0]['run_id']" in progress
writers=close_defs['nhep_normal_close_writers']['raw']
ordered=[b"nhep_child_close($children, 'http')",b'nhep_channel_no_extra($children)',b'nhep_channel_close($state)',b"nhep_child_close($children, 'channel')",b'nhep_normal_close_files_expected(',b'nhep_migration_snapshot_expected(']
assert [writers.index(call) for call in ordered]==sorted(writers.index(call) for call in ordered)
assert writers.count(b'$statistics')==7
view=view_defs['nhep_normal_close_public_view']['raw']
assert b"['bytes' => $raw['bytes'], 'complete_verified' => true]" in view
assert b"'sha256' => $raw['sha256']" in view # private verification, not the public stream view
assert view.index(b'FULL_PRIVATE_BODY_AND_ORIGINAL_HANDLE_RECEIPT_VERIFIED_FIRST')<view.index(b"$streamViews[$name] = ['bytes'")
transforms=[]
for name in ['legacy-history-channel.php','legacy-history-producer.php']:
 fwd=''.join(difflib.unified_diff(before[name].decode().splitlines(keepends=True),after[name].decode().splitlines(keepends=True),fromfile='before/'+name,tofile='candidate/'+name)).encode()
 rev=''.join(difflib.unified_diff(after[name].decode().splitlines(keepends=True),before[name].decode().splitlines(keepends=True),fromfile='candidate/'+name,tofile='before/'+name)).encode()
 assert apply_unified(before[name],fwd)==after[name] and apply_unified(after[name],rev)==before[name]
 transforms.append({'name':name,'before':{'bytes':len(before[name]),'sha256':sha(before[name])},'candidate':{'bytes':len(after[name]),'sha256':sha(after[name])},'standard_forward_and_reverse_exact':True})
 for rel,raw in [('before/'+name,before[name]),('candidate/'+name,after[name]),('diff/'+name+'.diff',fwd),('reverse/'+name+'.diff',rev)]:new(rel,raw)
overlay={}
for name,previous in overlay_before['artifacts'].items():
 path=(OUT/'candidate'/name) if name in after else Path(previous['source'])
 raw=read(path)
 if name not in after:assert len(raw)==previous['bytes'] and sha(raw)==previous['sha256']
 overlay[name]={'source':path.as_posix(),'bytes':len(raw),'sha256':sha(raw)}
assert len(overlay)==13 and len([name for name in overlay if name.endswith('.php')])==9
control={path:overlay[path.rsplit('/',1)[-1]] for path in overlay_before['bundle_control_files']}
current=overlay_before['unchanged_Root_owned_current_control_paths'];assert len(control)==8 and len(current)==3
new('SOURCE-OVERLAY.json',js({'schema':1,'scope':'ACTUAL_THIRTEEN_COMPONENT_IDENTITIES_NOT_FUTURE_SOURCE_BINDING','artifacts':overlay,'actual_artifact_count':13,'actual_PHP_component_count':9,'bundle_control_files':control,'unchanged_Root_owned_current_control_paths':current,'exact_control_count':11,'future_adoption':'Final Root current controls, complete source/freeze/Run/Host and total parent qualification remain unbound; original direct refusal stays.'}))
new('TRANSFORM.json',js({'schema':1,'scope':'TWO_FINITE_NORMAL_CLOSE_SOURCE_TRANSFORMS','channel_three_exact_seams':[{'before':a.decode(),'candidate':b.decode()} for a,b in seams],'producer_insertion_offset':len(p)-169,'producer_fragment':{'bytes':len(fragment),'sha256':sha(fragment)},'files':transforms}))
for name in ['DESIGN-TASKS.md','PUBLIC-SEAL-CONTRACT.md']:
 read(OUT/name)
maps=[]
for folder in ['nonempty-history-normal-close-source-map-r1','nonempty-history-normal-close-stop-metadata-source-map-r1']:
 for name in ['DESIGN-MAP.md','SOURCE-MAP.json']:
  raw=read(BASE/folder/name);maps.append({'path':str(BASE/folder/name),'bytes':len(raw),'sha256':sha(raw)})
new('SOURCE-INPUTS.json',js({'schema':1,'windows':INPUTS,'all_read_windows_closed':True,'ended_ns':time.time_ns()}))
new('MANIFEST.json',js({'schema':1,'scope':'ORIGINAL_NORMAL_WRITERS_CLOSE_AND_SAFE_VIEW_SOURCE_ONLY','lineage':{'fixed_pre37_PINS':{'bytes':len(pins),'sha256':sha(pins)},'fixed_pre37_independent':{'bytes':len(review),'sha256':sha(review)}},'files':transforms,'source_maps':maps,'original_producer_definitions_unchanged':158,'new_complete_definitions':sorted(set(close_defs)|set(view_defs)),'original_channel_definition_changed_only_by_three_exact_seams':'nhe_channel_main','original169_refusal_preserved':True,'actual13_artifacts9PHP11controls':True,'private_stream_bodies_and_any_body_hashes':'RAM only; public streams only bytes/complete_verified','target_execution':'NOT RUN'}))
new('CHECKS.json',js({'schema':1,'status':'PASS_FINITE_SOURCE_TRANSFORMS_AND_NAMED_NORMAL_CLOSE_CALL_CLOSURE_ONLY','standard_forward_reverse':True,'all158_original_producer_definitions_preserved':True,'original169_refusal':True,'channel_fixed_pinned_Select_actual_async_false_UTC_tmp_readback':True,'single_scalar_before_destruct_N_record_existing_private_stderr':True,'independent_vendor_modulus_progress_and_fixed_master_messages':True,'complete_original_prefix_per_process_order_native_identity_and_real_TIME_windows':True,'whole164_DB_fullRedis_natural_expiry_five_trees_and_original_native_receipts_retained':True,'safe_view_after_original_private_receipts_verified_no_public_stream_body_hash':True,'not_run':['PHP syntax','target component','Host','Docker','App/PDO','Redis','HTTP','37 steps','four CLI','acceptance'],'still_required':['independent complete startup effects','fixed parent full37/seal wiring','full Source binding and Host admission','current reader/true enabled','actual full normal history and original four CLI','normal close zero and user acceptance'],'all_read_windows_closed':True,'ended_ns':time.time_ns()}))
members={}
for path in sorted(OUT.rglob('*')):
 if path.is_file() and path.name!='PINS.json':
  raw=path.read_bytes();members[path.relative_to(OUT).as_posix()]={'bytes':len(raw),'sha256':sha(raw)}
new('PINS.json',js({'schema':1,'scope':'ORIGINAL_NORMAL_CLOSE_TWO_SOURCE_COMPONENT_PINS_ONLY','members':members}))
print(json.dumps({'status':'NORMAL_CLOSE_SOURCE_ONLY_CANDIDATE_CREATED','files':transforms,'PINS':{'bytes':(OUT/'PINS.json').stat().st_size,'sha256':sha((OUT/'PINS.json').read_bytes())},'target_execution':'NOT RUN'},ensure_ascii=False))
