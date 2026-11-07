<?php
declare(strict_types=1);

/** Normal arithmetic/import cases only. No App, driver, DB, Redis or real money. */
require_once __DIR__.'/../../crmeb/vendor/autoload.php';

use app\services\marketplace\finance\FinanceBillReconciliation as Bills;
use app\services\marketplace\finance\FinanceSettlementCalculation as Fees;
use app\services\marketplace\finance\FinanceSettingsInput;
use app\services\marketplace\finance\FinanceBillInput;
use app\services\marketplace\finance\FinanceStatementProjection as Statements;
use app\services\marketplace\payment\MarketplaceRefundEvidence;

$checks=[];
function billExpect(bool $ok,string $name):void
{ global $checks; if(!$ok)throw new RuntimeException($name); $checks[]=$name; }

$header='kind,local_ref,mer_id,channel,account_ref,account_version,currency,amount,channel_fee,provider_no,occurred_at';
$csv=$header."\r\npayment,payment:11,2,test,test:merchant:2,1,CNY,40.30,0.24,test:trade:11,2026-11-07T09:00:00+08:00\r\n"
    ."refund,refund:1:15,2,test,test:merchant:2,1,CNY,20.15,-0.12,test:refund:15,2026-11-07T09:10:00+08:00\r\n";
$bill=Bills::parse("\xef\xbb\xbf".$csv);
billExpect($bill['schema']===1&&count($bill['rows'])===2&&$bill['file_sha256']===hash('sha256',"\xef\xbb\xbf".$csv),
    'UTF8_BOM_CRLF_AND_ORIGINAL_FILE_DIGEST');
billExpect($bill['rows'][0]['mer_id']===2&&$bill['rows'][1]['channel_fee']==='-0.12'
    &&$bill['rows'][0]['occurred_at']===1794013200,'EXACT_MONEY_ACCOUNT_VERSION_AND_EXPLICIT_TIMEZONE');
$local=$bill['rows'];foreach($local as &$row)$row['channel_fee']=null;unset($row);
$match=Bills::reconcile($bill['rows'],$local);
billExpect($match['counts']===['matched'=>2,'differences'=>0]&&$match['totals']['bill']===
    ['payments'=>'40.30','refunds'=>'20.15','channel_fee'=>'0.12']&&$match['real_funds_enabled']===false,
    'MATCH_TWO_NORMAL_MOVEMENTS_WITH_SEPARATE_NET_CHANNEL_FEE');
billExpect(Bills::reconcile(Bills::parse("\xef\xbb\xbf".$csv)['rows'],$local)===$match,
    'REPEATED_COMPARISON_HAS_NO_NEW_MOVEMENTS');
$utc=Bills::parse(str_replace(['2026-11-07T09:00:00+08:00','2026-11-07T09:10:00+08:00'],
    ['2026-11-07T01:00:00Z','2026-11-07T01:10:00+00:00'],$csv));
billExpect(Bills::reconcile($utc['rows'],$local)===$match,'SAME_INSTANT_WITH_EXPLICIT_DIFFERENT_TIMEZONE_MATCHES');
$otherAccount=$bill['rows'];$otherAccount[0]['mer_id']=3;$otherAccount[0]['account_ref']='test:merchant:3';$otherAccount[0]['account_version']=2;
billExpect(Bills::reconcile($otherAccount,$local)['rows'][0]['differences']===['mer_id','account_ref','account_version'],
    'ACCOUNT_AND_MERCHANT_DIFFERENCES_ARE_REPORTED_DESPITE_EQUAL_AMOUNT');
$different=$bill['rows'];$different[0]['amount']='40.29';
$diff=Bills::reconcile($different,$local);
billExpect($diff['rows'][0]['status']==='FIELD_DIFFERENCE'&&$diff['rows'][0]['differences']===['amount']
    &&$diff['counts']===['matched'=>1,'differences'=>1],'NORMAL_BILL_AMOUNT_DIFFERENCE_REMAINS_VISIBLE');
