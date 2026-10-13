<?php
declare(strict_types=1);

use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

// Glob inclusion has no registration side effect. The unique entry calls this after clear.
return static function(): void {
    $options=['merchant_permission'=>'attachment.manage'];
    $middleware=[MerchantAuthTokenMiddleware::class,MerchantPermissionMiddleware::class];
    Route::get('attachments','Attachment/index')->option($options)->middleware($middleware)->name('merchant.attachment.list');
    Route::post('attachments/upload','Attachment/upload')->option($options)->middleware($middleware)->name('merchant.attachment.upload');
    Route::delete('attachments','Attachment/delete')->option($options)->middleware($middleware)->name('merchant.attachment.delete');
    Route::get('attachment-categories','Attachment/categories')->option($options)->middleware($middleware)->name('merchant.attachment.category.list');
    Route::post('attachment-categories','Attachment/createCategory')->option($options)->middleware($middleware)->name('merchant.attachment.category.create');
    Route::put('attachment-categories/<id>','Attachment/updateCategory')->option($options)->middleware($middleware)->name('merchant.attachment.category.update');
};
