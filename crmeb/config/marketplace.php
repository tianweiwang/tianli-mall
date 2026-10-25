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
    'aftersale'=>[
        // Only new applications use this switch; saved obligations and replay remain available.
        'enabled'=>Env::get('marketplace.aftersale.enabled',false),
        // Explicit persistent private storage; no public/runtime path fallback.
        'private_root'=>Env::get('marketplace.aftersale.private_root',''),
    ],
    'customer_form'=>[
        'private_root'=>Env::get('marketplace.customer_form.private_root',''),
    ],
    'product_media_scan'=>[
        'private_root'=>Env::get('marketplace.product_media_scan.private_root',''),
        'max_open_leases'=>Env::get('marketplace.product_media_scan.max_open_leases',16),
        'per_issuer'=>Env::get('marketplace.product_media_scan.per_issuer',4),
        'max_terminal_leases'=>Env::get('marketplace.product_media_scan.max_terminal_leases',64),
        'gc_batch'=>Env::get('marketplace.product_media_scan.gc_batch',16),
        'gc_period_seconds'=>Env::get('marketplace.product_media_scan.gc_period_seconds',60),
    ],
    'product_video_upload'=>[
        // Opt-in; the original whole-file upload remains available while disabled.
        'enabled'=>Env::get('marketplace.product_video_upload.enabled',false),
        'private_root'=>Env::get('marketplace.product_video_upload.private_root',''),
        'max_open_sessions'=>Env::get('marketplace.product_video_upload.max_open_sessions',16),
        'per_actor'=>Env::get('marketplace.product_video_upload.per_actor',4),
        'ttl_seconds'=>Env::get('marketplace.product_video_upload.ttl_seconds',600),
    ],
    'payment'=>[
        // Think Env reads PHP_MARKETPLACE_PAYMENT_TEST_ENABLED; APP_ENV is checked separately at resolution.
        'test_enabled'=>Env::get('marketplace.payment.test_enabled',false),
    ],
];
