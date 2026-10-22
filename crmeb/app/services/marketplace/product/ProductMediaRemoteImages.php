<?php
declare(strict_types=1);
namespace app\services\marketplace\product;

use app\services\marketplace\attachment\MerchantAttachmentInput;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;
use think\exception\HttpException;
use think\facade\Config;

/** Product-only bounded byte fetcher. It never writes an object or attachment. */
final class ProductMediaRemoteImages
{
    private const TOTAL_BYTES=67108864;
    private const MAX_FILE_BYTES=52428800;
    private const BUDGET_NS=60000000000;

    /** Preserve source order and duplicates; nothing from a partial batch is returned. */
    public function fetch(array $urls): array
    {
        try{
            if(!array_is_list($urls)||count($urls)<1||count($urls)>50)MerchantAttachmentInput::invalid();
            $maximum=Config::get('upload.filesize');
            if(!is_int($maximum)||$maximum<1||$maximum>self::MAX_FILE_BYTES||PHP_INT_SIZE<8
                ||!extension_loaded('curl')||!function_exists('dns_get_record'))self::unavailable();
            $parsed=[];foreach($urls as$url)$parsed[]=$this->absolute($url);
            $start=hrtime(true);if(!is_int($start)||$start<1||$start>PHP_INT_MAX-self::BUDGET_NS)self::unavailable();
            $deadline=$start+self::BUDGET_NS;$total=0;$out=[];
            foreach($parsed as$index=>$uri){
                $bytes=$this->download($uri,$maximum,$total,$deadline);
                $out[]=['bytes'=>$bytes,'url_sha256'=>hash('sha256',$urls[$index])];
            }
            $this->remaining($deadline);return $out;
        }catch(HttpException|AuthException $error){throw $error;}
        catch(\Throwable $error){self::unavailable();}
    }

