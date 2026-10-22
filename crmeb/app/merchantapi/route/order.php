<?php
declare(strict_types=1);

use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

// The entrypoint invokes this module only after clearing prior global routes.
return static function(): void {
    $middleware=[MerchantAuthTokenMiddleware::class,MerchantPermissionMiddleware::class];
    Route::get('orders/<id>/packages','Fulfillment/packages')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.fulfillment.packages');
    Route::get('packages/<id>','Fulfillment/read')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.fulfillment.package');
    Route::post('orders/<id>/shipments','Fulfillment/ship')->option(['merchant_permission'=>'order.ship'])->middleware($middleware)->name('merchant.fulfillment.ship');
    Route::post('orders/<root_id>/form-files/<asset_id>/read-ticket','OrderFormFile/read')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.order.form.ticket');
    Route::get('orders/<root_id>/form-files/<asset_id>/content','OrderFormFile/read')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.order.form.content');
    Route::get('orders','Order/index')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.order.list');
    Route::get('orders/<id>','Order/read')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.order.read');
};
