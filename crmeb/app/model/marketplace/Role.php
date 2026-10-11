<?php
declare(strict_types=1);

namespace app\model\marketplace;

use think\Model;

/** The service supplies every persisted timestamp explicitly. */
final class Role extends Model
{
    protected $name = 'marketplace_role';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;
    protected $json = ['permissions'];
    protected $jsonAssoc = true;
}