    private function raw(string $value): void
    {
        if($value===''||strlen($value)>2048||str_contains($value,'\\')||preg_match('/[\x00-\x20\x7f]/',$value)===1
            ||str_contains($value,'#')||preg_match('/%(?![0-9a-fA-F]{2})/',$value)===1)MerchantAttachmentInput::invalid();
    }
    private function absolute(mixed $value): UriInterface
    {
        if(!is_string($value))MerchantAttachmentInput::invalid();$this->raw($value);
        if(preg_match('~\Ahttps?://([^/?#]+)~iD',$value,$parts)!==1||str_contains($parts[1],'@'))MerchantAttachmentInput::invalid();
        $uri=new Uri($value);$scheme=$uri->getScheme();
        if(!in_array($scheme,['http','https'],true)||$uri->getUserInfo()!==''||$uri->getFragment()!==''
            ||$uri->getHost()==='')MerchantAttachmentInput::invalid();
        $this->host($uri->getHost());$port=$this->port($uri);
        if($port<1||$port>65535)MerchantAttachmentInput::invalid();
        return $uri;
    }
    private function host(string $host): string
    {
        if(str_starts_with($host,'[')||str_ends_with($host,']')){
            if(!str_starts_with($host,'[')||!str_ends_with($host,']'))MerchantAttachmentInput::invalid();
            $ip=substr($host,1,-1);
            if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)===false)MerchantAttachmentInput::invalid();
            return $ip;
        }
        if(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false)return $host;
        // Do not let cURL interpret an inet_aton number/hex spelling as a different target.
        if(preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/iD',$host)===1
            ||preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D',$host)!==1)
            MerchantAttachmentInput::invalid();
        return $host;
    }
    private function port(UriInterface $uri): int
    { return $uri->getPort()??($uri->getScheme()==='https'?443:80); }

    private function download(UriInterface $uri,int $maximum,int &$total,int $deadline): string
    {
        for($redirects=0;$redirects<=3;$redirects++){
            $this->remaining($deadline);
            $allowLocal=$this->siteUpload($uri);$host=$this->host($uri->getHost());
            $pin=$this->address($host,$allowLocal,$deadline);$this->remaining($deadline);
            $response=$this->get($uri,$host,$pin,$maximum,$total,$deadline);
            if($response['status']===200)return $response['bytes'];
            if($redirects===3||!in_array($response['status'],[301,302,303,307,308],true)
                ||$response['location']===null)self::unavailable();
            $location=$response['location'];$this->raw($location);
            // Reject credentials even in a scheme-relative Location before URI normalization.
            if(preg_match('~\A(?:https?:)?//([^/?#]*)~iD',$location,$authority)===1
                &&str_contains($authority[1],'@'))MerchantAttachmentInput::invalid();
            if(preg_match('/\A[a-z][a-z0-9+.-]*:/iD',$location)===1)$uri=$this->absolute($location);
            elseif(str_starts_with($location,'//'))$uri=$this->absolute($uri->getScheme().':'.$location);
            else $uri=$this->absolute((string)UriResolver::resolve($uri,new Uri($location)));
        }
        self::unavailable();
    }

    /** Only the actual configured site origin and canonical public /uploads path may be nonpublic. */
    private function siteUpload(UriInterface $uri): bool
    {
        $site=sys_config('site_url');
        if(!is_string($site)||$site===''||Config::get('filesystem.disks.public.type')!=='local'
            ||Config::get('filesystem.disks.public.url')!=='/uploads')return false;
        try{$origin=$this->absolute($site);}catch(\Throwable $error){return false;}
        $root=Config::get('filesystem.disks.public.root');
        if(!is_string($root)||realpath($root)===false||!is_dir($root)||$this->origin($origin)!==$this->origin($uri))return false;
        $path=$uri->getPath();if(!str_starts_with($path,'/uploads/')||strlen($path)<=9)return false;
        foreach(explode('/',substr($path,1))as$segment){
            $decoded=rawurldecode($segment);
            if($segment===''||$decoded===''||in_array($decoded,['.','..'],true)||str_contains($decoded,'%')
                ||str_contains($decoded,'\\')||str_contains($decoded,'/')||preg_match('/[\x00-\x20\x7f]/',$decoded)===1)return false;
        }
        return true;
    }
    private function origin(UriInterface $uri): array
    {
        $host=$this->host($uri->getHost());$packed=@inet_pton($host);
        return [$uri->getScheme(),is_string($packed)?'ip:'.bin2hex($packed):'dns:'.$host,$this->port($uri)];
    }

    private function address(string $host,bool $allowLocal,int $deadline): string
    {
        if(filter_var($host,FILTER_VALIDATE_IP)!==false){$this->allowedAddress($host,$allowLocal);return $host;}
        $current=$host;$seen=[];$recordCount=0;
        for($depth=0;$depth<8;$depth++){
            $this->remaining($deadline);
            if(isset($seen[$current]))self::unavailable();$seen[$current]=true;
            // Synchronous OS DNS cannot be forcibly interrupted; check the monotonic budget on both sides.
            $records=@dns_get_record($current,DNS_A|DNS_AAAA|DNS_CNAME);
            $this->remaining($deadline);
            if(!is_array($records)||!array_is_list($records)||$records===[]||count($records)>64)self::unavailable();
            $recordCount+=count($records);if($recordCount>64)self::unavailable();$addresses=[];$targets=[];
            foreach($records as$record){
                if(!is_array($record)||!is_string($record['host']??null)||!is_string($record['type']??null))self::unavailable();
                // A resolver can include later-chain RRs; only this current owner is authoritative here.
                if($this->dnsName($record['host'])!==$current)continue;
                if(($record['class']??null)!=='IN')self::unavailable();
                if($record['type']==='CNAME'){
                    if(!is_string($record['target']??null))self::unavailable();$targets[]=$this->dnsName($record['target']);
                }elseif($record['type']==='A'||$record['type']==='AAAA'){
                    $ip=$record[$record['type']==='A'?'ip':'ipv6']??null;
                    if(!is_string($ip)||filter_var($ip,FILTER_VALIDATE_IP,$record['type']==='A'?FILTER_FLAG_IPV4:FILTER_FLAG_IPV6)===false)self::unavailable();
                    $this->allowedAddress($ip,$allowLocal);$addresses[]= $ip;
                }else self::unavailable();
            }
            $targets=array_values(array_unique($targets));$addresses=array_values(array_unique($addresses));
            if(count($targets)>1||count($addresses)>16||($targets!==[]&&$addresses!==[]))self::unavailable();
            if($targets!==[]){$current=$targets[0];continue;}
            if($addresses===[])self::unavailable();sort($addresses,SORT_STRING);return $addresses[0];
        }
        self::unavailable();
    }
    private function dnsName(string $name): string
    {
        $name=strtolower($name);if(str_ends_with($name,'.'))$name=substr($name,0,-1);
        if($name===''||$this->host($name)!==$name||filter_var($name,FILTER_VALIDATE_IP)!==false)self::unavailable();return $name;
    }
    private function allowedAddress(string $address,bool $allowLocal): void
    {
        $packed=@inet_pton($address);if(!is_string($packed)||!in_array(strlen($packed),[4,16],true))self::unavailable();
        if($allowLocal)return;
        if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)self::unavailable();
        $reserved=strlen($packed)===4?[
            '0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12',
            '192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15',
            '198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4',
        ]:[
            '::/96','::ffff:0:0/96','64:ff9b::/96','64:ff9b:1::/48','100::/64','2001::/32',
            '2001:10::/28','2001:20::/28','2001:db8::/32','2002::/16','fc00::/7','fe80::/10','fec0::/10','ff00::/8',
        ];
        foreach($reserved as$network)if($this->inNetwork($packed,$network))self::unavailable();
    }
    private function inNetwork(string $address,string $network): bool
    {
        [$base,$bits]=explode('/',$network,2);$packed=inet_pton($base);$length=(int)$bits;
        if(!is_string($packed)||strlen($address)!==strlen($packed))return false;
        $whole=intdiv($length,8);$remainder=$length%8;
        return substr($address,0,$whole)===substr($packed,0,$whole)
            &&($remainder===0||((ord($address[$whole])^ord($packed[$whole]))&(255<<(8-$remainder)))===0);
    }

    private function get(UriInterface $uri,string $host,string $pin,int $maximum,int &$total,int $deadline): array
    {
        $timeout=min(10000,$this->remaining($deadline));$port=$this->port($uri);$bytes='';$headerBytes=0;$status=null;$location=null;$bad=false;
        $handle=curl_init();if(!$handle instanceof \CurlHandle)self::unavailable();
        try{
            $options=[CURLOPT_URL=>(string)$uri,CURLOPT_HTTPGET=>true,CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_HTTPAUTH=>CURLAUTH_NONE,
                CURLOPT_UNRESTRICTED_AUTH=>false,CURLOPT_CONNECTTIMEOUT_MS=>min(5000,$timeout),CURLOPT_TIMEOUT_MS=>$timeout,
                CURLOPT_NOSIGNAL=>true,CURLOPT_HTTPHEADER=>['Accept: image/*','Accept-Encoding: identity'],
                CURLOPT_HEADERFUNCTION=>function(\CurlHandle $curl,string $line)use(&$headerBytes,&$status,&$location,&$bad):int{
                    $length=strlen($line);$headerBytes+=$length;
                    if($headerBytes>65536){$bad=true;return 0;}
                    $text=rtrim($line,"\r\n");
                    if(preg_match('~\AHTTP/(?:1\.[01]|2|3) ([1-5][0-9]{2})(?: .*)?\z~D',$text,$parts)===1){
                        $status=(int)$parts[1];$location=null;return $length;
                    }
                    if($text==='')return $length;
                    if($status===null||$text[0]===' '||$text[0]==="\t"||!str_contains($text,':')){$bad=true;return 0;}
                    [$name,$value]=explode(':',$text,2);$name=strtolower($name);$value=trim($value," \t");
                    if(preg_match('/\A[a-z0-9!#$%&\x27*+.^_`|~-]+\z/D',$name)!==1
                        ||preg_match('/[\x00-\x08\x0a-\x1f\x7f]/',$value)===1){$bad=true;return 0;}
                    if($name==='location'){
                        if($location!==null||$value===''||strlen($value)>2048){$bad=true;return 0;}$location=$value;
                    }
                    if($name==='content-encoding'&&strcasecmp($value,'identity')!==0){$bad=true;return 0;}
                    return $length;
                },
                CURLOPT_WRITEFUNCTION=>function(\CurlHandle $curl,string $chunk)use(&$bytes,&$total,$maximum,&$bad,$deadline):int{
                    $length=strlen($chunk);$total+=$length;
                    if($total>self::TOTAL_BYTES||strlen($bytes)+$length>$maximum||hrtime(true)>=$deadline){$bad=true;return 0;}
                    $bytes.=$chunk;return $length;
                }];
            if(filter_var($host,FILTER_VALIDATE_IP)===false)$options[CURLOPT_RESOLVE]=[$host.':'.$port.':'.(str_contains($pin,':')?'['.$pin.']':$pin)];
            if(curl_setopt_array($handle,$options)!==true)self::unavailable();
            $result=@curl_exec($handle);$info=curl_getinfo($handle);$errno=curl_errno($handle);$this->remaining($deadline);
            if($result!==true||$errno!==0||$bad||!is_array($info)||!is_int($info['http_code']??null)
                ||$info['http_code']!==$status||!is_string($info['primary_ip']??null)||!is_int($info['primary_port']??null)
                ||$info['primary_port']!==$port||@inet_pton($info['primary_ip'])!==@inet_pton($pin))self::unavailable();
            if($status===200&&$bytes==='')self::unavailable();
            return ['status'=>$status,'bytes'=>$bytes,'location'=>$location];
        }finally{curl_close($handle);}
    }
    private function remaining(int $deadline): int
    {
        $now=hrtime(true);if(!is_int($now)||$now>=$deadline)self::unavailable();$milliseconds=intdiv($deadline-$now,1000000);
        if($milliseconds<1)self::unavailable();return $milliseconds;
    }
    private static function unavailable(): never { throw new AuthUnavailableException(); }
}
