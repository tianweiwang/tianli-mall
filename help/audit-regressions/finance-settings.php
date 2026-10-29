<?php
declare(strict_types=1);

/** Pure normal draft checks only. No App, DB, Redis, payment driver or environment changes. */
require_once __DIR__.'/../../crmeb/vendor/autoload.php';

use app\services\marketplace\finance\FinanceSettingsInput;

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
echo json_encode(['schema'=>1,'scope'=>'FINANCE_SETTINGS_PURE_NORMAL','checks'=>7,'status'=>'PASS',
    'App_DB_HTTP'=>'NOT RUN','real_funds_enabled'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