$missing=Bills::reconcile([$bill['rows'][0]],$local);
billExpect($missing['rows'][1]['status']==='MISSING_BILL'&&$missing['rows'][1]['local']['amount']==='20.15',
    'LOCAL_REFUND_WITHOUT_A_BILL_LINE_IS_LISTED');
$unregistered=Bills::reconcile($bill['rows'],[$local[0]]);
billExpect($unregistered['rows'][1]['status']==='MISSING_LOCAL','BILL_REFUND_WITHOUT_LOCAL_MOVEMENT_IS_LISTED');
$duplicates=Bills::reconcile([$bill['rows'][0],$bill['rows'][0]],[$local[0]]);
billExpect(array_column($duplicates['rows'],'status')===['DUPLICATE_BILL_REFERENCE','DUPLICATE_BILL_REFERENCE']
    &&$duplicates['counts']['matched']===0,'DUPLICATE_BILL_LINES_ARE_NOT_COLLAPSED_OR_MATCHED');
$pending=$local;$pending[1]['provider_no']=null;
billExpect(Bills::reconcile($bill['rows'],$pending)['rows'][1]['status']==='LOCAL_PROVIDER_REFERENCE_PENDING',
    'LOCAL_PARTIAL_REFUND_WITHOUT_PROVIDER_NUMBER_IS_NOT_SIGNED_MATCHED');
$increments=Bills::refundIncrements([
    ['action_id'=>'14','applied_at'=>1794013200,'confirmed_amount'=>'0.00'],
    ['action_id'=>'15','applied_at'=>1794013800,'confirmed_amount'=>'20.15'],
    ['action_id'=>'16','applied_at'=>1794014400,'confirmed_amount'=>'20.15'],
    ['action_id'=>'17','applied_at'=>1794015000,'confirmed_amount'=>'40.30'],
]);
billExpect(array_column($increments,'amount')===['20.15','20.15']&&array_column($increments,'action_id')===['15','17'],
    'CUMULATIVE_PARTIAL_REFUND_COUNTS_ONLY_POSITIVE_CHANGES');
billExpect(Bills::refundIncrements([['action_id'=>'18','applied_at'=>1794015000,'confirmed_amount'=>'40.30']])[0]['amount']==='40.30',
    'SINGLE_FULL_REFUND_APPLICATION_COUNTS_ONCE');
billExpect(Bills::parse($header."\n")['rows']===[]&&Bills::reconcile([],[])['counts']===['matched'=>0,'differences'=>0],
    'EMPTY_COMPARISON_HAS_ZERO_ROWS_ONLY');
$period=FinanceBillInput::period(['anchor_attempt_id'=>'11','date_from'=>'2026-11-01','date_to'=>'2026-11-30']);
billExpect($period['period_end']-$period['period_start']===30*86400
    &&gmdate('Y-m-d H:i:s',$period['period_start'])==='2026-10-31 16:00:00',
    'INCLUSIVE_CALENDAR_PERIOD_USES_EXPLICIT_SHANGHAI_BOUNDARIES');
$import=FinanceBillInput::import(['anchor_attempt_id'=>'11','date_from'=>'2026-11-07','date_to'=>'2026-11-07',
    'filename'=>'本机合成账单.csv','csv'=>"\xef\xbb\xbf".$csv]);
billExpect($import['csv']==="\xef\xbb\xbf".$csv&&$import['parsed']===$bill&&$import['filename']==='本机合成账单.csv'
    &&$import['period_end']-$import['period_start']===86400,'IMPORT_PRESERVES_ORIGINAL_BYTES_WITH_EXPLICIT_ACCOUNT_AND_PERIOD');

$draft=array_replace(FinanceSettingsInput::emptyDraft(),['platform_fee_enabled'=>true,'platform_fee_payer'=>'merchant',
    'channel_fee_payer'=>'merchant','platform_fee_basis'=>'goods_paid','platform_fee_rate'=>'2.50','refund_platform_fee'=>'proportional']);
