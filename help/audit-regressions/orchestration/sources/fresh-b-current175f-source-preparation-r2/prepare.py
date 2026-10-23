"""One active metadata-literal Source correction only; no author invocation."""
from pathlib import Path
import ast,difflib,hashlib,json,os,re,time
ROOT=Path(__file__).resolve().parent;R1=ROOT.parent/'fresh-b-current175f-source-preparation-r1'
READS={};KS=['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def read(p,expected=None):
 p=Path(p);a=[getattr(p.stat(),k) for k in KS]
 with p.open('rb') as f:
  b=[getattr(os.fstat(f.fileno()),k) for k in KS];raw=f.read();assert f.read(1)==b'';c=[getattr(os.fstat(f.fileno()),k) for k in KS]
 d=[getattr(p.stat(),k) for k in KS];assert a==d and b==c and len(raw)==a[6] and [a[i] for i in [0,1,6,7]]==[b[i] for i in [0,1,6,7]]
 if expected is not None:assert pin(raw)=={k:expected[k] for k in ['bytes','sha256']}
 READS[p.as_posix()]={'path':p.as_posix(),**pin(raw),'native9':a};return raw
def obj(p,expected=None):return json.loads(read(p,expected))
def pointer(p):return {'path':p.as_posix(),**pin(read(p))}
def put(name,raw):
 p=ROOT/name;p.parent.mkdir(parents=True,exist_ok=True)
 with p.open('xb') as f:f.write(raw)
def enc(v):return (json.dumps(v,ensure_ascii=False,indent=2)+'\n').encode()
def defs(raw):
 s=raw.decode();return {n.name:ast.get_source_segment(s,n).encode() for n in ast.parse(raw).body if isinstance(n,(ast.FunctionDef,ast.ClassDef))}
def apply_patch(before, raw):
    """Independent strict standard unified-diff replay, including zero-length hunks."""
    lines = raw.decode('utf8').splitlines(keepends=True)
    result = dict(before)
    i = 0
    seen = set()
    while i < len(lines):
        assert lines[i].startswith('--- a/')
        name = lines[i][6:].rstrip('\r\n')
        assert name in before and name not in seen
        seen.add(name)
        i += 1
        assert lines[i].rstrip('\r\n') == '+++ b/' + name
        i += 1
        original = before[name].decode('utf8').splitlines(keepends=True)
        output = []
        cursor = 0
        while i < len(lines) and lines[i].startswith('@@ '):
            match = re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n?', lines[i])
            assert match is not None
            old_count = int(match[2]) if match[2] is not None else 1
            new_count = int(match[4]) if match[4] is not None else 1
            start = int(match[1]) - 1 if old_count else int(match[1])
            assert start >= cursor
            output.extend(original[cursor:start])
            cursor = start
            used = made = 0
            i += 1
            while i < len(lines) and not lines[i].startswith(('@@ ', '--- a/')):
                line = lines[i]
                assert line[0] in ' +-'
                if line[0] in ' -':
                    assert cursor < len(original) and original[cursor] == line[1:]
                    cursor += 1
                    used += 1
                if line[0] in ' +':
                    output.append(line[1:])
                    made += 1
                i += 1
            assert (used, made) == (old_count, new_count)
        output.extend(original[cursor:])
        result[name] = ''.join(output).encode('utf8')
    assert seen == {name for name in before if before[name] != result[name]}
    return result
def main():
 read(__file__);read(ROOT/'DESIGN.md');assert not (ROOT/'PINS.json').exists()
 p=obj(R1/'PINS.json',{'bytes':3920,'sha256':'0a0bd107121995480b3220e173387252f5e7c199aa8d142c0422158e24bd465f'})
 final=obj(R1/'FINAL-PINS.json',{'bytes':1416,'sha256':'e3fb39e5977335686e0532abed60ce5034c8d69cbce2391b615d5d2b8c1fb244'})
 assert set(x.relative_to(R1).as_posix() for x in R1.rglob('*') if x.is_file())==set(p['files'])|{'PINS.json','FINAL-PINS.json'}
 for name,row in p['files'].items():put('predecessor-r1/'+name,read(R1/name,row))
 put('predecessor-r1/PINS.json',read(R1/'PINS.json'));put('predecessor-r1/FINAL-PINS.json',read(R1/'FINAL-PINS.json'))
 review=ROOT.parent/'fresh-b-current175f-independent-source-review-r1'
 rp=obj(review/'REPORT.json',{'bytes':6585,'sha256':'c609c510bdfbae23ed1983108935ba2f6539c17ba3f2bf926e73032e6c830273'})
 read(review/'PINS.json',{'bytes':899,'sha256':'17ad9116a958efb9970854059c8fabed641246b95ae5db90463cdf1aa60f72f3'})
 put('inputs/INDEPENDENT-R1-REPORT.json',read(review/'REPORT.json'));put('inputs/INDEPENDENT-R1-PINS.json',read(review/'PINS.json'))
 before=read(R1/'impl/author_draft.py',final['author']);leaf=read(R1/'leaf/ms4-initialize.php',final['initializer_leaf'])
 old=b'REAL_SAME28DC_A_SUCCESS_INPUTS_BOUND_SOURCE_ONLY_B_NOT_SEALED';new=b'REAL_SAME175F_A_SUCCESS_INPUTS_BOUND_SOURCE_ONLY_B_NOT_SEALED'
 assert before.count(old)==1 and before.count(new)==0
 after=before.replace(old,new);assert after.replace(new,old)==before and len(after)==len(before)
 a,z=defs(before),defs(after);assert list(a)==list(z) and [n for n in a if a[n]!=z[n]]==['build'] and z['build'].replace(new,old)==a['build']
 assert ast.dump(ast.parse(after.replace(new,old)))==ast.dump(ast.parse(before))
 put('before/author_draft.py',before);put('before/ms4-initialize.php',leaf)
 put('impl/author_draft.py',after);put('impl/DESIGN-NOTES.md',read(R1/'impl/DESIGN-NOTES.md'));put('leaf/ms4-initialize.php',leaf)
 forward=''.join(difflib.unified_diff(before.decode().splitlines(True),after.decode().splitlines(True),'a/author_draft.py','b/author_draft.py')).encode()
 reverse=''.join(difflib.unified_diff(after.decode().splitlines(True),before.decode().splitlines(True),'a/author_draft.py','b/author_draft.py')).encode()
 put('AUTHOR-FORWARD.diff',forward);put('AUTHOR-REVERSE.diff',reverse)
 assert apply_patch({'author_draft.py':before},forward)=={'author_draft.py':after} and apply_patch({'author_draft.py':after},reverse)=={'author_draft.py':before}
 values={}
 for n in ast.parse(after).body:
  if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name):
   try:values[n.targets[0].id]=ast.literal_eval(n.value)
   except (TypeError,ValueError):pass
 assert values['ROOT_INITIALIZER_LEAF']==final['initializer_leaf'] and read(Path(values['ROOT_INITIALIZER_LEAF']['path']),values['ROOT_INITIALIZER_LEAF'])==leaf
 np=Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1/PINS.json')
 read(np,values['CURRENT_A_SOURCE_PINS']);read(Path(values['CURRENT_M_RESULT']['path']),values['CURRENT_M_RESULT']);read(Path(values['CURRENT_M_FREEZE']['path']),values['CURRENT_M_FREEZE'])
 assert values['HEAD']=='175f3cd6183894185656a2974579e1be39eed7a7' and values['PARENT']=='28dcab632da3e62f7c8b8d926b6ab9406534d0d3'
 put('TRANSFORM.json',enc({'schema':1,'seam':{'before':old.decode(),'candidate':new.decode(),'occurrences':1},'ordinary_whole_forward_reverse':True,'entire_author_only_one_literal_diff':True,'all_other_methods_whole':list(n for n in a if n!='build'),'build_whole_inverse_exact':True,'leaf_whole_reused':True,'ROOT_INITIALIZER_LEAF_intentionally_original_r1_real_pointer':values['ROOT_INITIALIZER_LEAF']}))
 for path,row in READS.items():assert [getattr(Path(path).stat(),k) for k in KS]==row['native9']
 put('INPUTS.json',enc({'files':list(READS.values()),'all_windows_ended':True,'author_or_target_invoked':False}))
 report={'schema':1,'status':'MINIMAL_CURRENT175F_ACTIVE_SCOPE_SOURCE_CORRECTION_UNEXECUTED_UNSEALED','author':pointer(ROOT/'impl/author_draft.py'),'initializer_leaf_copy':pointer(ROOT/'leaf/ms4-initialize.php'),'actual_initializer_leaf_pointer_preserved':values['ROOT_INITIALIZER_LEAF'],'independent_R1_finding':pointer(review/'REPORT.json'),'predecessor_r1':pointer(R1/'PINS.json'),'one_active_CURRENT_A_SOURCE_BINDING_scope':'REAL_SAME175F_A_SUCCESS_INPUTS_BOUND_SOURCE_ONLY_B_NOT_SEALED','all_other_author_bytes_functions_counts_scope_business_unchanged':True,'ordinary_full_diff_both_directions':True,'full_r1_packet_preserved':True,'full_leaf_bytes_unchanged':True,'actual_Source_M_freeze_whole_pins_joined':True,'A0_postA_successclose_zero':'UNBOUND','author_leaf_B_Host_Docker_Node_browser_executed':False,'runtime_ready':False,'still_pending':'New author independent minimal correction review; changed full118698-byte B leaf actual PHP syntax after live S ends; real same-source successful A0/postA before invocation; all original complete B/UI20/Task2/H5 and remaining acceptance requirements.'}
 put('REPORT.json',enc(report))
 files={p.relative_to(ROOT).as_posix():pin(p.read_bytes()) for p in sorted(ROOT.rglob('*')) if p.is_file()}
 put('PINS.json',enc({'schema':1,'scope':'FULL_IMMUTABLE_SOURCE_PREPARATION_WITH_R1_ARCHIVE_NOT_B_OUTPUT','files':files,'runtime_ready':False,'author_invoked':False,'A0':'UNBOUND'}))
 put('FINAL-PINS.json',enc({'schema':1,'status':report['status'],'PINS':pointer(ROOT/'PINS.json'),'REPORT':pointer(ROOT/'REPORT.json'),'author':report['author'],'leaf':report['initializer_leaf_copy'],'actual_initializer_leaf_pointer_preserved':values['ROOT_INITIALIZER_LEAF'],'author_or_target_invoked':False}))
 print(json.dumps({'PINS':pointer(ROOT/'PINS.json'),'FINAL':pointer(ROOT/'FINAL-PINS.json'),'REPORT':pointer(ROOT/'REPORT.json'),'author':report['author'],'leaf':report['initializer_leaf_copy']}))
if __name__=='__main__':main()
