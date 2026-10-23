from pathlib import Path
import ast,copy,hashlib,json,os,re,stat,sys,time
sys.dont_write_bytecode=True
ROOT=Path(__file__).resolve().parent
ORG=ROOT.parent
OLD=ORG/'current175f-B-initializer-syntax-source-r1'
KNOWN_HELPER=ORG/'current175f-complete-normal-S-readbacks-source-r1/candidate/finite-helper.py'
KEYS=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
HP={'bytes':33935,'sha256':'a84cb0edd5beaa998a26f8b96819b765e8cfe83e52a62619846b431c417855c0'}
def need(ok,label):
    if not ok:raise RuntimeError(label)
def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def put(name,v):
    raw=enc(v)
    with (ROOT/name).open('xb') as f:need(f.write(raw)==len(raw),'COMPLETE_NEW_REVIEW')
def n9(st):return [getattr(st,k) for k in KEYS]

# Only the established whole finite class is reused as this Source reader.
# No prepared run.py, launch.py, helper module or PHP candidate is imported.
helper=KNOWN_HELPER.read_bytes();need(fp(helper)==HP,'ALREADY_REVIEWED_WHOLE_CROSS4_METHOD')
ht=ast.parse(helper)
finite=next(x for x in ht.body if isinstance(x,ast.ClassDef) and x.name=='Finite')
ns={'Path':Path,'os':os,'stat':stat,'hashlib':hashlib,'time':time,'need':need,'fp':fp,'n9':n9,'KEYS':KEYS,'pinpart':lambda v:{k:v[k] for k in ['bytes','sha256']}}
exec(compile(ast.Module(body=[finite],type_ignores=[]),'<reviewed-Finite-class-only>','exec'),ns)
r=ns['Finite']();r.read(KNOWN_HELPER,HP);started=time.time_ns()
def raw(name):return r.read(ROOT/name)
def obj(name):return json.loads(raw(name))
def parts(content):
    text=content.decode();tree=ast.parse(text);lines=text.splitlines(keepends=True)
    definitions={x.name:''.join(lines[x.lineno-1:x.end_lineno]).encode() for x in tree.body if isinstance(x,(ast.FunctionDef,ast.ClassDef))}
    return tree,definitions
def calls(tree,name):return [x for x in ast.walk(tree) if isinstance(x,ast.Call) and isinstance(x.func,ast.Name) and x.func.id==name]
def assignment(tree,name):
    rows=[x.value for x in ast.walk(tree) if isinstance(x,ast.Assign) and any(isinstance(t,ast.Name) and t.id==name for t in x.targets)]
    need(len(rows)==1,'ONE_ASSIGNMENT:'+name);return rows[0]

final_raw=r.read(OLD/'FINAL-PINS.json',{'bytes':3266,'sha256':'7ab6668c494b177b5befd3bf44711e6bb286d2f43b9710fcef10823141cc0455'})
final=json.loads(final_raw);need(final['file_count']==len(final['files'])==22,'ALL_R1_MEMBERS')
for name,pin in final['files'].items():
    need(r.read(OLD/name,pin)==r.read(ROOT/'before-r1'/name,pin),'R1_WHOLE_ARCHIVE_JOIN:'+name)
need(raw('before-r1/FINAL-PINS.json')==final_raw,'R1_WHOLE_FINAL_ARCHIVE')
need({p.relative_to(ROOT/'before-r1').as_posix() for p in (ROOT/'before-r1').rglob('*') if p.is_file()}==set(final['files'])|{'FINAL-PINS.json'},'EXACT_ALL_TWENTY_THREE_R1_ARCHIVE')
for name,pin in [('REPORT.json',{'bytes':4576,'sha256':'bd886f9fa1e3ba13a32c1d1d51d14a57df7e0157d68d970f62063f0904f2ba6f'}),('PINS.json',{'bytes':558,'sha256':'12c2b16be510a04a82078d1406b62614dc20f219cd007ab98cf280472456cc2d'})]:
    need(r.read(ORG/'current175f-B-initializer-syntax-independent-source-r1'/name,pin)==r.read(ROOT/'finding-r1'/name,pin),'REAL_FIXED_FINDING_RETAINED')
need(raw('finite-helper.py')==helper,'LOCAL_COMPLETE_HELPER_NO_EDIT')

