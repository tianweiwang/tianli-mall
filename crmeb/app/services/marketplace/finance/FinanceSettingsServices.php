<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use app\dao\marketplace\scope\PlatformScope;
use app\services\marketplace\AuditLogServices;
use app\services\marketplace\MerchantManagementInput;
use app\services\marketplace\PlatformAccessServices;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use think\exception\HttpException;
use Throwable;

/** Versioned configuration drafts. This service never enables or resolves a payment account. */
final class FinanceSettingsServices
{
    public const READ='marketplace-finance-settings-read';
    public const WRITE='marketplace-finance-settings-write';

    public function __construct(private PlatformAccessServices $access,private AuditLogServices $audit) {}

    public function read(PlatformScope $scope,array $query=[]): array
    {
        try {
            $this->authorize($scope,self::READ);MerchantManagementInput::body($query,[]);
            return $this->transaction(function(PDO $pdo,string $prefix)use($scope):array{
                $row=$this->current($pdo,$prefix,false);
                $this->authorize($scope,self::READ);
                $result=$this->result($pdo,$prefix,$row);
                $this->audit->recordFinanceSettingsScope($scope,$pdo);
                return $result;
            });
        } catch (AuthException|HttpException $error) { throw $error; }
        catch (Throwable $error) { throw new AuthUnavailableException(); }
    }

    public function save(PlatformScope $scope,array $body): array
    {
        try {
            $this->authorize($scope,self::WRITE);$input=FinanceSettingsInput::saveBody($body);
            return $this->transaction(function(PDO $pdo,string $prefix)use($scope,$input):array{
                // Serialize saves before establishing authorization's fresh transaction read view.
                $row=$this->current($pdo,$prefix,true);
                $this->authorize($scope,self::WRITE);
                if ($input['expected_revision']!==$row['revision'])
                    throw new HttpException(409,'草稿已更新，请重新读取后再保存');
                $changed=FinanceSettingsInput::changedFields($row['payload'],$input['draft']);
                if ($changed!==[]) {
                    if ($row['revision']===4294967295) throw new AuthUnavailableException();
                    $revision=$row['revision']+1;$now=time();
                    if ($now<1||$now>4294967295) throw new AuthUnavailableException();
                    $payload=FinanceSettingsInput::encode($input['draft']);
                    $update=$pdo->prepare('UPDATE `'.$prefix.'marketplace_finance_settings` SET revision=?,payload=?,updated_by=?,updated_at=? WHERE id=1 AND revision=?');
                    if ($update===false) throw new AuthUnavailableException();
                    try { if (!$update->execute([$revision,$payload,$scope->adminId,$now,$row['revision']])||$update->rowCount()!==1) throw new AuthUnavailableException(); }
                    finally { $update->closeCursor(); }
                    $insert=$pdo->prepare('INSERT INTO `'.$prefix.'marketplace_finance_settings_history` (revision,payload,changed_fields,actor_id,created_at) VALUES (?,?,?,?,?)');
                    if ($insert===false) throw new AuthUnavailableException();
                    try { if (!$insert->execute([$revision,$payload,FinanceSettingsInput::encode($changed),$scope->adminId,$now])||$insert->rowCount()!==1) throw new AuthUnavailableException(); }
                    finally { $insert->closeCursor(); }
                    $row=$this->current($pdo,$prefix,true);
                    if ($row!==['id'=>1,'revision'=>$revision,'payload'=>$input['draft'],'updated_by'=>$scope->adminId,'updated_at'=>$now])
                        throw new AuthUnavailableException();
                }
                $result=$this->result($pdo,$prefix,$row);
                $this->audit->recordFinanceSettingsScope($scope,$pdo);
                return $result;
            });
        } catch (AuthException|HttpException $error) { throw $error; }
        catch (Throwable $error) { throw new AuthUnavailableException(); }
    }

    private function authorize(PlatformScope $scope,string $permission): void
    {
        if ($scope->targetMerId!==null||$scope->adminId>4294967295) throw new AuthUnavailableException();
        $this->access->authorizeScope($scope,$permission);
    }

    private function transaction(callable $operation): array
    {
        $connection=Db::connect();$pdo=$connection->getPdo();
        if (!$pdo instanceof PDO) { $connection->query('SELECT 1',[],true);$pdo=$connection->getPdo(); }
        $prefix=$connection->getConfig('prefix');
        if (!$connection instanceof Mysql||$connection->transactionDepth()!==0||!$pdo instanceof PDO||$pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1
            ||strlen($prefix.'marketplace_finance_settings_history')>64) throw new AuthUnavailableException();
        $result=$connection->transaction(function()use($operation,$connection,$pdo,$prefix):array{
            if (Db::connect()!==$connection||$connection->getPdo()!==$pdo||$connection->transactionDepth()!==1||!$pdo->inTransaction()) throw new AuthUnavailableException();
            $result=$operation($pdo,$prefix);
            if (Db::connect()!==$connection||$connection->getPdo()!==$pdo||$connection->transactionDepth()!==1||!$pdo->inTransaction()) throw new AuthUnavailableException();
            return $result;
        });
        if (Db::connect()!==$connection||$connection->getPdo()!==$pdo||$connection->transactionDepth()!==0||$pdo->inTransaction()) throw new AuthUnavailableException();
        return $result;
    }

