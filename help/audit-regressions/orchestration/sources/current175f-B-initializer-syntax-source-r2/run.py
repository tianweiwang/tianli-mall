import hashlib,importlib.util,json,re,subprocess,sys,time
from pathlib import Path
sys.dont_write_bytecode=True
SOURCE_ROOT=Path(__file__).resolve().parent;B=SOURCE_ROOT.parents[1]/'ms4-worker-normal-source-preparation-r1'
OUT=SOURCE_ROOT.parent/'current175f-B-initializer-syntax-actual-r2'
HELPER=SOURCE_ROOT/'finite-helper.py'
sp=importlib.util.spec_from_file_location('own_closed_reader',HELPER);h=importlib.util.module_from_spec(sp);sp.loader.exec_module(h)
fp,enc,need=h.fp,h.enc,h.need
DOCKER='C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
IMAGE='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a'
LABEL='codex.ms4.syntax=current175f-b-ui-initializer-r2'
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


def ended_s_authority(read):
    def require(ok, label):
        if not ok:
            raise RuntimeError(label)
    def typed(path, raw):
        return {'path': path.as_posix(), 'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
    require(sys.argv[1:] == ['--authorize-ended-S17155-root-admitted'], 'EXPLICIT_ROOT_ADMISSION_AFTER_ORIGINAL_S17155_ENDED_REQUIRED')
    root = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1')
    source = root / 'launcher/launch.py'
    source_raw = read(source)
    require(typed(source, source_raw) == {'path': source.as_posix(), 'bytes': 15839, 'sha256': '2e3bf0739a978420241f7c75128abfb4528c2d911d9e73230f4c0ef3dd3aad25'}, 'EXACT_ORIGINAL_S_LAUNCHER_SOURCE')
    created_path = root / 'launcher/normal-start.created.json'
    terminal_path = root / 'launcher/normal-start.terminal.json'
    created_raw, terminal_raw = read(created_path), read(terminal_path)
    created, terminal = json.loads(created_raw), json.loads(terminal_raw)
    require(type(created) is dict and set(created) == {'pid', 'argv', 'started_ns'}, 'ORIGINAL_S_CREATED_EXACT_THREE_FIELDS')
    require(type(terminal) is dict and set(terminal) == {'pid', 'native_exit', 'wait_returncode', 'finished_ns', 'stdout', 'stderr'}, 'ORIGINAL_S_TERMINAL_EXACT_SIX_FIELDS')
    expected_argv = ['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe', '-B', '-X', 'utf8', str(root / 'validation/run.py'), '--root-source-pins-sha256', '0516d5f9eff69c4a36ab8b2a012333fbbcd9c2751614330ee9459cb391fe32a4', '--setup-sha256', '6449487f1da61266fbdfe76260fced9196b398f1cbb92cc59074924d8e76c8aa']
    require(type(created['pid']) is int and created['pid'] == terminal['pid'] == 35944 and created['argv'] == expected_argv, 'EXACT_ORIGINAL_S_PID_AND_CURRENT_SOURCE_M_ARGV')
    require(type(terminal['native_exit']) is int and type(terminal['wait_returncode']) is int and terminal['native_exit'] == terminal['wait_returncode'], 'ORIGINAL_S_ACTUAL_NATIVE_AND_WAIT_TERMINATED')
    require(type(created['started_ns']) is int and type(terminal['finished_ns']) is int and terminal['finished_ns'] >= created['started_ns'] > 0, 'ORIGINAL_S_ACTUAL_TIME_ORDER')
    for ext in ['stdout', 'stderr']:
        row = terminal[ext]
        require(type(row) is dict and set(row) == {'path', 'bytes', 'sha256'} and row['path'] == (root / ('launcher/normal-start.' + ext)).as_posix() and type(row['bytes']) is int and row['bytes'] >= 0 and isinstance(row['sha256'], str) and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None, 'ORIGINAL_S_STREAM_POINTER_SCHEMA_WITHOUT_PAYLOAD_READ')
    return {'authorization': 'EXPLICIT_ROOT_OPERATOR_CLI_AFTER_ENDED_S', 'required_flag': '--authorize-ended-S17155-root-admitted', 'original_tool_session': 17155, 'original_S_pid': 35944, 'source_launcher': typed(source, source_raw), 'created': typed(created_path, created_raw), 'terminal': typed(terminal_path, terminal_raw), 'original_S_native_exit': terminal['native_exit'], 'original_S_wait_returncode': terminal['wait_returncode'], 'raw_child_streams_read': False}


def main():
    need(OUT.is_dir() and not (OUT/'RESULT.json').exists(),'UNIQUE_CANDIDATE_LINT')
    started=time.time_ns();r=h.Finite()
    authority=ended_s_authority(r.read)
    r.read(HELPER,{'bytes': 33935, 'sha256': 'a84cb0edd5beaa998a26f8b96819b765e8cfe83e52a62619846b431c417855c0'})
    code_pins_raw=r.read(SOURCE_ROOT/'CODE-PINS.json');code_pins=json.loads(code_pins_raw)
    need(code_pins['schema']==1 and set(code_pins['files'])=={'run.py','launch.py','DESIGN.md','CANDIDATE-SOURCE.json'},'EXACT_SYNTAX_JOB_SOURCE_NAMES')
    for name,pin in code_pins['files'].items():r.read(SOURCE_ROOT/name,pin)
    manifest_path=SOURCE_ROOT/'CANDIDATE-SOURCE.json';manifest_raw=r.read(manifest_path);manifest=json.loads(manifest_raw)
    need(manifest['schema']==1 and manifest['scope']=='ONE_CURRENT175F_COMPLETE_B_UI_INITIALIZER_PHP82_SYNTAX_SOURCE_ONLY'
        and manifest['source_sha']=='175f3cd6183894185656a2974579e1be39eed7a7','EXACT_ACTUAL_SOURCE')
    for key,expected,scope in [('leaf_preparation_PINS',{'bytes':3920,'sha256':'0a0bd107121995480b3220e173387252f5e7c199aa8d142c0422158e24bd465f'},'COMPLETE_IMMUTABLE_SOURCE_PREPARATION_PACKET_NOT_B_OUTPUT_PINS'),
        ('author_preparation_PINS',{'bytes':5846,'sha256':'0894afc646de39ff557884843471aacdf102875fa59239214f656df6c66b8a0d'},'FULL_IMMUTABLE_SOURCE_PREPARATION_WITH_R1_ARCHIVE_NOT_B_OUTPUT')]:
        item=manifest[key];need({k:item[k] for k in ['bytes','sha256']}==expected,'FIXED_PACKET_POINTER')
        path=Path(item['path']);packet=json.loads(r.read(path,item));need(packet['schema']==1 and packet['scope']==scope and type(packet['files']) is dict,'EXACT_PACKET')
        for name,pin in packet['files'].items():r.read(path.parent/name,pin)
    parent=SOURCE_ROOT/'candidates';path=parent/'ms4-initialize.php'
    need(sorted(p.name for p in parent.iterdir())==['ms4-initialize.php'] and path.is_file() and not path.is_symlink(),'ONE_OWN_ORDINARY_FULL_COPY')
    need(manifest['copy']['path']==path.as_posix(),'EXACT_COPY_PATH')
    actual=r.read(Path(manifest['candidate']['path']),manifest['candidate'])
    need(fp(actual)=={'bytes': 118698, 'sha256': '29376b4266010285ec4cebd92f0197d849142988442ead06cbcc7d26ff840db8'}
        and r.read(path,manifest['copy'])==actual,'FULL_REVIEWED_INITIALIZER_EQUAL_COPY')
    author_root=Path(manifest['author_preparation_PINS']['path']).parent
    need(r.read(author_root/'leaf/ms4-initialize.php')==actual,'EXACT_R2_LEAF_WHOLE_JOIN_WITH_ORIGINAL_R1_POINTER')
    for item in manifest['evidence']:r.read(Path(item['path']),item)
    _,before,err=native('own-label-before',['ps','-a','-q','--no-trunc','--filter','label='+LABEL]);need(before==err==b'','OWN_LABEL_BEFORE_EMPTY')
    _,raw,err=native('existing-fixed-image',['image','inspect','--format','{{.Id}}',IMAGE]);need(raw==(IMAGE+'\n').encode() and err==b'','EXISTING_FIXED_PHP82_IMAGE')
    command='php -l /candidate/ms4-initialize.php'
    args=['create','--pull','never','--name','ms4-current175f-b-ui-initializer-php-l-r2','--label',LABEL,'--network','none','--read-only','--user','65534:65534','--cap-drop','ALL','--security-opt','no-new-privileges:true','--memory','128m','--pids-limit','32','--tmpfs','/tmp:rw,nosuid,nodev,size=16m,mode=1777','--mount','type=bind,source='+parent.as_posix()+',target=/candidate,readonly','--entrypoint','/bin/sh',IMAGE,'-c',command]
    _,raw,err=native('create-one-owned-lint-container',args);cid=raw.decode().strip();need(re.fullmatch('[a-f0-9]{64}',cid) is not None and err==b'','EXACT_OWN_CID')
    code,stdout,stderr=native('attach-original-php-l',['start','-a',cid],allow_nonzero=True)
    _,waited,waiterr=native('same-cid-native-wait',['wait',cid])
    _,raw,inspecterr=native('same-cid-native-terminal',['inspect','--format','{"id":{{json .Id}},"image":{{json .Image}},"state":{{json .State}},"restart":{{json .RestartCount}},"labels":{{json .Config.Labels}},"network":{{json .HostConfig.NetworkMode}},"readonly":{{json .HostConfig.ReadonlyRootfs}},"user":{{json .Config.User}},"mounts":{{json .Mounts}}}',cid]);state=json.loads(raw)
    need(state['id']==cid and state['image']==IMAGE and state['state']['Status']=='exited' and state['state']['Pid']==0 and state['restart']==0 and state['network']=='none' and state['readonly'] is True and state['user']=='65534:65534' and state['labels']['codex.ms4.syntax']=='current175f-b-ui-initializer-r2','EXACT_OWN_NATIVE_TERMINAL')
    need(len(state['mounts'])==1 and state['mounts'][0]['Destination']=='/candidate' and state['mounts'][0]['Type']=='bind' and state['mounts'][0]['RW'] is False
        and state['mounts'][0]['Source'].replace('\\','/')==parent.as_posix(),'ONE_EXACT_RO_CANDIDATE_BIND')
    _,removed,rmerr=native('ordinary-exact-owned-cid-rm',['rm',cid]);need(removed==(cid+'\n').encode() and rmerr==b'','OWN_TERMINAL_CID_NORMAL_REMOVED')
    _,after,err=native('own-label-after',['ps','-a','-q','--no-trunc','--filter','label='+LABEL]);need(after==err==b'','OWN_LABEL_AFTER_EMPTY')
    expected_stdout=b'No syntax errors detected in /candidate/ms4-initialize.php\n'
    passed=code==state['state']['ExitCode']==0 and waited==b'0\n' and stdout==expected_stdout and stderr==waiterr==inspecterr==b''
    ended=r.end();put('INPUTS.json',enc({'files':list(r.rows.values()),'all_windows_ended':True,'ended_ns':ended}))
    result={'status':'PASS_CURRENT175F_COMPLETE_B_UI_INITIALIZER_PHP82_SYNTAX_ONLY' if passed else 'FAIL_CANDIDATE_SYNTAX','started_ns':started,'ended_ns':ended,
        'source_sha':manifest['source_sha'],'candidate':manifest['candidate'],'copy':manifest['copy'],'leaf_preparation_PINS':manifest['leaf_preparation_PINS'],'author_preparation_PINS':manifest['author_preparation_PINS'],
        'candidate_source':{'path':manifest_path.as_posix(),**fp(manifest_raw)},'job_code_PINS':{'path':(SOURCE_ROOT/'CODE-PINS.json').as_posix(),**fp(code_pins_raw)},
        'pre_execution_authority':authority,'php_l_count_required':1,'container_id':cid,'container_native_exit':state['state']['ExitCode'],'operations':OPERATIONS,'own_label_before_after_empty':True,'ordinary_own_container_removed':True,
        'all_input_windows_ended':True,'initializer_or_M_S_App_business_execution':'NOT RUN','W_Val_Run_mutations':0}
    put('RESULT.json',enc(result));print(json.dumps({'status':result['status'],'RESULT':fp(enc(result)),'operations':len(OPERATIONS),'own_container_removed':True}));raise SystemExit(0 if passed else 1)
if __name__=='__main__':main()
