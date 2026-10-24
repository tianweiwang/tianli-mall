<?php
declare(strict_types=1);

use think\facade\Route;
use app\adminapi\middleware\AdminAuthTokenMiddleware;
use app\adminapi\middleware\MarketplaceBoundaryMiddleware;
use app\adminapi\middleware\MarketplaceScopeMiddleware;
use app\http\middleware\AllowOriginMiddleware;

Route::group('marketplace', function () {
    Route::get('platform-assets', 'v1.marketplace.PlatformAsset/index')->name('platform-assets-list')->option(['marketplace_permission'=>'platform-assets-list']);
    Route::post('platform-assets', 'v1.marketplace.PlatformAsset/upload')->name('platform-assets-upload')->option(['marketplace_permission'=>'platform-assets-upload']);
    Route::get('platform-asset-categories', 'v1.marketplace.PlatformAsset/categories')->name('platform-asset-categories-list')->option(['marketplace_permission'=>'platform-asset-categories-list']);
    Route::post('products/<id>/import-platform-image', 'v1.marketplace.PlatformAsset/importProduct')->name('marketplace-products-import-platform-image')
        ->option(['marketplace_permission'=>'marketplace-products-import-platform-image','legacy_product_write'=>'marketplace-products-import-platform-image']);
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
    Route::get('products', 'v1.marketplace.ProductReview/index')->name('marketplace-products-list')
        ->option(['marketplace_permission'=>'marketplace-products-list']);
    Route::put('products/<id>/review', 'v1.marketplace.ProductReview/review')->name('marketplace-products-review')
        ->option(['marketplace_permission'=>'marketplace-products-review']);
    Route::put('products/<id>/off-shelf', 'v1.marketplace.ProductReview/offShelf')->name('marketplace-products-off-shelf')
        ->option(['marketplace_permission'=>'marketplace-products-off-shelf']);
    Route::post('products/<id>/stock', 'v1.marketplace.ProductStock/adjust')->name('marketplace-products-stock-adjust')
        ->option(['marketplace_permission'=>'marketplace-products-stock-adjust']);
    Route::put('products/<id>/submit-review', 'v1.marketplace.ProductSubmission/submitReview')->name('marketplace-products-submit-review')
        ->option(['marketplace_permission'=>'marketplace-products-submit-review']);
    Route::get('reviews', 'v1.marketplace.OrderReview/index')->name('marketplace-reviews-list')
        ->option(['marketplace_permission'=>'marketplace-reviews-list']);
    Route::get('reviews/<id>', 'v1.marketplace.OrderReview/read')->name('marketplace-reviews-read')
        ->option(['marketplace_permission'=>'marketplace-reviews-read']);
    Route::put('reviews/<id>/review', 'v1.marketplace.OrderReview/review')->name('marketplace-reviews-moderate')
        ->option(['marketplace_permission'=>'marketplace-reviews-moderate']);
    Route::get('aftersales', 'v1.marketplace.Aftersale/index')->name('marketplace-aftersales-list')
        ->option(['marketplace_permission'=>'marketplace-aftersales-list']);
    Route::get('aftersales/<id>', 'v1.marketplace.Aftersale/read')->name('marketplace-aftersales-read')
        ->option(['marketplace_permission'=>'marketplace-aftersales-read']);
    Route::post('aftersales/<id>/approve', 'v1.marketplace.Aftersale/approve')->name('marketplace-aftersales-approve')
        ->option(['marketplace_permission'=>'marketplace-aftersales-approve']);
    Route::post('aftersales/<id>/reject', 'v1.marketplace.Aftersale/reject')->name('marketplace-aftersales-reject')
        ->option(['marketplace_permission'=>'marketplace-aftersales-reject']);
    Route::post('aftersales/<id>/return-accept', 'v1.marketplace.Aftersale/acceptReturn')->name('marketplace-aftersales-return-accept')
        ->option(['marketplace_permission'=>'marketplace-aftersales-return-accept']);
    Route::post('aftersale-files/<file_id>/read-ticket', 'v1.marketplace.AftersaleFile/ticket')->name('marketplace-aftersale-files-ticket')
        ->option(['marketplace_permission'=>'marketplace-aftersale-files-ticket']);
    Route::get('aftersale-files/<file_id>/content', 'v1.marketplace.AftersaleFile/content')->name('marketplace-aftersale-files-read')
        ->option(['marketplace_permission'=>'marketplace-aftersale-files-read']);
})->option(['marketplace_api'=>true, 'mark'=>'marketplace', 'mark_name'=>'经营商家'])
    ->middleware([AllowOriginMiddleware::class, MarketplaceBoundaryMiddleware::class,
        AdminAuthTokenMiddleware::class, MarketplaceScopeMiddleware::class]);
