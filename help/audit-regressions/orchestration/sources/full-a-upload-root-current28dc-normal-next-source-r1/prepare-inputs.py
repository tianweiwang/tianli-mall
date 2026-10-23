"""Actual readonly Git export and full frozen predecessor source checks; no target execution."""
import json,os,subprocess,time
from pathlib import Path
import source_reader as r
OUT,B,OLD=r.OUT,r.B,r.OLD
ORG=B.parent/'project-organization-20261023'
def git(stem,args):
 folder=OUT/'git-lineage';folder.mkdir(exist_ok=True)
 repo=Path('H:/天俪商城/worktrees/ms4-asset-consumers')
 argv=['git','-c','safe.directory='+repo.as_posix(),'-C',repo.as_posix(),*args]
 started=time.time_ns();o=folder/(stem+'.stdout');e=folder/(stem+'.stderr')
 with o.open('xb')as out,e.open('xb')as err:
  p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=out,stderr=err,env={**os.environ,'GIT_OPTIONAL_LOCKS':'0'})
  r.put('git-lineage/'+stem+'.created.json',{'pid':p.pid,'argv':argv,'started_ns':started});code=p.wait()
 op={'pid':p.pid,'argv':argv,'started_ns':started,'finished_ns':time.time_ns(),'native_exit':code,'wait_returncode':p.returncode,'stdout':r.pointer(o),'stderr':r.pointer(e)}
 r.put('git-lineage/'+stem+'.terminal.json',op);assert code==p.returncode==0 and r.read(e)==b'',stem
 return r.read(o),{k:r.pointer(folder/(stem+'.'+x),True)for k,x in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]}
def main():
 priorpath=OLD/'PINS.json';prior=r.obj(priorpath)
 assert r.pin(r.read(priorpath))=={'bytes':138143,'sha256':'2b7702c1d6aa6459c4044f17fa7af213fe92d958d5a855ffd1a98b1ce76ae4fa'}
 assert type(prior['files'])is dict and len(prior['files'])==247 and len(prior['source_members18'])==18
 for name,pin in prior['files'].items():r.fixed(OLD/name,pin)
 r.put('PREDECESSOR-COMPLETE-SOURCE-CHECKS.json',{'schema':1,'predecessor':r.pointer(priorpath),'complete_members_verified':247,'runtime_members':prior['source_members18'],'all_complete_bytes_pinned':True,'target_execution':'NOT RUN'})
 commitpath=ORG/'current0c13-reviewed-eight-path-adoption-actual-r1/COMMIT.json'
 pushpath=ORG/'current0c13-reviewed-eight-path-adoption-actual-r1/PUSH-AND-VAL.json'
 commit=r.obj(commitpath);push=r.obj(pushpath)
 assert r.pin(r.read(commitpath))=={'bytes':1217,'sha256':'dddff28df08eed7e900d92809a935a36deb33c0f7affbca203b1122f40b08ca2'}
 assert r.pin(r.read(pushpath))=={'bytes':839,'sha256':'e8606e88cc174c260288e25639f595145e4afe9c651fb8682848dcd3d2dd4bb3'}
 assert commit['sha']==push['main_source']==push['remote_sha']==r.HEAD and commit['parent']==push['parent']==r.PARENT
 assert commit['count']==push['changed_paths']==8 and push['W_clean']is push['Val_clean']is push['Val_detached_same_SHA']is True and push['tracked_counts']==[15748,15748]
 expected=sorted(commit['files']);assert len(set(expected))==8
 peer=commit['independent_adoption_review'];r.fixed(peer['path'],peer)
 ops={}
 def get(stem,args):
  raw,p=git(stem,args);ops[stem]=p;return raw
 assert get('head-before',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
 assert get('clean-before',['status','--porcelain=v1','--untracked-files=all'])==b''
 assert get('parent',['rev-list','--parents','-n','1',r.HEAD])==(r.HEAD+' '+r.PARENT+'\n').encode()
 header=get('commit-object',['cat-file','-p',r.HEAD]);assert header.splitlines()[1]==('parent '+r.PARENT).encode()
 paths=get('changed-paths',['diff-tree','--no-commit-id','--name-only','-r',r.HEAD]).decode().splitlines();assert paths==expected
 status=get('name-status',['diff-tree','--no-commit-id','--name-status','-r',r.HEAD]).decode().splitlines();assert status==['M\t'+name for name in expected]
 rows=[]
 for i,name in enumerate(paths,1):
  after=get('member-'+str(i)+'-after',['show',r.HEAD+':'+name]);r.write('git-blobs/after/'+name,after)
  before=get('member-'+str(i)+'-before',['show',r.PARENT+':'+name]);r.write('git-blobs/before/'+name,before)
  rows.append({'path':name,'before':r.pin(before),'after':r.pin(after)})
 assert get('head-after',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
 assert get('clean-after',['status','--porcelain=v1','--untracked-files=all'])==b''
 r.put('CURRENT-SOURCE-AUTHORITY.json',{'schema':1,'scope':'ACTUAL_READ_ONLY_GIT_EIGHT_FILE_UPLOAD_ROOT_AND_H5_RESOURCE_CONSUMER_STEP','source_sha':r.HEAD,'parent':r.PARENT,
  'kind':'owned_upload_root_and_h5_resource_consumers','files':rows,'git_operations':ops,'native_count':len(ops),'business':'NOT RUN','worktree_mutations':0,
  'actual_adoption':r.pointer(commitpath),'actual_push_and_Val':r.pointer(pushpath),'independent_adoption_review':peer})
 r.put('PREPARE-INPUTS-PINS.json',{'schema':1,'source_step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'original247':r.pointer(OUT/'PREDECESSOR-COMPLETE-SOURCE-CHECKS.json'),
  'input_windows_ended':True,'input_count':len(r.READS),'all_runtime':'NOT RUN'})
 print(json.dumps({'step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'readonly_Git':len(ops),'old_complete':len(prior['files']),'new_head':r.HEAD}))
if __name__=='__main__':main()