$amounts=['goods_paid'=>'100.00','shipping_paid'=>'10.00','goods_refunded'=>'50.00','shipping_refunded'=>'0.00','channel_fee'=>'0.60'];
$fees=Fees::calculate($draft,$amounts);
billExpect($fees['sales_amount']==='110.00'&&$fees['refund_amount']==='50.00'&&$fees['platform_fee_amount']==='2.50'
    &&$fees['platform_fee_reversal']==='1.25'&&$fees['platform_fee_net']==='1.25'&&$fees['merchant_receivable']==='58.15'
    &&$fees['settled_amount']===null,'GOODS_BASIS_PARTIAL_REFUND_SEPARATES_EVERY_FEE');
$orderFees=Fees::calculate(array_replace($draft,['platform_fee_basis'=>'order_paid']),$amounts);
billExpect($orderFees['platform_fee_amount']==='2.75'&&$orderFees['platform_fee_reversal']==='1.25'
    &&$orderFees['merchant_receivable']==='57.90','ORDER_BASIS_INCLUDES_SHIPPING');
$full=Fees::calculate($draft,array_replace($amounts,['goods_refunded'=>'100.00','shipping_refunded'=>'10.00','channel_fee'=>'0.00']));
billExpect($full['platform_fee_reversal']==='2.50'&&$full['platform_fee_net']==='0.00'&&$full['merchant_receivable']==='0.00',
    'FULL_REFUND_REVERSES_EXACTLY_THE_ORIGINAL_PLATFORM_FEE');
$retained=Fees::calculate(array_replace($draft,['refund_platform_fee'=>'none']),
    array_replace($amounts,['goods_refunded'=>'100.00','shipping_refunded'=>'10.00']));
billExpect($retained['platform_fee_reversal']==='0.00'&&$retained['merchant_receivable']==='-3.10',
    'NONREFUNDABLE_FEES_KEEP_NEGATIVE_RECEIVABLE_VISIBLE');
$platform=Fees::calculate(array_replace($draft,['platform_fee_payer'=>'platform','channel_fee_payer'=>'platform']),$amounts);
billExpect($platform['merchant_receivable']==='60.00'&&$platform['platform_fee_net']==='1.25',
    'PLATFORM_BORNE_FEES_DO_NOT_REDUCE_MERCHANT_RECEIVABLE');
$tiny=Fees::calculate(array_replace($draft,['platform_fee_rate'=>'50.00']),
    ['goods_paid'=>'0.03','shipping_paid'=>'0.00','goods_refunded'=>'0.01','shipping_refunded'=>'0.00','channel_fee'=>'0.00']);
billExpect($tiny['platform_fee_amount']==='0.02'&&$tiny['platform_fee_reversal']==='0.01',
    'CUMULATIVE_FEE_REVERSAL_ROUNDS_FROM_ORIGINAL_FEE');
$maximum=Fees::calculate(array_replace($draft,['platform_fee_rate'=>'100.00']),
    ['goods_paid'=>'9999999999.99','shipping_paid'=>'0.00','goods_refunded'=>'9999999999.98','shipping_refunded'=>'0.00','channel_fee'=>'0.00']);
billExpect($maximum['platform_fee_amount']==='9999999999.99'&&$maximum['platform_fee_reversal']==='9999999999.98',
    'FULL_DECIMAL_CAPACITY_PROPORTION_DOES_NOT_USE_FLOATS');
$unknown=Fees::calculate($draft,array_replace($amounts,['channel_fee'=>null]));
billExpect($unknown['merchant_receivable']===null&&$unknown['status']==='CHANNEL_FEE_UNKNOWN',
    'UNKNOWN_CHANNEL_FEE_IS_NOT_ASSUMED_ZERO');
$empty=Fees::calculate(FinanceSettingsInput::emptyDraft(),$amounts);
billExpect($empty['status']==='RULE_INCOMPLETE'&&$empty['platform_fee_amount']===null&&$empty['merchant_receivable']===null,
    'UNDECIDED_FEE_RULES_REMAIN_UNKNOWN');
