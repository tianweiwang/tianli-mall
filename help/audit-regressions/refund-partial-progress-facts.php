<?php
declare(strict_types=1);
namespace app\services\marketplace\payment;
// Pure public fixtures only. No App, driver execution, DB/HTTP, permissions or actual financial application.
require_once '/workspace/crmeb/crmeb/exceptions/AuthException.php';
require_once '/workspace/crmeb/crmeb/exceptions/AuthUnavailableException.php';
require_once '/workspace/crmeb/app/services/marketplace/order/MarketplaceOrderInput.php';
require_once '/workspace/crmeb/crmeb/services/marketplace/RefundSchemaReadiness.php';
require_once '/workspace/crmeb/app/services/marketplace/payment/MarketplaceRefundEvidence.php';
require_once '/workspace/crmeb/app/dao/marketplace/payment/MarketplaceRefundDao.php';

final class ExpectedRefundMigrationContract
{
    private static function column(string $type, string $comment, bool $nullable = false, ?string $default = null, ?string $charset = null, bool $auto = false): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset', 'auto');
    }
    public static function newTables(): array
    {
        $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, $comment, $null, $default, $charset, $auto);
        return [
            'marketplace_refund_attempt' => [
                'columns' => [
                    'id' => $c('bigint unsigned', '原生测试退款意图主键', false, null, null, true),
                    'contract_version' => $c('tinyint unsigned', '独立测试退款合同版本'),
                    'refund_id' => $c('int', '唯一原生售后申请主键'),
                    'root_order_id' => $c('int unsigned', '原商业根订单主键'),
                    'uid' => $c('int unsigned', '原订单顾客编号'),
                    'mer_id' => $c('int unsigned', '原订单商家编号'),
                    'payment_attempt_id' => $c('bigint unsigned', '已验证原付款成功尝试主键'),
                    'approval_action_id' => $c('bigint unsigned', '已提交批准售后动作主键'),
                    'idem_key' => $c('varchar(128)', '原测试退款请求幂等键', false, null, 'ascii'),
                    'request_digest' => $c('binary(32)', '完整规范测试退款请求SHA256原始摘要'),
                    'binding_digest' => $c('binary(32)', '原退款驱动绑定SHA256原始摘要'),
                    'account_domain_digest' => $c('binary(32)', '原测试账户域SHA256原始摘要'),
                    'account_snapshot' => $c('json', '原付款测试账户完整不可变快照'),
                    'amount' => $c('decimal(12,2) unsigned', '本申请剩余全额精确退款金额元'),
                    'currency' => $c('char(3)', '原付款币种完整快照', false, null, 'ascii'),
                    'attempt_ref' => $c('varchar(45)', '实际驱动返回的退款尝试引用', true, null, 'ascii'),
                    'applied_refund_no' => $c('varchar(45)', '仅已应用成功的原测试退款引用', true, null, 'ascii'),
                    'evidence' => $c('json', '完整真实create及query观察应用事实'),
                    'created_at' => $c('int unsigned', '原测试退款意图本地登记秒'),
                    'updated_at' => $c('int unsigned', '最后真实退款观察本地登记秒'),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq_ms5_refund_refund' => [true, ['refund_id']],
                    'idx_ms5_refund_root' => [false, ['root_order_id', 'id']],
                    'idx_ms5_refund_payment' => [false, ['payment_attempt_id', 'id']],
                    'uq_ms5_refund_account_attempt' => [true, ['account_domain_digest', 'attempt_ref']],
                    'uq_ms5_refund_account_number' => [true, ['account_domain_digest', 'applied_refund_no']],
                ],
            ],
        ];
    }
}

$account=['source'=>'test_config','account_id'=>null,'account_ref'=>'test:normal-component','version'=>1,
    'mer_id'=>2,'channel'=>'test','currency'=>'CNY','verification_scope'=>'test_driver_only'];
$binding=MarketplaceRefundEvidence::binding(['refund_contract_version'=>1,'refund_id'=>3,'root_order_id'=>4,'order_no'=>'normal-component-order',
    'uid'=>5,'mer_id'=>2,'payment_attempt_id'=>'6','payment_attempt_ref'=>'test-attempt:'.str_repeat('a',32),
    'provider_trade_no'=>'test-payment:'.str_repeat('a',32),'approval_action_id'=>'7','amount'=>'20.15',
    'confirmed_before'=>'0.00','requested_amount'=>'20.15','currency'=>'CNY','account_snapshot'=>$account]);
