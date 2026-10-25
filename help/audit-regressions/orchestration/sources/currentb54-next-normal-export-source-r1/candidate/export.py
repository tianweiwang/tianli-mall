"""Root-only readonly Git Source export; never runs an application entry."""
from pathlib import Path
import argparse,hashlib,json,os,re,stat,subprocess,sys,time
ROOT=Path(__file__).resolve().parents[1]
OUT=Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-git-export-actual-r1')
HEAD='b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
PARENT='bafb6305062aeb6b6e945c21da572863062ca480'
W=Path('H:/天俪商城/worktrees/ms4-language-cache-normal')
VAL=Path('H:/天俪商城/worktrees/ms4-language-cache-normal-check')
READS={};OPS=[]
def fp(b):return {'bytes':len(b),'sha256':hashlib.sha256(b).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def need(ok,label):
 if not ok:raise RuntimeError(label)
def read(p,expected=None):
 p=Path(p);a=p.lstat();keys=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');n=lambda s:[getattr(s,k) for k in keys]
 need(stat.S_ISREG(a.st_mode) and not p.is_symlink() and not getattr(a,'st_file_attributes',0)&0x400,'REGULAR_QUALIFIED_SOURCE')
 with p.open('rb') as f:
  b=os.fstat(f.fileno());raw=f.read();need(f.read(1)==b'' and len(raw)==a.st_size,'FULL_EOF_SOURCE');c=os.fstat(f.fileno())
 z=p.lstat();need(n(a)==n(z) and a.st_birthtime_ns==z.st_birthtime_ns and n(b)==n(c) and b.st_birthtime_ns==c.st_birthtime_ns,'OWN_NINE_SOURCE_WINDOWS')
 need([n(a)[i] for i in (0,1,6,7)]==[n(b)[i] for i in (0,1,6,7)],'CROSS_FOUR_SOURCE_WINDOW')
 if expected:need(fp(raw)=={k:expected[k] for k in ('bytes','sha256')},'EXACT_SOURCE_PIN')
 row={'path':p.as_posix(),**fp(raw),'native9':n(a),'fd9':n(b),'birthtime_ns':a.st_birthtime_ns,'EOF':True,'closed':True}
 need(p.as_posix() not in READS or READS[p.as_posix()]==row,'WHOLE_SOURCE_FIRST_FINAL_EQUAL');READS.setdefault(p.as_posix(),row);return raw
def put(name,value):
 p=OUT/name;p.parent.mkdir(parents=True,exist_ok=True)
 with p.open('xb') as f:f.write(value if isinstance(value,bytes) else enc(value))
def pointer(p,native=False):
 read(p);row={'path':Path(p).as_posix(),**fp(read(p))}
 if native:row['native9']=READS[Path(p).as_posix()]['native9']
 return row
def git(repo,stem,args,folder):
 # All callers use fixed readonly argv; no arbitrary user shell or Git argument.
 argv=['git','-c','safe.directory='+repo.as_posix(),'-C',repo.as_posix(),*args]
 started=time.time_ns();process=subprocess.Popen(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env={**os.environ,'GIT_OPTIONAL_LOCKS':'0'})
 made={'pid':process.pid,'argv':argv,'started_ns':started};put(folder+'/'+stem+'.created.json',made)
 stdout,stderr=process.communicate();waited=process.wait();put(folder+'/'+stem+'.stdout',stdout);put(folder+'/'+stem+'.stderr',stderr)
 op={**made,'finished_ns':time.time_ns(),'native_exit':process.returncode,'wait_returncode':waited,'stdout':pointer(OUT/folder/(stem+'.stdout')),'stderr':pointer(OUT/folder/(stem+'.stderr'))}
 if folder=='current-source-readback':op['stem']=stem
 put(folder+'/'+stem+'.terminal.json',op);OPS.append(op)
 need(process.returncode==waited==0 and stderr==b'','ACTUAL_READONLY_GIT_NATIVE0:'+stem)
 receipts={k:pointer(OUT/folder/(stem+'.'+suffix),True) for k,suffix in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]}
 return stdout,op,receipts
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--source-pins-sha256',required=True);ap.add_argument('--authorize-readonly-source-export',action='store_true');args=ap.parse_args()
 need(not sys.flags.optimize and args.authorize_readonly_source_export,'ROOT_READONLY_SCOPE')
 raw=read(ROOT/'PINS.json');need(fp(raw)['sha256']==args.source_pins_sha256,'REVIEWED_SOURCE_PINS_HASH');packet=json.loads(raw)
 need({p.relative_to(ROOT).as_posix() for p in ROOT.rglob('*') if p.is_file()}==set(packet['files'])|{'PINS.json'},'EXACT_SOURCE_NAMESPACE')
 for n,row in packet['files'].items():read(ROOT/n,row)
 e=json.loads(read(ROOT/'EXPECTATIONS.json'));local=json.loads(read(e['actual_local_commit']['path'],e['actual_local_commit']));validation=json.loads(read(e['actual_validation_update']['path'],e['actual_validation_update']))
 need(e['source_admission']=={'mode':'LOCAL_ONLY_PENDING_FINAL_PUSH','remote_qualification':'UNBOUND','final_push':'PENDING'},'EXPLICIT_LOCAL_ONLY_ADMISSION_NO_REMOTE_CLAIM')
 adoption=json.loads(read(e['Root_design']['path'],e['Root_design']))
 red=json.loads(read(e['actual_component_red_root']['path'],e['actual_component_red_root']));green=json.loads(read(e['actual_component_green_root']['path'],e['actual_component_green_root']))
 need(adoption['status']=='ROOT_DESIGN_ADOPTED_MINIMAL_B54_FULL_NORMAL_SUCCESSOR_SOURCE_FIRST'
      and adoption['HEAD']==HEAD and adoption['PARENT']==PARENT and adoption['W']==W.as_posix() and adoption['Val']==VAL.as_posix()
      and adoption['branch']==e['expected_branch']=='fix/ms4-language-cache-fixture-20261025'
      and adoption['both_roots_actual_HEAD_clean'] is adoption['old_all_packets_immutable'] is True
      and adoption['first_author_scope']=='SOURCE_ONLY_NEW_EXPORTER_ADAPTATION_NOT_EXECUTION'
      and adoption['real_commit_record']==e['actual_local_commit'] and adoption['new_Val_native_worktree']==e['actual_validation_update']
      and adoption['component_real_red_root']==e['actual_component_red_root'] and adoption['component_real_green_root']==e['actual_component_green_root'],
      'ROOT_ADOPTED_REAL_B54_LOCAL_COMMIT_VAL_AND_RED_GREEN_SOURCE_ONLY')
 need(local['status']=='LOCAL_L2_FIX_COMMITTED_CLEAN' and local['HEAD']==HEAD and local['parent']==PARENT
      and local['branch']==e['expected_branch'] and len(local['paths'])==len(set(local['paths']))==3
      and sorted(local['paths'])==[row['path'] for row in e['changed_files']]
      and local['actual_component_red_green']==[1,0] and local['syntax3']==0
      and local['ordinary_close_and_independent_zero']==[0,0] and local['push']=='NOT_RUN'
      and local['fullnormal_UI_acceptance']=='PENDING_NOT_ASSERTED','REAL_LOCAL_B54_THREE_PATH_COMMIT_NOT_FULL_NORMAL')
 need(validation['status']=='ACTUAL_NEW_FIX_W_AND_VALIDATION_HEAD_CLEAN' and validation['HEAD']==HEAD
      and validation['W']==W.as_posix() and validation['Val']==VAL.as_posix() and e['expected_W']==W.as_posix() and e['expected_Val']==VAL.as_posix()
      and type(validation['native_PID']) is int and validation['native_PID']>0 and validation['native_wait']==0
      and validation['old_main_and_old_validation_unchanged'] is True,'REAL_NEW_CLEAN_B54_VALIDATION_ROOTS')
 need(red['status']=='ROOT_FULL_ACTUAL_TARGET_RED1_CLOSE0_INDEPENDENT_ZERO0_VERIFIED' and red['native_wait']==1
      and green['status']=='ROOT_FULL_ACTUAL_SAME_COMMAND_RED1_GREEN0_CLOSE0_INDEPENDENT_ZERO0_VERIFIED' and green['native_wait']==0
      and red['same_natural_two_App_exit']==[0,1] and green['same_natural_two_App_exit']==[0,0]
      and red['two_empty_maps_persisted_from_first_to_fresh_second_App'] is True
      and green['same_actual_runner_CLI_as_red'] is green['only_shared_leaf_changed_between_real_red_green'] is True
      and green['two_original_empty_maps_rebuilt_by_real_getter'] is green['unrelated_type_id_cache_and_deadline_unchanged'] is True,
      'GENUINE_SAME_COMMAND_TARGET_RED_THEN_GREEN_NOT_SETUP_FAILURE')
 for observed in (red,green):
  need(observed['full_output_files_read']==613 and observed['all_native_pairs_and_whole_streams_verified']==[135,12]
       and observed['syntax3_native_wait']==0 and observed['source_full_rows']==[1,1,2515]
       and observed['all_current45_source_pins_match'] is observed['foreign53_all_safe_snapshots_unchanged'] is True
       and observed['ordinary_resources_removed']==[6,1,7] and observed['ordinary_close_mutations']==5
       and observed['fresh_six_raw_empty']==[6,6] and observed['full_normal_UI19_groups']=='OPEN_NOT_ASSERTED',
       'ROOT_FULL_COMPONENT_READBACK_AND_ORDINARY_CLOSE_INDEPENDENT_ZERO_ONLY')
 need(e['source_sha']==HEAD and e['parent']==PARENT and len(e['changed_files'])==3
      and [row['before'] is None for row in e['changed_files']]==[True,False,True]
      and adoption['exact_real_changed_paths_M_A_A']==[('A' if row['before'] is None else 'M')+'\t'+row['path'] for row in e['changed_files']]
      and e['export_native_count']==9+sum(1+(row['before'] is not None) for row in e['changed_files'])==13
      and e['qualification_native_count']==12,'REAL_THREE_PATH_A_M_A_EXPECTED_PLAN_NOT_ACTUAL')
 need(not OUT.exists() and OUT.parent.is_dir(),'FRESH_OWN_OUTPUT');OUT.mkdir();put('export-source.py',read(Path(__file__)))
 expected_paths=[r['path'] for r in e['changed_files']];ops={}
 def get(stem,args):
  raw,op,receipts=git(W,stem,args,'git-lineage');ops[stem]=receipts;return raw
 need(get('head-before',['rev-parse','HEAD'])==(HEAD+'\n').encode(),'HEAD_BEFORE')
 need(get('clean-before',['status','--porcelain=v1','--untracked-files=all'])==b'','CLEAN_BEFORE')
 need(get('parent',['rev-list','--parents','-n','1',HEAD])==(HEAD+' '+PARENT+'\n').encode(),'REAL_PARENT')
 need(get('commit-object',['cat-file','-p',HEAD]).splitlines()[1]==('parent '+PARENT).encode(),'ACTUAL_COMMIT_OBJECT_PARENT')
 need(get('changed-paths',['diff-tree','--no-commit-id','--name-only','-r',HEAD]).decode().splitlines()==expected_paths,'EXACT_THREE_CHANGED_PATHS')
 need(get('name-status',['diff-tree','--no-commit-id','--name-status','-r',HEAD]).decode().splitlines()==[('A' if row['before'] is None else 'M')+'\t'+row['path'] for row in e['changed_files']],'EXACT_THREE_A_M_A_NAME_STATUS')
 need(get('branch',['branch','--show-current'])==(local['branch']+'\n').encode(),'REAL_CURRENT_LOCAL_WORKING_BRANCH')
 for i,row in enumerate(e['changed_files'],1):
  modes=[('after',HEAD)]+([('before',PARENT)] if row['before'] is not None else [])
  for mode,revision in modes:
   raw=get('member-'+str(i)+'-'+mode,['show',revision+':'+row['path']]);need(fp(raw)==row[mode],'REAL_WHOLE_COMMITTED_BLOB');put('git-blobs/'+mode+'/'+row['path'],raw)
 need(get('head-after',['rev-parse','HEAD'])==(HEAD+'\n').encode() and get('clean-after',['status','--porcelain=v1','--untracked-files=all'])==b'','EXACT_STABLE_SOURCE_AFTER_EXPORT')
 need(len(ops)==e['export_native_count'],'DERIVED_ACTUAL_EXPORT_FROM_THREE_MEMBERS_PLUS_REAL_BRANCH')
 put('CURRENT-SOURCE-AUTHORITY.json',{'schema':1,'scope':'ACTUAL_READ_ONLY_GIT_LOCAL_B54_LANGUAGE_CACHE_FIX_STEP','source_sha':HEAD,'parent':PARENT,'kind':'language_cache_fixture_fix','files':e['changed_files'],'git_operations':ops,'native_count':len(ops),'business':'NOT RUN','worktree_mutations':0,'actual_local_commit':e['actual_local_commit'],'actual_validation_update':e['actual_validation_update'],'actual_component_red_root':e['actual_component_red_root'],'actual_component_green_root':e['actual_component_green_root'],'Root_design':e['Root_design'],'source_admission':e['source_admission']})
 qualification=[]
 def q(repo,stem,args):
  raw,op,_=git(repo,stem,args,'current-source-readback');qualification.append(op);return raw
 for label,repo in [('main',W),('validation',VAL)]:
  need(q(repo,label+'-head-before',['rev-parse','HEAD'])==(HEAD+'\n').encode(),'CURRENT_HEAD')
  need(q(repo,label+'-clean-before',['status','--porcelain=v1','--untracked-files=all'])==b'','CURRENT_CLEAN')
  need(q(repo,label+'-parent',['rev-list','--parents','-n','1',HEAD])==(HEAD+' '+PARENT+'\n').encode(),'CURRENT_PARENT')
  names=q(repo,label+'-tracked',['ls-files','-z']).decode().split('\0');need(names[-1]=='' and sorted(names[:-1])==e['complete_tracked_paths'],'COMPLETE_ACTUAL_TRACKED_NAMESPACE')
 for repo in (W,VAL):
  for row in e['required_source_files']:read(repo/row['path'],row)
 for label,repo in [('main',W),('validation',VAL)]:
  need(q(repo,label+'-head-after',['rev-parse','HEAD'])==(HEAD+'\n').encode() and q(repo,label+'-clean-after',['status','--porcelain=v1','--untracked-files=all'])==b'','SOURCE_QUALIFICATION_HELD')
 need(len(qualification)==e['qualification_native_count'] and len(OPS)==len(ops)+len(qualification),'ACTUAL_QUALIFICATION_AND_TOTAL_COUNTS')
 result={'schema':1,'scope':'ACTUAL_LOCAL_B54_MAIN_AND_VALIDATION_COMPLETE_REQUIRED_FILES_PLUS_FULL_NAMESPACE','source_sha':HEAD,'parent':PARENT,'required_files_per_checkout':len(e['required_source_files']),'tracked_names_per_checkout':len(e['complete_tracked_paths']),'current_Main_working_tree_asserted':True,'current_Val_working_tree_asserted':True,'operations':qualification,'all_operations_read_only':True,'target_runtime':'NOT RUN','ended_ns':time.time_ns()}
 put('CURRENT-SOURCE-QUALIFICATION.json',result)
 for p,row in READS.items():
  s=Path(p).lstat();need([getattr(s,k) for k in ('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')]==row['native9'] and s.st_birthtime_ns==row['birthtime_ns'],'ALL_SOURCE_FINAL_NAMED_CONTINUITY')
 put('SOURCE-INPUTS.json',{'files':READS,'all_first_final_EOF_closed':True,'full_group_final_native9_birthtime':True,'application_or_runtime_bodies_read':False})
 put('REPORT.json',{'status':'ACTUAL_LOCAL_B54_READ_ONLY_SOURCE_EXPORT13_AND_QUALIFICATION12_ZERO_ONLY','source_sha':HEAD,'parent':PARENT,'export_native':len(ops),'qualification_native':len(qualification),'actual_total_native':len(OPS),'actual_source_authority':pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'actual_current_qualification':pointer(OUT/'CURRENT-SOURCE-QUALIFICATION.json'),'required_files_per_checkout':len(e['required_source_files']),'tracked_names_per_checkout':len(e['complete_tracked_paths']),'new_M_S_App_business':'NOT RUN'})
 print(json.dumps({'status':'ACTUAL_LOCAL_B54_READ_ONLY_SOURCE_EXPORT13_AND_QUALIFICATION12_ZERO_ONLY','export':len(ops),'qualification':len(qualification),'source_authority':pointer(OUT/'CURRENT-SOURCE-AUTHORITY.json'),'source_qualification':pointer(OUT/'CURRENT-SOURCE-QUALIFICATION.json')},ensure_ascii=False))
if __name__=='__main__':main()
