<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

use crmeb\services\upload\storage\Cos;

/** Preserve the installed driver move/thumb/water; only replace its private request client. */
final class MerchantObservedCosUpload extends Cos
{
    protected function app()
    {
        $this->handle=new MerchantObservedCosClient(['accessKey'=>$this->accessKey,'secretKey'=>$this->secretKey,
            'region'=>$this->storageRegion?:'ap-chengdu','bucket'=>$this->storageName,
            'appid'=>$this->appid,'uploadUrl'=>$this->uploadUrl]);
        return $this->handle;
    }
    public function observation(): array
    {
        return $this->handle instanceof MerchantObservedCosClient ? $this->handle->observation()
            : ['transport_complete'=>false,'http_status'=>0];
    }
}
