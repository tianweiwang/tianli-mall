<?php
declare(strict_types=1);

namespace app\model\marketplace;

use think\Model;

/** The service supplies every persisted timestamp explicitly. */
final class Merchant extends Model
{
    protected $name = 'marketplace_merchant';
    protected $pk = 'mer_id';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;
}
