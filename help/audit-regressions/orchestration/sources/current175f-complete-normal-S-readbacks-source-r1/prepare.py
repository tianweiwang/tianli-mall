"""Construct only complete175f S readback Source; never execute a reader."""
import ast,difflib,hashlib,json,os,time
from pathlib import Path
OUT=Path(__file__).parent
ORG=OUT.parent;B=ORG.parent/'ms4-worker-normal-source-preparation-r1';N=B/'full-a-quota-wire-current175f-normal-next-source-r1'
INPUTS={};SEAMS={}
def fp(b):return {'bytes':len(b),'sha256':hashlib.sha256(b).hexdigest()}
def n9(s):return [getattr(s,k)for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']]
def read(p,expected=None):
 p=Path(p);pn=n9(p.stat())
 with p.open('rb')as f:
  fn=n9(os.fstat(f.fileno()));b=f.read();eof=f.read(1);fa=n9(os.fstat(f.fileno()))
 pa=n9(p.stat());assert pn==pa and fn==fa and all(pn[i]==fn[i]for i in [0,1,6,7])and eof==b'' and len(b)==fn[6]
 if expected is not None:assert fp(b)==expected,(p,fp(b),expected)
 INPUTS[p.as_posix()]={'path':p.as_posix(),**fp(b),'named_before':pn,'named_after':pa,'fd_open':fn,'fd_after':fa,'EOF':True,'closed':True};return b
def write(name,b):
 p=OUT/name;p.parent.mkdir(exist_ok=True,parents=True)
 with p.open('xb')as f:f.write(b)
def put(name,v):write(name,(json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode())
def once(label,text,before,after):
 assert text.count(before)==1,(label,before[:120],text.count(before));SEAMS.setdefault(label,[]).append({'before':before,'after':after});return text.replace(before,after,1)
def literal(label,text,key,value):
 node=next(n for n in ast.parse(text).body if isinstance(n,ast.Assign)and len(n.targets)==1 and isinstance(n.targets[0],ast.Name)and n.targets[0].id==key)
 return once(label,text,ast.get_source_segment(text,node),key+' = '+repr(value))
def definitions(text):return {n.name:ast.get_source_segment(text,n)for n in ast.parse(text).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def apply(raw,patch):
 original=raw.decode().splitlines(keepends=True);lines=patch.decode().splitlines(keepends=True);assert lines[0].startswith('--- a/')and lines[1].startswith('+++ b/');i=2;cursor=0;out=[]
 import re
 while i<len(lines):
  m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n?',lines[i]);assert m
  oldn=int(m[2]or 1);newn=int(m[4]or 1);start=int(m[1])-1 if oldn else int(m[1]);assert start>=cursor;out.extend(original[cursor:start]);cursor=start;i+=1;used=made=0
  while i<len(lines)and not lines[i].startswith('@@ '):
   line=lines[i];assert line[0]in ' +-'
   if line[0]in ' -':assert original[cursor]==line[1:];cursor+=1;used+=1
   if line[0]in ' +':out.append(line[1:]);made+=1
   i+=1
  assert (used,made)==(oldn,newn)
 out.extend(original[cursor:]);return ''.join(out).encode()
def patch(before,after,name):return ''.join(difflib.unified_diff(before.decode().splitlines(keepends=True),after.decode().splitlines(keepends=True),fromfile='a/'+name,tofile='b/'+name)).encode()
def main():
 rootsource=ORG/'current28dc-complete-normal-S-readback-source-r1/candidate/readback-current28dc-complete-normal-S.py';peersource=ORG/'current28dc-complete-normal-S-actual-independent-reader-source-r2/readback-actual-current28dc-complete-normal-S-independent.py'
 before={'root.py':read(rootsource,{'bytes':12417,'sha256':'f76984c14e1bf004edf73d885093b6bfbecd2ff302316facfbed416f1e76a02e'}),'peer.py':read(peersource,{'bytes':17937,'sha256':'6ee762117eed3b9c7096ebdd68519f6c3a373f1fbbb527618d2d2d44046209fe'})}
 helperpath=B/'customer-form-current-three-front-material-producer-independent-r1/review.py';helper=read(helperpath,{'bytes':33902,'sha256':'4673d95c60c22f4653b6f22f833e24fb6accc3b286c555ca0462b0fb501252e0'});before['finite-helper.py']=helper
 helpernew=once('finite-helper.py',helper.decode(),"if k != 'st_ctime_ns'","if k in {'st_dev', 'st_ino', 'st_size', 'st_mtime_ns'}").encode()
 sourcepin={'bytes':147196,'sha256':'2653282ad09cdf30e030c6c1cf5976b73a1a06bd0be52070085e3a3f33bb270b'};sourcepins=json.loads(read(N/'PINS.json',sourcepin))
 compact=lambda x:{k:x[k]for k in ['bytes','sha256']}
 profile={k.removeprefix('validation/actual-profile/'):compact(v)for k,v in sourcepins['files'].items()if k.startswith('validation/actual-profile/')};assert len(profile)==9
 mcode=read(N/'materializer/prepare.py',compact(sourcepins['files']['materializer/prepare.py'])).decode();mtree=ast.parse(mcode);keys=ast.literal_eval(next(n.value for n in mtree.body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='CURRENT_M_RESULT_KEYS35'for t in n.targets)));assert len(keys)==35
 mrootpath=B/'current175f-complete-materialization-root-readback-r1/REPORT.json';mrootraw=read(mrootpath,{'bytes':1814,'sha256':'6901f56b93e54ae6493a02e3851084e56be995d316294314e0bf03a0b710af05'});mroot=json.loads(mrootraw)
 assert mroot['M_RESULT_fields']==35 and mroot['steps']==19 and mroot['native_operations']==1088 and mroot['source_sha']==sourcepins['source_sha']
 mpin=compact(mroot['actual_RESULT']);freeze=compact(mroot['actual_freeze']);driverpin=compact(sourcepins['files']['validation/run.py']);rootpin=compact(sourcepins['files']['validation/PINS.json'])
 current={}
 for label in ['root','peer']:
  name=label+'.py';text=before[name].decode()
  # Fixed original ancestor reader/static qualification paths are retained below.
  for old,new in [('full-a-upload-root-current28dc-normal-next-source-r1','full-a-quota-wire-current175f-normal-next-source-r1'),('28dcab632da3e62f7c8b8d926b6ab9406534d0d3','175f3cd6183894185656a2974579e1be39eed7a7')]:
   while old in text:
    line=next(line for line in text.splitlines(keepends=True)if old in line);text=once(name,text,line,line.replace(old,new))
  olddesc="description='Read only the completed successful current28dc normal S receipts'";text=once(name,text,olddesc,"description='Read only the completed successful current175f normal S receipts'")
  text=once(name,text,"args.run_id == '20261023124755f45a9e'","args.run_id in ('20261023124755f45a9e', '202610231609185fbd23')")
  text=once(name,text,"the retired failed0c13 Run is forbidden","both retired failed0c13 and failed28dc Runs are forbidden")
  for key,v in [('SOURCE_PINS',sourcepin),('ROOT_SOURCE_PINS',rootpin),('M_RESULT',mpin),('FREEZE',freeze),('M_ROOT_READBACK',fp(mrootraw))]:text=literal(name,text,key,v)
  oldprofile=ast.literal_eval(next(n.value for n in ast.parse(text).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='PROFILE_SOURCE_PINS9'for t in n.targets)))
  text=literal(name,text,'PROFILE_SOURCE_PINS9',{k:profile[k]for k in oldprofile})
  text=literal(name,text,'driver_pin',driverpin)
  oldscripts=ast.literal_eval(next(n.value for n in ast.parse(text).body if isinstance(n,ast.Assign)and any(isinstance(t,ast.Name)and t.id=='scripts'for t in n.targets)));text=literal(name,text,'scripts',{k:profile[k]for k in oldscripts})
  text=once(name,text,"current28dc-complete-materialization-root-readback-r2/REPORT.json","current175f-complete-materialization-root-readback-r1/REPORT.json")
  text=once(name,text,'PASS_ACTUAL_FULL_CURRENT28DC_MATERIALIZATION_ONLY','PASS_ACTUAL_FULL_CURRENT175F_MATERIALIZATION_ONLY')
  text=once(name,text,"context = r.json(run / 'actual-run-context.json')","context = r.json(run / 'actual-run-context.json', {'bytes': 12301, 'sha256': 'c56e609828ba3e51e63f69b83e4af49279bdaebee487116728c383e02dae7959'})")
  if label=='root':
   text=once(name,text,"helper = base / 'customer-form-current-three-front-material-producer-independent-r1/review.py'","helper = Path(__file__).resolve().parent / 'finite-helper.py'")
   text=once(name,text,"assert len(raw) == 33902 and hashlib.sha256(raw).hexdigest() == '4673d95c60c22f4653b6f22f833e24fb6accc3b286c555ca0462b0fb501252e0'","assert len(raw) == "+str(len(helpernew))+" and hashlib.sha256(raw).hexdigest() == '"+fp(helpernew)['sha256']+"'")
   text=once(name,text,"parser.add_argument('--prepare-pid', required=True, type=positive_pid)","parser.add_argument('--prepare-pid', required=True, type=positive_pid)\nparser.add_argument('--authorize-completed-s0', action='store_true', help='Root has authorized original S native0/wait0')")
   text=once(name,text,"org = Path('H:/天俪商城/artifacts/project-organization-20261023')","if not args.authorize_completed_s0:\n    parser.error('Root must authorize completed original S native0/wait0 before this reader runs')\nif (args.run_id, args.outer_pid, args.prepare_pid) != ('20261023182634096f57', 35944, 49064):\n    parser.error('Supply this actual published175f Run and original PIDs')\n\norg = Path('H:/天俪商城/artifacts/project-organization-20261023')")
   text=once(name,text,"r.read(source / 'validation/materialize-freeze-assets-r1/RESULT.json', M_RESULT)","actual_M = r.json(source / 'validation/materialize-freeze-assets-r1/RESULT.json', M_RESULT)")
   text=once(name,text,"PASS_ORIGINAL_CURRENT28DC_COMPLETE_NORMAL_A_RECEIPT_READBACK_ONLY","PASS_ORIGINAL_CURRENT175F_COMPLETE_NORMAL_A_RECEIPT_READBACK_ONLY")
   text=once(name,text,"out = base / 'current28dc-complete-normal-A-root-readback-r1'","out = Path(__file__).resolve().parents[1] / 'actual-root-readback-r1'")
  else:
   text=once(name,text,"('202610231609185fbd23', 50220, 9100)","('20261023182634096f57', 35944, 49064)")
   text=once(name,text,'bound to the genuine current28dc Run and original PIDs','bound to the genuine current175f Run and original PIDs')
   text=once(name,text,'the actual completed original current28dc S.','the actual completed original current175f S.')
   text=once(name,text,'PASS_INDEPENDENT_ACTUAL_CURRENT28DC_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY','PASS_INDEPENDENT_ACTUAL_CURRENT175F_COMPLETE_NORMAL_S_PUBLIC_RECEIPTS_ONLY')
   text=once(name,text,"out = Path(__file__).resolve().parent / 'actual-readback-r1'","out = Path(__file__).resolve().parents[1] / 'actual-peer-readback-r1'")
   text=once(name,text,"assert reviewed_static['status'] == 'PASS_INDEPENDENT_STATIC_CURRENT28DC_COMPLETE_NORMAL_S_READER_SOURCE_ONLY'","assert reviewed_static['status'] == 'PASS_INDEPENDENT_STATIC_CURRENT28DC_COMPLETE_NORMAL_S_READER_SOURCE_ONLY'\n# Preserve fixed ancestor qualification; bind the full current Root Source separately.\nr.read(Path(__file__).resolve().parent / 'readback-current175f-complete-normal-S-root.py', "+repr(fp(current['root.py']))+")")
  text=once(name,text,"for name, expected in PROFILE_SOURCE_PINS9.items():","CURRENT_M_RESULT_KEYS35 = "+repr(keys)+"\nassert set(actual_M) == set(CURRENT_M_RESULT_KEYS35) and len(actual_M) == 35\nassert len(actual_M['commit_chain']) == 19 and len(actual_M['native_operations']) == 1088\nassert m_readback['M_RESULT_fields'] == 35 and m_readback['steps'] == 19 and m_readback['native_operations'] == 1088\n\nfor name, expected in PROFILE_SOURCE_PINS9.items():")
  assert definitions(text)==definitions(before[name].decode()),name
  ast.parse(text);current[name]=text.encode()
 current['finite-helper.py']=helpernew
 report={}
 for name,raw in current.items():
  before_raw=before[name];recovered=raw.decode()
  for seam in reversed(SEAMS[name]):assert recovered.count(seam['after'])==1,(name,seam['after'][:80]);recovered=recovered.replace(seam['after'],seam['before'],1)
  assert recovered.encode()==before_raw,name
  forward,reverse=patch(before_raw,raw,name),patch(raw,before_raw,name);assert apply(before_raw,forward)==raw and apply(raw,reverse)==before_raw
  for suffix,b in [('FORWARD.diff',forward),('REVERSE.diff',reverse)]:write(name+'.'+suffix,b)
  filename={'root.py':'readback-current175f-complete-normal-S-root.py','peer.py':'readback-current175f-complete-normal-S-independent.py','finite-helper.py':'finite-helper.py'}[name]
  write('candidate/'+filename,raw)
  if name=='finite-helper.py':write('before/'+name,before_raw)
  report[name]={'before':fp(before_raw),'candidate':fp(raw),'seams':len(SEAMS[name]),'whole_ordered_inverse':True,'ordinary_forward_reverse':True,'functions_classes_whole_identical':name!='finite-helper.py','original_definition_names':list(definitions(before_raw.decode()))}
 # The helper whole module changes exactly its settled Windows cross-layer condition.
 assert once('helper_proof',helpernew.decode(),"if k in {'st_dev', 'st_ino', 'st_size', 'st_mtime_ns'}","if k != 'st_ctime_ns'").encode()==helper
 put('TRANSFORM.json',{'schema':1,'sources':report,'ordered_seams':SEAMS,'no_reader_or_helper_executed':True})
 ended=time.time_ns()
 for p,row in list(INPUTS.items()):
  assert fp(read(p))=={k:row[k]for k in ['bytes','sha256']} and INPUTS[p]==row
 put('SOURCE-INPUTS.json',{'schema':1,'files':INPUTS,'all_windows_closed':True,'ended_ns':ended,'incomplete_S_or_Run_outputs_read':False})
 put('REPORT.json',{'schema':1,'status':'PASS_COMPLETE_CURRENT175F_ROOT_AND_PEER_SUCCESS_READBACK_SOURCE_ONLY_NOT_EXECUTED','source_sha':sourcepins['source_sha'],'run_id':'20261023182634096f57','original_S_pid':35944,'original_prepare_pid':49064,'context_pin':{'bytes':12301,'sha256':'c56e609828ba3e51e63f69b83e4af49279bdaebee487116728c383e02dae7959'},'actual_M':mpin,'actual_freeze':freeze,'M_schema_fields':35,'commit_steps':19,'native_budget':1088,'parent_readers':{'root':{'path':rootsource.as_posix(),**fp(before['root.py'])},'peer':{'path':peersource.as_posix(),**fp(before['peer.py'])}},'whole_original_reader_functions_classes_preserved':True,'whole_finite_helper_inverse_except_exact_Windows_cross4':True,'all_original_nine_proofs_native_streams_close_six_query_zero_Source_M_freeze_profile_predicates':'PRESERVED','ordinary_diffs_all_six_directions_replayed':True,'AST_only':True,'readers_or_helpers_imported_or_executed':False,'incomplete_S_outputs_or_private_payload_read':False,'S_actual_acceptance':'NOT OBSERVED; Root S0 admission required, S1 forbids these success readers','full_acceptance':'INCOMPLETE','outputs':report})
 files={p.relative_to(OUT).as_posix():fp(p.read_bytes())for p in sorted(OUT.rglob('*'))if p.is_file()and p.name!='PINS.json'}
 put('PINS.json',{'schema':1,'scope':'COMPLETE175F_SUCCESS_ONLY_ROOT_PEER_READBACK_SOURCE_NOT_EXECUTED','files':files,'runtime_or_S_success_claimed':False})
 print(json.dumps({'PINS':fp((OUT/'PINS.json').read_bytes()),'files':len(files),'readers':report,'executed':'SOURCE_PRODUCER_AST_AND_DIFF_ONLY'}))
if __name__=='__main__':main()
