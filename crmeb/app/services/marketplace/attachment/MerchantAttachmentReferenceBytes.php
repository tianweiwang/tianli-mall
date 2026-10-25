<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

/** Internal reads of an already located commercial object, never a URL authority. */
final class MerchantAttachmentReferenceBytes
{
    private const STAT=['dev','ino','mode','nlink','uid','gid','size','mtime','ctime'];

    public static function local(array $file,int $maximum,?int $expectedSize): string
    {
        $path=$file['path'];clearstatcache(true,$path);$before=lstat($path);
        if(!is_array($before)||is_link($path)||($before['mode']&0170000)!==0100000
            ||!is_int($before['size'])||$before['size']<1||$before['size']>$maximum
            ||($expectedSize!==null&&$before['size']!==$expectedSize))self::fail();
        foreach(self::STAT as$key)if(!isset($file['stat'][$key])||$file['stat'][$key]!==$before[$key])self::fail();
        $input=fopen($path,'rb');if(!is_resource($input))self::fail();$bytes='';
        try{
            $fd=fstat($input);foreach(self::STAT as$key)if(!is_array($fd)||$fd[$key]!==$before[$key])self::fail();
            while(!feof($input)){
                $chunk=fread($input,262144);if(!is_string($chunk)||($chunk===''&&!feof($input)))self::fail();
                $length=strlen($chunk);if($length>$maximum-strlen($bytes))self::fail();
                self::room(strlen($bytes)+$length);$bytes.=$chunk;
            }
            if(strlen($bytes)!==$before['size']||fread($input,1)!=='')self::fail();
            $afterFd=fstat($input);clearstatcache(true,$path);$after=lstat($path);
            foreach(self::STAT as$key)if(!is_array($afterFd)||!is_array($after)||is_link($path)
                ||$afterFd[$key]!==$before[$key]||$after[$key]!==$before[$key])self::fail();
        }finally{fclose($input);}
        return $bytes;
    }

