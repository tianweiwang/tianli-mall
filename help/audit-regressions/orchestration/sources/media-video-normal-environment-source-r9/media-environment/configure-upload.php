<?php
declare(strict_types=1);
require __DIR__.'/lib/harness.php';
try{
    audit_guard_assert();
    audit_expect(getenv('AUDIT_MEDIA_NORMAL')==='1','normal media mode required');
    $upload=require audit_repo_dir().'/crmeb/config/upload.php';$maximum=$upload['filesize']??null;
    audit_expect(is_int($maximum)&&$maximum>0&&$maximum<=intdiv(PHP_INT_MAX-268435456,8),'actual Source upload limit unavailable');
    $directory='/run/audit-app/media-ini';audit_expect(!file_exists($directory),'normal INI already exists');
    audit_expect(mkdir($directory,0700),'normal INI directory unavailable');
    $memory=max(1073741824,$maximum*8+268435456);
    $ini='upload_max_filesize='.$maximum."\n".'post_max_size='.($maximum+1048576)."\n"
        .'memory_limit='.$memory."\n".'auto_prepend_file=/media-environment/observe-ini.php'."\n";
    $file=$directory.'/99-media-normal.ini';audit_expect(file_put_contents($file,$ini)===strlen($ini)&&chmod($file,0600),'normal INI write failed');
    audit_write_json(audit_run_dir().'/media-upload-configuration.json',['schema'=>1,'run_id'=>getenv('AUDIT_RUN_ID'),
        'source_sha'=>getenv('AUDIT_SOURCE_SHA'),'Config_upload_filesize'=>$maximum,'post_allowance_bytes'=>1048576,
        'upload_max_filesize'=>(string)$maximum,'post_max_size'=>(string)($maximum+1048576),'memory_limit'=>(string)$memory,
        'INI_sha256'=>hash('sha256',$ini),'ini_reaches_actual_cli_server'=>'PENDING_REAL_HTTP_OBSERVATION',
        'business_upload_limit_changed'=>false,'large_file_boundary_acceptance'=>false]);
    echo json_encode(['status'=>'CONFIGURED_NORMAL_UPLOAD_INI','upload_max_bytes'=>$maximum],JSON_THROW_ON_ERROR)."\n";exit(0);
}catch(Throwable $error){echo json_encode(['status'=>'NORMAL_INI_PREPARATION_FAILED','class'=>get_class($error)],JSON_THROW_ON_ERROR)."\n";exit(1);}
