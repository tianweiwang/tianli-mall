<?php
declare(strict_types=1);
require __DIR__.'/lib/harness.php';
try{
    audit_guard_assert();audit_expect(getenv('AUDIT_MEDIA_NORMAL')==='1','normal media mode required');
    $action=$argv[1]??'';audit_expect(in_array($action,['start','observe','stop'],true)&&count($argv)===2,'normal control action unavailable');
    if($action==='start'){
        $prepared=json_decode(file_get_contents(audit_run_dir().'/media-preparation.json'),true,512,JSON_THROW_ON_ERROR);
        $proof=json_decode(file_get_contents(audit_run_dir().'/media-ms2-final-check.json'),true,512,JSON_THROW_ON_ERROR);
        audit_expect(array_keys($prepared)===['schema','run_id','source_sha','self_operated_mer_id','upload_max_bytes','status']
            &&$prepared['schema']===1&&$prepared['run_id']===getenv('AUDIT_RUN_ID')&&$prepared['source_sha']===getenv('AUDIT_SOURCE_SHA')
            &&$prepared['status']==='PREPARED_NORMAL_MEDIA_SCHEMA_AND_ACTORS','normal media preparation unavailable');
        $self=$prepared['self_operated_mer_id'];audit_expect(is_int($self)&&$self>0&&$self<=4294967295,'normal actual SELF unavailable');
        audit_expect(array_keys($proof)===['schema','run_id','source_sha','self_operated_mer_id','actual_cli_receipt','native_exit','cli_control']
            &&$proof['schema']===1&&$proof['run_id']===$prepared['run_id']&&$proof['source_sha']===$prepared['source_sha']
            &&$proof['self_operated_mer_id']===$self&&$proof['native_exit']===0,'normal actual MS2 proof unavailable');
        $actual=$proof['actual_cli_receipt'];
        audit_expect(is_array($actual)&&$actual['state']==='completed'&&$actual['native_exit']===0&&$actual['enabled']===false
            &&$actual['ownership_phase']==='enforced'&&$actual['migration_complete']===true&&$actual['self_mer_id']===$self,'normal MS2 final check incomplete');
        $upload=require audit_repo_dir().'/crmeb/config/upload.php';audit_expect($prepared['upload_max_bytes']===$upload['filesize'],'normal actual upload cap changed');
        $statement=audit_db()->prepare('SELECT mer_id,review_status,security_locked,closed_at FROM eb_marketplace_merchant WHERE mer_id=?');
        $statement->execute([$self]);$rows=$statement->fetchAll(PDO::FETCH_ASSOC);$statement->closeCursor();
        audit_expect(count($rows)===1&&(int)$rows[0]['mer_id']===$self&&(int)$rows[0]['review_status']===1
            &&(int)$rows[0]['security_locked']===0&&$rows[0]['closed_at']===null,'normal SELF row differs');
        require_once audit_repo_dir().'/help/audit-regressions/isolated/lib/media-normal-profile.php';
        $url=audit_app_start(audit_media_normal_overrides());
        $configuration=json_decode(file_get_contents(audit_run_dir().'/media-upload-configuration.json'),true,512,JSON_THROW_ON_ERROR);
        $observed=[];
        foreach(glob(audit_run_dir().'/media-server-ini-*.json')as$file){
            $value=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
            audit_expect($value['run_id']===$prepared['run_id']&&$value['source_sha']===$prepared['source_sha']
                &&$value['status']==='OBSERVED_REAL_NORMAL_CLI_SERVER_REQUEST'&&$value['sapi']==='cli-server'
                &&is_int($value['pid'])&&$value['pid']>0,'normal actual SAPI observation differs');
            foreach(['upload_max_filesize','post_max_size','memory_limit']as$key)audit_expect($value[$key]===$configuration[$key],'normal child INI did not inherit');
            $observed[]=['file'=>basename($file),'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file),'pid'=>$value['pid']];
        }
        audit_expect($observed!==[],'normal real HTTP INI observation missing');
        audit_write_json(audit_run_dir().'/media-server-start.json',['schema'=>1,'status'=>'NORMAL_SERVER_STARTED_REAL_HTTP_INI_OBSERVED',
            'run_id'=>$prepared['run_id'],'source_sha'=>$prepared['source_sha'],'url'=>$url,'self_operated_mer_id'=>$self,
            'observations'=>$observed,'large_file_boundary_acceptance'=>false]);
    }elseif($action==='observe')audit_app_request('observe');
    else audit_app_stop();
    echo json_encode(['status'=>'NORMAL_APP_CONTROL_COMPLETE','action'=>$action],JSON_THROW_ON_ERROR)."\n";exit(0);
}catch(Throwable $error){echo json_encode(['status'=>'NORMAL_APP_CONTROL_FAILED','action'=>$argv[1]??'','class'=>get_class($error)],JSON_THROW_ON_ERROR)."\n";exit(1);}
