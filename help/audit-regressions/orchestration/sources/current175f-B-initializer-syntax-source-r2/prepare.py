from pathlib import Path
import difflib,hashlib,json
ROOT=Path(__file__).resolve().parent
ORG=ROOT.parent
OLD=ORG/'current175f-B-initializer-syntax-source-r1'
HELPER=ORG/'current175f-complete-normal-S-readbacks-source-r1/candidate/finite-helper.py'
REVIEW=ORG/'current175f-B-initializer-syntax-independent-source-r1'
HELPER_PIN={'bytes':33935,'sha256':'a84cb0edd5beaa998a26f8b96819b765e8cfe83e52a62619846b431c417855c0'}
def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def need(ok,label):
    if not ok:raise RuntimeError(label)
def put(path,raw):
    path.parent.mkdir(parents=True,exist_ok=True)
    with path.open('xb') as f:need(f.write(raw)==len(raw),'COMPLETE_NEW_SOURCE')
def enc(value):return (json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode()
def ptr(path):return {'path':path.as_posix(),**fp(path.read_bytes())}

old_final=(OLD/'FINAL-PINS.json').read_bytes()
need(fp(old_final)=={'bytes':3266,'sha256':'7ab6668c494b177b5befd3bf44711e6bb286d2f43b9710fcef10823141cc0455'},'FIXED_R1_FULL_PACKET')
v=json.loads(old_final);need(v['file_count']==len(v['files'])==22,'R1_ALL_TWENTY_TWO_MEMBERS')
for name,pin in v['files'].items():
    raw=(OLD/name).read_bytes();need(fp(raw)==pin,'WHOLE_R1:'+name);put(ROOT/'before-r1'/name,raw)
put(ROOT/'before-r1/FINAL-PINS.json',old_final)
need({p.relative_to(OLD).as_posix() for p in OLD.rglob('*') if p.is_file()}==set(v['files'])|{'FINAL-PINS.json'},'R1_COMPLETE_EXACT_NAMESPACE')
for name,pin in [('REPORT.json',{'bytes':4576,'sha256':'bd886f9fa1e3ba13a32c1d1d51d14a57df7e0157d68d970f62063f0904f2ba6f'}),('PINS.json',{'bytes':558,'sha256':'12c2b16be510a04a82078d1406b62614dc20f219cd007ab98cf280472456cc2d'})]:
    raw=(REVIEW/name).read_bytes();need(fp(raw)==pin,'REAL_R1_INDEPENDENT_FINDING');put(ROOT/'finding-r1'/name,raw)
helper=HELPER.read_bytes();need(fp(helper)==HELPER_PIN,'COMPLETE_REVIEWED_CROSS4_HELPER')
put(ROOT/'finite-helper.py',helper)
leaf=(OLD/'candidates/ms4-initialize.php').read_bytes()
need(fp(leaf)=={'bytes':118698,'sha256':'29376b4266010285ec4cebd92f0197d849142988442ead06cbcc7d26ff840db8'},'UNCHANGED_COMPLETE_B_LEAF')
put(ROOT/'candidates/ms4-initialize.php',leaf)

run=(OLD/'run.py').read_bytes().decode()
run_changes=[]
def seam(label,old,new):
    global run
    need(run.count(old)==1,'ONE_EXACT_SEAM:'+label);run_changes.append({'label':label,'before':old,'after':new});run=run.replace(old,new,1)
seam('local_reviewed_complete_cross4_helper',"HELPER=B/'customer-form-current-three-front-material-producer-independent-r1/review.py'","HELPER=SOURCE_ROOT/'finite-helper.py'")
seam('complete_cross4_helper_expected_pin',"{'bytes':33902,'sha256':'4673d95c60c22f4653b6f22f833e24fb6accc3b286c555ca0462b0fb501252e0'}",repr(HELPER_PIN))
seam('own_r2_actual_directory',"OUT=SOURCE_ROOT.parent/'current175f-B-initializer-syntax-actual-r1'","OUT=SOURCE_ROOT.parent/'current175f-B-initializer-syntax-actual-r2'")
seam('own_r2_label',"LABEL='codex.ms4.syntax=current175f-b-ui-initializer-r1'","LABEL='codex.ms4.syntax=current175f-b-ui-initializer-r2'")
seam('own_r2_name',"'ms4-current175f-b-ui-initializer-php-l-r1'","'ms4-current175f-b-ui-initializer-php-l-r2'")
seam('own_r2_inspect_label',"state['labels']['codex.ms4.syntax']=='current175f-b-ui-initializer-r1'","state['labels']['codex.ms4.syntax']=='current175f-b-ui-initializer-r2'")
put(ROOT/'run.py',run.encode())
launch=(OLD/'launch.py').read_bytes().decode()
old="OUT=SOURCE_ROOT.parent/'current175f-B-initializer-syntax-actual-r1'";new="OUT=SOURCE_ROOT.parent/'current175f-B-initializer-syntax-actual-r2'"
need(launch.count(old)==1,'ONE_LAUNCH_R2_OUTPUT');launch_changes=[{'label':'own_r2_actual_directory','before':old,'after':new}]
launch=launch.replace(old,new,1);put(ROOT/'launch.py',launch.encode())

manifest=json.loads((OLD/'CANDIDATE-SOURCE.json').read_bytes())
manifest['copy']=ptr(ROOT/'candidates/ms4-initialize.php')
manifest['runtime_finite_helper']={'original_reviewed_whole':ptr(HELPER),'local_complete_copy':ptr(ROOT/'finite-helper.py'),'named_fd_cross_fields':['st_dev','st_ino','st_size','st_mtime_ns'],'own_native9_birthtime_fullEOF_final_unchanged':True}
manifest['source_repair_authority']={'original_r1_FINAL_PINS':ptr(OLD/'FINAL-PINS.json'),'independent_r1_REPORT':ptr(REVIEW/'REPORT.json'),'independent_r1_PINS':ptr(REVIEW/'PINS.json'),'formal_P2':'R1_RUNTIME_HELPER_CROSS8_REPLACED_BY_REVIEWED_COMPLETE_CROSS4','r1_immutable_not_admitted':True}
put(ROOT/'CANDIDATE-SOURCE.json',enc(manifest))
interface=json.loads((OLD/'RESULT-INTERFACE.json').read_bytes())
interface['launch_argv'][4]=str(ROOT/'launch.py')
interface['run_argv'][4]=str(ROOT/'run.py')
interface['actual_directory']=(ORG/'current175f-B-initializer-syntax-actual-r2').as_posix()
interface['copy']=manifest['copy']
need(interface['result_exact_key_count']==len(interface['result_exact_keys'])==20,'SAME_TWENTY_KEY_SCHEMA')
put(ROOT/'RESULT-INTERFACE.json',enc(interface))
put(ROOT/'TRANSFORM.json',enc({'schema':1,'run_seams':run_changes,'launch_seams':launch_changes,'original_full_r1':ptr(OLD/'FINAL-PINS.json'),'whole_helper_addition':{'before':'ABSENT_IN_R1_NOT_A_FABRICATED_BEFORE_FILE','reviewed_source':ptr(HELPER),'copy':ptr(ROOT/'finite-helper.py')},'interface_changes':['own_r2_Source_and_actual_paths','run_argv_and_launch_argv_native_Windows_str_Path','own_copy_pointer'],'unchanged_result_schema':True}))
code={'schema':1,'files':{name:fp((ROOT/name).read_bytes()) for name in ['run.py','launch.py','DESIGN.md','CANDIDATE-SOURCE.json']}}
put(ROOT/'CODE-PINS.json',enc(code))
names=['CANDIDATE-SOURCE.json','CODE-PINS.json','DESIGN.md','launch.py','run.py','RESULT-INTERFACE.json','candidates/ms4-initialize.php','finite-helper.py']
def patch(reverse=False):
    chunks=[]
    for name in names:
        a=(OLD/name).read_bytes() if name!='finite-helper.py' else b''
        b=(ROOT/name).read_bytes()
        fromname='a/'+name if name!='finite-helper.py' else '/dev/null';toname='b/'+name
        if reverse:a,b=b,a;fromname='a/'+name;toname='b/'+name if name!='finite-helper.py' else '/dev/null'
        chunks.extend(difflib.unified_diff(a.decode().splitlines(keepends=True),b.decode().splitlines(keepends=True),fromfile=fromname,tofile=toname,n=3))
    return ''.join(chunks).encode()
put(ROOT/'SOURCE-FORWARD.diff',patch())
put(ROOT/'SOURCE-REVERSE.diff',patch(True))
print(json.dumps({'status':'R2_SOURCE_ONLY_CONSTRUCTED','run':fp((ROOT/'run.py').read_bytes()),'launch':fp((ROOT/'launch.py').read_bytes()),'whole_local_helper':fp(helper),'jobs_executed':0}))
