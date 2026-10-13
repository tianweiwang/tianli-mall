<?php
declare(strict_types=1);
use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

return static function():void {
    $options=['merchant_permission'=>'freight.manage'];$middleware=[MerchantAuthTokenMiddleware::class,MerchantPermissionMiddleware::class];
    Route::get('freight-templates','Freight/index')->option($options)->middleware($middleware)->name('merchant.freight.list');
    Route::get('freight-templates/<id>','Freight/read')->option($options)->middleware($middleware)->name('merchant.freight.read');
    Route::post('freight-templates','Freight/create')->option($options)->middleware($middleware)->name('merchant.freight.create');
    Route::put('freight-templates/<id>','Freight/update')->option($options)->middleware($middleware)->name('merchant.freight.update');
    Route::delete('freight-templates/<id>','Freight/delete')->option($options)->middleware($middleware)->name('merchant.freight.delete');
    Route::get('freight-cities','Freight/cities')->option($options)->middleware($middleware)->name('merchant.freight.cities');
};
