<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

use crmeb\services\upload\storage\Obs;

/** Preserve the installed Huawei move/thumb/water and actual parent signing chain. */
final class MerchantObservedObsUpload extends Obs
{
    protected function app()
    {
        $this->handle=new MerchantObservedObsClient(['accessKey'=>$this->accessKey,'secretKey'=>$this->secretKey,
            'region'=>$this->storageRegion?:'cn-north-1','bucket'=>$this->storageName,
            'uploadUrl'=>$this->uploadUrl]);
        return $this->handle;
    }
    public function observation(): array
    {
        return $this->handle instanceof MerchantObservedObsClient ? $this->handle->observation()
            : ['transport_complete'=>false,'http_status'=>0];
    }
}
