<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

use crmeb\services\upload\extend\cos\Client;

/** Real parent signing/endpoint chain; finite native PUT/DELETE observation only. */
final class MerchantObservedCosClient extends Client
{
    private array $observation = ['transport_complete'=>false,'http_status'=>0];
    public function observation(): array { return $this->observation; }
    public function request(string $url, string $method, array $data, array $header = [], int $timeout = 5)
    {
        $this->observation = ['transport_complete'=>false,'http_status'=>0];
        $method = strtoupper($method);
        if (!in_array($method, ['PUT','DELETE'], true)) throw new \RuntimeException('Unsupported operation');
        $parsed = parse_url($url);
        if (!is_array($parsed)) throw new \RuntimeException('Transport unavailable');
        if ($method === 'PUT' && (!is_string($data['body']??null) || $data['body']==='')) {
            throw new \RuntimeException('Transport unavailable');
        }
        // Identical input to the installed parent's actual getSign producer.
        $header = array_merge($header, $this->getSign($url,$method,$parsed['path']??'',[],$header));
        $headers=[];
        foreach ($header as $key=>$value) $headers[]=$key.':'.$value;
        $curl=curl_init($url);
        if ($curl===false) throw new \RuntimeException('Transport unavailable');
        try {
            $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>$timeout,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HEADER=>false,CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_WRITEFUNCTION=>static fn($handle,string $bytes):int=>strlen($bytes)];
            if ($method==='PUT') $options[CURLOPT_POSTFIELDS]=$data['body'];
            if (!curl_setopt_array($curl,$options)) throw new \RuntimeException('Transport unavailable');
            $result=curl_exec($curl);
            $this->observation=['transport_complete'=>$result!==false&&curl_errno($curl)===0,
                'http_status'=>(int)curl_getinfo($curl,CURLINFO_HTTP_CODE)];
            // Discarded body is not a success signal; the caller must inspect native observation.
            return [];
        } finally { curl_close($curl); }
    }
}
