from pathlib import Path
import ast,builtins,copy,difflib,hashlib,json,os,re,stat,symtable,time,functools
P=Path(__file__).resolve().parent;ORG=P.parent;B=ORG.parent/'ms4-worker-normal-source-preparation-r1'
OLD=ORG/'currentd0a5-next-normal-preparation-r1/normal-source';OUT=P/'normal-source'
PROFILE=P/'profile-source-r1';EXPORT=ORG/'currentbafb-git-export-actual-r1';AD=ORG/'current41c0-normal-root-admission-r1'
HEAD='bafb6305062aeb6b6e945c21da572863062ca480';PARENT='d0a5d5398546e223122b5c2a2f115e1908f78ced'
READS={}
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def read(p,expected=None):
 p=Path(p);a=p.lstat();keys=('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns');stamp=lambda s:[getattr(s,k) for k in keys]
 assert stat.S_ISREG(a.st_mode) and not p.is_symlink() and not getattr(a,'st_file_attributes',0)&0x400
 with p.open('rb') as f:
  b=os.fstat(f.fileno());raw=f.read();assert f.read(1)==b'';c=os.fstat(f.fileno())
 z=p.lstat();assert stamp(a)==stamp(z) and a.st_birthtime_ns==z.st_birthtime_ns and stamp(b)==stamp(c) and b.st_birthtime_ns==c.st_birthtime_ns and len(raw)==a.st_size
 assert [stamp(a)[i] for i in (0,1,6,7)]==[stamp(b)[i] for i in (0,1,6,7)]
 if expected:assert pin(raw)=={k:expected[k] for k in ('bytes','sha256')},p
 row={'path':p.as_posix(),**pin(raw),'native9':stamp(a),'fd9':stamp(b),'birthtime_ns':a.st_birthtime_ns,'EOF':True,'closed':True}
 assert p.as_posix() not in READS or READS[p.as_posix()]==row;READS.setdefault(p.as_posix(),row);return raw
def obj(p,expected=None):return json.loads(read(p,expected))
def pointer(p):return {'path':Path(p).as_posix(),**pin(read(p))}
def save(name,raw):
 p=OUT/name;p.parent.mkdir(parents=True,exist_ok=True)
 if p.exists():assert read(p)==raw;return
 with p.open('xb') as f:assert f.write(raw)==len(raw)
def put(name,v):save(name,enc(v))
def once(s,a,b):assert s.count(a)==1,(a[:120],s.count(a));return s.replace(a,b,1)
def fn(s,name):return ast.get_source_segment(s,next(n for n in ast.parse(s).body if isinstance(n,ast.FunctionDef) and n.name==name))
@functools.lru_cache(maxsize=6)
def funcs(s):return {n.name:ast.get_source_segment(s,n) for n in ast.parse(s).body if isinstance(n,ast.FunctionDef)}
def constants(s):
 result={}
 for n in ast.parse(s).body:
  if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name):
   try:result[n.targets[0].id]=ast.literal_eval(n.value)
   except(ValueError,TypeError):pass
 return result
def const(s,name,value):
 n=next(n for n in ast.parse(s).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id==name)
 return once(s,ast.get_source_segment(s,n),name+' = '+repr(value))
def missing(raw):
 table=symtable.symtable(raw,'own-static-only','exec');known={s.get_name() for s in table.get_symbols() if s.is_assigned() or s.is_imported() or s.is_namespace()}|set(dir(builtins))|{'__file__','__name__'};result=[]
 def walk(t):
  result.extend((t.get_name(),s.get_name()) for s in t.get_symbols() if s.is_referenced() and s.is_global() and s.get_name() not in known)
  for c in t.get_children():walk(c)
 walk(table);return result
def patch(before,after):return ''.join(''.join(difflib.unified_diff(before[n].decode().splitlines(True),after[n].decode().splitlines(True),fromfile='a/'+n,tofile='b/'+n)) for n in before if before[n]!=after[n]).encode()
def replay(before,raw):
 result=dict(before);lines=raw.decode().splitlines(True);i=0
 while i<len(lines):
  assert lines[i].startswith('--- a/') and lines[i+1].startswith('+++ b/');name=lines[i][6:].rstrip('\r\n');i+=2;source=result[name].decode().splitlines(True);out=[];at=0
  while i<len(lines) and lines[i].startswith('@@ '):
   m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\r\n]*\r?\n',lines[i]);assert m
   oldn,newn=int(m[2] or 1),int(m[4] or 1);start=int(m[1])-(oldn!=0);assert start>=at;out.extend(source[at:start]);at=start;i+=1;a=b=0
   while i<len(lines) and not lines[i].startswith(('@@ ','--- a/')):
    line=lines[i];i+=1;assert line[:1] in (' ','+','-')
    if line[0] in ' -':assert source[at]==line[1:];at+=1;a+=1
    if line[0] in ' +':out.append(line[1:]);b+=1
   assert (a,b)==(oldn,newn)
  out.extend(source[at:]);result[name]=''.join(out).encode()
 return result

