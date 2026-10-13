<?php
declare(strict_types=1);

use app\merchantapi\middleware\MerchantAuthTokenMiddleware;
use app\merchantapi\middleware\MerchantPermissionMiddleware;
use think\facade\Route;

// Service boot can register global routes before MultiApp selects this app.
Route::clear();

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

Route::get('employees', 'Employee/index')->option(['merchant_permission' => 'merchant.employee.manage'])
    ->middleware([MerchantAuthTokenMiddleware::class, MerchantPermissionMiddleware::class])->name('merchant.employee.list');
Route::post('employees', 'Employee/create')->option(['merchant_permission' => 'merchant.employee.manage'])
    ->middleware([MerchantAuthTokenMiddleware::class, MerchantPermissionMiddleware::class])->name('merchant.employee.create');
Route::put('employees/<id>', 'Employee/update')->option(['merchant_permission' => 'merchant.employee.manage'])
    ->middleware([MerchantAuthTokenMiddleware::class, MerchantPermissionMiddleware::class])->name('merchant.employee.update');
Route::put('employees/<id>/status', 'Employee/status')->option(['merchant_permission' => 'merchant.employee.manage'])
    ->middleware([MerchantAuthTokenMiddleware::class, MerchantPermissionMiddleware::class])->name('merchant.employee.status');
Route::get('roles', 'Role/index')->option(['merchant_permission' => 'merchant.role.manage'])
    ->middleware([MerchantAuthTokenMiddleware::class, MerchantPermissionMiddleware::class])->name('merchant.role.list');
Route::post('roles', 'Role/create')->option(['merchant_permission' => 'merchant.role.manage'])
    ->middleware([MerchantAuthTokenMiddleware::class, MerchantPermissionMiddleware::class])->name('merchant.role.create');
Route::put('roles/<id>', 'Role/update')->option(['merchant_permission' => 'merchant.role.manage'])
    ->middleware([MerchantAuthTokenMiddleware::class, MerchantPermissionMiddleware::class])->name('merchant.role.update');

// Register product routes only after this app has cleared prior global routes.
$registerProducts = require __DIR__ . "/product.php";
if (!$registerProducts instanceof \Closure) { throw new \LogicException("Invalid product route module"); }
$registerProducts();
unset($registerProducts);
