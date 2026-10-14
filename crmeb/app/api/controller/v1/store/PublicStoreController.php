<?php
declare(strict_types=1);
namespace app\api\controller\v1\store;

use app\services\marketplace\PublicStoreServices;

final class PublicStoreController
{
    public function __construct(private PublicStoreServices $stores) {}

    public function read($id)
    {
        return app('json')->success($this->stores->read($id));
    }
}
