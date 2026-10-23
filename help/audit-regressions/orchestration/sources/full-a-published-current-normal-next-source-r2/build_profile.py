"""Finite active-contract profile repair; no target/runtime execution."""
from pathlib import Path
import ast,json,copy,hashlib,difflib,time
ROOT=Path(__file__).parent
B=ROOT.parent
OLD=B/'full-a-published-current-normal-next-source-r1'
OUT=ROOT/'profile-source'
NAMES=['README.md','compose.yml','ms4-host.py','ms4-initialize.php','seed-source-inverse.json','interface-v1.json','source-derivation.json','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def read(p):return Path(p).read_bytes()
def put(p,raw):
 p=Path(p);p.parent.mkdir(parents=True,exist_ok=True)
 if p.exists():assert read(p)==raw
 else:p.write_bytes(raw)
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def once(raw,before,after):assert raw.count(before)==1;return raw.replace(before,after,1)
def const(raw,name,value):
 text=raw.decode();n=next(x for x in ast.parse(text).body if isinstance(x,ast.Assign) and len(x.targets)==1 and isinstance(x.targets[0],ast.Name) and x.targets[0].id==name)
 return once(raw,ast.get_source_segment(text,n).encode(),(name+' = '+repr(value)).encode())
def funcs(raw):
 text=raw.decode();return {x.name:ast.get_source_segment(text,x) for x in ast.parse(text).body if isinstance(x,(ast.FunctionDef,ast.ClassDef))}
def apply(before,text):
 lines=text.splitlines(keepends=True);i=0;result=dict(before)
 while i<len(lines):
  assert lines[i].startswith('--- a/');name=lines[i][6:].rstrip('\r\n');i+=1;assert lines[i].rstrip('\r\n')=='+++ b/'+name;i+=1
  old=before[name].decode().splitlines(keepends=True);out=[];cursor=0
  while i<len(lines) and lines[i].startswith('@@ '):
   import re
   m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n?',lines[i]);assert m
   oldn=int(m[2] or 1);newn=int(m[4] or 1);start=int(m[1])-1 if oldn else int(m[1]);assert start>=cursor;out+=old[cursor:start];cursor=start;i+=1;used=made=0
   while i<len(lines) and not lines[i].startswith(('@@ ','--- a/')):
    line=lines[i];assert line[0] in [' ','+','-']
    if line[0] in [' ','-']:assert old[cursor]==line[1:];cursor+=1;used+=1
    if line[0] in [' ','+']:out.append(line[1:]);made+=1
    i+=1
   assert (used,made)==(oldn,newn)
  out+=old[cursor:];result[name]=''.join(out).encode()
 return result
def patch(before,after):return ''.join(''.join(difflib.unified_diff(before[n].decode().splitlines(keepends=True),after[n].decode().splitlines(keepends=True),fromfile='a/'+n,tofile='b/'+n)) for n in NAMES)
def main():
 assert not (OUT/'PINS.json').exists()
 oldpins=json.loads(read(OLD/'PINS.json'));before={n:read(OLD/'validation/actual-profile'/n) for n in NAMES}
 for n,raw in before.items():assert pin(raw)=={k:oldpins['files']['validation/actual-profile/'+n][k] for k in ['bytes','sha256']};put(OUT/'before'/n,raw)
 after=dict(before);interface=json.loads(before['interface-v1.json']);assert enc(interface)==before['interface-v1.json']
 rows={x['path']:x for x in interface['customer_form_current_profile']['php_sources']}
 paid=rows['help/audit-regressions/isolated/ms5-paid-consumer-normal.php'];fn=rows['help/audit-regressions/isolated/ms4-fulfillment-normal.php']
 assert paid['bytes']==109531 and fn['bytes']==128359
 for key,row in [('paid_producer_successor',paid),('fulfillment_normal_successor',fn)]:
  assert interface[key]['entry']==row['path'];interface[key]['entry_bytes']=row['bytes'];interface[key]['entry_sha256']=row['sha256']
 after['interface-v1.json']=enc(interface)
 for name in ['ms4-host.py','verify-zero-ms4-fulfillment.py']:
  raw=before[name]
  for prefix,row in [('PAID_ENTRY',paid),('FULFILLMENT_ENTRY',fn)]:
   raw=const(raw,prefix+'_BYTES',row['bytes']);raw=const(raw,prefix+'_SHA256',row['sha256'])
  after[name]=raw
 oldtuple=b"[108005, 'ee59d99ea8db768b0a45727d9cdffe276b7f0c11f7e07cea5cc10d10a449f4b7']"
 newtuple=("["+str(paid['bytes'])+", '"+paid['sha256']+"']").encode()
 after['ms4-initialize.php']=once(before['ms4-initialize.php'],oldtuple,newtuple)
 after['ms4-host.py']=const(after['ms4-host.py'],'INTERFACE_SHA256',pin(after['interface-v1.json'])['sha256'])
 after['normal-close-ms4-fulfillment.py']=const(before['normal-close-ms4-fulfillment.py'],'HOST_SHA256',pin(after['ms4-host.py'])['sha256'])
 for key,target in [('INTERFACE_SHA256','interface-v1.json'),('HOST_SHA256','ms4-host.py'),('CLOSE_SHA256','normal-close-ms4-fulfillment.py')]:after['verify-zero-ms4-fulfillment.py']=const(after['verify-zero-ms4-fulfillment.py'],key,pin(after[target])['sha256'])
 deriv=json.loads(before['source-derivation.json']);assert enc(deriv)==before['source-derivation.json'];oldderiv=copy.deepcopy(deriv)
 deriv['current_nonself_artifacts']=[{'path':x['path'],**pin(after[x['path']])} for x in deriv['current_nonself_artifacts']]
 deriv['published_current_active_contract_source_repair']={'scope':'ACTIVE_INDEPENDENT_CALLER_PINS_ONLY_NO_BUSINESS_CHANGE','previous_root_source_PINS':{'path':(OLD/'PINS.json').as_posix(),**pin(read(OLD/'PINS.json'))},
  'actual_repository_source':'584532ba22b1824164aa11c06b163715be8c224c','unchanged_actual_caller_adoption':'76efa7807c6137bc430e314e2faa78691cf60431','current_callers':[paid,fn],
  'exact_source_edits':{'Host_constants':4,'zero_constants':4,'interface_active_fields':4,'initializer_worker_paid_tuple':1,'existing_hash_edges':5},'all_original_functions_and_historical_nodes_retained':True,'runtime_and_current_initializer_syntax':'NOT RUN'}
 after['source-derivation.json']=enc(deriv)
 for name in ['ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py']:assert funcs(before[name])==funcs(after[name])
 assert once(after['ms4-initialize.php'],newtuple,oldtuple)==before['ms4-initialize.php']
 oldinterface=json.loads(before['interface-v1.json']);recovered=copy.deepcopy(interface)
 for key in ['paid_producer_successor','fulfillment_normal_successor']:
  for field in ['entry_bytes','entry_sha256']:recovered[key][field]=oldinterface[key][field]
 assert recovered==oldinterface
 recovered=copy.deepcopy(deriv);recovered.pop('published_current_active_contract_source_repair');recovered['current_nonself_artifacts']=oldderiv['current_nonself_artifacts'];assert recovered==oldderiv
 for name,raw in after.items():put(OUT/'candidate'/name,raw)
 forward=patch(before,after);reverse=patch(after,before);assert apply(before,forward)==after and apply(after,reverse)==before
 put(OUT/'FORWARD.patch',forward.encode());put(OUT/'REVERSE.patch',reverse.encode())
 manifest={'schema':1,'scope':'SOURCE_ONLY_ACTIVE_INDEPENDENT_CONTRACT_REPAIR','before':{n:pin(raw) for n,raw in before.items()},'candidate':{n:pin(raw) for n,raw in after.items()},
  'changed':[n for n in NAMES if before[n]!=after[n]],'whole_Python_function_bodies_exact':True,'PHP_single_literal_inverse_exact':True,'interface_only_four_active_leaves':True,'all_derivation_history_except_nonself_and_new_named_entry_exact':True,'standard_forward_reverse_complete':True,'new_initializer_lint':'NOT RUN'}
 put(OUT/'MANIFEST.json',enc(manifest));put(OUT/'PINS.json',enc({'schema':1,'scope':'SOURCE_ONLY_ACTIVE_INDEPENDENT_CALLER_CONTRACT_REPAIR','predecessor_root_PINS':{'path':(OLD/'PINS.json').as_posix(),**pin(read(OLD/'PINS.json'))},'candidate':manifest['candidate'],'manifest':{'path':(OUT/'MANIFEST.json').as_posix(),**pin(read(OUT/'MANIFEST.json'))},'runtime':'NOT RUN'}))
 print(json.dumps({'status':'PROFILE_SOURCE_REPAIR_FROZEN_NOT_EXECUTED','PINS':pin(read(OUT/'PINS.json')),'initializer':{'path':(OUT/'candidate/ms4-initialize.php').as_posix(),**pin(after['ms4-initialize.php'])},'changed':manifest['changed']},ensure_ascii=False))
if __name__=='__main__':main()