# Verify the complete already-reviewed helper's one behavioral seam against
# original33902, rather than accepting a cross4 label in a report.
old_helper=ORG.parent/'ms4-worker-normal-source-preparation-r1/customer-form-current-three-front-material-producer-independent-r1/review.py'
old_helper_raw=r.read(old_helper,{'bytes':33902,'sha256':'4673d95c60c22f4653b6f22f833e24fb6accc3b286c555ca0462b0fb501252e0'})
modified=copy.deepcopy(ht)
class InverseCross4(ast.NodeTransformer):
    changed=0
    def visit_Compare(self,node):
        if (isinstance(node.left,ast.Name) and node.left.id=='k' and len(node.ops)==len(node.comparators)==1 and isinstance(node.ops[0],ast.In) and isinstance(node.comparators[0],ast.Set) and {x.value for x in node.comparators[0].elts if isinstance(x,ast.Constant)}=={'st_dev','st_ino','st_size','st_mtime_ns'}):
            self.changed+=1
            return ast.copy_location(ast.Compare(left=node.left,ops=[ast.NotEq()],comparators=[ast.Constant(value='st_ctime_ns')]),node)
        return self.generic_visit(node)
inverse=InverseCross4();modified=inverse.visit(modified)
need(inverse.changed==1 and ast.dump(modified,include_attributes=False)==ast.dump(ast.parse(old_helper_raw),include_attributes=False),'COMPLETE_HELPER_AST_EXACT_SINGLE_CROSS8_TO_CROSS4_CHANGE')
finite_text=parts(helper)[1]['Finite'].decode()
for token in ["if k in {'st_dev', 'st_ino', 'st_size', 'st_mtime_ns'}",'n9(y) == fn','n9(z) == pn','a.st_birthtime_ns == x.st_birthtime_ns',"f.read(1) == b'' and total == a.st_size",'final == row and raw == self.raws[key]']:
    need(token in finite_text,'FULL_OWN9_BIRTHTIME_EOF_FINAL_CONTRACT:'+token)

transform=obj('TRANSFORM.json')
run,launch=raw('run.py'),raw('launch.py')
for name,content,key in [('run.py',run,'run_seams'),('launch.py',launch,'launch_seams')]:
    restored=content.decode()
    for seam in reversed(transform[key]):
        need(restored.count(seam['after'])==1,'ONE_REVERSE_SEAM:'+seam['label']);restored=restored.replace(seam['after'],seam['before'],1)
    need(restored.encode()==raw('before-r1/'+name),'COMPLETE_WHOLE_MODULE_INVERSE:'+name)
ot,od=parts(raw('before-r1/run.py'));nt,nd=parts(run)
lot,lod=parts(raw('before-r1/launch.py'));lnt,lnd=parts(launch)
need(set(od)==set(nd) and set(lod)==set(lnd),'NO_NEW_RUNTIME_FUNCTIONS')
need(all(od[name]==nd[name] for name in od if name!='main'),'ALL_ORIGINAL_RUN_NON_MAIN_FUNCTIONS_WHOLE')
need(all(lod[name]==lnd[name] for name in lod),'ALL_ORIGINAL_LAUNCH_FUNCTIONS_WHOLE')
oldcalls,newcalls=calls(ot,'native'),calls(nt,'native')
need(len(oldcalls)==len(newcalls)==8 and [ast.dump(x,include_attributes=False) for x in oldcalls]==[ast.dump(x,include_attributes=False) for x in newcalls],'ALL_EIGHT_NATIVE_CALLS_FULL_ARGUMENT_AST_IDENTICAL')
operations=[ast.literal_eval(x.args[0]) for x in newcalls]
need(operations==['own-label-before','existing-fixed-image','create-one-owned-lint-container','attach-original-php-l','same-cid-native-wait','same-cid-native-terminal','ordinary-exact-owned-cid-rm','own-label-after'],'EIGHT_ORIGINAL_OPERATION_ORDER')
need(ast.dump(assignment(nt,'args'),include_attributes=False)==ast.dump(assignment(ot,'args'),include_attributes=False).replace('ms4-current175f-b-ui-initializer-php-l-r1','ms4-current175f-b-ui-initializer-php-l-r2'),'FULL_CREATE_ARGV_ONLY_R2_OWN_NAME')
need(ast.literal_eval(assignment(nt,'command'))=='php -l /candidate/ms4-initialize.php','ONE_UNCHANGED_FULL_FILE_PHP_L')
need(ast.literal_eval(assignment(nt,'IMAGE'))=='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a','EXACT_FIXED_PHP82_IMAGE')
need("HELPER=SOURCE_ROOT/'finite-helper.py'" in run.decode() and repr(HP) in run.decode(),'RUNTIME_LOCAL_REVIEWED_HELPER_AND_EXACT_PIN')
need(run.decode().index('authority=ended_s_authority(r.read)')<run.decode().index("native('own-label-before'"),'GENUINE_ENDED_S_AND_ROOT_GATE_BEFORE_NATIVE')
need(launch.decode().index('ended_s_authority(lambda path: path.read_bytes())')<launch.decode().index('OUT.mkdir()')<launch.decode().index('subprocess.Popen('),'ORIGINAL_GATE_BEFORE_ANY_ACTUAL_OUTPUT_OR_CHILD')