    /** Original body-qualified GET; signing remains the same finite provider leaf. */
    public static function cloud(array $object,#[\SensitiveParameter] array $credential,string $canonical,int $maximum,?int $expectedSize,?string &$servedMime=null): string
    {
        $servedMime=null;if($maximum<1)self::fail();
        try{[$url,$headers]=self::signedCloudGet($object,$credential,$canonical);
            return self::get($url,$headers,$maximum,$expectedSize,$servedMime);
        }catch(\Throwable $error){self::fail();}
    }

    /** Origin presence only. A 404 does not prove that an unexpired/in-flight upload can never arrive. */
    public static function cloudPresence(array $object,#[\SensitiveParameter] array $credential,string $canonical): string
    {
        [$url,$headers]=self::signedCloudGet($object,$credential,$canonical);
        $parts=parse_url($url);if(!is_array($parts)||($parts['scheme']??null)!=='https')self::fail();
        $lines=['Accept-Encoding: identity','Range: bytes=0-0'];
        foreach($headers as$k=>$v){if(!is_string($k)||!is_string($v)||preg_match('/[\r\n\x00]/',$k.$v))self::fail();$lines[]=$k.': '.$v;}
        $c=curl_init();if(!$c instanceof \CurlHandle)self::fail();$status=null;$headerBytes=0;$bad=false;$stopped=false;$bodyBytes=0;
        try{
            if(curl_setopt_array($c,[CURLOPT_URL=>$url,CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$lines,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
                CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_HTTPAUTH=>CURLAUTH_NONE,CURLOPT_UNRESTRICTED_AUTH=>false,
                CURLOPT_CONNECTTIMEOUT_MS=>5000,CURLOPT_TIMEOUT_MS=>5000,CURLOPT_NOSIGNAL=>true,
                CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$status,&$headerBytes,&$bad):int{
                    $n=strlen($line);$headerBytes+=$n;if($headerBytes>65536){$bad=true;return 0;}
                    if(preg_match('~\AHTTP/(?:1\.[01]|2|3) ([1-5][0-9]{2})(?: .*)?\r?\n\z~D',$line,$m)===1)$status=(int)$m[1];return $n;
                },
                CURLOPT_WRITEFUNCTION=>static function($c,string $bytes)use(&$status,&$stopped,&$bodyBytes):int{
                    if(in_array($status,[200,206],true)){$stopped=true;return 0;}
                    $bodyBytes+=strlen($bytes);return $bodyBytes<=65536?strlen($bytes):0;
                }])!==true)self::fail();
            $ok=curl_exec($c);$errno=curl_errno($c);$actual=curl_getinfo($c,CURLINFO_RESPONSE_CODE);
            if($bad||$actual!==$status)self::fail();
            if(in_array($status,[200,206],true)&&(($ok===true&&$errno===0)||($stopped&&$errno===CURLE_WRITE_ERROR)))return 'present';
            if($ok===true&&$errno===0&&$status===404)return 'absent';
            self::fail();
        }finally{curl_close($c);}
    }

    /** Config, bucket/key and canonical have already been derived from locateObject. */
    private static function signedCloudGet(array $object,#[\SensitiveParameter] array $credential,string $canonical): array
    {
        if(!in_array($object['type'],[2,3,4,5,6,7],true)
            ||!in_array($canonical,$object['canonical'],true))self::fail();
        $storage=$object['storage'];$key=$object['key'];
        if($credential['accessKey']!==$storage['access_key']||!str_ends_with($canonical,'/'.$key))self::fail();
        $base=substr($canonical,0,-strlen('/'.$key));$parts=parse_url($canonical);
        if(!is_array($parts)||!in_array($parts['scheme']??null,['http','https'],true)
            ||!is_string($parts['host']??null)||$parts['host']===''||isset($parts['user'])||isset($parts['pass'])
            ||isset($parts['query'])||isset($parts['fragment']))self::fail();
        $origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        $host=$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');$url=$canonical;$headers=[];
        try{
            switch($object['type']){
                case 2:
                    // Installed Qiniu/Auth.php::privateDownloadUrl, no network in signing.
                    $url=(new \Qiniu\Auth($credential['accessKey'],$credential['secretKey']))->privateDownloadUrl($canonical,120);
                    break;
                case 3:
                    // Installed OSS supports CNAME/IP endpoints. The canonical bucket/key
                    // is signed; only the generated query is attached to the exact alias.
                    $client=new \OSS\OssClient($credential['accessKey'],$credential['secretKey'],$origin,true);
                    $client->setUseSSL($parts['scheme']==='https');
                    $signed=$client->signUrl($storage['name'],$key,120,\OSS\OssClient::OSS_HTTP_GET);
                    $query=parse_url($signed,PHP_URL_QUERY);if(!is_string($query)||$query==='')self::fail();
                    $url=$canonical.'?'.$query;
                    break;
                case 4:
                    // Reuse the installed COS canonical/signing code, without its
                    // network/XML reader or its binary trim/SSL defaults.
                    $zone=date_default_timezone_get();
                    try{
                        $client=new \crmeb\services\upload\extend\cos\Client([
                            'accessKey'=>$credential['accessKey'],'secretKey'=>$credential['secretKey'],
                            'appid'=>$credential['appid']??'','bucket'=>$storage['name'],
                            'region'=>$storage['region'],'uploadUrl'=>$base]);
                        $headers=$client->getSign($host,'GET',$parts['path'],[],['Host'=>$host]);
                    }finally{date_default_timezone_set($zone);}
                    $headers['Host']=$host;
                    break;
                case 5:
                    // Installed AWS S3Client bucket_endpoint is the supported CNAME
                    // mode. Static credentials avoid an ENV/metadata credential chain.
                    $url=\crmeb\compat\AwsErrorCompatibility::run(static function()use($storage,$credential,$base,$parts,$key):string{
                        $client=new \Aws\S3\S3Client(['version'=>'latest','region'=>$storage['region'],
                            'endpoint'=>$base,'bucket_endpoint'=>true,'use_path_style_endpoint'=>false,
                            'signature_version'=>'v4','credentials'=>['key'=>$credential['accessKey'],'secret'=>$credential['secretKey']]]);
                        $command=$client->getCommand('GetObject',['Bucket'=>$storage['name'],'Key'=>$key]);
                        $request=$client->createPresignedRequest($command,'+120 seconds');$url=(string)$request->getUri();
                        // Do not follow a signed request to an unrelated object/endpoint.
                        $signed=parse_url($url);
                        if(!is_array($signed)||($signed['scheme']??null)!==$parts['scheme']
                            ||($signed['host']??null)!==$parts['host']||($signed['port']??null)!==($parts['port']??null)
                            ||rawurldecode($signed['path']??'')!==rawurldecode($parts['path']))self::fail();
                        return $url;
                    });
                    break;
                case 6: case 7:
                    // Same OBS canonical method and HMAC used by installed
                    // extend/obs/Client.php:537-550, with its exact bucket/key.
                    $client=new \crmeb\services\upload\extend\obs\Client([
                        'accessKey'=>$credential['accessKey'],'secretKey'=>$credential['secretKey'],
                        'region'=>$storage['region'],'bucket'=>$storage['name'],'uploadUrl'=>$base,
                        'type'=>$object['type']===6?'hw':'ty']);
                    $headers=['Host'=>$host,'Date'=>gmdate('D, d M Y H:i:s \G\M\T')];
                    $canonicalRequest=$client->makeCanonicalstring('GET',$headers,[],$storage['name'],$key);
                    $headers['Authorization']='OBS '.$credential['accessKey'].':'.base64_encode(hash_hmac('sha1',$canonicalRequest,$credential['secretKey'],true));
                    break;
                default:self::fail();
            }
            return [$url,$headers];
        }catch(\Throwable $error){self::fail();}
    }

    /** Single configured object; internal/private endpoints are intentional here. */
    private static function get(#[\SensitiveParameter] string $url,#[\SensitiveParameter] array $headers,int $maximum,?int $expectedSize,?string &$servedMime=null): string
    {
        $servedMime=null;
        if(PHP_INT_SIZE<8||!extension_loaded('curl')||$maximum<1)self::fail();
        $parts=parse_url($url);
        if(!is_array($parts)||!in_array($parts['scheme']??null,['http','https'],true)
            ||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))self::fail();
        $lines=['Accept: */*','Accept-Encoding: identity'];
        foreach($headers as$key=>$value){
            if(!is_string($key)||!is_string($value)||preg_match('/[\r\n\x00]/',$key.$value))self::fail();
            $lines[]=$key.': '.$value;
        }
        $handle=curl_init();if(!$handle instanceof \CurlHandle)self::fail();
        $bytes='';$headerBytes=0;$status=null;$bad=false;
        $mimeSeen=false;$mimeInvalid=false;$mimeValue=null;
        try{
            $options=[CURLOPT_URL=>$url,CURLOPT_HTTPGET=>true,CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
                CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_HTTPAUTH=>CURLAUTH_NONE,CURLOPT_UNRESTRICTED_AUTH=>false,
                CURLOPT_CONNECTTIMEOUT_MS=>5000,CURLOPT_TIMEOUT_MS=>60000,CURLOPT_NOSIGNAL=>true,CURLOPT_HTTPHEADER=>$lines,
                CURLOPT_HEADERFUNCTION=>static function(\CurlHandle $curl,string $line)use(&$headerBytes,&$status,&$bad,&$mimeSeen,&$mimeInvalid,&$mimeValue):int{
                    $length=strlen($line);$headerBytes+=$length;if($headerBytes>65536){$bad=true;return 0;}
                    $text=rtrim($line,"\r\n");
                    if(preg_match('~\AHTTP/(?:1\.[01]|2|3) ([1-5][0-9]{2})(?: .*)?\z~D',$text,$match)===1){
                        $status=(int)$match[1];$mimeSeen=false;$mimeInvalid=false;$mimeValue=null;return $length;
                    }
                    if($text==='')return $length;
                    if($status===null||!str_contains($text,':')||$text[0]===' '||$text[0]==="\t"){$bad=true;return 0;}
                    [$name,$value]=explode(':',$text,2);
                    if(strtolower($name)==='content-type'){
                        // Observation never changes the old body-only failure gate.
                        if($mimeSeen){$mimeInvalid=true;$mimeValue=null;}
                        else{
                            $mimeSeen=true;$mimeValue=self::responseMime($value);
                            if($mimeValue===null)$mimeInvalid=true;
                        }
                    }
                    if(strtolower($name)==='content-encoding'&&strcasecmp(trim($value),'identity')!==0){$bad=true;return 0;}
                    return $length;
                },
                CURLOPT_WRITEFUNCTION=>static function(\CurlHandle $curl,string $chunk)use(&$bytes,$maximum,&$bad):int{
                    $length=strlen($chunk);if($length>$maximum-strlen($bytes)){$bad=true;return 0;}
                    try{self::room(strlen($bytes)+$length);}catch(\Throwable $error){$bad=true;return 0;}
                    $bytes.=$chunk;return $length;
                }];
            if(curl_setopt_array($handle,$options)!==true)self::fail();
            $completed=curl_exec($handle);$info=curl_getinfo($handle);$errno=curl_errno($handle);
            if($completed!==true||$errno!==0||$bad||!is_array($info)||$status!==200||($info['http_code']??null)!==200
                ||$bytes===''||($expectedSize!==null&&strlen($bytes)!==$expectedSize))self::fail();
            $servedMime=$mimeSeen&&!$mimeInvalid?$mimeValue:null;
            return $bytes;
        }finally{curl_close($handle);}
    }

    /** HTTP media-type essence; invalid/ambiguous observations are not content failures. */
    private static function responseMime(string $value): ?string
    {
        $token='[!#$%&\'*+.^_`|~0-9A-Za-z-]+';
        $quoted='"(?:[\x09\x20\x21\x23-\x5B\x5D-\x7E\x80-\xFF]|\\\\[\x09\x20-\x7E\x80-\xFF])*"';
        $pattern='@\A[ \t]*('.$token.'/'.$token.')(?:[ \t]*;[ \t]*'.$token.'[ \t]*=[ \t]*(?:'.$token.'|'.$quoted.'))*[ \t]*\z@D';
        return preg_match($pattern,$value,$match)===1?strtolower($match[1]):null;
    }

    /** Actual positive process memory, not a replacement fixed media-size cap. */
    public static function room(int $bytes,int $pixels=0): void
    {
        $value=trim((string)ini_get('memory_limit'));
        if($bytes<0||$pixels<0||preg_match('/\A([1-9][0-9]*)([KMG]?)\z/iD',$value,$m)!==1)self::fail();
        $unit=match(strtoupper($m[2])){'K'=>1024,'M'=>1048576,'G'=>1073741824,default=>1};
        $ceiling=(string)intdiv(PHP_INT_MAX,$unit);
        if(strlen($m[1])>strlen($ceiling)||(strlen($m[1])===strlen($ceiling)&&strcmp($m[1],$ceiling)>0))self::fail();
        $limit=(int)$m[1]*$unit;$used=memory_get_usage(true);$reserve=16777216;
        if($bytes>intdiv(PHP_INT_MAX-$reserve,2))self::fail();$extra=$bytes*2+$reserve;
        if($used<0||$limit<=$used||$extra>$limit-$used||$pixels>intdiv($limit-$used-$extra,32))self::fail();
    }
    private static function fail(): never { MerchantAttachmentInput::unavailable(); }
}