$none=Fees::calculate(array_replace(FinanceSettingsInput::emptyDraft(),['platform_fee_enabled'=>false,'channel_fee_payer'=>'merchant']),$amounts);
billExpect($none['platform_fee_amount']==='0.00'&&$none['merchant_receivable']==='59.40',
    'EXPLICIT_NO_PLATFORM_FEE_NEEDS_NO_RATE_OR_REFUND_RULE');

$periodAmounts = ['sale_in_period'=>true,'goods_paid'=>'100.00','shipping_paid'=>'10.00',
    'refunded_before'=>'0.00','refunded_after'=>'50.00','goods_refunded_before'=>null,
    'goods_refunded_after'=>'50.00','channel_fee'=>'0.60'];
$salePeriod = Fees::calculatePeriod($draft, $periodAmounts);
billExpect($salePeriod === array_replace($fees, ['scope'=>'period_fee_calculation_only']),
    'SALE_PERIOD_MATCHES_CUMULATIVE_CALCULATION_WHEN_REFUNDS_BEGIN_AT_ZERO');

$refundPeriod = array_replace($periodAmounts, ['sale_in_period'=>false,'refunded_before'=>'50.00','refunded_after'=>'110.00',
    'goods_refunded_before'=>'50.00','goods_refunded_after'=>null,'channel_fee'=>'-0.20']);
$finalPeriod = Fees::calculatePeriod($draft, $refundPeriod);
billExpect($finalPeriod['sales_amount']==='0.00'&&$finalPeriod['refund_amount']==='60.00'
    &&$finalPeriod['platform_fee_amount']==='0.00'&&$finalPeriod['platform_fee_reversal']==='1.25'
    &&$finalPeriod['platform_fee_net']==='-1.25'&&$finalPeriod['channel_fee_amount']==='-0.20'
    &&$finalPeriod['merchant_receivable']==='-58.55'&&$finalPeriod['settled_amount']===null
    &&$finalPeriod['real_funds_enabled']===false,'REFUND_ONLY_PERIOD_REVERSES_REMAINING_ORIGINAL_FEE_AND_PRESERVES_NEGATIVE_BALANCE');

$smallPeriod = Fees::calculatePeriod(array_replace($draft, ['platform_fee_rate'=>'50.00']), [
    'sale_in_period'=>false,'goods_paid'=>'0.03','shipping_paid'=>'0.00','refunded_before'=>'0.01',
    'refunded_after'=>'0.02','goods_refunded_before'=>null,'goods_refunded_after'=>null,'channel_fee'=>'0.00']);
billExpect($smallPeriod['platform_fee_reversal']==='0.00'&&$smallPeriod['merchant_receivable']==='-0.01',
    'PERIOD_USES_DIFFERENCE_OF_ROUNDED_CUMULATIVES_NOT_ROUNDING_OF_INCREMENT');

$unsplitAmounts = array_replace($periodAmounts, ['goods_refunded_after'=>null]);
$unsplit = Fees::calculatePeriod($draft, $unsplitAmounts);
billExpect($unsplit['refund_amount']==='50.00'&&$unsplit['platform_fee_amount']==='2.50'
    &&$unsplit['platform_fee_reversal']===null&&$unsplit['merchant_receivable']===null
    &&$unsplit['status']==='REFUND_BASIS_UNKNOWN','PARTIAL_GOODS_REFUND_WITHOUT_PROVEN_SHIPPING_SPLIT_IS_PENDING');
$combined = Fees::calculatePeriod(array_replace($draft, ['platform_fee_basis'=>'order_paid']), $unsplitAmounts);
billExpect($combined['platform_fee_reversal']==='1.25'&&$combined['merchant_receivable']==='57.90',
    'ORDER_BASIS_USES_PROVEN_COMBINED_REFUND_WITHOUT_REQUIRING_A_SPLIT');
