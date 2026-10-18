<?php
declare(strict_types=1);

/** Independent ordinary UI backend. Importing does not read proofs or launch an application. */
require_once __DIR__.'/lib/ms4-ui-backend-normal.php';

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('zend.exception_ignore_args','1');umask(0077);
    $GLOBALS['m4uib_assertions']=0;$GLOBALS['m4uib_terminate_requested']=false;
    $GLOBALS['m4uib_state']=['phase'=>'source','source'=>null,'process'=>null,'group'=>null,'supervisor'=>null,
        'sent'=>false,'attempted'=>false,'logs'=>[],'child'=>null,'ready'=>null,'stop_kind'=>null,
        'completed'=>false,'published'=>false,'source_unchanged'=>false,'primary'=>null,'cleanup'=>null];
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
        if((error_reporting()&$severity)===0)return false;
        $GLOBALS['m4uib_code']='PHP_WARNING';throw new ErrorException('M4UIB_PHP_WARNING',0,$severity,$file,$line);
    });
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM,static function():void{$GLOBALS['m4uib_terminate_requested']=true;});
    register_shutdown_function(static function():void{m4uib_shutdown();});
    try{exit(m4uib_serve());}
    catch(Throwable $error){
        $state=&$GLOBALS['m4uib_state'];$state['primary']=$state['primary']??m4uib_diagnostic($error);
        if(is_array($state['source'])&&isset($state['control_ready'])&&!$state['published']){
            try{m4uib_publish(M4UIB_CONTROL.'/ms4-ui-backend.json',m4uib_result($state['source'],false,$state['primary']));
                $state['published']=true;}
            catch(Throwable $ignored){$state['cleanup']=$state['cleanup']??['code'=>'RESULT_NOT_PUBLISHED'];}
        }
        echo json_encode(['scope'=>'NORMAL_MS4_UI_BACKEND_LIFECYCLE_ONLY','status'=>'INCOMPLETE_MS4_UI_BACKEND_LIFECYCLE_ONLY',
            'phase'=>$state['phase'],'diagnostic'=>$state['primary'],'entry_return_code'=>1,
            'native_exit'=>null,'native_exit_observation'=>'HOST_NATIVE_REQUIRED'],JSON_THROW_ON_ERROR)."\n";
        exit(1);
    }
}
