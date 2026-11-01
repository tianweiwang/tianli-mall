<?php
declare(strict_types=1);

/** Pure normal draft checks only. No App, DB, Redis, payment driver or environment changes. */
require_once __DIR__.'/../../crmeb/vendor/autoload.php';

use app\services\marketplace\finance\FinanceSettingsInput;
use app\services\marketplace\finance\FinanceTransactionProjection;

function financeNormalExpect(bool $value,string $name): void
{ if (!$value) throw new RuntimeException($name); }

$empty=FinanceSettingsInput::emptyDraft();
financeNormalExpect(count($empty)===14&&array_values($empty)===array_fill(0,14,null)
    &&FinanceSettingsInput::draft($empty)===$empty&&count(FinanceSettingsInput::missingFields($empty))===14,
    'UNDECIDED_FIELDS_REMAIN_NULL_AND_LISTED');
$partial=array_replace($empty,['contracting_entity'=>'  本机合成平台  ','payment_provider'=>'   ']);
$partial=FinanceSettingsInput::draft($partial);
financeNormalExpect($partial['contracting_entity']==='本机合成平台'&&$partial['payment_provider']===null
    &&FinanceSettingsInput::changedFields($empty,$partial)===['contracting_entity']
    &&count(FinanceSettingsInput::missingFields($partial))===13,'PARTIAL_DRAFT_NORMALIZATION');
$full=['contracting_entity'=>'本机合成平台','payment_provider'=>'本机拟选服务商','payment_product'=>'本机拟选收款产品',
    'account_model'=>'provider_submerchant','platform_fee_enabled'=>true,'aftersale_hold'=>true,
    'platform_fee_payer'=>'merchant','channel_fee_payer'=>'merchant','platform_fee_basis'=>'goods_paid',
    'platform_fee_rate'=>'2.50','settlement_trigger'=>'aftersale_end','settlement_cycle'=>'weekly',
    'settlement_delay_days'=>7,'refund_platform_fee'=>'proportional'];
financeNormalExpect(FinanceSettingsInput::saveBody(['expected_revision'=>1,'draft'=>$full])===['expected_revision'=>1,'draft'=>$full]
    &&FinanceSettingsInput::missingFields($full)===[],'COMPLETE_DRAFT_IS_DATA_ONLY');
$withoutFee=array_replace($full,['platform_fee_enabled'=>false,'platform_fee_payer'=>null,
    'platform_fee_basis'=>null,'platform_fee_rate'=>null,'aftersale_hold'=>false,'settlement_delay_days'=>0]);
financeNormalExpect(FinanceSettingsInput::draft($withoutFee)===$withoutFee&&FinanceSettingsInput::missingFields($withoutFee)===[],
    'EXPLICIT_FALSE_AND_ZERO_ARE_CONFIGURED_VALUES');
financeNormalExpect(FinanceSettingsInput::changedFields($full,FinanceSettingsInput::draft($full))===[]
    &&FinanceSettingsInput::draft(json_decode(FinanceSettingsInput::encode($full),true,32,JSON_THROW_ON_ERROR))===$full,
    'SAME_DRAFT_AND_SAVED_JSON_ROUNDTRIP');
foreach (['0.00','100.00'] as $rate) financeNormalExpect(FinanceSettingsInput::draft(array_replace($full,['platform_fee_rate'=>$rate]))['platform_fee_rate']===$rate,
    'NORMAL_PERCENT_ENDPOINTS_REMAIN_DECIMAL_STRINGS');
$unicode=array_replace($empty,['contracting_entity'=>str_repeat("\u{3000}",121)."\u{00a0}\u{feff}本机合成平台\u{feff}\u{00a0}\u{3000}",
    'payment_provider'=>"\u{0085}本机拟选服务商\u{0085}", 'payment_product'=>"\u{00a0}\u{3000}\u{feff}"]);
$unicode=FinanceSettingsInput::draft($unicode);
financeNormalExpect($unicode['contracting_entity']==='本机合成平台'&&$unicode['payment_product']===null
    &&$unicode['payment_provider']==="\u{0085}本机拟选服务商\u{0085}"
    &&FinanceSettingsInput::draft(json_decode(FinanceSettingsInput::encode($unicode),true,32,JSON_THROW_ON_ERROR))===$unicode,
    'ECMASCRIPT_EDGE_WHITESPACE_AND_SAVED_ROUNDTRIP');
financeNormalExpect(FinanceTransactionProjection::estimate($empty,'100.00','10.00')===null,'UNDECIDED_FEE_IS_UNKNOWN');
financeNormalExpect(FinanceTransactionProjection::estimate($withoutFee,'100.00','10.00')===
    ['basis_amount'=>null,'platform_fee_amount'=>'0.00','payer'=>null],'EXPLICIT_NO_FEE_IS_ZERO');
financeNormalExpect(FinanceTransactionProjection::estimate($full,'100.00','10.00')===
    ['basis_amount'=>'100.00','platform_fee_amount'=>'2.50','payer'=>'merchant'],'GOODS_BASIS_EXCLUDES_SHIPPING');
financeNormalExpect(FinanceTransactionProjection::estimate(array_replace($full,['platform_fee_basis'=>'order_paid']),'100.00','10.00')===
    ['basis_amount'=>'110.00','platform_fee_amount'=>'2.75','payer'=>'merchant'],'ORDER_BASIS_INCLUDES_SHIPPING');
