"""Author-only stdlib full-reader Source successor. Never imports/runs readers or authority."""
from pathlib import Path
import ast,builtins,copy,difflib,hashlib,json,os,re,stat,symtable
P=Path(__file__).resolve().parent;O=P.parent;A=O/'current41c0-normal-root-admission-r1'
OLD=O/'currentb54-materialization-readers-source-r1';N=O/'currentb54-next-normal-preparation-r3/normal-source'
READS={};LOG={}
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(x):return (json.dumps(x,ensure_ascii=False,indent=2)+'\n').encode()
def read(path,expected=None):
 path=Path(path);a=path.lstat();ks=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');nine=lambda v:[getattr(v,k) for k in ks]
 assert stat.S_ISREG(a.st_mode) and not path.is_symlink() and not getattr(a,'st_file_attributes',0)&1024
 with path.open('rb') as f:b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
 z=path.lstat();assert nine(a)==nine(z) and nine(b)==nine(c) and a.st_birthtime_ns==z.st_birthtime_ns==b.st_birthtime_ns==c.st_birthtime_ns and all(nine(a)[i]==nine(b)[i] for i in (0,1,6,7))
 if expected is not None:assert pin(raw)=={k:expected[k] for k in ('bytes','sha256')}
 row={'path':path.as_posix(),**pin(raw),'named9':nine(z),'fd9':nine(c),'birthtime_ns':z.st_birthtime_ns,'EOF':True,'closed':True}
 assert path.as_posix() not in READS or READS[path.as_posix()]==row;READS[path.as_posix()]=row;return raw
def obj(path,expected=None):return json.loads(read(path,expected))
def ref(path):return {'path':Path(path).as_posix(),**pin(read(path))}
def save(name,data):
 q=P/name;q.parent.mkdir(parents=True,exist_ok=True)
 if q.exists():assert q.read_bytes()==data
 else:
  with q.open('xb') as f:assert f.write(data)==len(data)
def constants(raw):
 d={}
 for t in ast.parse(raw).body:
  if isinstance(t,ast.Assign) and len(t.targets)==1 and isinstance(t.targets[0],ast.Name):
   try:d[t.targets[0].id]=ast.literal_eval(t.value)
   except (ValueError,TypeError):pass
 return d
def defs(s):
 ls=s.splitlines(True);return {n.name:''.join(ls[n.lineno-1:n.end_lineno]) for n in ast.parse(s).body if isinstance(n,ast.FunctionDef)}
def once(s,was,new,label):
 assert s.count(was)==1,(label,s.count(was),was[:100]);LOG.setdefault(label,[]).append({'before':was,'candidate':new});return s.replace(was,new,1)
def assign(s,key,value,label):
 node=next(n for n in ast.parse(s).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id==key)
 return once(s,ast.get_source_segment(s,node),key+' = '+repr(value),label)
def patch(a,b,name):return ''.join(difflib.unified_diff(a.splitlines(True),b.splitlines(True),fromfile='a/'+name,tofile='b/'+name)).encode()
def replay(src,patchraw):
 ls=patchraw.decode().splitlines(True);i=2;original=src.splitlines(True);out=[];pos=0;hunks=0
 assert ls[0].startswith('--- a/') and ls[1].startswith('+++ b/')
 while i<len(ls):
  m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n',ls[i]);assert m;i+=1;hunks+=1
  no,nn=int(m[2] or 1),int(m[4] or 1);start=int(m[1])-(no!=0);assert pos<=start;out+=original[pos:start];pos=start;a=b=0
  while i<len(ls) and not ls[i].startswith('@@ '):
   line=ls[i];i+=1;assert line[:1] in (' ','-','+')
   if line[0] in ' -':assert original[pos]==line[1:];pos+=1;a+=1
   if line[0] in ' +':out.append(line[1:]);b+=1
  assert (a,b)==(no,nn)
 out+=original[pos:];return ''.join(out),hunks
def missing(s):
 table=symtable.symtable(s,'Source-only','exec');bound={x.get_name() for x in table.get_symbols() if x.is_assigned() or x.is_imported() or x.is_namespace()}|set(dir(builtins))|{'__file__'};bad=set()
 def visit(t):
  for x in t.get_symbols():
   if x.is_global() and x.is_referenced() and x.get_name() not in bound:bad.add(x.get_name())
  for child in t.get_children():visit(child)
 visit(table);return sorted(bad)

