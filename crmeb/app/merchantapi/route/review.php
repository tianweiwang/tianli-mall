<?php
declare(strict_types=1);
use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

// Only the cleared merchant route entrypoint invokes this module.
return static function():void {
    $middleware=[MerchantAuthTokenMiddleware::class,MerchantPermissionMiddleware::class];
    Route::get('reviews','Review/index')->option(['merchant_permission'=>'product.read'])->middleware($middleware)->name('merchant.review.list');
    Route::get('reviews/<id>','Review/read')->option(['merchant_permission'=>'product.read'])->middleware($middleware)->name('merchant.review.read');
    Route::put('reviews/<id>/reply','Review/reply')->option(['merchant_permission'=>'product.write'])->middleware($middleware)->name('merchant.review.reply');
};
