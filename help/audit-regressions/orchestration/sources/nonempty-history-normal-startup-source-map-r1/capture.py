"""Finite startup Source capture only. Never imports or runs PHP/targets/resources."""
from pathlib import Path
import hashlib,json,os,subprocess,time
B=Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1');OLD=Path('H:/天俪商城/tianli-mall--mm')
O=B/'nonempty-history-normal-startup-source-map-r1';SHA='477732e3e03ed4ba1dbb6b44a94dbba75f7e1978'
PUBLIC=B/'nonempty-history-normal-startup-public-source-r1'
FIELDS=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');windows=[]
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def read(p):
    t=time.time_ns();a=p.lstat();n=lambda s:{k:int(getattr(s,k)) for k in FIELDS};assert not p.is_symlink()
    with p.open('rb') as f:
        b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
    d=p.lstat();assert n(a)==n(d) and n(b)==n(c) and all(getattr(a,k)==getattr(b,k) for k in FIELDS[:-1]) and len(raw)==a.st_size
    windows.append({'path':p.as_posix(),**pin(raw),'named_before_after':n(a),'fd_before_after':n(b),
                    'started_ns':t,'ended_ns':time.time_ns(),'EOF':True,'closed':True});return raw
def git(*args):
    r=subprocess.run(['git','-c','safe.directory='+OLD.as_posix(),'-C',OLD.as_posix(),*args],capture_output=True)
    assert r.returncode==0,(args,r.returncode);return r.stdout
paths=['vendor/workerman/workerman/Worker.php','vendor/workerman/workerman/Connection/TcpConnection.php',
 'vendor/workerman/workerman/Connection/ConnectionInterface.php','vendor/workerman/channel/src/Server.php',
 'vendor/workerman/workerman/Protocols/Frame.php','crmeb/compat/workerman/Events/Select.php',
 'vendor/workerman/workerman/Lib/Timer.php','vendor/workerman/workerman/Autoloader.php']
rels=['crmeb/'+p for p in paths]
assert git('rev-parse','HEAD').decode().strip()==SHA
assert set(x for x in git('ls-files','-z','--',*rels).decode().split('\0') if x)==set(rels)
assert git('diff','--exit-code',SHA,'--',*rels)==b''
text={p:read(OLD/'crmeb'/p).decode('utf-8-sig') for p in paths}
w=text[paths[0]];tcp=text[paths[1]];server=text[paths[3]]
def at(t,lo,hi,needle):assert needle in '\n'.join(t.splitlines()[lo-1:hi]),(lo,hi,needle)
at(w,503,518,'static::displayUI();');at(w,503,518,'static::forkWorkers();')
assert w.index('static::displayUI();')<w.index('static::forkWorkers();')
at(w,564,567,'chmod($log_file, 0622);');at(w,857,857,'static::log("Workerman[$start_file] $command $mode");')
at(w,780,790,"'proto'     =>  'transport'");at(w,780,790,"'socket'    =>  'socket'")
at(w,732,738,"str_pad('PHP version:', 22, ' ', STR_PAD_LEFT)")
at(w,768,768,'static::safeEcho("Press Ctrl+C to stop. Start success.\\n");')
at(w,1217,1227,'file_put_contents(static::$pidFile, static::$_masterPid)')
at(w,706,710,'posix_getpwuid(posix_getuid())');at(w,2304,2307,"lcfirst($this->_socketName)")
at(w,2133,2135,'static::$_outputDecorated = false;')
at(tcp,20,25,'#[\\AllowDynamicProperties]')
at(server,20,28,'new Worker("frame://$ip:$port")');at(server,56,107,"case 'subscribe':")
at(server,56,107,"case 'publish':");at(server,56,107,"case 'unsubscribe':")
assert all(x not in server for x in ['Redis','PDO','file_put_contents','fopen(','require','include'])

r2=B/'nonempty-history-normal-parent-components-source-r2'
pins=read(r2/'PINS.json');assert pin(pins)=={'bytes':1681,'sha256':'c822fb9206724d38455534bce4363f5be82e11274959d2d4282cee1dcbf3ff7d'}
p=read(r2/'candidate/legacy-history-producer.php');assert pin(p)=={'bytes':1052008,'sha256':'6a01674bdabfdfb2ff40a7df915642889373f05ee948aeae204429f5e84be48a'}
p=p.decode('utf8')
at(p,188,217,"['type' => 'publish', 'channels' => [$private], 'data' => $ready]")
at(p,458,533,"!array_key_exists('PHP_CLI_SERVER_WORKERS', $environment)")
at(p,458,533,"['setsid', PHP_BINARY, '-S', '127.0.0.1:8000'")
at(p,2192,2217,'PHP_VERSION_ID === 80233');at(p,2192,2217,'nhep_http_utc_template()')
at(p,2219,2237,'strlen($text) === 24 && $canonical === $text')
ch=read(B/'nonempty-history-normal-close-components-source-r1/candidate/legacy-history-channel.php')
assert pin(ch)=={'bytes':11296,'sha256':'98f1b863f3a5538e1c9ba867e58206adafdceb57d40faad39ca92e4018957ffc'}
for needle in [b"date_default_timezone_set('UTC')",b"sys_get_temp_dir() === '/tmp'",b'\\Workerman\\Worker::$daemonize = false;',b'$worker->count === 1']:
    assert needle in ch
