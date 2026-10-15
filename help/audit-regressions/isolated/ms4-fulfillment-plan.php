<?php
declare(strict_types=1);

use app\services\marketplace\order\MarketplaceOrderFulfillmentPlan;

// Numeric examples only. No saved orders/items, identity, App, PDO, payment or shipment fixtures.
require dirname(__DIR__,3).'/crmeb/app/services/marketplace/order/MarketplaceOrderFulfillmentPlan.php';

$assertions=0;
function fulfillmentSame(string $expected,string $actual,string $label):void
{
    global $assertions;
    ++$assertions;
    if($expected!==$actual)throw new RuntimeException('Fulfillment numeric example failed: '.$label);
}

$examples=[
    ['0.00',3,0,3,'0.00','zero budget'],
    ['0.01',3,0,1,'0.01','one cent first unit'],
    ['0.01',3,1,2,'0.00','one cent second unit'],
    ['0.01',3,2,3,'0.00','one cent last unit'],
    ['0.02',3,0,1,'0.01','two cents first unit'],
    ['0.02',3,1,2,'0.01','two cents second unit'],
    ['0.02',3,2,3,'0.00','two cents last unit'],
    ['0.05',3,0,2,'0.04','absolute first range'],
    ['0.05',3,2,3,'0.01','absolute remaining range'],
    ['0.05',3,1,1,'0.00','empty mathematical interval'],
    ['0.03',2,0,1,'0.02','independent first budget first half'],
    ['0.03',2,1,2,'0.01','independent first budget second half'],
    ['0.05',3,0,1,'0.02','independent second budget first third'],
    ['0.05',3,1,2,'0.02','independent second budget middle third'],
    ['0.05',3,2,3,'0.01','independent second budget last third'],
    ['12.34',1,0,1,'12.34','single quantity'],
    ['18.00','6','1','4','9.00','canonical integer text parameters'],
    ['9999999999.99',2147483647,0,1,'4.66','maximum saved amount first unit'],
    ['9999999999.99',2147483647,2147483646,2147483647,'4.65','maximum quantity last unit'],
    ['100000000000000000000.01',3,0,1,'33333333333333333333.34','derived budget beyond native integer first'],
    ['100000000000000000000.01',3,1,2,'33333333333333333333.34','derived budget beyond native integer second'],
    ['100000000000000000000.01',3,2,3,'33333333333333333333.33','derived budget beyond native integer third'],
    ['21474836469978525163.53',2147483647,0,1,'9999999999.99','derived product first unit'],
    ['21474836469978525163.53',2147483647,2147483646,2147483647,'9999999999.99','derived product last unit'],
];
foreach($examples as [$budget,$quantity,$start,$end,$expected,$label]){
    fulfillmentSame($expected,MarketplaceOrderFulfillmentPlan::sliceMoney($budget,$quantity,$start,$end),$label);
}

// Recombining absolute ranges in any grouping never changes their original denominator or cent budget.
foreach([['0.01',3,[0,1,3]],['0.02',3,[0,2,3]],['0.05',3,[0,1,2,3]],
    ['19.99',7,[0,2,5,7]],['100000000000000000000.01',2147483647,[0,1,2147483646,2147483647]]] as [$budget,$quantity,$cuts]){
    $sum='0.00';
    for($index=1;$index<count($cuts);++$index){
        $sum=bcadd($sum,MarketplaceOrderFulfillmentPlan::sliceMoney($budget,$quantity,$cuts[$index-1],$cuts[$index]),2);
    }
    fulfillmentSame($budget,$sum,'partition conserves original budget '.$budget);
}
fulfillmentSame('0.05',bcadd(MarketplaceOrderFulfillmentPlan::sliceMoney('0.05',3,2,3),
    MarketplaceOrderFulfillmentPlan::sliceMoney('0.05',3,0,2),2),'separate requests regroup absolute intervals');

echo json_encode(['status'=>'NORMAL_FULFILLMENT_MATH_ONLY_PASS','assertions'=>$assertions,
    'saved_plan_and_projection'=>'PENDING_REAL_SAVED_ROWS','App_SQL_identity_payment_shipping_executed'=>false],JSON_THROW_ON_ERROR)."\n";