manifest=obj('CANDIDATE-SOURCE.json');oldmanifest=obj('before-r1/CANDIDATE-SOURCE.json')
need(manifest['candidate']==oldmanifest['candidate'] and manifest['candidate']['bytes']==118698 and manifest['candidate']['sha256']=='29376b4266010285ec4cebd92f0197d849142988442ead06cbcc7d26ff840db8','UNCHANGED_REAL_R1_B_LEAF_IDENTITY')
candidate=r.read(Path(manifest['candidate']['path']),manifest['candidate'])
need(raw('candidates/ms4-initialize.php')==candidate and r.read(Path(manifest['copy']['path']),manifest['copy'])==candidate,'ALL_FULL_118698_BYTES_EQUAL')
need(manifest['copy']['path']==(ROOT/'candidates/ms4-initialize.php').as_posix(),'OWN_R2_COPY_PATH')
need(manifest['runtime_finite_helper']['local_complete_copy']=={'path':(ROOT/'finite-helper.py').as_posix(),**HP} and manifest['runtime_finite_helper']['original_reviewed_whole']=={'path':KNOWN_HELPER.as_posix(),**HP},'EXACT_HELPER_SOURCE_DAG')
for key in ['leaf_preparation_PINS','author_preparation_PINS']:
    need(manifest[key]==oldmanifest[key],'UNCHANGED_ACTUAL_B_PREPARATION_POINTERS')
    p=Path(manifest[key]['path']);value=json.loads(r.read(p,manifest[key]));need(type(value['files']) is dict,'REAL_PREPARATION_FILES_MAP')
    for name,pin in value['files'].items():r.read(p.parent/name,pin)
    need(r.read(p.parent/'leaf/ms4-initialize.php')==candidate,'BOTH_LEAF_PREPARATIONS_WHOLE_JOIN')
for row in manifest['evidence']:r.read(Path(row['path']),row)
for row in manifest['protocol_provenance_not_B_qualification'].values():r.read(Path(row['path']),row)
for key in ['original_r1_FINAL_PINS','independent_r1_REPORT','independent_r1_PINS']:
    row=manifest['source_repair_authority'][key];r.read(Path(row['path']),row)
row=manifest['pre_execution_requirement']['source_launcher'];r.read(Path(row['path']),row)
code=obj('CODE-PINS.json');need(set(code['files'])=={'run.py','launch.py','DESIGN.md','CANDIDATE-SOURCE.json'},'ORIGINAL_EXACT_FOUR_CODE_PIN_CONTRACT')
for name,pin in code['files'].items():r.read(ROOT/name,pin)
interface=obj('RESULT-INTERFACE.json');oldinterface=obj('before-r1/RESULT-INTERFACE.json')
need(interface['run_argv']==[oldinterface['run_argv'][0],'-B','-X','utf8',str(ROOT/'run.py'),'--authorize-ended-S17155-root-admitted'],'EXACT_REAL_WINDOWS_NATIVE_CHILD_ARGV')
need(interface['launch_argv']==[oldinterface['launch_argv'][0],'-B','-X','utf8',str(ROOT/'launch.py'),'--authorize-ended-S17155-root-admitted'],'EXACT_REAL_WINDOWS_NATIVE_LAUNCH_INTERFACE')
need(interface['actual_directory']==(ORG/'current175f-B-initializer-syntax-actual-r2').as_posix(),'R2_OWN_FUTURE_ACTUAL_ROOT')
normalized=copy.deepcopy(interface)
for key in ['run_argv','launch_argv','actual_directory','copy']:normalized[key]=oldinterface[key]
need(normalized==oldinterface,'ALL_OTHER_INTERFACE_FIELDS_WHOLE_UNCHANGED')
result_node=next(x for x in ast.walk(nt) if isinstance(x,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='result' for t in x.targets))
need([ast.literal_eval(k) for k in result_node.value.keys]==interface['result_exact_keys'] and interface['result_exact_key_count']==20,'EXACT_UNCHANGED_TWENTY_RESULT_KEYS')
need(nd['ended_s_authority']==od['ended_s_authority'] and lnd['ended_s_authority']==lod['ended_s_authority'],'ORIGINAL_END_S_ROOT_ADMISSION_GUARDS_WHOLE')

