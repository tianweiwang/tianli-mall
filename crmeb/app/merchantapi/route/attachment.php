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
    Route::get('attachments/videos','Attachment/videos')->option($options)->middleware($middleware)->name('merchant.attachment.video.list');
    Route::get('attachments/audio','Attachment/audio')->option($options)->middleware($middleware)->name('merchant.attachment.audio.list');
    Route::post('attachments/upload','Attachment/upload')->option($options)->middleware($middleware)->name('merchant.attachment.upload');
    Route::delete('attachments','Attachment/delete')->option($options)->middleware($middleware)->name('merchant.attachment.delete');
    Route::get('attachment-categories','Attachment/categories')->option($options)->middleware($middleware)->name('merchant.attachment.category.list');
    Route::post('attachment-categories','Attachment/createCategory')->option($options)->middleware($middleware)->name('merchant.attachment.category.create');
    Route::put('attachment-categories/<id>','Attachment/updateCategory')->option($options)->middleware($middleware)->name('merchant.attachment.category.update');
    Route::delete('attachment-categories/<id>','Attachment/deleteCategory')->option($options)->middleware($middleware)->name('merchant.attachment.category.delete');
};
