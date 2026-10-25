<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

/** Finite provider operations for one server-allocated temporary video, not an owner authority. */
final class ProductVideoCloudUploadSigner
{
    public static function endpoint(string $url): string
    {
        $p=parse_url($url);
        if(!is_array($p)||($p['scheme']??null)!=='https'||!is_string($p['host']??null)||$p['host']===''
            ||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])
            ||!in_array($p['path']??'',['','/'],true))MerchantAttachmentInput::unavailable();
        return rtrim($url,'/');
    }
    private static function client(array $storage,#[\SensitiveParameter] array $credential): \Aws\S3\S3Client
    {
        return new \Aws\S3\S3Client(['version'=>'latest','region'=>$storage['region'],
            'endpoint'=>self::endpoint($storage['domain']),'bucket_endpoint'=>true,'use_path_style_endpoint'=>false,
            'signature_version'=>'v4','credentials'=>['key'=>$credential['accessKey'],'secret'=>$credential['secretKey']],
            'http'=>['timeout'=>5,'connect_timeout'=>5,'allow_redirects'=>false,'verify'=>true,'proxy'=>'']]);
    }
    public static function issue(array $object,#[\SensitiveParameter] array $credential,int $size,int $expires,string $qiniuUpload): array
    {
        $type=$object['type'];$storage=$object['storage'];$key=$object['key'];$origin=self::endpoint($storage['domain']);
        if(!in_array($type,[2,3,4,5,6,7],true)||$size<1||$expires<=time()
            ||$credential['accessKey']!==$storage['access_key'])MerchantAttachmentInput::unavailable();
        $mime='video/mp4';$fields=[];$headers=[];$transport='form';$url=$origin;
        if($type===2){
            $remaining=$expires-time();if($remaining<1)MerchantAttachmentInput::unavailable();
            $url=self::endpoint($qiniuUpload);
            $token=(new \Qiniu\Auth($credential['accessKey'],$credential['secretKey']))->uploadToken($storage['name'],$key,$remaining,
                ['isPrefixalScope'=>0,'insertOnly'=>1,'fsizeMin'=>$size,'fsizeLimit'=>$size,'mimeLimit'=>$mime,'detectMime'=>1],true);
            if(!is_string($token)||$token==='')MerchantAttachmentInput::unavailable();
            $parts=explode(':',$token,3);$raw=count($parts)===3?base64_decode(strtr($parts[2],'-_','+/'),true):false;
            $policy=is_string($raw)?json_decode($raw,true,16,JSON_THROW_ON_ERROR):null;
            if(!is_array($policy)||($policy['scope']??null)!==$storage['name'].':'.$key
                ||!is_int($policy['deadline']??null)||$policy['deadline']> $expires||$policy['deadline']<=time()
                ||($policy['isPrefixalScope']??null)!==0||($policy['insertOnly']??null)!==1
                ||($policy['fsizeMin']??null)!==$size||($policy['fsizeLimit']??null)!==$size||($policy['mimeLimit']??null)!==$mime)MerchantAttachmentInput::unavailable();
            $fields=['key'=>$key,'token'=>$token];
        }elseif(in_array($type,[3,6,7],true)){
            // Existing provider POST policy/HMAC, with equalities rather than the legacy broad prefix.
            $policy=base64_encode(json_encode(['expiration'=>gmdate('Y-m-d\TH:i:s\Z',$expires),
                'conditions'=>[['bucket'=>$storage['name']],['key'=>$key],['Content-Type'=>$mime],
                    ['content-length-range',$size,$size],['success_action_status'=>'200']]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $signature=base64_encode(hash_hmac('sha1',$policy,$credential['secretKey'],true));
            $fields=['key'=>$key,'Content-Type'=>$mime,'success_action_status'=>'200','policy'=>$policy,'signature'=>$signature];
            if($type===3){$fields['OSSAccessKeyId']=$credential['accessKey'];$fields['Signature']=$fields['signature'];unset($fields['signature']);}
            else $fields['AccessKeyId']=$credential['accessKey'];
        }elseif($type===4){
            $url=$origin.'/'.$key;$parts=parse_url($url);$host=$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
            $signed=['Host'=>$host,'Content-Type'=>$mime,'Content-Length'=>(string)$size];$zone=date_default_timezone_get();
            try{$signer=new \crmeb\services\upload\extend\cos\Signature($credential['accessKey'],$credential['secretKey'],['signHost'=>true]);
                $authorization=$signer->createAuthorization('PUT',$parts['path'],[],$signed,'@'.$expires);
            }finally{date_default_timezone_set($zone);}
            // The browser supplies its actual File length. It must never forge the forbidden Content-Length header.
            $transport='put';$headers=['Content-Type'=>$mime,'Authorization'=>$authorization];
        }elseif($type===5){
            $fields=\crmeb\compat\AwsErrorCompatibility::run(static function()use($storage,$credential,$key,$size,$mime,$expires):array{
                $post=new \Aws\S3\PostObjectV4(self::client($storage,$credential),$storage['name'],
                    ['key'=>$key,'Content-Type'=>$mime,'success_action_status'=>'200'],
                    [['bucket'=>$storage['name']],['key'=>$key],['Content-Type'=>$mime],['content-length-range',$size,$size],['success_action_status'=>'200']],
                    $expires);
                // PostObjectV4's generic host builder does not honor bucket_endpoint CNAME mode.
                $post->setFormAttribute('action',self::endpoint($storage['domain']));
                if($post->getFormAttributes()['action']!==self::endpoint($storage['domain']))MerchantAttachmentInput::unavailable();
                return $post->getFormInputs();
            });
        }
        return ['provider'=>$type,'transport'=>$transport,'url'=>$url,'fields'=>(object)$fields,'headers'=>(object)$headers,
            'byte_size'=>$size,'content_type'=>$mime,'expires_at'=>$expires];
    }
    /** Only a temporary server key; the caller keeps unknown outcomes and observes origin absence separately. */
    public static function remove(array $object,#[\SensitiveParameter] array $credential,string $qiniuRs): void
    {
        $type=$object['type'];$s=$object['storage'];$key=$object['key'];$origin=self::endpoint($s['domain']);
        if($credential['accessKey']!==$s['access_key'])MerchantAttachmentInput::unavailable();
        if($type===2){
            $url=self::endpoint($qiniuRs).'/delete/'.\Qiniu\base64_urlSafeEncode($s['name'].':'.$key);
            $headers=(new \Qiniu\Auth($credential['accessKey'],$credential['secretKey']))->authorizationV2($url,'POST',null,'application/x-www-form-urlencoded');
            $headers['Content-Type']='application/x-www-form-urlencoded';self::request($url,'POST',$headers);return;
        }
        if($type===3){
            self::request(self::ossDeleteUrl($s,$credential,$key),'DELETE',[]);return;
        }
        if($type===5){
            $url=\crmeb\compat\AwsErrorCompatibility::run(static function()use($s,$credential,$key,$origin):string{
                $client=self::client($s,$credential);$command=$client->getCommand('DeleteObject',['Bucket'=>$s['name'],'Key'=>$key]);
                $request=$client->createPresignedRequest($command,'+30 seconds');$url=(string)$request->getUri();
                if($request->getMethod()!=='DELETE')MerchantAttachmentInput::unavailable();self::sameObjectUrl($url,$origin,$key);
                $query=[];$raw=parse_url($url,PHP_URL_QUERY);
                if(!is_string($raw))MerchantAttachmentInput::unavailable();
                $allowed=['X-Amz-Content-Sha256','X-Amz-Algorithm','X-Amz-Credential','X-Amz-Date','X-Amz-Expires','X-Amz-SignedHeaders','X-Amz-Signature'];
                foreach(explode('&',$raw)as$piece){$pair=explode('=',$piece,2);if(count($pair)!==2)MerchantAttachmentInput::unavailable();
                    $name=rawurldecode($pair[0]);$value=rawurldecode($pair[1]);
                    if(!in_array($name,$allowed,true)||array_key_exists($name,$query)||$value===''||preg_match('/[\r\n\x00]/',$value))MerchantAttachmentInput::unavailable();$query[$name]=$value;
                }
                if(count($query)!==7||$query['X-Amz-SignedHeaders']!=='host'||$query['X-Amz-Content-Sha256']!=='UNSIGNED-PAYLOAD'
                    ||$query['X-Amz-Algorithm']!=='AWS4-HMAC-SHA256'||!str_starts_with($query['X-Amz-Credential'],$credential['accessKey'].'/')
                    ||$query['X-Amz-Expires']!=='30'||preg_match('/\A[0-9]{8}T[0-9]{6}Z\z/D',$query['X-Amz-Date'])!==1
                    ||preg_match('/\A[a-f0-9]{64}\z/D',$query['X-Amz-Signature'])!==1)MerchantAttachmentInput::unavailable();return $url;
            });
            self::request($url,'DELETE',[]);return;
        }
        $url=$origin.'/'.$key;$parts=parse_url($url);$host=$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        if($type===4){
            $zone=date_default_timezone_get();
            try{$signer=new \crmeb\services\upload\extend\cos\Signature($credential['accessKey'],$credential['secretKey'],['signHost'=>true]);
                $headers=['Host'=>$host,'Authorization'=>$signer->createAuthorization('DELETE',$parts['path'],[],['Host'=>$host],'+30 seconds')];
            }finally{date_default_timezone_set($zone);}
        }elseif($type===6||$type===7){
            $client=new \crmeb\services\upload\extend\obs\Client(['accessKey'=>$credential['accessKey'],'secretKey'=>$credential['secretKey'],
                'region'=>$s['region'],'bucket'=>$s['name'],'uploadUrl'=>$origin,'type'=>$type===6?'hw':'ty']);
            $headers=['Host'=>$host,'Date'=>gmdate('D, d M Y H:i:s \G\M\T')];
            $canonical=$client->makeCanonicalstring('DELETE',$headers,[],$s['name'],$key);
            $headers['Authorization']='OBS '.$credential['accessKey'].':'.base64_encode(hash_hmac('sha1',$canonical,$credential['secretKey'],true));
        }else MerchantAttachmentInput::unavailable();
        self::request($url,'DELETE',$headers);
    }
    private static function sameObjectUrl(#[\SensitiveParameter] string $url,string $origin,string $key): void
    {
        $actual=parse_url($url);$wanted=parse_url(self::endpoint($origin));
        if(!is_array($actual)||!is_array($wanted)||($actual['scheme']??null)!=='https'
            ||($actual['host']??null)!==$wanted['host']||($actual['port']??null)!==($wanted['port']??null)
            ||($actual['path']??null)!=='/'.$key||isset($actual['user'])||isset($actual['pass'])||isset($actual['fragment']))MerchantAttachmentInput::unavailable();
    }
    /** Installed OSS exposes only GET/PUT presigning. Verify its exact GET canonical before changing the fixed verb. */
    private static function ossDeleteUrl(array $s,#[\SensitiveParameter] array $credential,string $key): string
    {
        if(preg_match('/\A[A-Za-z0-9_.-]+\z/D',$s['name'])!==1
            ||preg_match('~\Auploads/merchant/[1-9][0-9]*/video-staging/[a-f0-9]{32}/[a-f0-9]{32}\.mp4\z~D',$key)!==1)MerchantAttachmentInput::unavailable();
        $origin=self::endpoint($s['domain']);$client=new \OSS\OssClient($credential['accessKey'],$credential['secretKey'],$origin,true);$client->setUseSSL(true);
        $signed=$client->signUrl($s['name'],$key,30,\OSS\OssClient::OSS_HTTP_GET,[\OSS\OssClient::OSS_CONTENT_TYPE=>'']);
        self::sameObjectUrl($signed,$origin,$key);$raw=parse_url($signed,PHP_URL_QUERY);$fields=[];
        if(!is_string($raw)||$raw==='')MerchantAttachmentInput::unavailable();
        foreach(explode('&',$raw)as$part){$pair=explode('=',$part,2);
            if(count($pair)!==2)MerchantAttachmentInput::unavailable();$name=rawurldecode($pair[0]);$value=rawurldecode($pair[1]);
            if(!in_array($name,['OSSAccessKeyId','Expires','Signature'],true)||array_key_exists($name,$fields)
                ||$value===''||preg_match('/[\r\n\x00]/',$value))MerchantAttachmentInput::unavailable();$fields[$name]=$value;
        }
        if(count($fields)!==3||$fields['OSSAccessKeyId']!==$credential['accessKey']
            ||preg_match('/\A[1-9][0-9]{0,10}\z/D',$fields['Expires'])!==1
            ||(int)$fields['Expires']<=time()||(int)$fields['Expires']>time()+30)MerchantAttachmentInput::unavailable();
        $resource='/'.$s['name'].'/'.$key;$suffix="\n\n\n".$fields['Expires']."\n".$resource;
        $get=base64_encode(hash_hmac('sha1','GET'.$suffix,$credential['secretKey'],true));
        if(!hash_equals($get,$fields['Signature']))MerchantAttachmentInput::unavailable();
        $delete=base64_encode(hash_hmac('sha1','DELETE'.$suffix,$credential['secretKey'],true));
        return $origin.'/'.$key.'?OSSAccessKeyId='.rawurlencode($fields['OSSAccessKeyId']).'&Expires='.$fields['Expires'].'&Signature='.rawurlencode($delete);
    }
    private static function request(#[\SensitiveParameter] string $url,string $method,#[\SensitiveParameter] array $headers): void
    {
        $lines=[];foreach($headers as$k=>$v){if(!is_string($k)||!is_string($v)||preg_match('/[\r\n\x00]/',$k.$v))MerchantAttachmentInput::unavailable();$lines[]=$k.': '.$v;}
        $c=curl_init();if(!$c instanceof \CurlHandle)MerchantAttachmentInput::unavailable();$count=0;
        try{
            if(curl_setopt_array($c,[CURLOPT_URL=>$url,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$lines,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
                CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_HTTPAUTH=>CURLAUTH_NONE,CURLOPT_UNRESTRICTED_AUTH=>false,
                CURLOPT_CONNECTTIMEOUT_MS=>5000,CURLOPT_TIMEOUT_MS=>5000,CURLOPT_NOSIGNAL=>true,
                CURLOPT_WRITEFUNCTION=>static function($c,string $b)use(&$count):int{$count+=strlen($b);return $count<=65536?strlen($b):0;}])!==true)MerchantAttachmentInput::unavailable();
            $ok=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);
            if($ok!==true||curl_errno($c)!==0||!is_int($code)||$code<200||$code>=300)MerchantAttachmentInput::unavailable();
        }finally{curl_close($c);}
    }
}
