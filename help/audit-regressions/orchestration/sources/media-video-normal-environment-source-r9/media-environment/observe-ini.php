<?php
declare(strict_types=1);
// Normal-only measurement on the actual cli-server request. It never reads a
// URL/header/body/actor, changes a route or response, or creates authentication.
if(PHP_SAPI==='cli-server'){
    try{
        $manifest=json_decode(file_get_contents('/run/audit/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if(($manifest['commit_sha']??null)===json_decode(file_get_contents('/media-environment/source-binding.json'),true,512,JSON_THROW_ON_ERROR)['source_sha']
            &&preg_match('/\A[0-9]{14}[a-f0-9]{6}\z/D',$manifest['run_id']??'')===1){
            $result=['schema'=>1,'status'=>'OBSERVED_REAL_NORMAL_CLI_SERVER_REQUEST','run_id'=>$manifest['run_id'],
                'source_sha'=>$manifest['commit_sha'],'pid'=>getmypid(),'sapi'=>PHP_SAPI,
                'upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size'),'memory_limit'=>ini_get('memory_limit')];
            $path='/run/audit/media-server-ini-'.getmypid().'.json';
            if(!file_exists($path)){
                $bytes=json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
                $handle=@fopen($path,'xb');if(is_resource($handle)){@fwrite($handle,$bytes);@fflush($handle);@fclose($handle);}
            }
        }
    }catch(Throwable $ignored){/* Missing/invalid measurement fails the test's readback, never the request. */}
}