def main():
 assert not (OUT/'PINS.json').exists()
 prior=obj(OLD/'PINS.json',{'bytes':87279,'sha256':'d1ee9ba1981bdc56bf5c694478f455797a810d9635e5053b320cebd30759e6ae'})
 assert len(prior['files'])==212 and len(prior['source_members18'])==18
 original={name:read(OLD/name,row) for name,row in prior['files'].items()}
 for name,raw in original.items():save('predecessor-source/'+name,raw)
 save('predecessor-source/PINS.json',read(OLD/'PINS.json'));members=prior['source_members18'];before={n:original[n] for n in members};after=dict(before)
 profile_packet=obj(PROFILE/'PINS.json',{'bytes':11027,'sha256':'06bc74b807bb5d17d988f26f0d3103cdf75ea76bb3c9bd476e8b90b60a5369bd'});manifest=obj(PROFILE/'MANIFEST.json',{'bytes':16340,'sha256':'116172fd45d191c0be6ec27d6b4f522eec763b66e0afca263af00f9a064ab730'})
 for name,row in profile_packet['members'].items():read(PROFILE/name,row)
 oldpub=json.loads(before['validation/PROFILE-PUBLICATION.json']);assert oldpub['source_sha']==PARENT and manifest['before_selected9']==oldpub['selected9']
 candidate9={row['path']:read(PROFILE/'candidate/profile'/row['path'],row) for row in manifest['selected9']}
 changed_profile={n for n in candidate9 if candidate9[n]!=before['validation/actual-profile/'+n]};assert changed_profile=={'ms4-initialize.php','ms4-host.py','normal-close-ms4-fulfillment.py','verify-zero-ms4-fulfillment.py','interface-v1.json','source-derivation.json'}
 for name,raw in candidate9.items():after['validation/actual-profile/'+name]=raw
 for name,raw in before.items():save('before/'+name,raw)
 stage=obj(EXPORT/'CURRENT-SOURCE-AUTHORITY.json',{'bytes':25900,'sha256':'88f5a5329ec551a891929e4403e89ef40f08013362198f2b1cc59ae8a8d56e76'})
 qual=obj(EXPORT/'CURRENT-SOURCE-QUALIFICATION.json',{'bytes':13117,'sha256':'80dbedee76529af71a1043b805681a7ecc6c485d9bbfdefd5d7e5d492d196209'})
 inputs=obj(EXPORT/'SOURCE-INPUTS.json',{'bytes':687642,'sha256':'52451057185f98022742099e4ebe1003bb9a10ad0e0f78d243864e68b0ff9812'})
 root_export=obj(AD/'R98-BAFB-EXPORT-ACTUAL-ROOT-READBACK.json',{'bytes':30041,'sha256':'f8569cbfa101432a72d82d6cf951e51bd29cbacb19b38912d8eed37bc8429f20'})
 assert stage['source_sha']==qual['source_sha']==HEAD and stage['parent']==qual['parent']==PARENT and stage['native_count']==12 and len(qual['operations'])==12
 assert root_export['authority']==pointer(EXPORT/'CURRENT-SOURCE-AUTHORITY.json') and root_export['qualification']==pointer(EXPORT/'CURRENT-SOURCE-QUALIFICATION.json') and root_export['source_inputs']==pointer(EXPORT/'SOURCE-INPUTS.json') and root_export['actual_native']==24
 assert root_export['all_created_terminal_PID_samehandle_wait_full_double_streams_readback0'] is True and root_export['full_source_input_EOF_windows_preserved']==len(inputs['files']) and root_export['remote_push']=='UNBOUND_PENDING_FINAL_PUSH'
 # Only already qualified own export outputs (Source/Git metadata and blobs), never old M/S or Run streams.
 for row in root_export['output_files']:
  path=Path(row['path']);assert path.is_relative_to(EXPORT) and '..' not in path.parts;read(path,row)
 assert len(root_export['output_files'])==root_export['all_own_output_files_EOF']
 qualification_receipts={}
 for row in qual['operations']:
  base=EXPORT/'current-source-readback'/row['stem'];qualification_receipts[row['stem']]={k:pointer(Path(str(base)+suffix)) for k,suffix in [('created','.created.json'),('terminal','.terminal.json'),('stdout','.stdout'),('stderr','.stderr')]}
 finite_path=AD/'R98-D0A5-FAILED-FINITE-HISTORY-INDEX.json';finite=obj(finite_path,{'bytes':3972,'sha256':'c43abf997074d602d56b839791412e281dfb334ad826579b0a9fae3b7e31a35f'})
 assert finite['source_sha']==PARENT and finite['original_S_native_exit_wait']==1 and finite['Task6_exit0_retired'] is True
 for row in finite['qualified_public'].values():read(row['path'],row)
 # The new callback may read Root's precise own close/zero output leaves; this author does not execute it or open those 420 leaves.
 pub=copy.deepcopy(oldpub);context=pub['finite_contexts'];m0=before['materializer/prepare.py'].decode();s0=before['validation/run.py'].decode();oldfn=fn(m0,'source_authority');assert oldfn==fn(s0,'source_authority');oldvalues=constants(m0)
 chain=copy.deepcopy(oldvalues['COMMIT_CHAIN'])+[{k:stage[k] for k in ['kind','source_sha','parent','files']}|{'commit':pointer(EXPORT/'CURRENT-SOURCE-AUTHORITY.json')}]
 inventory=[json.loads(line) for line in read(context['baseline']['inventory']['path'],context['baseline']['inventory']).splitlines()];base={r['path']:{k:r[k] for k in ['path','bytes','sha256']} for r in inventory};expected=dict(base);first={}
 for step in chain:
  for row in step['files']:
   name=row['path'];assert row['before']==({k:expected[name][k] for k in ['bytes','sha256']} if name in expected else None);first.setdefault(name,{k:base[name][k] for k in ['bytes','sha256']} if name in base else None);expected[name]={'path':name,**row['after']}
 expected=dict(sorted(expected.items()));changes=[{'path':n,'before':first[n],'after':pinrow(expected[n])} for n in sorted(first)];committed=[expected[r['path']] for r in changes];requirements=sorted(set(oldvalues['SOURCE_REQUIREMENT_PATHS'])|{r['path'] for r in stage['files']});newtracked=sorted(set(expected)-set(base))
 previous=obj(context['baseline']['previous_full_freeze']['path'],context['baseline']['previous_full_freeze']);groups={};combined={}
 for prefix in ('driver','customer','paid','fulfillment','aftersale_review','worker'):
  if prefix=='driver':names=[r['path'] for r in previous[prefix+'_source_files']]
  else:
   roots=previous[prefix+'_source_roots'];extras=sorted(set(previous[prefix+'_source_extra'])|({oldvalues['ASSET_CLI']} if prefix in ('fulfillment','aftersale_review','worker') else set())|{'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php'});names=sorted({n for n in expected if any(n.startswith(root+'/') for root in roots)}|set(extras))
  groups[prefix]=[expected[n] for n in names]
  for row in groups[prefix]:assert row['path'] not in combined or combined[row['path']]==row;combined[row['path']]=row
 counts={k:len(v) for k,v in groups.items()};budget=6+sum(2+2*len(step['files']) for step in chain)+1+len(committed)+2+3+1+4
 assert len(chain)==24 and len(expected)==15750 and len(requirements)==316 and len(committed)==311 and len(newtracked)==264 and budget==1150 and sum(r['bytes'] for r in expected.values())==341939628
 assert len(stage['files'])==2 and [r['before'] is None for r in stage['files']]==[False,True] and len(oldvalues['CURRENT_M_RESULT_KEYS37'])==37
 composite={'scope':'ACTUAL_LOCAL_BAFB_WARM_DIAGNOSTIC_OVER_COMPLETE_TWENTYTHREE_NORMAL_PROFILE','source_sha':HEAD,'profile_PINS':pointer(PROFILE/'PINS.json'),'profile_interface':{'path':(OUT/'validation/actual-profile/interface-v1.json').as_posix(),**pin(candidate9['interface-v1.json'])},'predecessor_publication':pointer(OLD/'validation/PROFILE-PUBLICATION.json'),'predecessor_composite':copy.deepcopy(context['current_composite_customer_form_source']),'php_sources':manifest['current_CU7']}
 context.update(commit_chain=chain,committed_changes=changes,committed_files=committed,new_tracked_paths=newtracked,actual_complete_expected_tracked_count=len(expected),source_counts=counts,current_composite_customer_form_source=composite,
  source_authoring_qualification_scope='ACTUAL_LOCAL_BAFB_MAIN_VAL_316_REQUIRED_BYTES_15750_NAMES_CURRENT_BRANCH_EXPORT12_QUALIFICATION12_FULL_TRACKED_BYTES_PENDING_M',current_published_source_scope='Complete23 raw authority/all recursive19 literal frame/11whole and prior finite attempts; actual local-only bafb AR M plus new explanation doc A; d0 trueS1 closed90/zero12 finite only. New callback/M/S/initializer20keys8native38artifacts NOT RUN.',
  historical_twentythird_publication=pointer(OLD/'validation/PROFILE-PUBLICATION.json'),current_warm_source_history=pointer(EXPORT/'CURRENT-SOURCE-AUTHORITY.json'),current_warm_source_qualification=pointer(EXPORT/'CURRENT-SOURCE-QUALIFICATION.json'),current_warm_source_inputs=pointer(EXPORT/'SOURCE-INPUTS.json'),current_warm_export_Root_readback=pointer(AD/'R98-BAFB-EXPORT-ACTUAL-ROOT-READBACK.json'),current_warm_profile_source=pointer(PROFILE/'PINS.json'),current_d0a5_failed_finite_history=pointer(finite_path))
 pub.update(source_sha=HEAD,parent=PARENT,selected9=manifest['selected9'],repository_Source_requirements=[expected[n] for n in requirements])
 table=symtable.symtable(m0,'original_complete23_static','exec');used=set()
 def usedglobals(t):
  used.update(x.get_name() for x in t.get_symbols() if x.is_referenced() and x.is_global())
  for child in t.get_children():usedglobals(child)
 usedglobals(next(t for t in table.get_children() if t.get_name()=='source_authority'));literals={k:oldvalues[k] for k in sorted(used&oldvalues.keys())};assert len(literals)==19
 added={'HISTORICAL_TWENTYTHREE_M_SOURCE':pointer(OLD/'materializer/prepare.py'),'HISTORICAL_TWENTYTHREE_PUBLICATION':pointer(OLD/'validation/PROFILE-PUBLICATION.json'),'HISTORICAL_TWENTYTHREE_CONSTANTS':literals,'HISTORICAL_TWENTYTHREE_AUTHORITY_SOURCE':pin(oldfn.encode()),'CURRENT_WARM_SOURCE_HISTORY':pointer(EXPORT/'CURRENT-SOURCE-AUTHORITY.json'),'CURRENT_WARM_SOURCE_QUALIFICATION':pointer(EXPORT/'CURRENT-SOURCE-QUALIFICATION.json'),'CURRENT_WARM_SOURCE_INPUTS':pointer(EXPORT/'SOURCE-INPUTS.json'),'CURRENT_WARM_QUALIFICATION_RECEIPTS':qualification_receipts,'CURRENT_WARM_EXPORT_ROOT_READBACK':pointer(AD/'R98-BAFB-EXPORT-ACTUAL-ROOT-READBACK.json'),'CURRENT_WARM_PROFILE_SOURCE':pointer(PROFILE/'PINS.json'),'CURRENT_WARM_PROFILE_MANIFEST':pointer(PROFILE/'MANIFEST.json'),'CURRENT_WARM_PROFILE_NEW_NODE':json.loads(candidate9['source-derivation.json'])['bafb_warm_failure_context_source_successor'],'CURRENT_D0A5_FAILED_FINITE_INDEX':pointer(finite_path)}
 oldtree=ast.parse(oldfn);helpers='\n'.join('\n'.join(oldfn.splitlines()[n.lineno-1:n.end_lineno]) for n in oldtree.body[0].body if isinstance(n,ast.FunctionDef) and n.name in ['compact','pairs','decode','read','definitions','constants','patch_apply'])+'\n';assert len([n for n in oldtree.body[0].body if isinstance(n,ast.FunctionDef) and n.name in ['compact','pairs','decode','read','definitions','constants','patch_apply']])==7
 nested='    def historical_twentythree(pub, checked, check):\n'+''.join('        '+k+' = HISTORICAL_TWENTYTHREE_CONSTANTS['+repr(k)+']\n' for k in literals)+'\n'.join('    '+line for line in oldfn.splitlines()[1:])+'\n'
 fragment=read(P/'authority-next.py.fragment').decode();fragment=once(fragment,'__HELPERS__\n',helpers);fragment=once(fragment,'__HISTORICAL_23__\n',nested);fragment=once(fragment,'__CURRENT_PROFILE_CHECKS__\n',read(P/'current-profile-checks.py.fragment').decode());fragment=once(fragment,'__CURRENT_FAILED_FINITE__\n',read(P/'d0a5-failed-finite-checks.py.fragment').decode())
 node=next(n for n in ast.parse(fragment).body[0].body if isinstance(n,ast.FunctionDef) and n.name=='historical_twentythree');lines=ast.get_source_segment(fragment,node).splitlines()[1+len(literals):];assert oldfn.splitlines()[0]+'\n'+'\n'.join(x[4:] for x in lines)==oldfn
 def shared(s):
  s=once(s,oldfn,fragment)
  for name,value in [('COMMIT_CHAIN',chain),('COMMITTED_PATHS',[r['path'] for r in changes]),('SOURCE_REQUIREMENT_PATHS',requirements),('NEW_TRACKED_PATHS',newtracked),('CURRENT_COMPOSITE_CUSTOMER_FORM_SOURCE',composite)]:s=const(s,name,value)
  return once(s,'def source_authority(',''.join(k+' = '+repr(v)+'\n' for k,v in added.items())+'\ndef source_authority(')
 after['validation/PROFILE-PUBLICATION.json']=enc(pub);save('validation/PROFILE-PUBLICATION.json',after['validation/PROFILE-PUBLICATION.json'])
 pp=json.loads(before['validation/PROFILE-PINS.json']);pp['members']={r['path']:pinrow(r) for r in manifest['selected9']};pp['members']['PROFILE-PUBLICATION.json']=pin(after['validation/PROFILE-PUBLICATION.json']);after['validation/PROFILE-PINS.json']=enc(pp);save('validation/PROFILE-PINS.json',after['validation/PROFILE-PINS.json'])
 note=('\n\nR98 bafb local-only warm failure context successor: real export12+qualification12/Root complete readback are distinct from original derived M1150; complete original23 authority/19 recursive literal frame/11 whole and old finite histories preserved. Current source profile9 six pin-DAG leaves, initializer only2 AR identities; all other SourceTree, installer, paid, Task6, AR and worker owner functions/business remain raw. New md is a real Git A path in complete15750/required316/cumulative311/double31500; M37 and dynamic full native count retain original full coverage. Current d0 trueS1/Task6 retired0/AR1/ordinary90/zero12 appended as precise finite qualified eight-report/two-own-output-root closure, no12th recursive whole and no original Run/M/S/ENV/actor/App body follow. New exact initializer20keys8native38artifacts syntax/callback/M/S/readers/postA/UI are UNBOUND_NOT_RUN; remote push UNBOUND_PENDING_FINAL_PUSH.\n').encode()
 after['validation/DESIGN-TASKS.md']=before['validation/DESIGN-TASKS.md']+note;s=shared(s0);s=const(s,'SOURCE',HEAD);s=const(s,'PROFILE_PUBLICATION',pin(after['validation/PROFILE-PINS.json']));s=const(s,'PINS',{name:pin(candidate9[name])['sha256'] for name in constants(s0)['PINS']});after['validation/run.py']=s.encode();save('validation/run.py',after['validation/run.py']);save('validation/DESIGN-TASKS.md',after['validation/DESIGN-TASKS.md'])
 rootpins=json.loads(before['validation/PINS.json']);rootpins['members']={n:pin(after['validation/'+n]) for n in ['DESIGN-TASKS.md','run.py']};after['validation/PINS.json']=enc(rootpins);save('validation/PINS.json',after['validation/PINS.json'])
 m=shared(m0);m=const(m,'head',HEAD);m=const(m,'parent',PARENT);m=once(m,"root=Path('"+(OLD/'validation').as_posix()+"')","root=Path('"+(OUT/'validation').as_posix()+"')");m=once(m,"tool=Path('"+(OLD/'materializer').as_posix()+"')","tool=Path('"+(OUT/'materializer').as_posix()+"')");m=once(m,"need(pin((root/'PINS.json').read_bytes())=="+repr(pin(before['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')","need(pin((root/'PINS.json').read_bytes())=="+repr(pin(after['validation/PINS.json']))+",'REVIEWED_ROOT_SOURCE_PINS')");after['materializer/prepare.py']=m.encode()
 after['materializer/PLAN.md']=before['materializer/PLAN.md']+note;inverse=json.loads(before['materializer/INVERSE.json']);inverse.update(source_sha=HEAD,parent=PARENT,commit_chain=chain,cumulative_changed_paths=changes);inverse['currentbafb_local_warm_failure_successor']={'source_history':pointer(EXPORT/'CURRENT-SOURCE-AUTHORITY.json'),'source_qualification':pointer(EXPORT/'CURRENT-SOURCE-QUALIFICATION.json'),'export_Root_readback':pointer(AD/'R98-BAFB-EXPORT-ACTUAL-ROOT-READBACK.json'),'profile_Source_PINS':pointer(PROFILE/'PINS.json'),'failed_d0a5_finite':pointer(finite_path),'stages_derived':len(chain),'M_fields':37,'M_native_budget_derived_not_actual':budget,'new_initializer_syntax_contract':'UNBOUND_ORIGINAL20KEYS8NATIVE38ARTIFACTS','runtime':'UNBOUND_NOT_RUN'};after['materializer/INVERSE.json']=enc(inverse)
 launch0=before['launcher/launch.py'].decode();launch=once(launch0,"ROOT=Path('"+(OLD/'validation').as_posix()+"')","ROOT=Path('"+(OUT/'validation').as_posix()+"')");launch=once(launch,(OLD/'materializer/prepare.py').as_posix(),(OUT/'materializer/prepare.py').as_posix());launch=once(launch,pin(before['validation/PINS.json'])['sha256'],pin(after['validation/PINS.json'])['sha256']);launch=once(launch,"value['source_sha']=='"+PARENT+"'","value['source_sha']=='"+HEAD+"'");after['launcher/launch.py']=launch.encode()
 for name,raw in after.items():save(name,raw)
 reader=read(OLD/'source_reader.py').decode();reader=const(reader,'HEAD',HEAD);reader=const(reader,'PARENT',PARENT);save('source_reader.py',reader.encode())
 for name in ['author_support.py','namespace_static.py']:save(name,read(OLD/name))
 for name in ['authority-next.py.fragment','current-profile-checks.py.fragment','d0a5-failed-finite-checks.py.fragment']:save(name,read(P/name))
 save('assemble_next_source.py',read(Path(__file__)));save('DESIGN.md',read(P/'DESIGN-TASKS.md'))
 for name,body in funcs(m0).items():
  if name!='source_authority':assert funcs(m)[name]==body,name
 for name,body in funcs(s0).items():
  if name!='source_authority':assert funcs(s)[name]==body,name
 assert funcs(launch)==funcs(launch0) and fn(m,'source_authority')==fn(s,'source_authority') and fn(m,'validate_complete_source_tree')==fn(m0,'validate_complete_source_tree')==fn(s,'validate_complete_source_tree')
 suffix="    require(not ARCHIVE.exists() and not ARCHIVE.is_symlink(), 'ONE_NEW_NORMAL_ORCHESTRATION_ONLY')";assert s[s.index(suffix):]==s0[s0.index(suffix):]
 for label,source in [('M',m),('S',s),('launcher',launch)]:assert not missing(source),(label,missing(source))
 for label,source in [('M',m),('S',s)]:
  tree=ast.parse(source);bound=set(dir(builtins))|{'source_authority'}
  for node in tree.body:
   if isinstance(node,ast.Import):bound.update(alias.asname or alias.name.split('.')[0] for alias in node.names)
   elif isinstance(node,ast.ImportFrom):bound.update(alias.asname or alias.name for alias in node.names)
   elif isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
    try:ast.literal_eval(node.value)
    except (TypeError,ValueError):continue
    bound.add(node.targets[0].id)
  needed=set()
  def callback_globals(t):
   needed.update(x.get_name() for x in t.get_symbols() if x.is_referenced() and x.is_global())
   for child in t.get_children():callback_globals(child)
  callback_globals(next(t for t in symtable.symtable(source,'actual-literal-namespace-static-only','exec').get_children() if t.get_name()=='source_authority'));assert needed<=bound,(label,sorted(needed-bound))
 assert all(after['validation/actual-profile/'+n]==before['validation/actual-profile/'+n] for n in ['compose.yml','README.md','seed-source-inverse.json'])
 forward,reverse=patch(before,after),patch(after,before);assert replay(before,forward)==after and replay(after,reverse)==before;save('DIFF.patch',forward);save('REVERSE.patch',reverse)
 proof={'schema':1,'status':'COMPLETE_BAFB24_ORIGINAL_FULL_NORMAL_SOURCE_STATIC_ONLY_CALLBACK_M_S_NOT_RUN','HEAD':HEAD,'parent':PARENT,'predecessor_Source_PINS':pointer(OLD/'PINS.json'),'all_212_Source_members_archived':True,'source_members18':members,'changed_runtime_members':[n for n in members if before[n]!=after[n]],'stages_derived':len(chain),'tracked_derived':len(expected),'double_namespace_derived':2*len(expected),'complete_tracked_bytes_derived':sum(r['bytes'] for r in expected.values()),'cumulative_derived':len(committed),'required_derived':len(requirements),'newtracked_derived':len(newtracked),'group_counts_derived':counts,'business_union_derived':len(combined),'M_native_budget_derived_not_actual':budget,'M_fields':37,'original23_authority':pin(oldfn.encode()),'original23_recursive_actual_literal_names':list(literals),'all11_whole_historical_objects_and_old_finite_preserved':True,'new_d0a5_failed_finite_index':pointer(finite_path),'new_recursive_retirement_whole':False,'all_non_authority_M_S_functions_raw':True,'S_business_suffix_raw':True,'all_SourceTree_helpers_raw':True,'ordinary_all18_forward_reverse_exact':True,'actual_current_export_native':12,'actual_current_qualification_native':12,'actual_full15750_byte_replay':'UNBOUND_PENDING_ORIGINAL_M','new_initializer_syntax':'UNBOUND_ORIGINAL20KEYS8NATIVE38ARTIFACTS','new_callback_M_S_results':'UNBOUND_NOT_RUN','remote_qualification':'UNBOUND_PENDING_FINAL_PUSH','T4_media_candidates_adopted':False}
 put('AUTHOR-CHECKS.json',proof)
 for path,row in READS.items():
  st=Path(path).lstat();assert [getattr(st,k) for k in ('st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns')]==row['native9'] and st.st_birthtime_ns==row['birthtime_ns']
 put('AUTHOR-INPUTS.json',{'files':READS,'all_input_windows_ended':True,'all_final_native9_birthtime_unchanged':True,'original_Run_M_S_ENV_actor_App_body_reads':0,'new_close_zero_own_leaf_body_reads_by_Source_author':0,'prepared_callback_or_target_execution':False})
 files={p.relative_to(OUT).as_posix():{'path':p.as_posix(),**pin(read(p))} for p in sorted(OUT.rglob('*')) if p.is_file()}
 put('PINS.json',{'schema':1,'status':proof['status'],'source_sha':HEAD,'parent':PARENT,'source_members18':members,'files':files,'Root14_M3_contract':True,'before_source':pointer(OLD/'PINS.json'),'profile_source':pointer(PROFILE/'PINS.json'),'current_source_history':pointer(EXPORT/'CURRENT-SOURCE-AUTHORITY.json'),'current_source_qualification':pointer(EXPORT/'CURRENT-SOURCE-QUALIFICATION.json'),'current_failed_finite':pointer(finite_path),'eleventh_retirement':prior['eleventh_retirement'],'eight_PHP_syntax':prior['eight_PHP_syntax'],'new_initializer_syntax':'UNBOUND_ORIGINAL20KEYS8NATIVE38ARTIFACTS','pure_authority':proof,'all_input_windows_ended':True,'new_M_S_PHP_Docker_App_business':'NOT RUN'})
 print(json.dumps({'PINS':pointer(OUT/'PINS.json'),'M':pin(after['materializer/prepare.py']),'S':pin(after['validation/run.py']),'launcher':pin(after['launcher/launch.py']),'members':len(files),'stages_derived':len(chain),'M_fields':37,'native_budget_derived':budget,'runtime':'NOT RUN'},ensure_ascii=False))
def pinrow(row):return {k:row[k] for k in ['bytes','sha256']}
if __name__=='__main__':main()
