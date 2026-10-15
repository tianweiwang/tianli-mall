<?php
declare(strict_types=1);

namespace crmeb\services\pay\marketplace;

use app\services\marketplace\order\MarketplaceOrderInput;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;

/** Explicit same-App test configuration only. Real account storage remains a later MS5 task. */
final class PaymentAccountResolver
{
    /** @var array<int,PaymentAccount> */
    private array $testingAccounts=[];
    private readonly \think\App $app;

    public function __construct()
    {
        $this->app=app();
    }

    /** Normal fixtures supply the actual native merchant PK; this creates no financial database row. */
    public function registerTestAccount(int $actualMerId, string $testReference, int $version, string $currency): PaymentAccount
    {
        $this->assertScope();
        $account=PaymentAccount::testing($actualMerId,$testReference,$version,$currency);
        $rows=$this->read('marketplace_merchant',['mer_id'],'`mer_id` = ?',[$actualMerId]);
        if(count($rows)!==1||MarketplaceOrderInput::unsigned($rows[0]['mer_id'],'商家编号')!==$actualMerId)
            throw new AuthUnavailableException();
        if(isset($this->testingAccounts[$actualMerId])){
            if($this->testingAccounts[$actualMerId]->snapshot()!==$account->snapshot())throw new AuthUnavailableException();
            return $this->testingAccounts[$actualMerId];
        }
        return $this->testingAccounts[$actualMerId]=$account;
    }

    /** Re-read the actual committed order. There is no platform-account fallback or fabricated account PK. */
    public function forOrder(array $order): PaymentAccount
    {
        $this->assertScope();$expected=self::orderIdentity($order);
        $rows=$this->read('store_order',['id','order_id','uid','mer_id','marketplace_trade_version','pay_price','paid','is_cancel','status'],
            '`id` = ?',[$expected['id']]);
        if(count($rows)!==1||self::orderIdentity($rows[0])!==$expected)throw new AuthUnavailableException();
        $account=$this->testingAccounts[$expected['mer_id']]??null;
        if(!$account instanceof PaymentAccount||$account->mer_id!==$expected['mer_id'])throw new AuthUnavailableException();
        return $account;
    }

    private static function orderIdentity(array $order): array
    {
        $actual=[];
        foreach(['id'=>2147483647,'uid'=>2147483647,'mer_id'=>4294967295] as $field=>$maximum){
            $actual[$field]=MarketplaceOrderInput::unsigned($order[$field]??null,'订单身份',$maximum);
            if($actual[$field]===0)throw new AuthUnavailableException();
        }
        if(MarketplaceOrderInput::unsigned($order['marketplace_trade_version']??null,'保存版本',1)!==1
            ||!is_string($order['order_id']??null)||$order['order_id']===''
            ||strlen($order['order_id'])>32||trim($order['order_id'])!==$order['order_id']
            ||!is_string($order['pay_price']??null)||preg_match('/\A(?:0|[1-9][0-9]{0,9})\.[0-9]{2}\z/D',$order['pay_price'])!==1)
            throw new AuthUnavailableException();
        $actual['marketplace_trade_version']=1;$actual['order_id']=$order['order_id'];$actual['pay_price']=$order['pay_price'];
        foreach(['paid'=>1,'is_cancel'=>1,'status'=>255] as $field=>$maximum)
            $actual[$field]=MarketplaceOrderInput::unsigned($order[$field]??null,'订单状态',$maximum);
        return $actual;
    }

    private function assertScope(): void
    {
        PaymentAccount::assertTesting();
        if(app()!==$this->app)throw new AuthUnavailableException();
    }

    /** Test registration/payment creation read only the real default primary connection, outside a transaction. */
    private function read(string $table, array $fields, string $where, array $values): array
    {
        if(!in_array($table,['marketplace_merchant','store_order'],true))throw new AuthUnavailableException();
        $connection=Db::connect();$pdo=$connection->getPdo();
        if(!$pdo instanceof PDO){$connection->query('SELECT 1',[],true);$pdo=$connection->getPdo();}
        $prefix=$connection->getConfig('prefix');
        if(!$pdo instanceof PDO||$pdo->inTransaction()||$pdo!==Db::connect()->getPdo()
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)
            ||!is_string($prefix)||preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.$table)>64)
            throw new AuthUnavailableException();
        $sql='SELECT `'.implode('`,`',$fields).'` FROM `'.$prefix.$table.'` WHERE '.$where.' LIMIT 2';
        $statement=$pdo->prepare($sql);
        if(!$statement)throw new AuthUnavailableException();
        foreach(array_values($values) as $index=>$value){
            if(!is_int($value)&&!is_string($value))throw new AuthUnavailableException();
            if(!$statement->bindValue($index+1,(string)$value,PDO::PARAM_STR))throw new AuthUnavailableException();
        }
        if(!$statement->execute())throw new AuthUnavailableException();
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        if(!is_array($rows))throw new AuthUnavailableException();
        return $rows;
    }
}
