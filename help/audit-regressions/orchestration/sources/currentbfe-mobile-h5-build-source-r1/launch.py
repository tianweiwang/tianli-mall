"""Root-only unique H5 build launch; caller supplies the independently reviewed PINS hash."""
import argparse,datetime,hashlib,json,pathlib,stat,subprocess,sys,time,uuid,os
ROOT=pathlib.Path(__file__).parent
BASE=pathlib.Path('H:/天俪商城/artifacts')
PWSH='C:/Users/Vwang/.cache/codex-runtimes/codex-primary-runtime/dependencies/native/powershell/pwsh.exe'

def digest(raw):return hashlib.sha256(raw).hexdigest()
def nine(p):
    s=p.lstat();assert stat.S_ISREG(s.st_mode) and not p.is_symlink()
    return [s.st_dev,s.st_ino,s.st_mode,s.st_nlink,s.st_uid,s.st_gid,s.st_size,s.st_mtime_ns,s.st_ctime_ns]
def packet(pins):
    names={p.relative_to(ROOT).as_posix() for p in ROOT.rglob('*') if p.is_file()}
    assert names==set(pins['files'])|{'PINS.json'}
    for rel,expected in pins['files'].items():
        p=ROOT/rel;before=nine(p);raw=p.read_bytes();assert before==nine(p)==expected['native9']
        assert len(raw)==expected['bytes'] and digest(raw)==expected['sha256']
def write(p,value):
    with p.open('xb') as f:f.write((json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode())
def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--pins-sha256',required=True);a=parser.parse_args()
    raw=(ROOT/'PINS.json').read_bytes();assert digest(raw)==a.pins_sha256
    pins=json.loads(raw);assert pins['status']=='FROZEN_SOURCE_ONLY_NOT_BUILD_OR_UI'
    packet(pins)
    run=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S')+uuid.uuid4().hex[:8]
    outer=BASE/'currentbfe-mobile-h5-build-launches'/run;outer.mkdir(parents=True,exist_ok=False)
    argv=[PWSH,'-NoProfile','-File',str(ROOT/'build-linux.ps1'),'-RunId',run]
    with (outer/'wrapper.stdout').open('xb') as out,(outer/'wrapper.stderr').open('xb') as err:
        child=subprocess.Popen(argv,stdout=out,stderr=err,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        write(outer/'wrapper.created.json',{'pid':child.pid,'argv':argv,'started_ns':time.time_ns(),'pins_sha256':a.pins_sha256,'run_id':run})
        waited=child.wait()
    terminal={'pid':child.pid,'native_exit':child.returncode,'wait_returncode':waited,'finished_ns':time.time_ns(),'run_id':run}
    for channel in ['stdout','stderr']:
        stream=(outer/('wrapper.'+channel)).read_bytes();terminal[channel]={'bytes':len(stream),'sha256':digest(stream)}
    write(outer/'wrapper.terminal.json',terminal)
    packet(pins);assert (ROOT/'PINS.json').read_bytes()==raw
    result_path=BASE/'currentbfe-mobile-h5-build-runs'/run/'evidence/build-result.json'
    actual=json.loads(result_path.read_text(encoding='utf-8-sig')) if result_path.is_file() else None
    verified=child.returncode==waited==0 and actual is not None and actual.get('status')=='BUILD_VERIFIED'
    summary={'status':'ACTUAL_H5_BUILD_VERIFIED_NOT_BROWSER' if verified else 'BUILD_FAILED_PRESERVED','run_id':run,'source_sha':pins['source_sha'],'source_base_head':pins['source_sha'],'source_identity':pins['source_identity'],'contains_uncommitted_changes':True,'wrapper':terminal,'actual_build_result':str(result_path),'package_fullbytes_native9_held':True,'browser_API_SQL_UI':'NOT RUN','push_deploy':False}
    write(outer/'RESULT.json',summary);print(json.dumps(summary,ensure_ascii=False),flush=True)
    sys.exit(0 if verified else 1)
if __name__=='__main__':main()
