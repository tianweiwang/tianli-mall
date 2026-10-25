from pathlib import Path
import hashlib,json,os,subprocess,sys,time
ADM=Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-normal-reattempt-source-r1/launcher')
ENTRY=Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-normal-reattempt-source-r1/candidate/normal-s.py')
def fp(path):
 raw=path.read_bytes();return {'path':path.as_posix(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def put(path,value):
 with path.open('xb')as f:f.write((json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode())
def main():
 admission=json.loads((ADM/'ROOT-NORMAL-S-ADMISSION.json').read_bytes())
 assert admission['scope']=='ONE_STATIC_B54_COMPLETE_NORMAL_S_REATTEMPT_REUSING_QUALIFIED_M0'
 assert admission['root_wrapper']==fp(Path(__file__).resolve())
 assert admission['driver_source']==fp(ENTRY)
 assert admission['engineering_before_driver']=={'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/run.py', 'bytes': 28528366, 'sha256': 'f23fd9ecee8447b0a50e328e6253ea9d73e97cc4f604b3c660f50c95f22c2deb'} and fp(Path(admission['engineering_before_driver']['path']))==admission['engineering_before_driver']
 assert admission['qualified_M_readback']=={'path': 'H:/天俪商城/artifacts/project-organization-20261023/current41c0-normal-root-admission-r1/R108-B54-M-DUAL-READERS-ACTUAL-ROOT-ACCEPTANCE.json', 'bytes': 6251, 'sha256': '2ee996cc88a555f608a056d1d69ae64a71658a6bc31ed7270a53a2e3c8f687d7'} and fp(Path(admission['qualified_M_readback']['path']))==admission['qualified_M_readback']
 assert admission['qualified_M_result']=={'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/materialize-freeze-assets-r1/RESULT.json', 'bytes': 265006271, 'sha256': '437984dd506e48300396b17ada94eda51d8c329c5d2e66463137b41e4adf96c3'}
 assert admission['baseline_Root_PINS']=={'path': 'H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation/PINS.json', 'bytes': 358, 'sha256': 'a5aba6db21420553b0cb1d9247512c0be8f5383081f41b8bf0730c46ac4f9863'}
 for row in admission['required_public_files']:assert fp(Path(row['path']))==row
 argv=[sys.executable,'-B','-X','utf8',str(ENTRY),'--root-source-pins-sha256','a5aba6db21420553b0cb1d9247512c0be8f5383081f41b8bf0730c46ac4f9863','--setup-sha256','437984dd506e48300396b17ada94eda51d8c329c5d2e66463137b41e4adf96c3','--attempt-driver-sha256',admission['driver_source']['sha256']]
 assert argv==admission['argv']
 stem=ADM/'normal-start';paths={key:Path(str(stem)+'.'+key)for key in ['created.json','terminal.json','stdout','stderr']}
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
