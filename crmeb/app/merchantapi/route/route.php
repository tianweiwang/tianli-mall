<?php
declare(strict_types=1);

use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

Route::post('login', 'Login/login')->name('merchant.login');
Route::post('password/setup', 'Login/setupPassword')->name('merchant.password.setup');

Route::post('refresh', 'Login/refresh')
    ->middleware(MerchantAuthTokenMiddleware::class)
    ->name('merchant.refresh');
Route::post('logout', 'Login/logout')
    ->middleware(MerchantAuthTokenMiddleware::class)
    ->name('merchant.logout');
Route::get('me', 'Me/index')
    ->option(['merchant_permission' => 'merchant.profile.read'])
    ->middleware([MerchantAuthTokenMiddleware::class, MerchantPermissionMiddleware::class])
    ->name('merchant.me');