$now=1700000000;
$row=['id'=>'8','contract_version'=>1,'refund_id'=>3,'root_order_id'=>4,'uid'=>5,'mer_id'=>2,'payment_attempt_id'=>'6','approval_action_id'=>'7',
    'idem_key'=>'normal-component-refund','request_digest'=>hash('sha256',MarketplaceRefundEvidence::canonical(['binding'=>$binding,'idempotency_key'=>'normal-component-refund']),true),
    'binding_digest'=>hash('sha256',MarketplaceRefundEvidence::canonical($binding),true),'account_domain_digest'=>MarketplaceRefundEvidence::domain($account),
    'account_snapshot'=>$account,'amount'=>'20.15','currency'=>'CNY','attempt_ref'=>null,'applied_refund_no'=>null,
    'evidence'=>MarketplaceRefundEvidence::initial($binding),'created_at'=>$now,'updated_at'=>$now];
$initial=MarketplaceRefundEvidence::row($row);
if(MarketplaceRefundEvidence::state($initial)!=='unresolved')throw new \RuntimeException('NORMAL_SAVED_INTENT');
$descriptor=['state'=>'pending','scope'=>MarketplaceRefundEvidence::SCOPE,'attempt_ref'=>'test-refund:'.str_repeat('b',32),
    'binding_sha256'=>hash('sha256',MarketplaceRefundEvidence::canonical($binding)),'binding'=>$binding,'provider_refund_no'=>null];
$created=MarketplaceRefundEvidence::observe($row,'createRefund',$descriptor,$now+1);
if(!$created['new']||MarketplaceRefundEvidence::state($created['row'])!=='pending')throw new \RuntimeException('NORMAL_CREATE_OBSERVATION');
$success=$descriptor;$success['state']='succeeded';$success['provider_refund_no']='test-rfnd:'.str_repeat('b',32);
$queried=MarketplaceRefundEvidence::observe($created['row'],'queryRefund',$success,$now+2);
if(!$queried['new']||$queried['row']['evidence']['success']!==$queried['fact']['key'])throw new \RuntimeException('NORMAL_QUERY_OBSERVATION');
$applied=$queried['row'];$applied['applied_refund_no']=$success['provider_refund_no'];$applied['updated_at']=$now+3;
$applied['evidence']['application']=['fact'=>$queried['fact']['key'],'action_id'=>'9','applied_at'=>$now+3,'refund_no'=>$success['provider_refund_no']];
$applied=MarketplaceRefundEvidence::row($applied);
$replay=MarketplaceRefundEvidence::observe($applied,'queryRefund',$success,$now+4);
if($replay['new']||$replay['row']!==$applied||MarketplaceRefundEvidence::state($applied)!=='applied')throw new \RuntimeException('NORMAL_SAVED_FACT_REPLAY');
$native=$applied;$native['evidence']=json_encode($native['evidence'],JSON_THROW_ON_ERROR);$native['account_snapshot']=json_encode($account,JSON_THROW_ON_ERROR);
$round=MarketplaceRefundEvidence::row($native);
if(MarketplaceRefundEvidence::canonical($round['evidence'])!==MarketplaceRefundEvidence::canonical($applied['evidence']))throw new \RuntimeException('NORMAL_JSON_ROUNDTRIP');
if(\app\dao\marketplace\payment\MarketplaceRefundDao::newTables()!==ExpectedRefundMigrationContract::newTables())throw new \RuntimeException('NORMAL_MIGRATION_RUNTIME_CONTRACT');

// The original complete v1 normal assertions above are unchanged. The following applications are pure fixture inputs.
$v1EvidenceBefore=MarketplaceRefundEvidence::canonical($applied['evidence']);
$progressBinding=$binding;$progressBinding['refund_contract_version']=2;$progressBinding['refund_id']=30;
$progressBinding['amount']='40.30';$progressBinding['requested_amount']='40.30';
$progressBinding=MarketplaceRefundEvidence::binding($progressBinding);
$progress=$row;$progress['id']='80';$progress['contract_version']=2;$progress['refund_id']=30;$progress['amount']='40.30';
$progress['idem_key']='normal-component-progress';$progress['evidence']=MarketplaceRefundEvidence::initial($progressBinding);
$progress['request_digest']=hash('sha256',MarketplaceRefundEvidence::canonical(['binding'=>$progressBinding,'idempotency_key'=>$progress['idem_key']]),true);
$progress['binding_digest']=hash('sha256',MarketplaceRefundEvidence::canonical($progressBinding),true);
$progress=MarketplaceRefundEvidence::row($progress);
$progressResult=['schema'=>2,'state'=>'pending','scope'=>MarketplaceRefundEvidence::SCOPE,
    'attempt_ref'=>'test-refund:'.str_repeat('c',32),'binding_sha256'=>hash('sha256',MarketplaceRefundEvidence::canonical($progressBinding)),
    'binding'=>$progressBinding,'provider_refund_no'=>null,'confirmed_amount'=>'0.00'];
