from pathlib import Path
import subprocess,json,re,time,hashlib,sys
SOURCE_ROOT=Path(__file__).resolve().parent
OUT=SOURCE_ROOT.parent/'current175f-B-initializer-syntax-actual-r2'
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

ended_s_authority(lambda path: path.read_bytes())
OUT.mkdir()
PY='C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
def put(name,value):
 with (OUT/name).open('x',encoding='utf-8',newline='\n') as f:json.dump(value,f,ensure_ascii=False,indent=2);f.write('\n')
argv=[PY,'-B','-X','utf8',str(SOURCE_ROOT/'run.py'),*sys.argv[1:]]
with (OUT/'outer.stdout').open('xb') as out,(OUT/'outer.stderr').open('xb') as err:
 p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=out,stderr=err)
 made={'pid':p.pid,'argv':argv,'started_ns':time.time_ns()};put('outer.created.json',made)
 rc=p.wait();waited=p.wait()
terminal={**made,'native_exit':rc,'wait_returncode':waited,'finished_ns':time.time_ns()}
for name in ['stdout','stderr']:
 raw=(OUT/('outer.'+name)).read_bytes();terminal[name+'_bytes']=len(raw);terminal[name+'_sha256']=hashlib.sha256(raw).hexdigest()
put('outer.terminal.json',terminal)
print(json.dumps(terminal));sys.exit(rc)
