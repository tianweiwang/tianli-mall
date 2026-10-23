"""Construct and inspect Source only; never imports/invokes either wrapper."""
import ast
import difflib
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time

ORG=Path('H:/天俪商城/artifacts/project-organization-20261023')
OUT=Path(__file__).resolve().parent
ORIGINAL=ORG/'current175f-actual-materialization-peer-outer-r1/launch.py'
READERS=ORG/'current175f-complete-normal-S-readbacks-source-r1/candidate'
N='H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1'
CONTEXT='H:/天俪商城/artifacts/ms4-aftersale-review-normal-runs/20261023182634096f57/actual-run-context.json'
KEYS=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
CROSS={'st_dev','st_ino','st_size','st_mtime_ns'}

def need(value,code):
    if not value: raise AssertionError(code)

def pin(raw): return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def enc(value): return (json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode()
def n9(info): return [getattr(info,k) for k in KEYS]

class Inputs:
    def __init__(self): self.rows,self.raws={},{}
    def observe(self,path):
        a=path.lstat(); named=n9(a)
        need(stat.S_ISREG(a.st_mode) and not a.st_file_attributes&0x400,'ORDINARY_FILE:'+str(path))
        with path.open('rb') as f:
            x=os.fstat(f.fileno()); fd=n9(x)
            need(all(named[i]==fd[i] for i,k in enumerate(KEYS) if k in CROSS) and a.st_birthtime_ns==x.st_birthtime_ns,'COMMON4_BIRTH:'+str(path))
            chunks=[]
            while True:
                block=f.read(262144)
                if not block: break
                chunks.append(block)
            raw=b''.join(chunks); y=os.fstat(f.fileno())
            need(len(raw)==a.st_size and f.read(1)==b'' and n9(y)==fd and y.st_birthtime_ns==x.st_birthtime_ns,'FULL_EOF_FD_OWN9:'+str(path))
        z=path.lstat(); need(n9(z)==named and z.st_birthtime_ns==a.st_birthtime_ns,'NAMED_OWN9:'+str(path))
        return {'path':path.as_posix(),'pin':pin(raw),'named_before':named,'named_after':n9(z),'fd_open':fd,'fd_after':n9(y),'named_birthtime_ns':a.st_birthtime_ns,'fd_birthtime_ns':x.st_birthtime_ns,'EOF':True,'closed':True},raw
    def read(self,path,expected=None):
        path=Path(path); key=path.as_posix()
        need('/ms4-aftersale-review-normal-runs/' not in key and '/full-a-quota-wire-current175f-normal-next-source-r1/' not in key,'SOURCE_PREPARATION_NEVER_READS_LIVE_S_OR_RUN')
        if key not in self.rows: self.rows[key],self.raws[key]=self.observe(path)
        raw=self.raws[key]
        if expected is not None: need(pin(raw)=={k:expected[k] for k in ('bytes','sha256')},'EXACT_SOURCE_PIN:'+key)
        return raw
    def end(self):
        for key,row in self.rows.items():
            now,raw=self.observe(Path(key)); need(now==row and raw==self.raws[key],'ALL_SOURCE_WINDOWS_WHOLE_FIRST_FINAL:'+key)
        return time.time_ns()

def write(path,raw):
    path.parent.mkdir(parents=True,exist_ok=True)
    with path.open('xb') as f: need(f.write(raw)==len(raw),'COMPLETE_NEW_SOURCE_WRITE')

def apply_unified(raw,patch):
    source=raw.decode().splitlines(keepends=True); lines=patch.decode().splitlines(keepends=True)
    need(lines[0].startswith('--- ') and lines[1].startswith('+++ '),'ORDINARY_HEADERS')
    out=[]; at=0; i=2
    while i<len(lines):
        m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@\n',lines[i]); need(m is not None,'ORDINARY_HUNK')
        os_,oc,ns,nc=int(m[1]),int(m[2] or '1'),int(m[3]),int(m[4] or '1'); start=os_-1 if oc else os_
        need(start>=at,'ORDINARY_ORDER'); out.extend(source[at:start]); at=start; need(len(out)==(ns-1 if nc else ns),'ORDINARY_NEW_OFFSET')
        i+=1; used=made=0
        while i<len(lines) and not lines[i].startswith('@@ '):
            tag,body=lines[i][0],lines[i][1:]; need(tag in ' +-','ORDINARY_TAG')
            if tag in ' -': need(source[at]==body,'ORDINARY_FULL_CONTEXT'); at+=1; used+=1
            if tag in ' +': out.append(body); made+=1
            i+=1
        need((used,made)==(oc,nc),'ORDINARY_COUNTS')
    out.extend(source[at:]); return ''.join(out).encode()

def definitions(raw):
    text=raw.decode(); return {n.name:ast.get_source_segment(text,n).encode() for n in ast.parse(raw).body if isinstance(n,(ast.FunctionDef,ast.AsyncFunctionDef))}

def guard(reader_pin):
    # These are Source strings, not filesystem reads during preparation.
    return f'''# Runtime admission guard: Source preparation never executes this block.
if sys.flags.optimize:
 raise RuntimeError('OPTIMIZED_PYTHON_FORBIDDEN_BEFORE_ANY_OUTPUT_OR_CHILD')
_reader_raw=Path(READER).read_bytes()
if len(_reader_raw)!={reader_pin['bytes']} or hashlib.sha256(_reader_raw).hexdigest()!={reader_pin['sha256']!r}:
 raise RuntimeError('EXACT_FULL_PREVIOUSLY_REVIEWED_SUCCESS_READER_REQUIRED')
_s_root=Path({N!r})
_s_created=json.loads((_s_root/'launcher/normal-start.created.json').read_bytes())
_s_terminal=json.loads((_s_root/'launcher/normal-start.terminal.json').read_bytes())
if set(_s_created)!={{'pid','argv','started_ns'}} or set(_s_terminal)!={{'pid','native_exit','wait_returncode','finished_ns','stdout','stderr'}}:
 raise RuntimeError('ORIGINAL_S_CREATED_TERMINAL_EXACT_SCHEMA_REQUIRED')
if type(_s_created['pid']) is not int or type(_s_terminal['pid']) is not int or _s_created['pid']!=35944 or _s_terminal['pid']!=35944:
 raise RuntimeError('SAME_GENUINE_ORIGINAL_S_PID35944_REQUIRED')
if type(_s_terminal['native_exit']) is not int or type(_s_terminal['wait_returncode']) is not int or _s_terminal['native_exit']!=0 or _s_terminal['wait_returncode']!=0:
 raise RuntimeError('ORIGINAL_S_NATIVE0_WAIT0_REQUIRED_S1_AND_LIVE_INELIGIBLE')
if type(_s_created['started_ns']) is not int or type(_s_terminal['finished_ns']) is not int or not 0<_s_created['started_ns']<_s_terminal['finished_ns']:
 raise RuntimeError('ORIGINAL_S_NATIVE_TIME_ORDER_REQUIRED')
if _s_created['argv']!=[PY,'-B','-X','utf8',str(_s_root/'validation/run.py'),'--root-source-pins-sha256','0516d5f9eff69c4a36ab8b2a012333fbbcd9c2751614330ee9459cb391fe32a4','--setup-sha256','6449487f1da61266fbdfe76260fced9196b398f1cbb92cc59074924d8e76c8aa']:
 raise RuntimeError('ORIGINAL_S_FROZEN_DRIVER_AND_M_ARGV_REQUIRED')
_context_raw=Path({CONTEXT!r}).read_bytes()
if len(_context_raw)!=12301 or hashlib.sha256(_context_raw).hexdigest()!='c56e609828ba3e51e63f69b83e4af49279bdaebee487116728c383e02dae7959':
 raise RuntimeError('GENUINE_FIXED_COMPLETED_S_CONTEXT_FULL_PIN_REQUIRED')
_context=json.loads(_context_raw)
if _context['run_id']!='20261023182634096f57' or _context['source_sha']!='175f3cd6183894185656a2974579e1be39eed7a7' or _context['freeze_sha256']!='4ac3a0b7b35db7a911e9abb61dfaa4a25b0ffaf3bdc79a7c100b48f0836ce6ed':
 raise RuntimeError('SAME_ORIGINAL_RUN_SOURCE_FREEZE_CONTEXT_REQUIRED')
'''

def main():
    r=Inputs(); r.read(Path(__file__)); r.read(OUT/'DESIGN.md')
    before=r.read(ORIGINAL)
    old_defs=definitions(before); need(list(old_defs)==['put','pointer'],'EXACT_ORIGINAL_TWO_FUNCTIONS')
    r.read(ORG/'current175f-complete-normal-S-readbacks-source-r1/PINS.json',{'bytes':2894,'sha256':'18f79c9e3e8752e40fcc5e7cc43a6aef0c34f86ecb29038d75f007e3caceb42a'})
    root_qualification=r.read(ORG/'current175f-complete-normal-S-readers-root-source-review-r1/REPORT.json',{'bytes':2546,'sha256':'5fce0d3970e7a983c39c3f84e51e927c895b7a257e4792fd49a715b6788cd1fd'})
    peer_qualification=r.read(ORG/'current175f-complete-normal-S-readers-independent-source-review-r1/REPORT.json',{'bytes':7971,'sha256':'6030c928a2961cee468b15824948c8f8e04342e62c5865e804b4b9e9e8656224'})
    targets={'root':('readback-current175f-complete-normal-S-root.py',{'bytes':14136,'sha256':'9e0475c305cc61c9524291da9f1e27ac160de1f3bd66021c677d8e5556677784'}),
             'peer':('readback-current175f-complete-normal-S-independent.py',{'bytes':19527,'sha256':'a6e36c47f7ec02d80ae6af3206ff13ba1524626ba927f4a9cc389516ce4bc2d5'})}
    original_lines=before.decode().splitlines(keepends=True)
    reader_line=next(line for line in original_lines if line.startswith('READER='))
    argv_line=next(line for line in original_lines if line.startswith('argv='))
    marker="with (D/'reader.stdout').open('xb') as out,(D/'reader.stderr').open('xb') as err:\n"
    need(before.decode().count(marker)==1,'EXACT_ORIGINAL_OUTPUT_CREATION_MARKER')
    payload={'before/launch.py':before}; transformations={}; candidates={}
    argv="argv=[PY,'-B','-X','utf8',READER,'--run-id','20261023182634096f57','--outer-pid','35944','--prepare-pid','49064','--authorize-completed-s0']\n"
    for role,(filename,expected) in targets.items():
        target=READERS/filename; r.read(target,expected)
        steps=[{'before':reader_line,'after':'READER='+repr(target.as_posix())+'\n'},
               {'before':argv_line,'after':argv}, {'before':marker,'after':guard(expected)+marker}]
        text=before.decode()
        for step in steps:
            need(text.count(step['before'])==1,'UNIQUE_ORDERED_SEAM:'+role); text=text.replace(step['before'],step['after'],1)
        candidate=text.encode(); replay=text
        for step in reversed(steps):
            need(replay.count(step['after'])==1,'UNIQUE_REVERSE_SEAM:'+role); replay=replay.replace(step['after'],step['before'],1)
        need(replay.encode()==before,'COMPLETE_WHOLE_ORDERED_INVERSE:'+role)
        need(definitions(candidate)==old_defs,'BOTH_FUNCTIONS_WHOLE_IDENTICAL:'+role)
        tree=ast.parse(candidate)
        need(sum(isinstance(n,ast.Call) and isinstance(n.func,ast.Attribute) and n.func.attr=='Popen' for n in ast.walk(tree))==1,'SOLE_ORIGINAL_POPEN:'+role)
        need(text.index('if sys.flags.optimize:')<text.index('_reader_raw=')<text.index('_s_created=')<text.index('_s_terminal=')<text.index('_context_raw=')<text.index(marker)<text.index('p=subprocess.Popen'),'ALL_REAL_INPUT_GUARDS_BEFORE_ANY_OUTPUT_OR_CHILD:'+role)
        for direction,left,right in [('FORWARD',before,candidate),('REVERSE',candidate,before)]:
            patch=''.join(difflib.unified_diff(left.decode().splitlines(keepends=True),right.decode().splitlines(keepends=True),fromfile='a/launch.py',tofile='b/launch.py')).encode()
            need(apply_unified(left,patch)==right,'FULL_ORDINARY_'+direction+':'+role)
            payload[role+'.'+direction+'.diff']=patch
        payload[role+'/launch.py']=candidate; candidates[role]={'path':(OUT/role/'launch.py').as_posix(),**pin(candidate)}
        transformations[role]={'original':{'path':ORIGINAL.as_posix(),**pin(before)},'reader':{'path':target.as_posix(),**expected},'steps':steps,'complete_whole_inverse':True,'ordinary_full_both_directions':True,'whole_original_functions':['put','pointer'],'all_native_output_protocol_other_bytes_unchanged':True}
    schema={'schema':1,'scope':'EXISTING_NATIVE_WRAPPER_PROTOCOL_DESCRIPTION_ONLY_NOT_A_RUNTIME_RECEIPT',
            'runtime_files_each_role':['reader.created.json','reader.terminal.json','reader.stdout','reader.stderr','OUTCOME.json'],
            'OUTCOME_exact_keys':{'created':'artifact pointer','terminal':'artifact pointer','stdout':'artifact pointer','stderr':'artifact pointer','native_exit':'native integer return code','wait_returncode':'native integer wait return code'},
            'artifact_pointer_exact_keys':{'path':'absolute original file path','bytes':'complete stream/file integer bytes','sha256':'full bytes SHA256'},
            'created_exact_keys':{'pid':'actual child integer PID','argv':'exact actual child argv array','started_ns':'actual time_ns'},
            'terminal_exact_keys':{'pid':'same original child PID','argv':'same original argv','started_ns':'same original start','native_exit':'first wait actual return code','wait_returncode':'second wait same original process return code','finished_ns':'actual time_ns','stdout':'complete original stream pointer','stderr':'complete original stream pointer'},
            'runtime_outputs_are_Source_PIN_members':False,'future_actual_value_or_success_fixture_created':False}
    payload['TRANSFORM.json']=enc({'schema':1,'roles':transformations,'prepared_wrappers_imported_or_executed':False})
    payload['OUTCOME-SCHEMA.json']=enc(schema)
    ended=r.end()
    report={'schema':1,'status':'COMPLETE_ROOT_PEER_SUCCESS_S_READER_NATIVE_OUTER_SOURCE_ONLY_NOT_EXECUTED',
            'original_native_wrapper':{'path':ORIGINAL.as_posix(),**pin(before)},'candidates':candidates,
            'ordinary_full_four_directions_replayed':True,'both_original_functions_whole':['put','pointer'],'only_three_module_seams_each':['READER literal','real readback argv','pre-output actual-S0/context/reader/optimization guard'],
            'runtime_guard_reads_only_at_future_invocation':{'original_S_pid':35944,'native_exit_wait_required':[0,0],'Run':'20261023182634096f57','context':{'path':CONTEXT,'bytes':12301,'sha256':'c56e609828ba3e51e63f69b83e4af49279bdaebee487116728c383e02dae7959'},'S1_or_live_ineligible':True,'before_any_output_creation_or_Popen':True},
            'root_peer_reader_full_Source_qualifications':{'Root':pin(root_qualification),'peer':pin(peer_qualification)},
            'runtime_output_protocol':'Exact existing 6-key OUTCOME, 3-key created, 8-key terminal and full stdout/stderr; see OUTCOME-SCHEMA.json.',
            'runtime_output_files_excluded_from_Sourcepins':True,'Root_whole_Source_review_and_admission_required_before_actual_invocation':True,
            'prepared_wrappers_or_readers_executed':False,'S_Run_private_ENV_actors_cookies_raw_streams_read':False,'A0_or_success_receipt_or_future_hash_PID_created':False,
            'original_wrapper_readers_N175_Run_W_Val_canonical_CP_modified':False,'all_source_windows_closed':True,'finite_inputs':len(r.rows),'ended_ns':ended}
    payload['REPORT.json']=enc(report)
    payload['SOURCE-INPUTS.json']=enc({'schema':1,'native_fields':KEYS,'cross_common_fields':sorted(CROSS),'members':list(r.rows.values()),'all_windows_closed':True,'ended_ns':ended})
    for name,body in payload.items(): write(OUT/name,body)
    names={p.relative_to(OUT).as_posix() for p in OUT.rglob('*') if p.is_file()}
    need(names==set(payload)|{'DESIGN.md','prepare.py'},'EXACT_SOURCE_NAMESPACE_NO_RUNTIME_OUTPUTS')
    files={name:pin((OUT/name).read_bytes()) for name in sorted(names)}
    pins={'schema':1,'scope':'FIXED_NATIVE_OUTER_SOURCE_ONLY_RUNTIME_OUTPUTS_EXCLUDED','files':files,
          'expected_Source_namespace':sorted(names),'runtime_output_names_excluded':schema['runtime_files_each_role'],
          'runtime_output_directories':{'root':(OUT/'root').as_posix(),'peer':(OUT/'peer').as_posix()},
          'prepared_programs_imported_or_executed':False,'A0':'UNBOUND','Root_admission':'REQUIRED_BEFORE_FUTURE_ACTUAL_S0_INVOCATION'}
    write(OUT/'PINS.json',enc(pins))
    print(json.dumps({'PINS':pin(enc(pins)),'REPORT':pin(payload['REPORT.json']),'candidates':candidates,'fixed_source_members':len(files),'wrappers_executed':False},ensure_ascii=False))

if __name__=='__main__': main()
