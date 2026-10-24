<?php
declare(strict_types=1);
use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

// Http glob may include this file first. Only the cleared entrypoint invokes it.
return static function(): void {
    $middleware=[MerchantAuthTokenMiddleware::class,MerchantPermissionMiddleware::class];
    Route::get('products','Product/index')->option(['merchant_permission'=>'product.read'])->middleware($middleware)->name('merchant.product.list');
    Route::post('products','Product/create')->option(['merchant_permission'=>'product.write'])->middleware($middleware)->name('merchant.product.create');
    Route::post('products/<id>/media/videos/upload','Product/uploadVideo')->option(['merchant_permission'=>'product.write'])->middleware($middleware)->name('merchant.product.media.video.upload');
    Route::post('products/<id>/media/audio/upload','Product/uploadAudio')->option(['merchant_permission'=>'product.write'])->middleware($middleware)->name('merchant.product.media.audio.upload');
    Route::put('products/batch/show','Product/batchShow')->option(['merchant_permission'=>'product.write'])->middleware($middleware)->name('merchant.product.batch.show');
    Route::get('products/<id>','Product/read')->option(['merchant_permission'=>'product.read'])->middleware($middleware)->name('merchant.product.read');
    Route::put('products/<id>','Product/update')->option(['merchant_permission'=>'product.write'])->middleware($middleware)->name('merchant.product.update');
    Route::put('products/<id>/submit-review','Product/submitReview')->option(['merchant_permission'=>'product.submit_review'])->middleware($middleware)->name('merchant.product.submit.review');
    Route::put('products/<id>/show','Product/show')->option(['merchant_permission'=>'product.write'])->middleware($middleware)->name('merchant.product.show');
    Route::delete('products/<id>','Product/delete')->option(['merchant_permission'=>'product.write'])->middleware($middleware)->name('merchant.product.delete');
    Route::put('products/<id>/stock','Stock/adjust')->option(['merchant_permission'=>'stock.adjust'])->middleware($middleware)->name('merchant.stock.adjust');
};
