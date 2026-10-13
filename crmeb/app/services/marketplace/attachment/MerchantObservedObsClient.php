<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

use crmeb\services\upload\extend\obs\Client;

/** Reuse the provider's real signing/endpoint chain; retain finite PUT/DELETE observation only. */
final class MerchantObservedObsClient extends Client
{
    private array $observation = ['transport_complete'=>false,'http_status'=>0];
    public function observation(): array { return $this->observation; }
    protected function requestClient(string $url, string $method, array $data = [], array $clientHeader = [], int $timeout = 10)
    {
        $this->observation = ['transport_complete'=>false,'http_status'=>0];
        if (!in_array($method, ['PUT','DELETE'], true)) throw new \RuntimeException('Unsupported operation');
        if ($method === 'PUT' && (!is_string($data['body']??null) || $data['body']==='')) {
            throw new \RuntimeException('Transport unavailable');
        }
        $curl = curl_init($url);
        if ($curl === false) throw new \RuntimeException('Transport unavailable');
        try {
            $headers = [];
            foreach ($clientHeader as $key=>$value) $headers[] = $key.':'.$value;
            $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HEADER=>false,
                CURLOPT_RETURNTRANSFER=>false,CURLOPT_WRITEFUNCTION=>static fn($handle,string $bytes): int => strlen($bytes)];
            if ($method==='PUT') $options[CURLOPT_POSTFIELDS]=$data['body'];
            if (!curl_setopt_array($curl,$options)) {
                throw new \RuntimeException('Transport unavailable');
            }
            $result = curl_exec($curl);
            $this->observation = ['transport_complete'=>$result !== false && curl_errno($curl) === 0,
                'http_status'=>(int)curl_getinfo($curl,CURLINFO_HTTP_CODE)];
            return []; // No body/authentication metadata retained; this is not a success signal.
        } finally { curl_close($curl); }
    }
}
