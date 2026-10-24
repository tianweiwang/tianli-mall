"""Write the minimally corrected complete successor Source; no callback/native program."""
from pathlib import Path
import ast,hashlib,json

B=Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1')
ORG=B.parent/'project-organization-20261023'
OLD=B/'full-a-preflight-diagnostic-current6e3850-normal-next-source-r1'
OUT=B/'full-a-preflight-diagnostic-current6e3850-normal-next-source-r2'

def fp(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def put(path,raw):
    path.parent.mkdir(parents=True,exist_ok=True)
    if path.exists():assert path.read_bytes()==raw,path
    else:path.write_bytes(raw)

def main():
    assert not (OUT/'PINS.json').exists()
    pins_raw=(OLD/'PINS.json').read_bytes()
    assert fp(pins_raw)=={'bytes':133944,'sha256':'78f5fe3a4d87887aed9ed94fe68d0d4eefd5e1740f0a73006e4f91f6099940be'}
    pins=json.loads(pins_raw);assert len(pins['files'])==234 and len(pins['source_members18'])==18
    names=['source_reader.py','author_support.py','namespace_static.py','assemble.py','authority-current.py.fragment',
           'authority-current.tail.fragment','bind-initializer-syntax.py','initializer-syntax-checks.py.fragment',
           'INITIALIZER-PREFLIGHT-DIAGNOSTIC-SYNTAX.json','retirement-checks.py.fragment',
           'CURRENT-SOURCE-AUTHORITY.json','PREDECESSOR-COMPLETE-SOURCE-CHECKS.json','PREPARE-INPUTS-PINS.json',
           'prepare-inputs.py','AUTHOR-GENERATION-ATTEMPTS.json']
    names += [name for name in pins['files'] if name.startswith('before-author/')]
    names += [name for name in pins['source_members18'] if name.startswith('validation/actual-profile/')]
    raws={}
    for name in names:
        raw=(OLD/name).read_bytes();assert fp(raw)=={k:pins['files'][name][k] for k in ['bytes','sha256']}
        raws[name]=raw
        if name in ['assemble.py','authority-current.py.fragment','authority-current.tail.fragment']:
            put(OUT/'predecessor-r1'/name,raw)
        else:put(OUT/name,raw)
    put(OUT/'predecessor-r1/PINS.json',pins_raw)
    text=raws['authority-current.py.fragment'].decode('utf8')
    before="name=lines[index][6:].rstrip('\\r\\n')"
    after="name=lines[index][len('--- '+oldlabel+'/'):].rstrip('\\r\\n')"
    assert text.count(before)==1;text=text.replace(before,after,1)
    changes=[
        ("**compact(packet_members['REVERSE.diff'])}))==before4", "**compact(packet_members['REVERSE.diff'])}),'b','a')==before4"),
        ("**profilepins['members']['FORWARD.diff']}))==after9", "**profilepins['members']['FORWARD.diff']}),'before','candidate')==after9"),
        ("**profilepins['members']['REVERSE.diff']}))==before9", "**profilepins['members']['REVERSE.diff']}),'before','candidate')==before9"),
    ]
    for old,new in changes:assert text.count(old)==1;text=text.replace(old,new,1)
    put(OUT/'authority-current.py.fragment',text.encode())
    tail=text[text.index('    prior_source='):]
    put(OUT/'authority-current.tail.fragment',tail.encode())
    original=raws['assemble.py'].decode('utf8');author=original
    author=author.replace('from author_support import funcs,current_qualification','from author_support import funcs')
    anchor="ORG=B.parent/'project-organization-20261023'\n"
    assert author.count(anchor)==1
    author=author.replace(anchor,anchor+"PREVIOUS=B/'full-a-preflight-diagnostic-current6e3850-normal-next-source-r1'\n",1)
    anchor="    assert not (OUT/'PINS.json').exists()\n"
    insertion="""    previous_raw=r.read(PREVIOUS/'PINS.json')
    assert r.pin(previous_raw)=={'bytes':133944,'sha256':'78f5fe3a4d87887aed9ed94fe68d0d4eefd5e1740f0a73006e4f91f6099940be'}
    previous_r1_source_pins=json.loads(previous_raw);assert len(previous_r1_source_pins['files'])==234
    previous18={name:r.fixed(PREVIOUS/name,previous_r1_source_pins['files'][name]) for name in previous_r1_source_pins['source_members18']}
    for name,raw in previous18.items():r.write('before-r1/'+name,raw)
    source_failure=r.pointer(ORG/'current6e3850-source-callback-failure-checkpoint-r52/REPORT.json')
    assert r.compact(source_failure)=={'bytes':1817,'sha256':'6246022e8019c16d5087e8e9cf33ec9df9868feae89bea3ccf18627473f8ea97'}
"""
    assert author.count(anchor)==1;author=author.replace(anchor,anchor+insertion,1)
    anchor="    qualification=current_qualification(pub,expected,values)\n"
    replacement="""    reused_qualification=r.fixed(PREVIOUS/'CURRENT-SOURCE-QUALIFICATION.json',previous_r1_source_pins['files']['CURRENT-SOURCE-QUALIFICATION.json'])
    reused=json.loads(reused_qualification)
    assert reused['source_sha']==r.HEAD and reused['parent']==r.PARENT and len(reused['operations'])==12
    assert reused['all_operations_read_only'] is True and reused['tracked_names_per_checkout']==15748 and reused['required_files_per_checkout']==313
    r.write('CURRENT-SOURCE-QUALIFICATION.json',reused_qualification)
    qualification=r.pointer(OUT/'CURRENT-SOURCE-QUALIFICATION.json')
    forward_r1,reverse_r1=r.patch(previous18,after),r.patch(after,previous18)
    assert r.apply(previous18,forward_r1)==after and r.apply(after,reverse_r1)==previous18
    r.write('FROM-R1.patch',forward_r1.encode());r.write('TO-R1.patch',reverse_r1.encode())
"""
    assert author.count(anchor)==1;author=author.replace(anchor,replacement,1)
    anchor="'pure_twentieth_authority_callback':'NOT RUN_ROOT_ADMISSION_REQUIRED','runtime':'NOT RUN'}"
    replacement="'pure_twentieth_authority_callback':'NOT RUN_ROOT_ADMISSION_REQUIRED','runtime':'NOT RUN',\n        'r1_failure':source_failure,'r1_complete_Source_PINS':r.pointer(PREVIOUS/'PINS.json'),'r1_18_complete_forward_reverse_replayed':True,\n        'current_source_qualification_reused_from_r1_not_new_Git':r.pointer(PREVIOUS/'CURRENT-SOURCE-QUALIFICATION.json')}"
    assert author.count(anchor)==1;author=author.replace(anchor,replacement,1)
    ast.parse(author)
    put(OUT/'assemble.py',author.encode())
    design="""# Complete current6e3850 normal Source r2

Requirements: retain all 18 orchestration/profile sources, complete original nineteen-stage body and recursively used literals, real twentieth Git stage, M36, derived1104, all ten genuine retirement objects, true initializer syntax and the entire original business suffix. No callback/M/S/business is executed here.

Observed defect: the real original full Source callback PID39848/session72560 ended native1/wait1 with UPROOT_PATCH_HEADER. r1 remains immutable and its prior static reviews remain historical facts. The public failure checkpoint is1817/6246022e8019c16d5087e8e9cf33ec9df9868feae89bea3ccf18627473f8ea97.

Design: change only the new outer patch_apply member-name slicing to use the full actual old-label prefix. Diagnostic forward remains a/b; diagnostic reverse explicitly b/a. Both actual full nine-profile patches use before/candidate, including the reverse file. Keep every original context, hunk/order/count/byte assertion. The nested original nineteen-stage function is unchanged. Rebind only this successor's own source/publication/root/launcher identities along the original DAG.

Tasks: preserve r1 PINS and eighteen whole before sources; verify all four real public patch headers and full byte replay using only the isolated parser; generate complete18 with all original four patch directions plus r1 forward/reverse; static actual-module globals and exact business-suffix checks; preserve true Git22 export and copy the prior actual12 read-only Git qualification with its original timestamps. No new Git/native task or PHP/metadata rerun is implied. Root will check current HEAD/clean and alone admit the complete Source callback.

Acceptance: exact isolated parser replays; unchanged strict assertion gates, old19 whole body/all literals, 20stages/M36/1104 derivation, all profile/retirement actual pins, six complete ordinary diff directions and no missing globals. Static Source is not callback success, A0, or business acceptance. The future M reader is paused and was never written or run.
"""
    put(OUT/'DESIGN.md',design.encode())
    put(OUT/'R2-TRANSFORM.json',(json.dumps({'schema':1,'r1_Source_PINS':{'path':(OLD/'PINS.json').as_posix(),**fp(pins_raw)},
        'outer_name_slice':{'before':before,'after':after},'three_call_changes':[{'before':a,'after':b} for a,b in changes],
        'callback_M_S_business_execution':False,'new_Git_or_PHP_or_metadata_task_execution':False},ensure_ascii=False,indent=2)+'\n').encode())
    print(json.dumps({'r2_root':OUT.as_posix(),'own_author_Source':fp(author.encode()),'outer_fragment':fp(text.encode()),'target_execution':False},ensure_ascii=False))

if __name__=='__main__':main()
