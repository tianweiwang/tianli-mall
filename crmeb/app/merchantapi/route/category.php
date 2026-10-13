<?php
declare(strict_types=1);
use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

// The cleared merchant entrypoint alone invokes this module.
return static function():void {
    Route::get('categories','Category/index')->option(['merchant_permission'=>'product.read'])
        ->middleware([MerchantAuthTokenMiddleware::class,MerchantPermissionMiddleware::class])->name('merchant.category.list');
};