def main():
 assert not (P/'PINS.json').exists()
 oldpins=read(OLD/'PINS.json',{'bytes':10778,'sha256':'e28d86d55feeaec39bb78e21343311e2bc74980cdba2390117f645c46ad39344'});packet=json.loads(oldpins)
 assert len(packet['files'])==67
 # Freeze only declared fixed Source members, never any reader output directory.
 assert {q.relative_to(OLD).as_posix() for q in OLD.rglob('*') if q.is_file()}==set(packet['files'])|{'PINS.json'}
 for name,row in packet['files'].items():read(OLD/name,row)
 for name in ['PINS.json','SOURCE-PINS.json','root-reader.py','peer-review.py','CLI.json','REPORT.json','SOURCE-PROOF.json']:
  save('before/'+name,read(OLD/name))
 root0=read(OLD/'root-reader.py').decode();peer0=read(OLD/'peer-review.py').decode()
 proofpath=A/'R106-B54-CALLBACK-ACTUAL-ROOT-READBACK.json';proofraw=read(proofpath,{'bytes':4131,'sha256':'2426a231bff2d7655b8dede264be984629a1a28ef1e5edbf3f502bede4445d63'});actual=json.loads(proofraw)
 correct=actual['status'];wrong='ROOT_ACTUAL_COMPLETE_BAFB_R3_CALLBACKS0_PUBLIC_READBACK0_ADMIT_FULL_M_ONLY'
 assert correct=='ROOT_ACTUAL_COMPLETE_B54_R3_CALLBACKS0_PUBLIC_READBACK0_ADMIT_FULL_M_ONLY'
 oldguard="callback_root['status']=="+repr(wrong);newguard="callback_root['status']=="+repr(correct)
 root=once(root0,"out = B / 'currentb54-complete-materialization-root-readback-r1'","out = B / 'currentb54-complete-materialization-root-readback-r2'",'Root-output-only')
 peer=once(peer0,oldguard,newguard,'peer-F1-exact-true-status')
 peer=once(peer,"OUT=Path('"+(O/'currentb54-complete-materialization-independent-readback-r1').as_posix()+"')","OUT=Path('"+(O/'currentb54-complete-materialization-independent-readback-r2').as_posix()+"')",'peer-output-only')
 programs={'root-reader.py':(root0,root),'peer-review.py':(peer0,peer)};results={}
 for name,(before,after) in programs.items():
  compile(after,name,'exec');assert not missing(after),(name,missing(after))
  olddefs=defs(before);newdefs=defs(after)
  if name=='root-reader.py':assert olddefs==newdefs
  else:
   assert list(olddefs)==list(newdefs)
   assert all(newdefs[n]==s for n,s in olddefs.items() if n!='main')
   assert newdefs['main'].replace(newguard,oldguard)==olddefs['main']
  f=patch(before,after,name);r=patch(after,before,name);forward,hf=replay(before,f);reverse,hr=replay(after,r)
  assert forward==after and reverse==before
  save(name,after.encode());save(name+'.FORWARD.diff',f);save(name+'.REVERSE.diff',r)
  results[name]={'before':pin(before.encode()),'candidate':pin(after.encode()),'entire_module_reverse_exact_R1':True,'forward_hunks':hf,'reverse_hunks':hr,'functions_raw':list(olddefs) if name=='root-reader.py' else [n for n in olddefs if n!='main'],'main_only_status_literal_changed':name=='peer-review.py','no_missing_globals':True}
 assert constants(peer.encode())['CALLBACK_ROOT_EXPECTED']==actual
 assert newguard in peer and oldguard not in peer
 for folder in ['author-fragments','inputs']:
  for q in (OLD/folder).rglob('*'):
   if q.is_file():save(q.relative_to(OLD).as_posix(),read(q))
 save('DESIGN.md',b'R108 thin complete-reader successor: fix exact peer B54 Root status only and use fresh disjoint r2 outputs. Historical/current scopes and all complete readback obligations unchanged. No target execution; final seal waits for complete independent R1 audit.\n')
 cli=json.loads(read(OLD/'CLI.json'))
 for item in cli['programs']:
  assert item['argv'][5]==(OLD/item['program']).as_posix();item['argv'][5]=(P/item['program']).as_posix()
 cli['Root_output']=str(Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/currentb54-complete-materialization-root-readback-r2')).replace('\\','/')
 cli['peer_output']=(O/'currentb54-complete-materialization-independent-readback-r2').as_posix();cli['final_Source_admission']='WAIT_COMPLETE_R1_FINAL_AUDIT_AND_R2_NONAUTHOR_REVIEW'
 save('CLI.json',enc(cli))
 save('INTERFACE.md',('Only the exact peer callback Root status and new disjoint outputs differ from R1. R1 remains sealed/unexecuted. All Root functions and every peer nonmain helper stay raw; peer main preserves its whole Root equality and full current/historical verification. Both actual reader results and S/business remain UNBOUND.\n').encode())
 save('SEAMS.json',enc({'schema':1,'before_R1_PINS':{'path':(OLD/'PINS.json').as_posix(),**pin(oldpins)},'exact_changes':LOG,'full_module_inverse':results,'F1_exact_producer':{'path':proofpath.as_posix(),**pin(proofraw)}}))
 for path in list(READS):read(path)
 save('INPUT-WINDOWS.json',enc({'schema':1,'files':READS,'all_complete_first_final_EOF_named9_fd9_birth_cross4_closed':True,'target_or_candidate_execution':False}))
 save('CANDIDATE-CHECKS.json',enc({'schema':1,'status':'CANDIDATES_CONSTRUCTED_STATIC_ONLY_WAIT_FINAL_R1_AUDIT_BEFORE_SEAL','programs':results,'all_helper_fragment_and_input_body_files_raw':True,'F1_status_exact_whole_EXPECTED_still_checked':True,'M0_Core_Git_profile_native_11whole_scope_unchanged':True,'candidate_or_fragment_or_targets_executed':False,'final_R1_audit':'PENDING','PINS_or_runtime_qualification':'NOT_CREATED'}))
 print(json.dumps({'programs':results,'final_PINS':'NOT_CREATED_WAIT_FINAL_R1_FINDINGS','candidate_or_targets':'NOT_EXECUTED'},ensure_ascii=False))

if __name__=='__main__':main()

