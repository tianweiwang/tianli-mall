<?php
declare(strict_types=1);

use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

// Invoked once by this app after its existing Route::clear().
return static function():void {
    $middleware=[MerchantAuthTokenMiddleware::class,MerchantPermissionMiddleware::class];
    Route::get('aftersales','Aftersale/index')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.aftersale.list');
    Route::get('aftersales/<id>','Aftersale/read')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.aftersale.read');
    Route::post('aftersales/<id>/approve','Aftersale/approve')->option(['merchant_permission'=>'aftersale.handle'])->middleware($middleware)->name('merchant.aftersale.approve');
    Route::post('aftersales/<id>/reject','Aftersale/reject')->option(['merchant_permission'=>'aftersale.handle'])->middleware($middleware)->name('merchant.aftersale.reject');
    Route::post('aftersales/<id>/return-accept','Aftersale/acceptReturn')->option(['merchant_permission'=>'aftersale.handle'])->middleware($middleware)->name('merchant.aftersale.return-accept');
    Route::post('aftersale-files/<file_id>/read-ticket','AftersaleFile/ticket')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.aftersale.file.ticket');
    Route::get('aftersale-files/<file_id>/content','AftersaleFile/content')->option(['merchant_permission'=>'order.read'])->middleware($middleware)->name('merchant.aftersale.file.content');
};
