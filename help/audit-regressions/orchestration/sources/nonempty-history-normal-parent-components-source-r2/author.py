"""Exactly one Source seam fixing LOAD-01; no target/runtime imports or execution."""
from pathlib import Path
import ast, difflib, hashlib, json, os, re, time
OUT=Path(__file__).resolve().parent
BASE=OUT.parent
INPUTS=[]
STAT_KEYS=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
helper_path=BASE/'nonempty-history-pre37-fixed-preparation-components-source-r1/author.py'
helper_raw=helper_path.read_bytes();tree=ast.parse(helper_raw)
helper_names={'sha','nine','read','new','js','php_functions','apply_unified'}
helpers=[n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name in helper_names]
assert {n.name for n in helpers}==helper_names
exec(compile(ast.Module(body=helpers,type_ignores=[]),str(helper_path),'exec'))
assert read(helper_path)==helper_raw
old_package=BASE/'nonempty-history-normal-parent-components-source-r1'
oldpins=read(old_package/'PINS.json')
assert len(oldpins)==1827 and sha(oldpins)=='c2f94c0d86d398384e5a6467bfa7ce522859e7f2897685c2ad11d0cdbcce37ae'
finding=read(BASE/'nonempty-history-normal-parent-independent-r1/REPORT.json')
assert len(finding)==3790 and sha(finding)=='8aa71682cddac403a822cc97d4b7a80a9eaf9cc87116c81945c34e0bbfda67f3'
assert json.loads(finding)['findings'][0]['id']=='PARENT-R1-LOAD-01'
before=read(old_package/'candidate/legacy-history-producer.php')
assert len(before)==1051627 and sha(before)=='606a49d80ccf211a14d4731c9cbd24077bea5a2a9a6b961c09cd1e86db30f9ab'
design=read(OUT/'DESIGN-TASKS.md')
old=b"        $qualified = nhe_qualify($freeze, 'audit-legacy-history-producer');\r\n        nhep_parent_qualified($qualified); nhep_parent_write($qualified);"
replacement=b"        $qualified = nhe_qualify($freeze, 'audit-legacy-history-producer');\r\n        // The shared qualifier pins all controls; load these three definitions\r\n        // before any parent write, observer connection or original pre37 call.\r\n        require_once '/run/nonempty/legacy-normal-config.php';\r\n        require_once '/run/nonempty/legacy-actors.php';\r\n        require_once '/run/nonempty/legacy-history-http.php';\r\n        nhe_includes($qualified);\r\n        nhep_parent_qualified($qualified); nhep_parent_write($qualified);"
assert before.count(old)==1
after=before.replace(old,replacement)
assert after.count(replacement)==1 and after.replace(replacement,old)==before
old_defs=php_functions(before);new_defs=php_functions(after)
assert len(old_defs)==len(new_defs)==165 and set(old_defs)==set(new_defs)
changed={name for name in old_defs if old_defs[name]['raw']!=new_defs[name]['raw']}
assert changed=={'nhep_normal_history_main'}
assert sum(old_defs[name]['raw']==new_defs[name]['raw'] for name in old_defs)==164
assert before[-169:]==after[-169:] and b'NHE_PRODUCER_NOT_IMPLEMENTED' in after[-169:]
main=new_defs['nhep_normal_history_main']['raw']
calls=[b"foreach (['nhep_normal_startup_plan', 'nhep_normal_startup_accept']",b"$qualified = nhe_qualify(",
 b"require_once '/run/nonempty/legacy-normal-config.php'",b"require_once '/run/nonempty/legacy-actors.php'",
 b"require_once '/run/nonempty/legacy-history-http.php'",b'nhe_includes($qualified)',
 b'nhep_parent_write($qualified)',b'$observer = audit_db()',b'$prepared = nhep_pre37_fixed_preparation(']
