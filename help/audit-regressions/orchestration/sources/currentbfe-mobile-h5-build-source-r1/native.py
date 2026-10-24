"""Finite native receipt adapter for this H5 build only; no work at import."""
import argparse,hashlib,json,pathlib,re,subprocess,sys,time,os
BASE=pathlib.Path('H:/天俪商城/artifacts/currentbfe-mobile-h5-build-runs')
DOCKER='C:/Users/Vwang/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'

def write(p,value):
    with p.open('xb') as f:f.write((json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode())

def main():
    a=argparse.ArgumentParser(description=__doc__);a.add_argument('arguments');args=a.parse_args()
    p=pathlib.Path(args.arguments).resolve();assert p.is_relative_to(BASE) and p.parent.name=='evidence'
    assert re.fullmatch(r'[0-9]{3}-[a-z0-9-]+\.arguments\.json',p.name)
    argv=json.loads(p.read_bytes());assert isinstance(argv,list) and all(isinstance(x,str) for x in argv)
    assert argv and argv[0] in [DOCKER,'git']
    stem=p.name.removesuffix('.arguments.json');folder=p.parent
    env=os.environ.copy();env['GIT_OPTIONAL_LOCKS']='0'
    with (folder/(stem+'.stdout')).open('xb') as out,(folder/(stem+'.stderr')).open('xb') as err:
        child=subprocess.Popen(argv,stdout=out,stderr=err,env=env,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        write(folder/(stem+'.created.json'),{'pid':child.pid,'wrapper_pid':os.getpid(),'argv':argv,'started_ns':time.time_ns()})
        waited=child.wait()
    terminal={'pid':child.pid,'wrapper_pid':os.getpid(),'native_exit':child.returncode,'wait_returncode':waited,'finished_ns':time.time_ns()}
    for channel in ['stdout','stderr']:
        raw=(folder/(stem+'.'+channel)).read_bytes()
        terminal[channel]={'path':str(folder/(stem+'.'+channel)),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
    write(folder/(stem+'.terminal.json'),terminal)
    print(json.dumps(terminal,ensure_ascii=True),flush=True)

if __name__=='__main__':main()