$phases=[['createRefund','0.00','PROCESSING','100'],['queryRefund','20.15','PARTIAL','101'],['queryRefund','40.30','SUCCEEDED','102']];
$phaseFacts=[];$prefixEvidence=$progress['evidence'];
foreach($phases as $i=>[$op,$amount,$finance,$actionId]){
    $result=$progressResult;$result['confirmed_amount']=$amount;
    if($finance==='SUCCEEDED'){$result['state']='succeeded';$result['provider_refund_no']='test-rfnd:'.str_repeat('c',32);}
    $expected=['finance_state'=>$finance,'confirmed_amount'=>$amount];
    if(MarketplaceRefundEvidence::progressState($result)!==$expected)throw new \RuntimeException('NORMAL_PROGRESS_STATE');
    $proposed=MarketplaceRefundEvidence::observe($progress,$op,$result,$now+10+$i*2);
    if(!$proposed['new'])throw new \RuntimeException('NORMAL_PROGRESS_NEW_FACT');
    $next=$proposed['row'];$fact=$proposed['fact'];$at=$now+11+$i*2;
    $next['evidence']['applications'][]=['fact'=>$fact['key'],'action_id'=>$actionId,'applied_at'=>$at,
        'finance_state'=>$finance,'confirmed_amount'=>$amount,'refund_no'=>$result['provider_refund_no']];
    if($finance==='SUCCEEDED'){
        $next['applied_refund_no']=$result['provider_refund_no'];
        $next['evidence']['application']=['fact'=>$fact['key'],'action_id'=>$actionId,'applied_at'=>$at,'refund_no'=>$result['provider_refund_no']];
    }
    $next['updated_at']=$at;$next=MarketplaceRefundEvidence::row($next);
    foreach(['observations','applications'] as $key)
        if(MarketplaceRefundEvidence::canonical(array_slice($next['evidence'][$key],0,count($prefixEvidence[$key])))
            !==MarketplaceRefundEvidence::canonical($prefixEvidence[$key]))throw new \RuntimeException('NORMAL_PROGRESS_PREFIX');
    $same=MarketplaceRefundEvidence::observe($next,$op,$result,$at+1);
    if($same['new']||$same['row']!==$next)throw new \RuntimeException('NORMAL_PROGRESS_SAME_FACT_READ');
    $native=$next;$native['evidence']=json_encode($next['evidence'],JSON_THROW_ON_ERROR);
    $native['account_snapshot']=json_encode($account,JSON_THROW_ON_ERROR);$round=MarketplaceRefundEvidence::row($native);
    if(MarketplaceRefundEvidence::canonical($round['evidence'])!==MarketplaceRefundEvidence::canonical($next['evidence']))
        throw new \RuntimeException('NORMAL_PROGRESS_JSON_ROUNDTRIP');
    if(MarketplaceRefundEvidence::state($round)!==($finance==='SUCCEEDED'?'applied':'pending'))throw new \RuntimeException('NORMAL_PROGRESS_PUBLIC_STATE');
    if(MarketplaceRefundEvidence::canonical(MarketplaceRefundEvidence::descriptor($round))!==MarketplaceRefundEvidence::canonical($progressResult))
        throw new \RuntimeException('NORMAL_PROGRESS_ORIGINAL_DESCRIPTOR');
    $phaseFacts[]=['confirmed_amount'=>$amount,'held_amount'=>bcsub('40.30',$amount,2),'finance_state'=>$finance,
        'applications'=>count($next['evidence']['applications'])];
    $progress=$round;$prefixEvidence=$progress['evidence'];
}
if($progress['evidence']['success']!==$progress['evidence']['application']['fact']
    ||$progress['evidence']['application']['action_id']!=='102'
    ||$v1EvidenceBefore!==MarketplaceRefundEvidence::canonical($applied['evidence']))throw new \RuntimeException('NORMAL_FINAL_PROGRESS_AND_V1_PRESERVED');
echo json_encode(['status'=>'PASS_NORMAL_PURE_V1_AND_V2_REFUND_FACTS_COMPONENT','original_v1_complete_normal_assertions'=>true,
    'v2_fixture_phases'=>$phaseFacts,'applications_and_observations_append_only'=>true,'same_fact_read_unchanged'=>true,
    'native_json_roundtrip'=>true,'original_descriptor_held'=>true,'all_inputs'=>'PUBLIC_SYNTHETIC_AND_TRACKED_PHP_ONLY',
    'DAO_static_newTables_contract_executed'=>true,'App_DB_HTTP_driver_Writer_and_DAO_database_execution'=>'NOT RUN','fault_concurrency_recovery'=>'NOT RUN'],JSON_THROW_ON_ERROR)."\n";