financeNormalExpect(FinanceTransactionProjection::estimate(array_replace($full,['platform_fee_rate'=>'50.00']),'0.01','0.00')['platform_fee_amount']==='0.01'
    &&FinanceTransactionProjection::estimate(array_replace($full,['platform_fee_rate'=>'49.99']),'0.01','0.00')['platform_fee_amount']==='0.00',
    'INTEGER_HALF_UP_AT_HALF_CENT');
financeNormalExpect(FinanceTransactionProjection::estimate(array_replace($full,['platform_fee_rate'=>'100.00']),'9999999999.99','0.00')['platform_fee_amount']==='9999999999.99',
    'FULL_DECIMAL12_CAPACITY_WITHOUT_FLOATS');
// Projection-only inputs stand for the original DAO's already validated observations.
// These normal view checks neither reconstruct a payment proof nor invoke a driver.
$header=['id'=>101,'order_id'=>'normal-finance-root','uid'=>3,'mer_id'=>2,'pid'=>0,'marketplace_trade_version'=>1,
    'paid'=>0,'pay_time'=>0,'pay_type'=>'','pay_price'=>'40.30','pay_postage'=>'0.00','total_price'=>'40.30'];
$closedAttempt=['id'=>'11','admitted_generation'=>'1','created_at'=>1700000000,
    'evidence'=>['application'=>null,'first_succeeded'=>null,'first_closed'=>str_repeat('c',64)]];
$unapplied=['root_payment_consistent'=>true,'locked'=>['root'=>$header,
    'registry'=>['winner_attempt_id'=>null,'fulfillment_hold'=>0],'attempts'=>[$closedAttempt]]];
$pendingView=FinanceTransactionProjection::row($header,$unapplied,$full);
financeNormalExpect(array_key_exists('refund_summary',$pendingView)&&$pendingView['refund_summary']===null
    &&$pendingView['estimate_status']==='NOT_APPLIED'&&$pendingView['fee_estimate']===null,
    'UNAPPLIED_PAYMENT_REFUND_FACTS_UNKNOWN_WITH_PAYMENT_VIEW_RETAINED');
$paidHeader=array_replace($header,['paid'=>1,'pay_time'=>1700000001,'pay_type'=>'marketplace_test']);
$appliedAttempt=$closedAttempt;$appliedAttempt['evidence']=['application'=>['at'=>1700000001],
    'first_succeeded'=>str_repeat('s',64),'first_closed'=>null];
$applied=['root_payment_consistent'=>true,'locked'=>['root'=>$paidHeader,
    'registry'=>['winner_attempt_id'=>'11','fulfillment_hold'=>0],'attempts'=>[$appliedAttempt]]];
$noRefund=['confirmed_amount'=>'0.00','held_amount'=>'0.00'];
$zeroView=FinanceTransactionProjection::row($paidHeader,$applied,$full,$noRefund);
financeNormalExpect($zeroView['refund_summary']===$noRefund&&$zeroView['order_amount']==='40.30'
    &&$zeroView['fee_estimate']===['basis_amount'=>'40.30','platform_fee_amount'=>'1.01','payer'=>'merchant'],
    'VERIFIED_EMPTY_REFUND_HISTORY_HAS_KNOWN_ZERO_AND_ORIGINAL_FEE_ESTIMATE');
$partialRefund=['confirmed_amount'=>'20.15','held_amount'=>'20.15'];
$partialView=FinanceTransactionProjection::row($paidHeader,$applied,$full,$partialRefund);
financeNormalExpect($partialView['refund_summary']===$partialRefund
    &&array_diff_key($partialView,['refund_summary'=>true])===array_diff_key($zeroView,['refund_summary'=>true]),
    'SAVED_PARTIAL_REFUND_AMOUNTS_DO_NOT_REDUCE_ORIGINAL_PAYMENT_OR_ESTIMATE');
$finalRefund=['confirmed_amount'=>'40.30','held_amount'=>'0.00'];
$finalView=FinanceTransactionProjection::row($paidHeader,$applied,$full,$finalRefund);
financeNormalExpect($finalView['refund_summary']===$finalRefund
    &&array_diff_key($finalView,['refund_summary'=>true])===array_diff_key($partialView,['refund_summary'=>true]),
    'CURRENT_REFUND_SUMMARY_REPLACES_PRIOR_VIEW_WITHOUT_ACCUMULATING_SNAPSHOTS');
$withoutRevision=FinanceTransactionProjection::row($paidHeader,$applied,null,$finalRefund);
financeNormalExpect($withoutRevision['refund_summary']===$finalRefund&&$withoutRevision['estimate_status']==='NO_REVISION'
    &&$withoutRevision['fee_estimate']===null,'SAVED_REFUND_FACTS_DO_NOT_REQUIRE_FEE_RULES_OR_A_REVISION');
financeNormalExpect(FinanceTransactionProjection::row($paidHeader,$applied,$full,$finalRefund)===$finalView
    &&$partialRefund===['confirmed_amount'=>'20.15','held_amount'=>'20.15']
    &&$finalRefund===['confirmed_amount'=>'40.30','held_amount'=>'0.00'],
    'NORMAL_REFUND_PROJECTION_REPEAT_PRESERVES_INPUTS_AND_CURRENT_VIEW');

echo json_encode(['schema'=>1,'scope'=>'FINANCE_SETTINGS_PURE_NORMAL','checks'=>19,'status'=>'PASS',
    'App_DB_HTTP'=>'NOT RUN','real_funds_enabled'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
