import importlib.util,json,re,subprocess,sys,time
from pathlib import Path
sys.dont_write_bytecode=True
SOURCE_ROOT=Path(__file__).resolve().parent;B=SOURCE_ROOT.parent
OUT=B/'published-active-contract-initializer-php-syntax-actual-r1'
HELPER=B/'customer-form-current-three-front-material-producer-independent-r1/review.py'
sp=importlib.util.spec_from_file_location('own_closed_reader',HELPER);h=importlib.util.module_from_spec(sp);sp.loader.exec_module(h)
fp,enc,need=h.fp,h.enc,h.need
DOCKER='C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
IMAGE='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a'
LABEL='codex.ms4.syntax=published-active-contract-initializer-r1'
OPERATIONS=[]

def put(name, raw):
    with (OUT / name).open('xb') as stream:
        need(stream.write(raw) == len(raw), 'COMPLETE_OWN_NEW_OUTPUT')


def native(name, args, allow_nonzero=False):
    stem = '%02d-' % (len(OPERATIONS) + 1) + name
    argv = [DOCKER, *args]
    process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    created = {'pid': process.pid, 'argv': argv, 'started_ns': time.time_ns()}
    put(stem + '.created.json', enc(created))
    stdout, stderr = process.communicate()
    waited = process.wait()
    put(stem + '.stdout', stdout)
    put(stem + '.stderr', stderr)
    row = {**created, 'native_exit': process.returncode, 'wait_returncode': waited,
           'finished_ns': time.time_ns(), 'stdout': fp(stdout), 'stderr': fp(stderr), 'stem': stem}
    put(stem + '.terminal.json', enc(row))
    OPERATIONS.append(row)
    need(allow_nonzero or process.returncode == 0, 'ACTUAL_NATIVE_EXIT:' + name)
    return process.returncode, stdout, stderr


