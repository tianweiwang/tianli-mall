"""Root-invoked isolated syntax only. Never includes or executes a PHP leaf."""
from pathlib import Path
import argparse,hashlib,json,os,re,stat,subprocess,sys,time

SOURCE_ROOT=Path(__file__).resolve().parent
INTEGRATION=SOURCE_ROOT/'integration'
OUT=SOURCE_ROOT.parent/'currentbafb-ui-two-php-syntax-actual-r1'
DOCKER='C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
IMAGE='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a'
LABEL='codex.ms4.syntax=currentbafb-ui-two-r1'
OPERATIONS=[]
READS={}

def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(value):return (json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode()
def need(ok,label):
    if not ok:raise RuntimeError(label)

def read(path,expected=None):
    path=Path(path);before=path.lstat()
    need(stat.S_ISREG(before.st_mode) and not path.is_symlink() and not getattr(before,'st_file_attributes',0)&0x400,'REGULAR_SOURCE_NO_REPARSE')
    keys=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');stamp=lambda value:[getattr(value,k) for k in keys]
    with path.open('rb') as stream:
        opened=os.fstat(stream.fileno());raw=stream.read();need(stream.read(1)==b'' and len(raw)==before.st_size,'COMPLETE_SOURCE_EOF')
        ended=os.fstat(stream.fileno())
    after=path.lstat()
    need(stamp(before)==stamp(after) and before.st_birthtime_ns==after.st_birthtime_ns and stamp(opened)==stamp(ended) and opened.st_birthtime_ns==ended.st_birthtime_ns,'OWN_NAMED_AND_FD_COMPLETE_NINE_STABLE')
    need([stamp(before)[i] for i in (0,1,6,7)]==[stamp(opened)[i] for i in (0,1,6,7)],'NAMED_FD_CROSS_FOUR')
    if expected is not None:need(fp(raw)=={k:expected[k] for k in ('bytes','sha256')},'COMPLETE_SOURCE_PIN')
    row={'path':path.as_posix(),**fp(raw),'named9':stamp(before),'fd9':stamp(opened),'named_birthtime_ns':before.st_birthtime_ns,'fd_birthtime_ns':opened.st_birthtime_ns,'EOF':True,'closed':True}
    need(path.as_posix() not in READS or READS[path.as_posix()]==row,'ALL_FIRST_FINAL_SOURCE_BYTES_AND_IDENTITIES_EQUAL')
    READS.setdefault(path.as_posix(),row);return raw

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

def qualify(args):
    need(not sys.flags.optimize and args.authorize_iso_php_l_only,'ROOT_EXPLICIT_ISOLATED_SYNTAX_SCOPE')
    need(re.fullmatch('[a-f0-9]{64}',args.syntax_source_pins_sha256) is not None,'EXACT_REVIEWED_SOURCE_PINS_HASH')
    source_raw=read(SOURCE_ROOT/'PINS.json');need(fp(source_raw)['sha256']==args.syntax_source_pins_sha256,'REAL_SYNTAX_SOURCE_PACKET')
    packet=json.loads(source_raw);need(packet['status']=='SOURCE_ONLY_NOT_RUNTIME_ADMITTED','SOURCE_PACKET_SCOPE')
    paths={p.relative_to(SOURCE_ROOT).as_posix() for p in SOURCE_ROOT.rglob('*') if p.is_file()}
    need(paths==set(packet['files'])|{'PINS.json'},'EXACT_SYNTAX_SOURCE_NAMESPACE')
    for name,pin in packet['files'].items():read(SOURCE_ROOT/name,pin)
    manifest_raw=read(INTEGRATION/'MANIFEST.json',{'bytes': 2094, 'sha256': '160e584225f64b65139a28fd94c682b4c5064f80c5c89d428d3e0dc3d8d6417e'})
    need(args.integration_manifest_sha256==fp(manifest_raw)['sha256'],'ROOT_REAL_INTEGRATION_MANIFEST_HASH')
    read(INTEGRATION/'PINS.json',{'bytes': 790, 'sha256': '045bbc5639f2dab6967a216a5467c92dee7f07efeb5e0badfa3cacc8e59b4e77'})
    manifest=json.loads(manifest_raw);leaves=json.loads(read(SOURCE_ROOT/'LEAVES.json'))
    source_packet=json.loads(read(INTEGRATION/'SOURCE-PINS.json',{'bytes': 8878, 'sha256': '6ab784d3e3fff270a38d948411d12f0849b8df969c58bc56014a47cc3a2d30c6'}))
    need(len(leaves)==2 and len({row['bundle_name'] for row in leaves})==2,'TWO_EXACT_WHOLE_PHP_LEAVES')
    for row in leaves:
        expected=manifest['candidate'][row['integration_relative']]
        source_pin=source_packet['files']['candidate/'+row['integration_relative']]
        need({k:source_pin[k] for k in ('bytes','sha256')}==expected,'REVIEWED_ORIGINAL_SOURCE_PINS_WHOLE_LEAF')
        need({k:row[k] for k in ('bytes','sha256')}==expected,'REAL_INTEGRATION_WHOLE_LEAF')
        need(read(INTEGRATION/'candidate'/row['integration_relative'],expected)==read(SOURCE_ROOT/'bundle'/row['bundle_name'],expected),'COMPLETE_COPIED_LEAF_BYTES_EQUAL')
    need(not OUT.exists() and OUT.parent.is_dir(),'NEW_OWN_ACTUAL_OUTPUT_ONLY')
    return packet,leaves

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--syntax-source-pins-sha256',required=True);parser.add_argument('--integration-manifest-sha256',required=True);parser.add_argument('--authorize-iso-php-l-only',action='store_true');args=parser.parse_args()
    packet,leaves=qualify(args)
    plan=json.loads(read(SOURCE_ROOT/'PLAN.json'));steps=plan['host_native_steps']
    need(steps==['own-label-before','existing-fixed-image','create-one-owned-lint-container','attach-two-php-l','same-cid-native-wait','same-cid-native-terminal','ordinary-exact-owned-cid-rm','own-label-after'],'ACTUAL_FIXED_NATIVE_PLAN')
    OUT.mkdir();put('runner-source.py',read(Path(__file__)));put('PLAN.json',enc(plan))
    _,before,err=native(steps[0],['ps','-a','-q','--no-trunc','--filter','label='+LABEL]);need(before==err==b'','OWN_LABEL_BEFORE_EMPTY')
    _,image,err=native(steps[1],['image','inspect','--format','{{.Id}}',IMAGE]);need(image==(IMAGE+'\n').encode() and err==b'','EXISTING_FIXED_IMAGE_ID_ONLY')
    bundle=SOURCE_ROOT/'bundle'
    create=['create','--pull','never','--name','ms4-currentbafb-ui-two-php-l-r1','--label',LABEL,'--network','none','--read-only','--user','65534:65534','--cap-drop','ALL','--security-opt','no-new-privileges:true','--memory','128m','--pids-limit','32','--tmpfs','/tmp:rw,nosuid,nodev,size=16m,mode=1777','--mount','type=bind,source='+bundle.as_posix()+',target=/lint-source,readonly','--entrypoint','/bin/sh',IMAGE,'/lint-source/lint.sh']
    _,raw,err=native(steps[2],create);cid=raw.decode().strip();need(re.fullmatch('[a-f0-9]{64}',cid) is not None and err==b'','EXACT_NEW_OWNED_CID')
    code,stdout,stderr=native(steps[3],['start','-a',cid],allow_nonzero=True)
    _,waited,waiterr=native(steps[4],['wait',cid])
    template='{"id":{{json .Id}},"image":{{json .Image}},"state":{{json .State}},"restart":{{json .RestartCount}},"labels":{{json .Config.Labels}},"network":{{json .HostConfig.NetworkMode}},"readonly":{{json .HostConfig.ReadonlyRootfs}},"user":{{json .Config.User}},"mounts":{{json .Mounts}},"capdrop":{{json .HostConfig.CapDrop}},"security":{{json .HostConfig.SecurityOpt}},"memory":{{json .HostConfig.Memory}},"pids":{{json .HostConfig.PidsLimit}},"tmpfs":{{json .HostConfig.Tmpfs}},"entrypoint":{{json .Config.Entrypoint}},"command":{{json .Config.Cmd}}}'
    _,raw,inspecterr=native(steps[5],['inspect','--format',template,cid]);state=json.loads(raw)
    need(inspecterr==b'' and state['id']==cid and state['image']==IMAGE and state['state']['Status']=='exited' and state['state']['Pid']==0 and state['restart']==0 and state['network']=='none' and state['readonly'] is True and state['user']=='65534:65534' and state['labels']['codex.ms4.syntax']=='currentbafb-ui-two-r1','REAL_OWN_TERMINAL_AND_ISOLATION')
    need(state['capdrop']==['ALL'] and state['security']==['no-new-privileges:true'] and state['memory']==128*1024*1024 and state['pids']==32 and set(state['tmpfs'])=={'/tmp'} and state['entrypoint']==['/bin/sh'] and state['command']==['/lint-source/lint.sh'],'REAL_FIXED_RESOURCE_AND_COMMAND_LIMITS')
    need(len(state['mounts'])==1 and state['mounts'][0]['Type']=='bind' and state['mounts'][0]['Destination']=='/lint-source' and state['mounts'][0]['RW'] is False,'ONE_REAL_SOURCE_READONLY_MOUNT')
    _,removed,rmerr=native(steps[6],['rm',cid]);need(removed==(cid+'\n').encode() and rmerr==b'','ORDINARY_OWN_TERMINAL_CID_REMOVED')
    _,after,err=native(steps[7],['ps','-a','-q','--no-trunc','--filter','label='+LABEL]);need(after==err==b'','OWN_LABEL_AFTER_EMPTY')
    need(len(OPERATIONS)==len(steps) and all(row['native_exit']==row['wait_returncode'] for row in OPERATIONS),'ACTUAL_PLAN_BUDGET_AND_SAME_HANDLE_NATIVE')
    lines=stdout.splitlines(keepends=True);children=[];valid=code==0 and waited==b'0\n' and waiterr==stderr==b'' and state['state']['ExitCode']==0
    valid=valid and len(lines)==3*len(leaves)+1 and lines[-1:]==[b'LINT_COMPLETE 2\n']
    for i,row in enumerate(leaves):
        control=lines[3*i] if len(lines)>3*i else b'';match=re.fullmatch(rb'LINT_CHILD (\d+) ([1-9][0-9]*) ([0-9]+)\n',control)
        fixed=('No syntax errors detected in /lint-source/'+row['bundle_name']+'\n').encode()
        ok=match is not None and int(match[1])==i+1 and int(match[3])==0 and lines[3*i+1:3*i+3]==[fixed,('LINT_END '+str(i+1)+'\n').encode()]
        valid=valid and ok
        children.append({'ordinal':i+1,'pid':int(match[2]) if match else None,'argv':['/usr/local/bin/php','-l','/lint-source/'+row['bundle_name']],'native_exit':int(match[3]) if match else None,'wait_returncode':int(match[3]) if match else None,'exit_observation':'ORIGINAL_SHELL_WAIT_FOR_EXACT_PID','fixed_stdout':fp(fixed) if ok else None,'stderr_empty_proved_by_complete_concatenation':stderr==b'','passed':ok})
    valid=valid and len({r['pid'] for r in children})==len(leaves)
    for name,pin in packet['files'].items():read(SOURCE_ROOT/name,pin)
    for row in leaves:read(INTEGRATION/'candidate'/row['integration_relative'],row)
    for path,row in READS.items():
        now=Path(path).lstat();need([getattr(now,k) for k in ('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')]==row['named9'] and now.st_birthtime_ns==row['named_birthtime_ns'],'FINAL_COMPLETE_GROUP_NAMED_CONTINUITY')
    put('SOURCE-INPUTS.json',enc({'files':READS,'all_first_final_windows_ended':True,'all_group_final_named9_birthtime_unchanged':True}))
    result={'schema':1,'status':'PASS_ISOLATED_PHP82_TWO_COMPLETE_UI_SOURCE_SYNTAX_ONLY' if valid else 'FAILED_ISOLATED_PHP82_SYNTAX_ONLY','scope':'COMPILE_ONLY_NO_PHP_LEAF_EXECUTION','integration_MANIFEST':{'path':(INTEGRATION/'MANIFEST.json').as_posix(),'bytes':2094,'sha256':args.integration_manifest_sha256},'syntax_source_PINS':{'path':(SOURCE_ROOT/'PINS.json').as_posix(),**fp(read(SOURCE_ROOT/'PINS.json'))},'IMAGE':IMAGE,'container_id':cid,'host_native_count_actual':len(OPERATIONS),'host_native_count_plan':len(steps),'PHP_lint_children_required':len(leaves),'PHP_lint_children':children,'operations':OPERATIONS,'ordinary_own_container_removed':True,'own_label_before_after_empty':True,'all_leaf_Source_bytes_and_windows_unchanged':True,'App_DB_Redis_HTTP_network_business_executed':False,'new_Git_HEAD_adoption':'UNBOUND','runtime_business_acceptance':False}
    put('RESULT.json',enc(result))
    expected={'runner-source.py','PLAN.json','SOURCE-INPUTS.json','RESULT.json'}|{r['stem']+suffix for r in OPERATIONS for suffix in ('.created.json','.terminal.json','.stdout','.stderr')}
    need({p.name for p in OUT.iterdir()}==expected,'COMPLETE_ACTUAL_OWN_OUTPUT_NAMESPACE_FROM_REAL_PLAN')
    print(json.dumps({'status':result['status'],'host_native_count_actual':len(OPERATIONS),'PHP_lint_children_required':len(leaves),'ordinary_own_container_removed':True},ensure_ascii=False))
    need(valid,'EACH_COMPLETE_PHP_LINT_CHILD_NATIVE_WAIT_ZERO_AND_FIXED_OUTPUT')

if __name__=='__main__':main()