# Replay ordinary complete null-file addition/deletion without claiming that
# an absent historical helper existed. Normalize only those two headers to a
# virtual empty RAM entry; strict original context/count/byte checks still run.
strict_source=ORG/'current175f-complete-source-independent-r1/whole-review.py'
strict_raw=r.read(strict_source);strict_tree=ast.parse(strict_raw)
fn=next(x for x in strict_tree.body if isinstance(x,ast.FunctionDef) and x.name=='apply_patch')
patch_ns={'re':re};exec(compile(ast.Module(body=[fn],type_ignores=[]),'<strict-complete-standard-diff-replay>','exec'),patch_ns)
names=['CANDIDATE-SOURCE.json','CODE-PINS.json','DESIGN.md','launch.py','run.py','RESULT-INTERFACE.json','candidates/ms4-initialize.php','finite-helper.py']
before={name:raw('before-r1/'+name) if name!='finite-helper.py' else b'' for name in names}
after={name:raw(name) for name in names}
forward,reverse=raw('SOURCE-FORWARD.diff'),raw('SOURCE-REVERSE.diff')
need(forward.count(b'--- /dev/null\n+++ b/finite-helper.py\n')==1 and reverse.count(b'--- a/finite-helper.py\n+++ /dev/null\n')==1,'GENUINE_ORDINARY_NEW_HELPER_ADDITION_DELETION_HEADERS')
f=forward.replace(b'--- /dev/null\n+++ b/finite-helper.py\n',b'--- a/finite-helper.py\n+++ b/finite-helper.py\n',1)
q=reverse.replace(b'--- a/finite-helper.py\n+++ /dev/null\n',b'--- a/finite-helper.py\n+++ b/finite-helper.py\n',1)
need(patch_ns['apply_patch'](before,f)==after,'COMPLETE_ORDINARY_FORWARD_ALL_BYTES_AND_NEW_FILE')
need(patch_ns['apply_patch'](after,q)==before,'COMPLETE_ORDINARY_REVERSE_ALL_BYTES_AND_FILE_REMOVAL')
for name in ['prepare.py','check_source.py','DESIGN.md']:raw(name)
need(not (ORG/'current175f-B-initializer-syntax-actual-r2').exists(),'NO_NEW_ACTUAL_OUTPUTS')
need(not any('normal-start.created.json' in path or 'normal-start.terminal.json' in path for path in r.rows),'NO_LIVE_S_RUN_OUTPUTS_READ')
ended=r.end();put('SOURCE-INPUTS.json',{'files':list(r.rows.values()),'all_windows_ended':True,'ended_ns':ended})
report={'schema':1,'status':'PASS_R2_COMPLETE_SOURCE_STATIC_REVIEW_ONLY_NOT_ACTUAL_SYNTAX','started_ns':started,'ended_ns':ended,'formal_r1_P2':'RUNTIME_CROSS8_REPLACED_WITH_EXACT_REVIEWED_COMPLETE_CROSS4_HELPER','r1_all_23_files_and_formal_finding_preserved':True,'helper_original':{'path':KNOWN_HELPER.as_posix(),**HP},'helper_local':{'path':(ROOT/'finite-helper.py').as_posix(),**HP},'helper_complete_AST_single_cross_comparison_delta_verified':True,'named_fd_cross_fields':['st_dev','st_ino','st_size','st_mtime_ns'],'own9_birthtime_fullEOF_fd_named_final_and_input_end_preserved':True,'run_reversible_seams':len(transform['run_seams']),'launch_reversible_seams':len(transform['launch_seams']),'whole_run_launch_inverse':True,'all_original_non_main_run_and_all_launcher_functions_whole':True,'all_8_native_calls_full_AST_and_argument_order_preserved':True,'operation_names':operations,'full_create_argv_only_own_r2_name_changed':True,'fixed_image_one_php_l_one_RO_bind_networknone_readonly_nonroot_128M_ordinary_rm_and_empty_selector_preserved':True,'guard_whole_and_before_any_actual_output_native':True,'exact_20_key_RESULT_and_original_schemas_unchanged':True,'actual_8_times_4_native_files_plus_4_outer_plus_INPUTS_RESULT_equals_38':True,'run_argv_exact_Windows_str_Path_not_posix_literal':True,'complete_ordinary_forward_reverse_with_true_helper_addition':True,'helper_addition_replay':'ONLY_NULL_HEADERS_NORMALIZED_TO_VIRTUAL_EMPTY_RAM_ENTRY_NO_FALSE_BEFORE_FILE','Source_DAG_whole_pins_joined':True,'candidate':manifest['candidate'],'copy':manifest['copy'],'result_interface':{'path':(ROOT/'RESULT-INTERFACE.json').as_posix(),**fp(raw('RESULT-INTERFACE.json'))},'source_windows':len(r.rows),'all_source_windows_ended':True,'current_S_and_Run_outputs_read':False,'prepared_run_launcher_PHP_or_helper_module_import_execution':False,'actual_job_Docker_Host_PHP_B_M_S_operations':0,'actual_output_directory_created':False,'W_Val_Run_Git_canonical_mutations':0,'runtime_ready':False,'A0_postA_B_H5_business':'UNBOUND_NOT_EXECUTED'}
put('REPORT.json',report)
print(json.dumps({'status':report['status'],'REPORT':fp(enc(report)),'source_windows':len(r.rows),'run':fp(run),'launch':fp(launch),'actual_jobs':0}))
