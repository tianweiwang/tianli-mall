"""Construct readonly exporter Source inputs. No exporter or Git is executed."""
from pathlib import Path
import ast,builtins,hashlib,json,os,stat,symtable
P=Path(__file__).resolve().parents[1];ORG=P.parent.parent
N=ORG/'currentd0a5-next-normal-preparation-r1/normal-source';READS={}
def fp(b):return {'bytes':len(b),'sha256':hashlib.sha256(b).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def read(p,expected=None):
 p=Path(p);a=p.lstat();keys=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');stamp=lambda s:[getattr(s,k) for k in keys]
 assert stat.S_ISREG(a.st_mode) and not p.is_symlink() and not getattr(a,'st_file_attributes',0)&0x400
 with p.open('rb') as f:
  b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
 z=p.lstat();assert stamp(a)==stamp(z) and a.st_birthtime_ns==z.st_birthtime_ns and stamp(b)==stamp(c) and b.st_birthtime_ns==c.st_birthtime_ns and len(raw)==a.st_size
 assert [stamp(a)[i] for i in (0,1,6,7)]==[stamp(b)[i] for i in (0,1,6,7)]
 if expected:assert fp(raw)=={k:expected[k] for k in ('bytes','sha256')}
 row={'path':p.as_posix(),**fp(raw),'named9':stamp(a),'fd9':stamp(b),'birthtime_ns':a.st_birthtime_ns,'EOF':True,'closed':True};assert p.as_posix() not in READS or READS[p.as_posix()]==row;READS.setdefault(p.as_posix(),row);return raw
def pointer(p):return {'path':Path(p).as_posix(),**fp(read(p))}
def obj(p,expected=None):return json.loads(read(p,expected))
def put(n,v):
 with (P/n).open('xb') as f:f.write(enc(v))
def main():
 assert not (P/'PINS.json').exists()
 head='bafb6305062aeb6b6e945c21da572863062ca480';parent='d0a5d5398546e223122b5c2a2f115e1908f78ced';ar='help/audit-regressions/isolated/ms4-aftersale-review-normal.php';doc='help/audit-regressions/isolated/ms4-aftersale-warm-failure-context.md'
 read(P/'inputs/ROOT-DESIGN.md',{'bytes':1218073,'sha256':'7959f35b8be6393568fdc8d7cecd2c1f59a477dbc8a12f7296aaf0103f1a1d9c'})
 local_path=ORG/'currentd0a5-warm-failure-context-local-commit-actual-r2/REPORT.json';val_path=ORG/'currentbafb-val-update-actual-r1/REPORT.json'
 local=obj(local_path,{'bytes':16331,'sha256':'af507526be5a252b7005e259a626b93806bf9798ef77e3cdc98662f3ff497059'});val=obj(val_path,{'bytes':4316,'sha256':'ca9d7d83d54e1b1485a39ad6ea902fe20c36917312bf5aff2216d1c6afe764d0'})
 assert local['head']==val['new_head']==head and local['parent']==val['old_head']==parent and local['status']=='LOCAL_SCOPED_COMMIT_COMPLETE_NOT_PUSHED'
 assert local['paths']==val['changed_paths_from_parent']==[ar,doc] and local['only_exact_two_paths_committed'] is local['committed_PHP_equals_reviewed_and_syntax_checked_candidate'] is local['worktree_index_clean'] is True
 assert local['branch']=='feature/ms4-asset-consumers' and local['push_deploy_production']=='NOT_RUN' and local['full_normal_business']=='PENDING'
 assert val['status']=='CLEAN_VALIDATION_WORKTREE_ALIGNED_TO_LOCAL_REVIEWED_COMMIT' and len(val['operations'])==6 and all(x['native_exit']==x['wait_returncode']==0 for x in val['operations'])
 for name,path in [('ROOT-LOCAL-COMMIT.json',local_path),('ROOT-VAL-UPDATE.json',val_path)]:
  with (P/'inputs'/name).open('xb') as f:f.write(read(path))
 packet=obj(N/'PINS.json',{'bytes':87279,'sha256':'d1ee9ba1981bdc56bf5c694478f455797a810d9635e5053b320cebd30759e6ae'});pub=obj(N/'validation/PROFILE-PUBLICATION.json',packet['files']['validation/PROFILE-PUBLICATION.json']);context=pub['finite_contexts'];assert pub['source_sha']==parent and len(context['commit_chain'])==23
 inventory=[json.loads(line) for line in read(context['baseline']['inventory']['path'],context['baseline']['inventory']).splitlines()];base={row['path']:{k:row[k] for k in ('path','bytes','sha256')} for row in inventory};expected=dict(base);assert len(base)==len(inventory)
 for step in context['commit_chain']:
  for row in step['files']:
   name=row['path'];assert row['before']==({k:expected[name][k] for k in ('bytes','sha256')} if name in expected else None);expected[name]={'path':name,**row['after']}
 assert len(expected)==context['actual_complete_expected_tracked_count']==15749
 for row in pub['repository_Source_requirements']:assert expected[row['path']]==row
 names=[ar,doc];wanted={ar:{'bytes':244912,'sha256':'a1258f537a3aa2de776d5a64afa5621141df0173e6d602feb168bd1f0c12ea0c'},doc:{'bytes':1781,'sha256':'b3092b34dbae1e421072f21e1217d6f36b0cd175b1b997391bd2b1998f0c301b'}};files=[]
 assert ar in expected and doc not in expected
 for name in names:
  before={k:expected[name][k] for k in ('bytes','sha256')} if name in expected else None
  for root in [Path('H:/天俪商城/worktrees/ms4-asset-consumers'),Path('H:/天俪商城/worktrees/ms4-aftersale-normal-check')]:read(root/name,wanted[name])
  files.append({'path':name,'before':before,'after':wanted[name]});expected[name]={'path':name,**wanted[name]}
 required=sorted({r['path'] for r in pub['repository_Source_requirements']}|set(names));export=6+len(files)+sum(row['before'] is not None for row in files)+2+1
 assert len(expected)==15750 and len(required)==316 and sum(r['bytes'] for r in expected.values())==341939628 and export==12
 e={'schema':1,'source_sha':head,'parent':parent,'changed_files':files,'complete_tracked_paths':sorted(expected),'required_source_files':[expected[name] for name in required],'export_native_count':export,'qualification_native_count':12,'source_admission':{'mode':'LOCAL_ONLY_PENDING_FINAL_PUSH','remote_qualification':'UNBOUND','final_push':'PENDING'},'actual_local_commit':pointer(local_path),'actual_validation_update':pointer(val_path),'baseline_twentythree_Source_PINS':pointer(N/'PINS.json'),'baseline_twentythree_publication':pointer(N/'validation/PROFILE-PUBLICATION.json'),'Root_design':pointer(P/'inputs/ROOT-DESIGN.md'),'source_derivation':'Complete23-stage baseline and actual local-only bafb Root commit/Val reports; exact AR M and doc A; real Git blobs/branch/full namespaces/required bodies still must be proven by Root execution. No fake remote qualification and no old current successful M/S receipts.','qualified_required_scope_preserved':True,'new_export_qualification_M_S_actuals':'UNBOUND_NOT_RUN','future_M_native_budget':'DERIVE_SEPARATELY_FROM_ORIGINAL_M_FORMULA'}
 put('EXPECTATIONS.json',e)
 for name in ['candidate/export.py','candidate/prepare_source.py']:
  raw=read(P/name);ast.parse(raw);table=symtable.symtable(raw.decode(),name,'exec');known={s.get_name() for s in table.get_symbols() if s.is_assigned() or s.is_imported() or s.is_namespace()}|set(dir(builtins))|{'__file__','__name__'}
  def walk(t):
   assert not [s.get_name() for s in t.get_symbols() if s.is_referenced() and s.is_global() and s.get_name() not in known]
   for child in t.get_children():walk(child)
  walk(table)
 for path,row in READS.items():
  s=Path(path).lstat();assert [getattr(s,k) for k in ('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')]==row['named9'] and s.st_birthtime_ns==row['birthtime_ns']
 put('SOURCE-INPUTS.json',{'files':READS,'all_complete_first_final_group_Source_windows':True,'exporter_Git_or_targets_executed':False})
 put('REPORT.json',{'status':'SOURCE_ONLY_BAFB_LOCAL_GIT_EXPORT12_PLUS_QUALIFICATION12_NOT_EXECUTED','source_sha':head,'parent':parent,'source_admission':'LOCAL_ONLY_PENDING_FINAL_PUSH','tracked_derived':len(expected),'tracked_bytes_derived':sum(r['bytes'] for r in expected.values()),'required_derived':len(required),'prior_chain_stages':23,'changed_paths':2,'modifications':1,'additions':1,'export_native_plan':12,'qualification_native_plan':12,'original_required_scope_preserved':True,'remote_qualification':'UNBOUND','new_PID_native_M_S_and_business_results':'UNBOUND_NOT_RUN','W_Val_MEDIA_Run_canonical_changes':False})
 print(json.dumps({'source_only':True,'export_plan':export,'qualification_plan':12,'tracked':len(expected),'required':len(required),'full_tree_bytes':sum(r['bytes'] for r in expected.values())}))
if __name__=='__main__':main()
