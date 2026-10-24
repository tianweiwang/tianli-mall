"""Actual read-only seven-file Git export. Source author only; no M/S target."""
import json,os,subprocess,time
from pathlib import Path
import source_reader as r
OUT,B,OLD=r.OUT,r.B,r.OLD
ORG=B.parent/'project-organization-20261023'
AD=ORG/'current175f-retirement-and-syntax-root-admission-r1'

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
 assert r.pin(r.read(priorpath))=={'bytes':147196,'sha256':'2653282ad09cdf30e030c6c1cf5976b73a1a06bd0be52070085e3a3f33bb270b'}
 assert len(prior['files'])==265 and len(prior['source_members18'])==18
 authors=['assemble.py','author_support.py','source_reader.py','prepare-inputs.py','profile-author.py','retirement-author.py','authority-current.py.fragment','bind-initializer-syntax.py','initializer-syntax-checks.py.fragment','retirement-checks.py.fragment','DESIGN.md']
 for name in prior['source_members18']+authors:r.fixed(OLD/name,prior['files'][name])
 r.put('PREDECESSOR-COMPLETE-SOURCE-CHECKS.json',{'schema':1,'predecessor':r.pointer(priorpath),'complete18_runtime_Source_and11_author_dependencies_verified':True,'whole265_member_map_preserved':True,'unselected_old_runtime_stream_bodies_reopened':False,'runtime_members':prior['source_members18'],'target_execution':'NOT RUN'})
 docs={}
 fixed={'ADOPTED.json':(3200,'07fd6c2e69ac15c0df73a6ed3452393dd2a2181efd66e6cf635eae205cf86145'),'COMMITTED.json':(936,'7c9f50b2864f4c6e392d71734e22e79e3da8356ec2a84f90893b7bb3ebe41ffb'),'PUSH-VERIFIED.json':(773,'e9303065e552bbe9b9ff92c9797de96d6dc066217c3aeaf3617c2e149ad280e7')}
 for name,(size,digest) in fixed.items():docs[name]=json.loads(r.fixed(AD/name,{'bytes':size,'sha256':digest}))
 adopted,commit,push=[docs[n]for n in fixed]
 assert commit['head']==push['head']==r.HEAD and adopted['before_Head']==commit['parent']==push['parent']==r.PARENT
 assert commit['all_committed_blob_pins_equal_adopted']is True and push['W']['clean']is push['Val']['clean']is True
 assert push['runtime_or_business_acceptance']is push['deployment_or_real_funds']is False
 expected=sorted(adopted['files']);assert commit['paths']==expected and len(expected)==7
 ops={}
 def get(stem,args):
  raw,pointers=git(stem,args);ops[stem]=pointers;return raw
 assert get('head-before',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
 assert get('clean-before',['status','--porcelain=v1','--untracked-files=all'])==b''
 assert get('parent',['rev-list','--parents','-n','1',r.HEAD])==(r.HEAD+' '+r.PARENT+'\n').encode()
 header=get('commit-object',['cat-file','-p',r.HEAD]);assert header.splitlines()[1]==('parent '+r.PARENT).encode()
 paths=get('changed-paths',['diff-tree','--no-commit-id','--name-only','-r',r.HEAD]).decode().splitlines();assert paths==expected
 statuses=get('name-status',['diff-tree','--no-commit-id','--name-status','-r',r.HEAD]).decode().splitlines();assert statuses==['M\t'+name for name in expected]
 rows=[]
 for i,name in enumerate(paths,1):
  after=get('member-'+str(i)+'-after',['show',r.HEAD+':'+name]);r.write('git-blobs/after/'+name,after)
  before=get('member-'+str(i)+'-before',['show',r.PARENT+':'+name]);r.write('git-blobs/before/'+name,before)
  assert r.pin(before)==adopted['files'][name]['before'] and r.pin(after)==adopted['files'][name]['after']
  rows.append({'path':name,'before':r.pin(before),'after':r.pin(after)})
 assert get('head-after',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
 assert get('clean-after',['status','--porcelain=v1','--untracked-files=all'])==b'' and len(ops)==22
 r.put('CURRENT-SOURCE-AUTHORITY.json',{'schema':1,'scope':'ACTUAL_READ_ONLY_GIT_SEVEN_EXISTING_FILE_PREFLIGHT_DIAGNOSTIC_STEP','source_sha':r.HEAD,'parent':r.PARENT,'kind':'form_upload_preflight_failure_diagnostic','files':rows,'git_operations':ops,'native_count':22,'business':'NOT RUN','worktree_mutations':0,'actual_adoption':r.pointer(AD/'ADOPTED.json'),'actual_commit':r.pointer(AD/'COMMITTED.json'),'actual_push':r.pointer(AD/'PUSH-VERIFIED.json')})
 r.put('PREPARE-INPUTS-PINS.json',{'schema':1,'source_step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'predecessor_Source':r.pointer(OUT/'PREDECESSOR-COMPLETE-SOURCE-CHECKS.json'),'input_windows_ended':True,'input_count':len(r.READS),'Git_export_native':22,'M_native_increment_derived_not_executed':16,'all_target_runtime':'NOT RUN'})
 print(json.dumps({'step':r.pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'readonly_Git':22,'new_head':r.HEAD,'M_S':'NOT RUN'}))

if __name__=='__main__':main()
