"""Bind already-completed real initializer syntax receipts; no Docker/PHP/job execution."""
import json,re
from pathlib import Path
import source_reader as r
OUT,B=r.OUT,r.B
ORG=B.parent/'project-organization-20261023'
def main():
 folder=ORG/'current28dc-initializer-syntax-actual-r1';rp=folder/'RESULT.json';value=r.obj(rp)
 assert r.pin(r.read(rp))=={'bytes':8964,'sha256':'d9fc9448e7bc7ddd269f46396e71fcf717f409d506a0a2b7209499c8ac5d9039'}
 root=ORG/'current28dc-initializer-syntax-root-actual-readback-r1/REPORT.json';rootv=r.obj(root)
 assert r.pin(r.read(root))=={'bytes':1228,'sha256':'2d0c69611ec60bd360cc2d73d1cebfdd7cfdec7a3a3d214e963bd3302f9142ea'}
 assert value['status']=='PASS_ACTUAL28DC_COMPLETE_INITIALIZER_PHP82_SYNTAX_ONLY'and value['source_sha']==r.HEAD
 assert value['candidate']==r.pointer(OUT/'initializer-review/candidate.php')and value['profile_PINS']==r.pointer(OUT/'profile-source/PINS.json')and value['mini_PINS']==r.pointer(OUT/'initializer-review/PINS.json')
 assert r.fixed(value['copy']['path'],value['copy'])==r.read(OUT/'initializer-review/candidate.php')
 assert value['php_l_count_required']==1 and value['container_native_exit']==0 and value['initializer_or_M_S_App_business_execution']=='NOT RUN'and value['W_Val_Run_mutations']==0
 assert value['ordinary_own_container_removed']is value['own_label_before_after_empty']is value['all_input_windows_ended']is True
 assert rootv['status']=='PASS_ROOT_COMPLETE_ACTUAL28DC_INITIALIZER_PHP82_SYNTAX_RECEIPTS'and rootv['RESULT']==r.pointer(rp)and rootv['candidate']==value['candidate']
 assert rootv['outer_pid']==5548 and rootv['outer_native_wait']==0 and rootv['native_operations']==8 and rootv['php_l_count']==1 and rootv['input_windows_closed_verified']==37
 peers=ORG/'current28dc-initializer-profile-independent-r1/FINAL-PINS.json';peer=r.obj(peers)
 assert r.pin(r.read(peers))=={'bytes':712,'sha256':'2c09d468e296f23a827159e3c2275b763aca16a9d7176e7c96752bf984f447c9'}
 for name,pin in peer['files'].items():r.fixed(peers.parent/name,pin)
 code=r.obj(value['job_code_PINS']['path']);r.fixed(value['job_code_PINS']['path'],value['job_code_PINS']);r.fixed(value['candidate_source']['path'],value['candidate_source'])
 assert type(code['files'])is dict
 source_members=[]
 for name,pin in code['files'].items():p=Path(value['job_code_PINS']['path']).parent/name;r.fixed(p,pin);source_members.append(r.pointer(p))
 receipts=[];streams=[]
 expected_names=['own-label-before','existing-fixed-image','create-one-owned-lint-container','attach-original-php-l','same-cid-native-wait','same-cid-native-terminal','ordinary-exact-owned-cid-rm','own-label-after']
 assert len(value['operations'])==8
 for i,(op,name)in enumerate(zip(value['operations'],expected_names),1):
  assert op['stem']==str(i).zfill(2)+'-'+name
  row={key:r.pointer(folder/(op['stem']+'.'+suffix))for key,suffix in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]}
  made=r.obj(row['created']['path']);ended=r.obj(row['terminal']['path']);out=r.read(row['stdout']['path']);err=r.read(row['stderr']['path'])
  assert ended==op and made=={'pid':op['pid'],'argv':op['argv'],'started_ns':op['started_ns']}
  assert op['pid']>0 and op['finished_ns']>=op['started_ns']and op['native_exit']==op['wait_returncode']==0 and err==b''
  assert r.pin(out)==op['stdout']and r.pin(err)==op['stderr'];receipts.append(row);streams.append(out)
 image='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';cid=value['container_id'];assert re.fullmatch('[a-f0-9]{64}',cid)
 assert streams[0]==streams[7]==b''and streams[1]==(image+'\n').encode()and streams[2]==streams[6]==(cid+'\n').encode()
 assert streams[3]==b'No syntax errors detected in /candidate/ms4-initialize.php\n'and streams[4]==b'0\n'
 terminal=json.loads(streams[5]);assert terminal['id']==cid and terminal['image']==image and terminal['state']['Status']=='exited'and terminal['state']['Pid']==0 and terminal['state']['ExitCode']==0 and terminal['restart']==0
 assert terminal['network']=='none'and terminal['readonly']is True and terminal['user']=='65534:65534'and terminal['labels']['codex.ms4.syntax']=='current28dc-initializer-r1'
 assert len(terminal['mounts'])==1 and terminal['mounts'][0]['Destination']=='/candidate'and terminal['mounts'][0]['RW']is False
 names=sorted(p.name for p in folder.iterdir());assert len(names)==38 and all((folder/name).is_file()for name in names)
 public={name:r.pointer(folder/name)for name in names}
 r.put('INITIALIZER-UPLOAD-ROOT-SYNTAX.json',{'schema':1,'status':'PASS_COMPLETE_CURRENT28DC_INITIALIZER_PHP82_SYNTAX_ONLY','source_profile_PINS':r.pointer(OUT/'profile-source/PINS.json'),
  'initializer':r.pointer(OUT/'profile-source/candidate/ms4-initialize.php'),'actual_RESULT_pointer':r.pointer(rp),'actual_result':value,'Root_readback':r.pointer(root),
  'independent_profile_source_review':r.pointer(peers),'job_source_members':source_members,'native_receipts':receipts,'actual_public_job_files':public,
  'App_business':'NOT RUN'})
 print(json.dumps({'syntax_authority':r.pointer(OUT/'INITIALIZER-UPLOAD-ROOT-SYNTAX.json'),'complete_actual_files':38,'native_receipts':len(receipts),'runtime_invoked':'NOT RUN'}))
if __name__=='__main__':main()