$noReversal = Fees::calculatePeriod(array_replace($draft, ['refund_platform_fee'=>'none']), $unsplitAmounts);
billExpect($noReversal['platform_fee_reversal']==='0.00'&&$noReversal['merchant_receivable']==='56.90',
    'EXPLICITLY_RETAINED_PLATFORM_FEE_NEEDS_NO_REFUND_SPLIT');
$unknownBasis = Fees::calculatePeriod(array_replace($draft, ['platform_fee_basis'=>null]), $periodAmounts);
billExpect($unknownBasis['status']==='RULE_INCOMPLETE'&&$unknownBasis['missing_fields']===['platform_fee_basis']
    &&$unknownBasis['platform_fee_amount']===null&&$unknownBasis['merchant_receivable']===null,
    'MISSING_SELECTED_BASIS_LEAVES_PERIOD_CALCULATION_PENDING');

$makeEvidence = static function (array $rows, array $orders): array {
    $source = ['rows' => $rows, 'issues' => []];
    return $source + ['orders' => $orders, 'source_sha256' => hash('sha256', MarketplaceRefundEvidence::canonical($source))];
};
$basis = ['root_order_id' => 1, 'sale_in_period' => true, 'goods_paid' => '40.30', 'shipping_paid' => '0.00',
    'refunded_before' => '0.00', 'refunded_after' => '20.15', 'goods_refunded_before' => null, 'goods_refunded_after' => null,
    'local_refs' => ['payment:11', 'refund:1:15']];
$statementEvidence = $makeEvidence($local, [$basis]);
$statementBill = ['source_sha256' => $statementEvidence['source_sha256'], 'source_issues' => [], 'comparison' => $match, 'period_open' => true];
$estimate = Statements::build($statementBill, $statementEvidence, $draft);
billExpect($estimate['status'] === 'PENDING_REVIEW' && $estimate['reasons'] === ['PERIOD_OPEN']
    && $estimate['totals']['platform_fee_amount'] === '1.01' && $estimate['totals']['platform_fee_reversal'] === '0.51'
    && $estimate['totals']['merchant_receivable'] === '19.53' && $estimate['totals']['settled_amount'] === null,
    'MATCHED_SALE_AND_REFUND_PRODUCE_EXACT_TRIAL_AMOUNT_WITH_OPEN_PERIOD_NOTICE');
$stale = Statements::build($statementBill, $makeEvidence([$local[0]], []), $draft);
billExpect($stale['status'] === 'PENDING_REVIEW' && in_array('SOURCE_CHANGED', $stale['reasons'], true)
    && $stale['orders'] === [] && count(array_filter($stale['totals'], static fn($value): bool => $value !== null)) === 0,
    'OUTDATED_RECONCILIATION_PRODUCES_NO_ESTIMATE');
$refundEvidence = $makeEvidence([$local[1]], [array_replace($basis, ['sale_in_period' => false, 'local_refs' => ['refund:1:15']])]);
$refundBill = ['source_sha256' => $refundEvidence['source_sha256'], 'source_issues' => [], 'period_open' => false,
    'comparison' => Bills::reconcile([$bill['rows'][1]], [$local[1]])];
$refundOnly = Statements::build($refundBill, $refundEvidence, $draft);
billExpect($refundOnly['reasons'] === ['ORIGINAL_SALE_RULE_UNKNOWN'] && $refundOnly['totals']['refund_amount'] === '20.15'
    && $refundOnly['totals']['channel_fee_amount'] === '-0.12' && $refundOnly['totals']['merchant_receivable'] === null,
    'REFUND_ONLY_WITHOUT_ORIGINAL_RULE_DOES_NOT_BORROW_SELECTED_CURRENT_RULE');

echo json_encode(['status'=>'PASS_NORMAL_FINANCE_COMPONENTS_ONLY','count'=>count($checks),'checks'=>$checks,
    'database'=>'NOT_RUN','http'=>'NOT_RUN','ui'=>'NOT_RUN','real_funds'=>'NOT_RUN'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