    private function current(PDO $pdo,string $prefix,bool $lock): array
    {
        $rows=self::rows($pdo,'SELECT id,revision,payload,updated_by,updated_at FROM `'.$prefix.'marketplace_finance_settings` WHERE id=1'.($lock?' FOR UPDATE':''));
        if (count($rows)!==1) throw new AuthUnavailableException();
        $raw=$rows[0];$row=['id'=>self::number($raw['id'],1),'revision'=>self::number($raw['revision']),
            'payload'=>self::storedDraft($raw['payload']),'updated_by'=>self::nullableNumber($raw['updated_by']),
            'updated_at'=>self::nullableNumber($raw['updated_at'])];
        if ($row['id']!==1||($row['revision']===0
            ?($row['payload']!==FinanceSettingsInput::emptyDraft()||$row['updated_by']!==null||$row['updated_at']!==null)
            :($row['updated_by']===null||$row['updated_at']===null))) throw new AuthUnavailableException();
        return $row;
    }

    private function result(PDO $pdo,string $prefix,array $row): array
    {
        $historyRows=self::rows($pdo,'SELECT revision,payload,changed_fields,actor_id,created_at FROM `'.$prefix.'marketplace_finance_settings_history` ORDER BY revision DESC LIMIT 20');
        $counts=self::rows($pdo,'SELECT COUNT(*) AS total FROM `'.$prefix.'marketplace_finance_settings_history`');
        if (count($counts)!==1||self::number($counts[0]['total'])!==$row['revision']) throw new AuthUnavailableException();
        $history=[];$expected=$row['revision'];
        foreach ($historyRows as $raw) {
            $revision=self::number($raw['revision'],1);$payload=self::storedDraft($raw['payload']);
            $changed=json_decode($raw['changed_fields'],true,32,JSON_THROW_ON_ERROR);
            if ($revision!==$expected--||!is_array($changed)||!array_is_list($changed)||$changed===[]) throw new AuthUnavailableException();
            foreach ($changed as $field) if (!is_string($field)||!in_array($field,FinanceSettingsInput::FIELDS,true)) throw new AuthUnavailableException();
            if (count(array_unique($changed))!==count($changed)) throw new AuthUnavailableException();
            $history[]=['revision'=>$revision,'payload'=>$payload,'changed_fields'=>$changed,
                'actor_id'=>self::number($raw['actor_id'],1),'created_at'=>self::number($raw['created_at'],1)];
        }
        if (count($history)!==min(20,$row['revision'])||($row['revision']>0
            &&($history[0]['payload']!==$row['payload']||$history[0]['actor_id']!==$row['updated_by']||$history[0]['created_at']!==$row['updated_at'])))
            throw new AuthUnavailableException();
        return ['schema'=>1,'status'=>'DRAFT','real_funds_enabled'=>false,'revision'=>$row['revision'],
            'draft'=>$row['payload'],'missing_fields'=>FinanceSettingsInput::missingFields($row['payload']),
            'activation_blockers'=>['当前仅保存资金方案草稿，真实收款尚未启用',
                '支付渠道开通及商家收款账户绑定尚未完成',
                '真实支付回调、原账户退款、账单对账和结算能力尚未接入'],
            'updated_at'=>$row['updated_at'],'updated_by'=>$row['updated_by'],'history'=>$history,'history_total'=>$row['revision']];
    }

    private static function rows(PDO $pdo,string $sql): array
    {
        $statement=$pdo->prepare($sql);if ($statement===false) throw new AuthUnavailableException();
        try { if (!$statement->execute()) throw new AuthUnavailableException();return $statement->fetchAll(PDO::FETCH_ASSOC); }
        finally { $statement->closeCursor(); }
    }

    private static function storedDraft(mixed $raw): array
    {
        if (!is_string($raw)) throw new AuthUnavailableException();
        try {
            $decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$draft=FinanceSettingsInput::draft($decoded);
            foreach ($draft as $field=>$value) if ($decoded[$field]!==$value) throw new AuthUnavailableException();
            return $draft;
        } catch (Throwable $error) { throw new AuthUnavailableException(); }
    }

    private static function number(mixed $value,int $minimum=0): int
    {
        if (!is_int($value)&&(!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)!==1)) throw new AuthUnavailableException();
        $number=(int)$value;if ($number<$minimum||$number>4294967295) throw new AuthUnavailableException();return $number;
    }
    private static function nullableNumber(mixed $value): ?int { return $value===null?null:self::number($value,1); }
}
