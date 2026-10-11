<?php
declare(strict_types=1);

namespace app\model\marketplace;

use think\Model;

/** The service supplies every persisted timestamp explicitly. */
final class Employee extends Model
{
    protected $name = 'marketplace_employee';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;
    protected $hidden = ['pwd'];
}