def main():
    need(OUT.is_dir() and not (OUT/'RESULT.json').exists(),'UNIQUE_CANDIDATE_LINT')
    started=time.time_ns();r=h.Finite()
    r.read(HELPER,{'bytes':33902,'sha256':'4673d95c60c22f4653b6f22f833e24fb6accc3b286c555ca0462b0fb501252e0'})
    code_pins_raw=r.read(SOURCE_ROOT/'CODE-PINS.json');code_pins=json.loads(code_pins_raw)
    need(code_pins['schema']==1 and set(code_pins['files'])=={'run.py','launch.py','DESIGN.md'},'EXACT_SYNTAX_JOB_SOURCE_NAMES')
    for name,pin in code_pins['files'].items():r.read(SOURCE_ROOT/name,pin)
    a_source=B/'full-a-published-current-normal-next-source-r2/profile-source'
    a_pins=a_source/'PINS.json';a_pin={'bytes':1954,'sha256':'4e34ce9f62401664a82d1d8543c080043fe3b57cf3337f13cd87fa9fcf7725fe'}
    a_document=json.loads(r.read(a_pins,a_pin))
    a_manifest=a_source/'MANIFEST.json';manifest_pin={'bytes':3150,'sha256':'2880544ee5790df6877ab45ca0fc0aafab06e3254d5a7f4f24f8d95dd49b23c0'}
    r.read(a_manifest,manifest_pin)
    parent=a_source/'candidate'
    sources=[{'path':(parent/'ms4-initialize.php').as_posix(),'bytes':78955,'sha256':'7046b79ca32b4faec307efa40547f8435a93b7fc8a0f3fac5214e44303d4a610'}]
    need(a_document['schema']==1 and a_document['scope']=='SOURCE_ONLY_ACTIVE_INDEPENDENT_CALLER_CONTRACT_REPAIR'
        and {k:a_document['manifest'][k] for k in ['bytes','sha256']}==manifest_pin
        and a_document['candidate']['ms4-initialize.php']=={k:sources[0][k] for k in ['bytes','sha256']},'EXACT_CURRENT_PROFILE_INITIALIZER_SOURCE_PINS')
    for row in sources:r.read(Path(row['path']),row)
    _,before,err=native('own-label-before',['ps','-a','-q','--no-trunc','--filter','label='+LABEL]);need(before==err==b'','OWN_LABEL_BEFORE_EMPTY')
    _,raw,err=native('existing-fixed-image',['image','inspect','--format','{{.Id}}',IMAGE]);need(raw==(IMAGE+'\n').encode() and err==b'','EXISTING_FIXED_PHP82_IMAGE')
    command='php -l /candidate/ms4-initialize.php'
    args=['create','--pull','never','--name','ms4-published-active-contract-initializer-php-l-r1','--label',LABEL,'--network','none','--read-only','--user','65534:65534','--cap-drop','ALL','--security-opt','no-new-privileges:true','--memory','128m','--pids-limit','32','--tmpfs','/tmp:rw,nosuid,nodev,size=16m,mode=1777','--mount','type=bind,source='+parent.as_posix()+',target=/candidate,readonly','--entrypoint','/bin/sh',IMAGE,'-c',command]
    _,raw,err=native('create-one-owned-lint-container',args);cid=raw.decode().strip();need(re.fullmatch('[a-f0-9]{64}',cid) is not None and err==b'','EXACT_OWN_CID')
    code,stdout,stderr=native('attach-original-php-l',['start','-a',cid],allow_nonzero=True)
    _,waited,waiterr=native('same-cid-native-wait',['wait',cid])
    _,raw,inspecterr=native('same-cid-native-terminal',['inspect','--format','{"id":{{json .Id}},"image":{{json .Image}},"state":{{json .State}},"restart":{{json .RestartCount}},"labels":{{json .Config.Labels}},"network":{{json .HostConfig.NetworkMode}},"readonly":{{json .HostConfig.ReadonlyRootfs}},"user":{{json .Config.User}},"mounts":{{json .Mounts}}}',cid]);state=json.loads(raw)
    need(state['id']==cid and state['image']==IMAGE and state['state']['Status']=='exited' and state['state']['Pid']==0 and state['restart']==0 and state['network']=='none' and state['readonly'] is True and state['user']=='65534:65534' and state['labels']['codex.ms4.syntax']=='published-active-contract-initializer-r1','EXACT_OWN_NATIVE_TERMINAL')
    need(len(state['mounts'])==1 and state['mounts'][0]['Destination']=='/candidate' and state['mounts'][0]['Type']=='bind' and state['mounts'][0]['RW'] is False
        and state['mounts'][0]['Source'].replace('\\','/')==parent.as_posix(),'ONE_EXACT_RO_CANDIDATE_BIND')
    _,removed,rmerr=native('ordinary-exact-owned-cid-rm',['rm',cid]);need(removed==(cid+'\n').encode() and rmerr==b'','OWN_TERMINAL_CID_NORMAL_REMOVED')
    _,after,err=native('own-label-after',['ps','-a','-q','--no-trunc','--filter','label='+LABEL]);need(after==err==b'','OWN_LABEL_AFTER_EMPTY')
    expected_stdout=b'No syntax errors detected in /candidate/ms4-initialize.php\n'
    passed=code==state['state']['ExitCode']==0 and waited==b'0\n' and stdout==expected_stdout and stderr==waiterr==inspecterr==b''
    ended=r.end();put('INPUTS.json',enc({'files':list(r.rows.values()),'all_windows_ended':True,'ended_ns':ended}))
    result={'status':'PASS_CANDIDATE_PHP82_SYNTAX_ONLY' if passed else 'FAIL_CANDIDATE_SYNTAX','started_ns':started,'ended_ns':ended,'sources':sources,
        'candidate_Source_PINS':{'path':a_pins.as_posix(),**a_pin},'candidate_Source_manifest':{'path':a_manifest.as_posix(),**manifest_pin},'job_code_PINS':{'path':(SOURCE_ROOT/'CODE-PINS.json').as_posix(),**fp(code_pins_raw)},
        'php_l_count_required':1,'container_id':cid,'container_native_exit':state['state']['ExitCode'],'operations':OPERATIONS,'own_label_before_after_empty':True,'ordinary_own_container_removed':True,'all_input_windows_ended':True,'candidate_entry_or_manifest_execution':'NOT RUN','App_or_business':'NOT RUN','M_A_B_postA_or_business_acceptance':'NOT RUN','W_Val_Run_mutations':0}
    put('RESULT.json',enc(result));print(json.dumps({'status':result['status'],'RESULT':fp(enc(result)),'operations':len(OPERATIONS),'own_container_removed':True}));raise SystemExit(0 if passed else 1)
if __name__=='__main__':main()
