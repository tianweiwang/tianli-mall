from pathlib import Path
import ast,hashlib,json,os,time,difflib,re
OUT=Path(__file__).parent
B=OUT.parent
OLD=B/'full-a-881-form-prefix-normal-next-source-r3'
HEAD='0c13c57ba1ec4bf15ea9240601a5d6361e986ce8'
PARENT='8819524a60028090c55bdcd3a9da05e50eaf19d0'
CACHE={};READS={}
def pin(raw):return {'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def compact(row):return {k:row[k] for k in ['bytes','sha256']}
def n9(s):return [int(getattr(s,k)) for k in ['st_dev','st_ino','st_mode','st_nlink','st_uid','st_gid','st_size','st_mtime_ns','st_ctime_ns']]
def read(p):
    p=Path(p);key=p.as_posix()
    if key in CACHE:return CACHE[key]
    started=time.time_ns();pb=n9(p.stat())
    with p.open('rb') as f:
        hb=n9(os.fstat(f.fileno()));raw=f.read();eof=f.read(1);ha=n9(os.fstat(f.fileno()))
    pa=n9(p.stat());assert pb==pa and hb==ha and eof==b'' and len(raw)==hb[6],key
    assert [hb[i] for i in [0,1,6,7]]==[pa[i] for i in [0,1,6,7]],key
    READS[key]={'path':key,**pin(raw),'native9':pa,'handle_before':hb,'handle_after':ha,'path_before':pb,'path_after':pa,'EOF':True,'closed':True,'started_ns':started,'ended_ns':time.time_ns()};CACHE[key]=raw;return raw
def fixed(p,row):
    raw=read(p);assert pin(raw)==compact(row),(str(p),pin(raw),row);return raw
def obj(p):return json.loads(read(p))
def pointer(p,native=False):
    read(p);r=READS[Path(p).as_posix()];return {k:r[k] for k in (['path','bytes','sha256','native9'] if native else ['path','bytes','sha256'])}
def write(name,raw):
    p=OUT/name
    if p.exists():assert read(p)==raw,('EXISTING_UNFROZEN_AUTHOR_COPY_MUST_BE_EXACT',str(p));return
    p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(raw)
def put(name,value):write(name,(json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode('utf-8'))
def once(source,before,after):
    assert source.count(before)==1,(before[:100],source.count(before));return source.replace(before,after,1)
def function(source,name):
    node=next(n for n in ast.parse(source).body if isinstance(n,ast.FunctionDef) and n.name==name)
    return ast.get_source_segment(source,node)
def constants(source):
    result={}
    for node in ast.parse(source).body:
        if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name):
            try:result[node.targets[0].id]=ast.literal_eval(node.value)
            except (ValueError,TypeError):pass
    return result
def const(source,name,value):
    node=next(n for n in ast.parse(source).body if isinstance(n,ast.Assign) and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id==name)
    return once(source,ast.get_source_segment(source,node),name+' = '+repr(value))
def patch(before,after):
    return ''.join(''.join(difflib.unified_diff(before[name].decode('utf-8').splitlines(keepends=True),after[name].decode('utf-8').splitlines(keepends=True),fromfile='a/'+name,tofile='b/'+name)) for name in before)
def apply(before,patch_text):
    lines=patch_text.splitlines(keepends=True);i=0;result=dict(before)
    while i<len(lines):
        assert lines[i].startswith('--- a/');name=lines[i][6:].rstrip('\r\n');i+=1
        assert lines[i].rstrip('\r\n')=='+++ b/'+name;i+=1
        old=before[name].decode('utf-8').splitlines(keepends=True);out=[];cursor=0
        while i<len(lines) and lines[i].startswith('@@ '):
            m=re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@[^\n]*\n?',lines[i]);assert m
            oldn=int(m[2] or 1);newn=int(m[4] or 1);start=int(m[1])-1 if oldn else int(m[1]);assert start>=cursor
            out.extend(old[cursor:start]);cursor=start;i+=1;used=made=0
            while i<len(lines) and not lines[i].startswith(('@@ ','--- a/')):
                line=lines[i];assert line[:1] in [' ','+','-']
                if line[0] in [' ','-']:assert old[cursor]==line[1:];cursor+=1;used+=1
                if line[0] in [' ','+']:out.append(line[1:]);made+=1
                i+=1
            assert (used,made)==(oldn,newn)
        out.extend(old[cursor:]);result[name]=''.join(out).encode('utf-8')
    return result
