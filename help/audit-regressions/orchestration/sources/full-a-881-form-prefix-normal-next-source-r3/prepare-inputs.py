"""Own Source preparation: only read-only Git and fixed public metadata; never run target code."""
import ast,json,os,subprocess,time
from pathlib import Path
import source_reader as r

OUT=r.OUT;B=r.B;OLD=r.OLD
def git(stem,args):
    folder=OUT/'git-lineage';folder.mkdir(exist_ok=True)
    repo=Path('H:/天俪商城/worktrees/ms4-asset-consumers')
    argv=['git','-c','safe.directory='+repo.as_posix(),'-C',repo.as_posix(),*args]
    started=time.time_ns();o=folder/(stem+'.stdout');e=folder/(stem+'.stderr')
    with o.open('xb') as out,e.open('xb') as err:
        p=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=out,stderr=err,env={**os.environ,'GIT_OPTIONAL_LOCKS':'0'})
        r.put('git-lineage/'+stem+'.created.json',{'pid':p.pid,'argv':argv,'started_ns':started});code=p.wait()
    op={'pid':p.pid,'argv':argv,'started_ns':started,'finished_ns':time.time_ns(),'native_exit':code,'wait_returncode':p.returncode,'stdout':r.pointer(o),'stderr':r.pointer(e)}
    r.put('git-lineage/'+stem+'.terminal.json',op)
    assert code==p.returncode==0 and r.read(e)==b'',stem
    return r.read(o),{k:r.pointer(folder/(stem+'.'+x),True) for k,x in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]}

def main():
    ops={}
    def get(stem,args):
        raw,p=git(stem,args);ops[stem]=p;return raw
    assert get('head-before',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
    assert get('clean-before',['status','--porcelain=v1','--untracked-files=all'])==b''
    assert get('parent',['rev-list','--parents','-n','1',r.HEAD])==(r.HEAD+' '+r.PARENT+'\n').encode()
    header=get('commit-object',['cat-file','-p',r.HEAD]);assert header.splitlines()[1]==('parent '+r.PARENT).encode()
    paths=get('changed-paths',['diff-tree','--no-commit-id','--name-only','-r',r.HEAD]).decode().splitlines();assert paths==sorted(paths) and len(paths)==3
    kinds=get('name-status',['diff-tree','--no-commit-id','--name-status','-r',r.HEAD]).decode().splitlines()
    expected={'docs/multi-merchant/CURRENT-STATUS.md':'M','docs/multi-merchant/records/ms4/form-assertion-prefix-repair.md':'A','help/audit-regressions/isolated/lib/ms4-customer-form-normal.php':'M'}
    assert {line.split('\t')[1]:line.split('\t')[0] for line in kinds}==expected and set(paths)==set(expected)
    rows=[]
    for i,name in enumerate(paths,1):
        after=get('member-'+str(i)+'-after',['show',r.HEAD+':'+name]);r.write('git-blobs/after/'+name,after)
        if expected[name]=='A':
            assert get('member-'+str(i)+'-absent',['ls-tree','--name-only',r.PARENT,'--',name])==b'';before=None
        else:
            before=get('member-'+str(i)+'-before',['show',r.PARENT+':'+name]);r.write('git-blobs/before/'+name,before)
        rows.append({'path':name,'before':None if before is None else r.pin(before),'after':r.pin(after)})
    assert get('head-after',['rev-parse','HEAD'])==(r.HEAD+'\n').encode()
    assert get('clean-after',['status','--porcelain=v1','--untracked-files=all'])==b''
    r.put('SIXTEENTH-SOURCE-AUTHORITY.json',{'schema':1,'scope':'ACTUAL_READ_ONLY_GIT_881_FORM_ASSERTION_PREFIX_THREE_FILE_STEP','source_sha':r.HEAD,'parent':r.PARENT,'kind':'form_assertion_prefix_repair','files':rows,'git_operations':ops,'native_count':len(ops),'business':'NOT RUN','worktree_mutations':0})
    # Bound current sixth retirement. Fixed actual public receipts and independently reviewed metadata only.
    names={
      'index':'current2e4-paid-failure-retirement-authority-r1/INDEX.json',
      'final_peer':'current2e4-paid-failure-retirement-actual-independent-r1/FINAL-REPORT.json',
      'final_peer_PINS':'current2e4-paid-failure-retirement-actual-independent-r1/FINAL-PINS.json',
      'main_peer':'current2e4-paid-failure-retirement-actual-independent-r1/REPORT.json',
      'global_peer':'current2e4-paid-failure-retirement-global-independent-r1/REPORT.json',
      'close':'current2e4-paid-producer-failed-normal-close-r1/RESULT.json',
      'zero':'current2e4-paid-producer-failed-independent-zero-r1/RESULT.json',
      'failure_authority':'full-a-current2e4-paid-failure-root-inputs-r1/REPORT.json',
      'diagnostic':'full-a-current2e4-paid-failure-readonly-diagnostic-r1/REPORT.json',
      'M_root_readback':'current2e4-complete-materialization-root-readback-r1/REPORT.json',
      'M_result':'full-a-2e4-current-normal-next-source-r2/validation/materialize-freeze-assets-r1/RESULT.json'}
    pointers={k:r.pointer(B/v,True) for k,v in names.items()}
    r.put('CURRENT-2E4-RETIREMENT.json',{'schema':1,'scope':'SIXTH_ACTUAL_2E4_PAID_FAILURE_RETIREMENT_NOT_A_SUCCESS','inputs':pointers,'older_five_retirements':'PRESERVED_BY_FULL_HISTORICAL_VALIDATOR','full_business_acceptance':'INCOMPLETE','private_or_old_source_bodies_read':False})
    print(json.dumps({'step':r.pointer(OUT/'SIXTEENTH-SOURCE-AUTHORITY.json'),'retirement':r.pointer(OUT/'CURRENT-2E4-RETIREMENT.json'),'read_only_Git':len(ops)},ensure_ascii=False))
if __name__=='__main__':main()
