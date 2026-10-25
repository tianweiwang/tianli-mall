<?php
declare(strict_types=1);

// Only the dedicated bootstrap's source-copy invocation has these RW mounts.
// It has no network, application actors, database or credentials.
ini_set('display_errors','0');ini_set('log_errors','0');ini_set('memory_limit','1073741824');
umask(0022);
try{
    $root='/run/media-source-rw';$input='/media-input';
    $binding=json_decode(file_get_contents('/media-environment/source-binding.json'),true,512,JSON_THROW_ON_ERROR);
    if(!is_string($binding['source_sha']??null)||preg_match('/\A[a-f0-9]{40}\z/D',$binding['source_sha'])!==1)throw new RuntimeException('SOURCE_UNBOUND');
    $manifest=json_decode(file_get_contents($input.'/SOURCE-TREE.json'),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['source_sha']??null)!==$binding['source_sha']
        ||($manifest['all_git_blobs_and_complete_tracked_namespace_verified']??null)!==true
        ||scandir($root)!==['.','..'])throw new RuntimeException('SOURCE_START');
    $archive=$input.'/source.tar';
    if(filesize($archive)!==$manifest['archive']['bytes']||hash_file('sha256',$archive)!==$manifest['archive']['sha256'])throw new RuntimeException('SOURCE_ARCHIVE');
    // GNU tar consumes the producer's PAX.path instead of its raw fallback
    // header name. Fixed argv only; all original checks below still apply.
    $tarArgv=['/bin/tar','--extract','--file',$archive,'--directory',$root];
    $tarProcess=proc_open($tarArgv,[['file','/dev/null','r'],['pipe','w'],['pipe','w']],$tarPipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($tarProcess))throw new RuntimeException('SOURCE_ARCHIVE_EXTRACT_PROCESS');
    $tarCounts=[1=>0,2=>0];$tarExit=null;
    try{
        foreach($tarPipes as$pipe)if(!stream_set_blocking($pipe,false))throw new RuntimeException('SOURCE_ARCHIVE_EXTRACT_NONBLOCKING');
        while(true){
            $read=[];foreach($tarPipes as$pipe)if(is_resource($pipe)&&!feof($pipe))$read[]=$pipe;
            if($read===[])break;
            $write=null;$except=null;
            if(stream_select($read,$write,$except,0,200000)===false)throw new RuntimeException('SOURCE_ARCHIVE_EXTRACT_SELECT');
            foreach($read as$pipe){
                $which=$pipe===$tarPipes[1]?1:2;$chunk=fread($pipe,8192);
                if(!is_string($chunk)||($chunk===''&&!feof($pipe)))throw new RuntimeException('SOURCE_ARCHIVE_EXTRACT_CAPTURE');
                $tarCounts[$which]+=strlen($chunk);
                if($tarCounts[$which]>65536)throw new RuntimeException('SOURCE_ARCHIVE_EXTRACT_OUTPUT_BOUND');
            }
        }
    }finally{
        foreach($tarPipes as$pipe)if(is_resource($pipe))fclose($pipe);
        // Do not consume the exit with proc_get_status: this is the actual
        // native return from this same child handle, with no new background.
        $tarExit=proc_close($tarProcess);
    }
    if($tarExit!==0||$tarCounts!==[1=>0,2=>0])throw new RuntimeException('SOURCE_ARCHIVE_EXTRACT_NATIVE_ZERO_EMPTY_STREAMS');
    $expected=[];
    foreach($manifest['files']as$row){
        if(!is_array($row)||!is_string($row['path']??null)||str_starts_with($row['path'],'/')
            ||str_contains($row['path'],"\0")||in_array('..',explode('/',$row['path']),true)
            ||isset($expected[$row['path']]))throw new RuntimeException('SOURCE_DESCRIPTOR');
        $file=$root.'/'.$row['path'];clearstatcache(true,$file);$stat=lstat($file);
        if(!is_array($stat)||is_link($file)||($stat['mode']&0170000)!==0100000||$stat['size']!==$row['bytes']
            ||hash_file('sha256',$file)!==$row['sha256'])throw new RuntimeException('SOURCE_FILE');
        $mode=$row['git_mode']==='100755'?0755:0644;
        if(!chmod($file,$mode))throw new RuntimeException('SOURCE_MODE');clearstatcache(true,$file);
        if((lstat($file)['mode']&0777)!==$mode)throw new RuntimeException('SOURCE_ACTUAL_MODE');
        $expected[$row['path']]=true;
    }
    $files=[];$directories=[];
    $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($walk as$item){
        $path=substr($item->getPathname(),strlen($root)+1);
        if($item->isLink())throw new RuntimeException('SOURCE_LINK');
        if($item->isDir()){$directories[]=$path;if(!chmod($item->getPathname(),0755))throw new RuntimeException('DIRECTORY_MODE');
            clearstatcache(true,$item->getPathname());if((lstat($item->getPathname())['mode']&0777)!==0755)throw new RuntimeException('DIRECTORY_ACTUAL_MODE');}
        elseif($item->isFile())$files[]=$path;else throw new RuntimeException('SOURCE_KIND');
    }
    sort($files,SORT_STRING);sort($directories,SORT_STRING);$wanted=array_keys($expected);sort($wanted,SORT_STRING);
    if($files!==$wanted||$directories!==$manifest['directories']||count($files)!==$manifest['file_count']
        ||count($directories)!==$manifest['directory_count'])throw new RuntimeException('SOURCE_NAMESPACE');
    $parents=[];$uploadsParent=$root.'/crmeb/public/uploads';
    if(!file_exists($uploadsParent)){
        if(!mkdir($uploadsParent,0755))throw new RuntimeException('UPLOAD_MOUNT_PARENT');$parents[]='crmeb/public/uploads';
    }elseif(!is_dir($uploadsParent)||is_link($uploadsParent))throw new RuntimeException('UPLOAD_MOUNT_PARENT_KIND');
    foreach(['/run/media-uploads','/run/media-app-logs']as$directory){
        if(scandir($directory)!==['.','..']||!chmod($directory,0700))throw new RuntimeException('EMPTY_PRIVATE_VOLUME');
    }
    // A RW mount must not hide the repository's existing public installation
    // assets. Seed only this exact, already-qualified tracked subtree.
    $prefix='crmeb/public/uploads/';$uploadFiles=[];$uploadDirectories=[];$uploadBytes=0;
    foreach($manifest['directories']as$directory)if(str_starts_with($directory,$prefix)){
        $relative=substr($directory,strlen($prefix));
        if(!mkdir('/run/media-uploads/'.$relative,0755,true)&&!is_dir('/run/media-uploads/'.$relative))throw new RuntimeException('PUBLIC_UPLOAD_DIRECTORY_COPY');
        $uploadDirectories[]=$relative;
    }
    foreach($manifest['files']as$row)if(str_starts_with($row['path'],$prefix)){
        $relative=substr($row['path'],strlen($prefix));$target='/run/media-uploads/'.$relative;
        if(file_exists($target)||!copy($root.'/'.$row['path'],$target)||!chmod($target,$row['git_mode']==='100755'?0755:0644)
            ||is_link($target)||filesize($target)!==$row['bytes']||hash_file('sha256',$target)!==$row['sha256'])throw new RuntimeException('PUBLIC_TRACKED_UPLOAD_BYTES_COPY');
        clearstatcache(true,$target);if((lstat($target)['mode']&0777)!==($row['git_mode']==='100755'?0755:0644))throw new RuntimeException('PUBLIC_UPLOAD_FILE_MODE');
        $uploadFiles[]=$relative;$uploadBytes+=$row['bytes'];
    }
    $copiedFiles=[];$copiedDirectories=[];
    $copiedTree=new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/run/media-uploads',FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($copiedTree as$item){
        if($item->isLink())throw new RuntimeException('PUBLIC_UPLOAD_LINK');$relative=substr($item->getPathname(),strlen('/run/media-uploads/'));
        if($item->isDir()){if(($item->getPerms()&0777)!==0755)throw new RuntimeException('PUBLIC_UPLOAD_DIRECTORY_MODE');$copiedDirectories[]=$relative;}
        elseif($item->isFile())$copiedFiles[]=$relative;else throw new RuntimeException('PUBLIC_UPLOAD_KIND');
    }
    sort($uploadFiles,SORT_STRING);sort($uploadDirectories,SORT_STRING);sort($copiedFiles,SORT_STRING);sort($copiedDirectories,SORT_STRING);
    if($uploadFiles!==$copiedFiles||$uploadDirectories!==$copiedDirectories)throw new RuntimeException('PUBLIC_UPLOAD_EXACT_TRACKED_SUBTREE');
    $result=['schema'=>1,'status'=>'COPIED_VERIFIED_REAL_COMMITTED_SOURCE','source_sha'=>$manifest['source_sha'],
        'tree_sha'=>$manifest['tree_sha'],'source_manifest_sha256'=>hash_file('sha256',$input.'/SOURCE-TREE.json'),
        'file_count'=>count($files),'directory_count'=>count($directories),'total_bytes'=>$manifest['total_bytes'],
        'archive_sha256'=>$manifest['archive']['sha256'],'runtime_empty_mount_parents'=>$parents,
        'uploads_seed'=>['source'=>'ONLY_COMMITTED_PUBLIC_UPLOADS_SUBTREE','file_count'=>count($uploadFiles),
            'directory_count'=>count($uploadDirectories),'total_bytes'=>$uploadBytes,'all_bytes_hashes_and_namespace_verified'=>true],
        'public_uploads_uid'=>posix_geteuid(),'public_uploads_gid'=>posix_getegid(),
        'complete_tracked_source_bytes_verified'=>true,'ordinary_source_writer_exit'=>'THIS_PROCESS_MUST_NATURALLY_EXIT0_AND_BE_REMOVED_BEFORE_APP'];
    $bytes=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $handle=fopen('/run/audit/source-tree.json','xb');if(!is_resource($handle)||fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle)||!fclose($handle))throw new RuntimeException('SOURCE_RECEIPT');
    echo json_encode(['status'=>$result['status'],'file_count'=>count($files),'directory_count'=>count($directories)],JSON_THROW_ON_ERROR)."\n";
    exit(0);
}catch(Throwable $error){
    echo json_encode(['status'=>'SOURCE_COPY_FAILED','class'=>get_class($error),'code'=>$error instanceof RuntimeException?$error->getMessage():'SOURCE_COPY_ERROR'],JSON_THROW_ON_ERROR)."\n";exit(1);
}
