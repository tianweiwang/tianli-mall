"""Actual read-only Git export for adopted175f; no target execution."""
import json,os,subprocess,time
from pathlib import Path
import source_reader as r
OUT,B,OLD=r.OUT,r.B,r.OLD
ORG=B.parent/'project-organization-20261023'
AD=ORG/'current28dc-form-quota-wire-fix-adoption-r1'
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
 assert r.pin(r.read(priorpath))=={'bytes':189060,'sha256':'0c2d0c6e68199e0c2b291fdaa764babb986b8d5ebe512903fbf06b6f7fea3650'}
 assert type(prior['files'])is dict and len(prior['files'])==340 and len(prior['source_members18'])==18
 for name,pin in prior['files'].items():r.fixed(OLD/name,pin)
 r.put('PREDECESSOR-COMPLETE-SOURCE-CHECKS.json',{'schema':1,'predecessor':r.pointer(priorpath),'complete_members_verified':len(prior['files']),'runtime_members':prior['source_members18'],'all_complete_bytes_pinned':True,'target_execution':'NOT RUN'})
 fixed={'GIT-ADOPTION.json':(1138,'2de26a1e59b146ccaa906dc50874ab91257a00e2cfb2419e35e12a8f787b2658'),'PUSH-VERIFIED.json':(387,'bf69463f74fa8b9f4d1c088cee882241ad70ffbdc8b3f092a1688b6edceafb59'),'VAL-VERIFIED.json':(968,'9a0032be00ac61271662d7ef5c032238abb2b8c14fe0949532475bcdcbda2714')}
 docs={}
 for name,(size,digest)in fixed.items():docs[name]=json.loads(r.fixed(AD/name,{'bytes':size,'sha256':digest}))
 commit,push,val=[docs[n]for n in fixed]
 assert commit['head']==push['commit']==push['remote_SHA']==val['commit']==r.HEAD and commit['parent']==push['parent']==r.PARENT
 assert commit['git_native_exit']==push['native_exit']==val['Val_native_exit']==0 and push['forced']is push['deployed']is False
 assert val['same_complete_index']is True and val['each_tracked']==15748 and val['exact_changed_blobs']==commit['paths']
 expected=sorted(commit['paths']);assert len(set(expected))==4
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
  after=get('member-'+str(i)+'-after',['show',r.HEAD+':'+name]);r.write('git-blobs/after/'+name,after);assert r.pin(after)==commit['paths'][name]
  before=get('member-'+str(i)+'-before',['show',r.PARENT+':'+name]);r.write('git-blobs/before/'+name,before)
  rows.append({'path':name,'before':r.pin(before),'after':r.pin(after)})
 assert get('head-after',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
 assert get('clean-after',['status','--porcelain=v1','--untracked-files=all'])==b''
 r.put('CURRENT-SOURCE-AUTHORITY.json',{'schema':1,'scope':'ACTUAL_READ_ONLY_GIT_FOUR_FILE_FORM_QUOTA_DECIMAL_WIRE_STEP','source_sha':r.HEAD,'parent':r.PARENT,'kind':'form_quota_canonical_decimal_wire','files':rows,'git_operations':ops,'native_count':len(ops),'business':'NOT RUN','worktree_mutations':0,'actual_adoption':r.pointer(AD/'GIT-ADOPTION.json'),'actual_push':r.pointer(AD/'PUSH-VERIFIED.json'),'actual_Val':r.pointer(AD/'VAL-VERIFIED.json')})
 r.put('PREPARE-INPUTS-PINS.json',{'schema':1,'source_step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'original340':r.pointer(OUT/'PREDECESSOR-COMPLETE-SOURCE-CHECKS.json'),'input_windows_ended':True,'input_count':len(r.READS),'all_runtime':'NOT RUN'})
 print(json.dumps({'step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'readonly_Git':len(ops),'old_complete':len(prior['files']),'new_head':r.HEAD}))
if __name__=='__main__':main()
