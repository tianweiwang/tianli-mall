<?php
use think\facade\Env;

// Deployment must bind this identity to the explicitly confirmed actual MS2 result.
// A matching existing row alone does not establish the self-operated business identity.
return [
    'enabled'=>Env::get('marketplace.enabled',false),
    'self_operated_mer_id'=>Env::get('marketplace.self_operated_mer_id',null),
    'trade'=>[
        'enabled'=>Env::get('marketplace.trade.enabled',false),
        'disabled_features'=>['coupon','integral','balance','brokerage','activity'],
    ],
    'payment'=>[
        // Think Env reads PHP_MARKETPLACE_PAYMENT_TEST_ENABLED; APP_ENV is checked separately at resolution.
        'test_enabled'=>Env::get('marketplace.payment.test_enabled',false),
    ],
];
