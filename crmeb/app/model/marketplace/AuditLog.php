<?php
declare(strict_types=1);

namespace app\model\marketplace;

use crmeb\basic\BaseModel;

/** The service supplies timestamps and the DAO inserts a JSON object explicitly. */
final class AuditLog extends BaseModel
{
    protected $name = 'marketplace_audit_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;
    protected $json = ['detail'];
    protected $jsonAssoc = true;
}
