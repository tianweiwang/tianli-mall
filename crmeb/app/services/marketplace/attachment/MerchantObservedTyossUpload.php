<?php
declare(strict_types=1);
namespace app\services\marketplace\attachment;

use crmeb\services\upload\storage\Tyoss;

/** Preserve the installed Tianyi move/thumb/water and actual parent signing chain. */
final class MerchantObservedTyossUpload extends Tyoss
{
    protected function app()
    {
        $this->handle=new MerchantObservedObsClient(['accessKey'=>$this->accessKey,'secretKey'=>$this->secretKey,
            'region'=>$this->storageRegion?:'cn-qhxn1','bucket'=>$this->storageName,
            'uploadUrl'=>$this->uploadUrl,'type'=>'ty']);
        return $this->handle;
    }
    public function observation(): array
    {
        return $this->handle instanceof MerchantObservedObsClient ? $this->handle->observation()
            : ['transport_complete'=>false,'http_status'=>0];
    }
}