assert [main.index(call) for call in calls]==sorted(main.index(call) for call in calls)
assert all(main.count(call)==1 for call in calls)
assert 'nhep_normal_startup_plan' not in new_defs and 'nhep_normal_startup_accept' not in new_defs
assert old_defs['nhep_normal_history_main']['raw'].replace(old,replacement)==main
overlay_before=json.loads(read(old_package/'SOURCE-OVERLAY.json'))
librow=overlay_before['artifacts']['legacy-app.php'];lib=read(librow['source']);libdefs=php_functions(lib)
qualifier=libdefs['nhe_qualify']['raw'];source_qualifier=libdefs['nhe_qualify_source']['raw'];includes=libdefs['nhe_includes']['raw']
assert b'nhe_qualify_source($freezePin, $role)' in qualifier and b'count($authority[\'control_files\']) === 11' in source_qualifier
assert b"array_column($state['authority']['control_files'], 'path')" in includes
load_sources={}
for file,required in [('legacy-normal-config.php',['m4lh_config_prepare','m4lh_config_verify_getters']),
 ('legacy-actors.php',['m4lh_actor_identity','m4lh_actors_prepare','m4lh_actors_reset_password']),
 ('legacy-history-http.php',['nhe_history_http_run','nheh_snapshot','nheh_observers'])]:
 row=overlay_before['artifacts'][file];raw=read(row['source'])
 assert len(raw)==row['bytes'] and sha(raw)==row['sha256']
 defs=php_functions(raw);assert all(name in defs for name in required)
 # All executable-looking source outside the top-level function bodies is only
 # the original PHP declaration/comments/whitespace, not an App/resource entry.
 rest=raw
 for item in sorted(defs.values(),key=lambda item:item['offset'],reverse=True):rest=rest[:item['offset']]+rest[item['end']:]
 rest=re.sub(rb'/\*.*?\*/|//[^\r\n]*',b'',rest,flags=re.S)
 rest=rest.replace(b'<?php',b'').replace(b'declare(strict_types=1);',b'')
 assert rest.strip()==b'',file
 assert '/run/nonempty/'+file in overlay_before['bundle_control_files']
 load_sources[file]={'source':row,'required_definitions':required,'original_definitions_count':len(defs),'definition_only_top_level':True}
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
new('SOURCE-OVERLAY.json',js({'schema':1,'scope':'ACTUAL_THIRTEEN_COMPONENT_IDENTITIES_NOT_FUTURE_SOURCE_BINDING','artifacts':overlay,'actual_artifact_count':13,'actual_PHP_component_count':9,'bundle_control_files':controls,'unchanged_Root_owned_current_control_paths':current,'exact_control_count':11,'future_adoption':'R2 fixes only LOAD-01. Startup/final current Source/Host/current-reader/freeze/Run remain unbound; original total guards stay.'}))
new('TRANSFORM.json',js({'schema':1,'scope':'SINGLE_EXACT_LOAD01_SEAM','before':{'bytes':len(before),'sha256':sha(before)},'candidate':{'bytes':len(after),'sha256':sha(after)},'offset':before.index(old),'old':old.decode(),'replacement':replacement.decode(),'standard_forward_reverse_exact':True}))
new('MANIFEST.json',js({'schema':1,'scope':'LOAD01_FIXED_THREE_FROZEN_DEFINITION_COMPONENTS_ONLY','lineage':{'R1_PINS':{'bytes':len(oldpins),'sha256':sha(oldpins)},'R1_finding':{'bytes':len(finding),'sha256':sha(finding)}},'candidate':{'bytes':len(after),'sha256':sha(after)},'only_changed_definition':'nhep_normal_history_main','original_unchanged_definitions':164,'original_prior162_preserved':True,'original169_tail_preserved':True,'real_load_sources_and_named_definitions':load_sources,'no_new_business_or_resource_operation':True,'original_full_scope_preserved':True,'target_execution':'NOT RUN'}))
new('CHECKS.json',js({'schema':1,'status':'PASS_SOURCE_LOAD01_FIXED_REQUIRE_AND_REAL_CALL_CLOSURE_ONLY','unique_R1_before':True,'one_finite_seam_standard_forward_reverse':True,'all_other164_defs_original169_and_original_missing_startup_guard_preserved':True,'three_definition_only_controls_already_in_original11_loaded_after_full_qualifier_before_writers_or_observer':True,'same_original_includes_Source_closed_set':True,'actual13_artifacts9PHP11controls_no_future_pins':True,'PHP82_compiler':'NOT RUN: no PHP command in PATH; no resource started','runtime_or_business':'NOT RUN','remaining':['independent review','complete startup effects','final Source binding and Host admission','current-reader/true-enabled','actual original full37/fourCLI/full normal close-zero/business acceptance']}))
new('SOURCE-INPUTS.json',js({'schema':1,'windows':INPUTS,'all_read_windows_closed':True,'ended_ns':time.time_ns()}))
members={}
for path in sorted(OUT.rglob('*')):
 if path.is_file() and path.name!='PINS.json':
  raw=path.read_bytes();members[path.relative_to(OUT).as_posix()]={'bytes':len(raw),'sha256':sha(raw)}
new('PINS.json',js({'schema':1,'scope':'LOAD01_FIXED_THREE_DEFINITION_LOADS_SOURCE_ONLY','members':members}))
print(json.dumps({'status':'LOAD01_SOURCE_ONLY_R2_CREATED','candidate':{'bytes':len(after),'sha256':sha(after)},'PINS':{'bytes':(OUT/'PINS.json').stat().st_size,'sha256':sha((OUT/'PINS.json').read_bytes())},'target_execution':'NOT RUN'},ensure_ascii=False))
