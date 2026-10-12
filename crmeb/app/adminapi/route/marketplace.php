<?php
declare(strict_types=1);

use think\facade\Route;
use app\adminapi\middleware\AdminAuthTokenMiddleware;
use app\adminapi\middleware\MarketplaceBoundaryMiddleware;
use app\adminapi\middleware\MarketplaceScopeMiddleware;
use app\http\middleware\AllowOriginMiddleware;

Route::group('marketplace', function () {
    Route::get('merchants', 'v1.marketplace.Merchant/index')->name('marketplace-merchants-list')
        ->option(['marketplace_permission'=>'marketplace-merchants-list']);
    Route::post('merchants', 'v1.marketplace.Merchant/create')->name('marketplace-merchants-create')
        ->option(['marketplace_permission'=>'marketplace-merchants-create']);
    Route::get('merchants/<id>', 'v1.marketplace.Merchant/read')->name('marketplace-merchants-read')
        ->option(['marketplace_permission'=>'marketplace-merchants-read']);
    Route::put('merchants/<id>', 'v1.marketplace.Merchant/update')->name('marketplace-merchants-update')
        ->option(['marketplace_permission'=>'marketplace-merchants-update']);
    Route::put('merchants/<id>/review', 'v1.marketplace.Merchant/review')->name('marketplace-merchants-review')
        ->option(['marketplace_permission'=>'marketplace-merchants-review']);
    Route::put('merchants/<id>/flags', 'v1.marketplace.Merchant/flags')->name('marketplace-merchants-flags')
        ->option(['marketplace_permission'=>'marketplace-merchants-flags']);
    Route::post('merchants/<id>/admin-employee', 'v1.marketplace.Merchant/createAdminEmployee')->name('marketplace-merchants-admin-employee')
        ->option(['marketplace_permission'=>'marketplace-merchants-admin-employee']);
})->option(['marketplace_api'=>true, 'mark'=>'marketplace', 'mark_name'=>'经营商家'])
    ->middleware([AllowOriginMiddleware::class, MarketplaceBoundaryMiddleware::class,
        AdminAuthTokenMiddleware::class, MarketplaceScopeMiddleware::class]);