for folder,size,sha in [('nonempty-history-normal-close-source-map-r1',22372,'6cd5d1d1577062a7765615397061cf917c4ef50f9aaca0623b20f121891f2c10'),
 ('nonempty-history-normal-close-stop-metadata-source-map-r1',13846,'ffbb2428c87752ede012ba0ba65073c9c9ffb105ef7c27fc6567559644a7edec')]:
    raw=read(B/folder/'SOURCE-MAP.json');assert pin(raw)=={'bytes':size,'sha256':sha}
pp=read(PUBLIC/'PINS.json');assert pin(pp)=={'bytes':926,'sha256':'3b703f70daf26a2dda3c064bb3ecc25f0df935b6cc45c6ab04b16b88f15be2ed'}
pub=json.loads(pp);public_raw={}
for name,want in pub['members'].items():
    assert '..' not in Path(name).parts and not Path(name).is_absolute();raw=read(PUBLIC/name);assert pin(raw)==want;public_raw[name]=raw
src=json.loads(public_raw['SOURCE.json']);ref=json.loads(public_raw['upstream/tag-ref.json']);tag=json.loads(public_raw['upstream/tag-object.json'])
assert src['actual_commit']=='651db3ebfa622cae0c4e6b39766812efbd274ced'
assert ref['object']['sha']==tag['sha'] and tag['object']['sha']==src['actual_commit'] and tag['object']['type']=='commit'
c=public_raw['upstream/sapi/cli/php_cli_server.c'].decode('utf8')
assert pin(public_raw['upstream/sapi/cli/php_cli_server.c'])=={'bytes':85378,'sha256':'6843266e3a7458e89c74c6d133ec21ae715da8c0708e718965db65fa560d4e63'}
at(c,258,268,'php_localtime_r(&tv.tv_sec, &tm);');at(c,258,268,'php_asctime_r(&tm, buf);')
at(c,108,108,'static zend_long php_cli_server_workers_max;')
at(c,210,214,'static int php_cli_server_log_level = 3;')
at(c,759,785,'fprintf(stderr, "[%s] %s\\n", buf, msg);')
at(c,2487,2491,'char *workers = getenv("PHP_CLI_SERVER_WORKERS");');at(c,2487,2491,'return;')
at(c,2527,2588,'server_sock = php_network_listen_socket(');at(c,2581,2588,'server->router = _router;')
at(c,2864,2871,'"PHP %s Development Server (http://%s%s%s:%d) started"')
at(c,2881,2885,'php_cli_server_do_event_loop(&server);')
at(c,2247,2260,'zend_stream_init_filename(&zfd, server->router);')
read(B/'startup-public-source-addendum-r1/ROOT-PUBLIC-INPUTS.json')
read(O/'DESIGN-MAP.md');read(O/'capture.py')
assert git('rev-parse','HEAD').decode().strip()==SHA and git('diff','--exit-code',SHA,'--',*rels)==b''
ended=time.time_ns();assert all(row['ended_ns']<=ended for row in windows)
r={'schema':1,'status':'FINITE_NORMAL_STARTUP_SOURCE_MAP_NO_TARGET_RUN',
 'old_source_sha':SHA,'targeted_old_paths':rels,'targeted_old_tracked_diff_exit':0,'targeted_old_source_count':len(rels),
 'original_parent_R2':{'bytes':1052008,'sha256':'6a01674bdabfdfb2ff40a7df915642889373f05ee948aeae204429f5e84be48a'},
 'official_PHP_source_commit':src['actual_commit'],'official_PHP_source_PINS':pin(pp),
 'normal_effects':['four original private streams','one0700 channel directory','one0622 Workerman log startup line',
  'one0600 master PID file no LF','complete original master stdout startup line plus seven UI lines',
  'HTTP stdout empty and one complete C stderr startup line','only original Source-fixed native groups/listeners and private readiness RAM/socket state'],
 'no_business_DB_Redis_or_framework_file_write_on_normal_ready_before_first_HTTP_request':True,
 'required_oracle_qualifications':['complete prepared DB164/wholeRedis/five-tree BEFORE join','independent expected fixed before child effects',
  'original native UID/passwd name and exact compile PHP version before body derivation','full original Source-qualified UI formula and no extra output',
  'full same-handle/stat9 stream read and Source first-line gate: listener alone is earlier than HTTP startup log',
  'only allowed kernel directory/native variable fields bound under actual startup walls','fullDB/Redis/five-tree comparison and no ignored tmp statistics leaf'],
 'windows':windows,'all_read_windows_closed':True,'ended_ns':ended,
 'target_executed':False,'Run_WVal_canonical_actual_private_or_resources_read_or_modified':False,
 'not_proven':['startup plan/accept implementation','PHP syntax/runtime','actual normal startup or complete37 acceptance']}
raw=(json.dumps(r,ensure_ascii=False,indent=2)+'\n').encode()
with (O/'SOURCE-MAP.json').open('xb') as f:assert f.write(raw)==len(raw)
print(json.dumps({'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'old_sources':len(rels),'windows':len(windows),'ended_ns':ended,'target_execution':False}))
