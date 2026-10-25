from pathlib import Path, PurePosixPath
import hashlib, json, subprocess, tarfile

ROOT=Path(__file__).parent
REPO=Path('H:/天俪商城/worktrees/media-normal-env7')
COMMIT=json.loads((ROOT/'media-environment'/'source-binding.json').read_text(encoding='utf-8'))['source_sha']
assert isinstance(COMMIT,str) and len(COMMIT)==40 and all(c in '0123456789abcdef' for c in COMMIT), 'Root actual committed Source binding is UNBOUND'
GIT=['git','-c','safe.directory='+REPO.as_posix(),'-C',str(REPO)]

def native(args):
    result=subprocess.run(GIT+args,capture_output=True,check=True)
    assert result.stderr==b''
    return result.stdout

assert native(['rev-parse','HEAD']).strip().decode()==COMMIT
assert native(['status','--porcelain=v1','--untracked-files=normal'])==b''
tree=native(['rev-parse',COMMIT+'^{tree}']).strip().decode()
tracked={}
for item in native(['ls-tree','-rz','--full-tree',COMMIT]).split(b'\0'):
    if not item:continue
    header,name=item.split(b'\t',1);mode,kind,blob=header.decode().split();name=name.decode('utf-8')
    assert mode in ['100644','100755'] and kind=='blob' and name not in tracked
    path=PurePosixPath(name);assert not path.is_absolute() and '..' not in path.parts and path.as_posix()==name
    tracked[name]={'git_blob':blob,'git_mode':mode}
directory=ROOT/'source-input';directory.mkdir(exist_ok=True)
archive=directory/'source.tar';assert not archive.exists()
with archive.open('xb') as output:
    result=subprocess.run(GIT+['archive','--format=tar',COMMIT],stdout=output,stderr=subprocess.PIPE)
assert result.returncode==0 and result.stderr==b''
files=[];actual={};tar_dirs=set()
with tarfile.open(archive,'r:') as tar:
    for member in tar:
        name=member.name.rstrip('/');path=PurePosixPath(name)
        assert not path.is_absolute() and '..' not in path.parts and str(path)==name
        if member.isdir():tar_dirs.add(name);continue
        assert member.isfile() and name in tracked and name not in actual
        stream=tar.extractfile(member);assert stream is not None
        data=stream.read();assert stream.read(1)==b'' and len(data)==member.size
        blob=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
        assert blob==tracked[name]['git_blob']
        row={'path':name,'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest(),**tracked[name]}
        actual[name]=row
assert actual.keys()==tracked.keys()
directories=set()
for name in actual:
    for parent in PurePosixPath(name).parents:
        if str(parent)!='.':directories.add(parent.as_posix())
assert tar_dirs==directories
manifest={'schema':1,'scope':'COMPLETE_REAL_COMMITTED_GIT_SOURCE_NOT_TEST_HELPERS','source_sha':COMMIT,'tree_sha':tree,
    'requested_repo_source':REPO.as_posix(),'files':[actual[name] for name in sorted(actual)],'directories':sorted(directories),
    'file_count':len(actual),'directory_count':len(directories),'total_bytes':sum(row['bytes'] for row in actual.values()),
    'archive':{'file':'source.tar','bytes':archive.stat().st_size,'sha256':hashlib.sha256(archive.read_bytes()).hexdigest()},
    'all_git_blobs_and_complete_tracked_namespace_verified':True,
    'untracked_runtime_private_ENV_Run_not_exported':True,
    'public_tracked_install_frontend_env_templates_and_SDK_fixtures_preserved':True}
(directory/'SOURCE-TREE.json').write_text(json.dumps(manifest,ensure_ascii=False,separators=(',',':'))+'\n',encoding='utf-8',newline='\n')
assert native(['rev-parse','HEAD']).strip().decode()==COMMIT and native(['status','--porcelain=v1','--untracked-files=normal'])==b''
print(json.dumps({'source_sha':COMMIT,'tree_sha':tree,'files':len(actual),'directories':len(directories),'bytes':manifest['total_bytes'],
    'archive':manifest['archive'],'Source_only_export':True}))
