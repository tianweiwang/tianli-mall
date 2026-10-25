from pathlib import Path
import hashlib,json,os,subprocess,sys,time
ADM=Path('H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1')
ENTRY=Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/launcher/launch.py')
def fp(path):
 raw=path.read_bytes();return {'path':path.as_posix(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def put(path,value):
 with path.open('xb')as f:f.write((json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode())
def main():
 admission=json.loads((ADM/'R107-B54-NORMAL-S-ADMISSION.json').read_bytes())
 assert admission['scope']=='ONE_ORIGINAL_CURRENT_B54_COMPLETE_NORMAL_S_AFTER_BOTH_FULL_M_READBACKS'
 assert admission['root_wrapper']==fp(Path(__file__).resolve())
 for row in admission['required_public_files']:assert fp(Path(row['path']))==row
 argv=[sys.executable,'-I','-B','-X','utf8',str(ENTRY),'normal-start']
 assert argv==admission['argv']
 stem=ADM/'r107-b54-normal-s';paths={key:Path(str(stem)+'.'+key)for key in ['created.json','terminal.json','stdout','stderr']}
 assert all(not path.exists()for path in paths.values())
 env=dict(os.environ)
 assert not any(key.startswith('GIT_CONFIG_')for key in env), 'EXISTING_GIT_CONFIG_ENV_REQUIRES_EXPLICIT_JOIN'
 process_git_config={'GIT_CONFIG_COUNT':'1','GIT_CONFIG_KEY_0':'core.longpaths','GIT_CONFIG_VALUE_0':'true'}
 env.update(process_git_config)
 with paths['stdout'].open('xb')as output,paths['stderr'].open('xb')as errors:
  process=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=output,stderr=errors,env=env)
  put(paths['created.json'],{'argv':argv,'pid':process.pid,'started_ns':time.time_ns(),'process_only_git_config':process_git_config})
  native_exit=process.wait();waited=process.wait()
 put(paths['terminal.json'],{'argv':argv,'pid':process.pid,'native_exit':native_exit,'wait_returncode':waited,'stdout':fp(paths['stdout']),'stderr':fp(paths['stderr']),'finished_ns':time.time_ns(),'process_only_git_config':process_git_config})
 print(json.dumps({'pid':process.pid,'native_exit':native_exit,'wait_returncode':waited}));return native_exit
if __name__=='__main__':sys.exit(main())
