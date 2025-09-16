<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// File serving route (unprotected)
Route::get('/files/{path}', [App\Http\Controllers\API\V1\FileController::class, 'serve'])->where('path', '.*');

// API V1 Routes with app credentials validation
Route::prefix('v1')->middleware(['api.credentials'])->group(function () {
    
    // Public routes (no authentication required)
    Route::post('/auth/login', [App\Http\Controllers\API\V1\AuthController::class, 'login']);
    Route::post('/auth/register', [App\Http\Controllers\API\V1\AuthController::class, 'register']);
    Route::post('/auth/verify-2fa', [App\Http\Controllers\API\V1\AuthController::class, 'verify2FA']);
    Route::post('/auth/setup-2fa', [App\Http\Controllers\API\V1\AuthController::class, 'setup2FA']);
    Route::post('/auth/confirm-2fa', [App\Http\Controllers\API\V1\AuthController::class, 'confirm2FA']);
    
    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        
        // Auth routes
        Route::post('/auth/logout', [App\Http\Controllers\API\V1\AuthController::class, 'logout']);
        Route::get('/auth/profile', [App\Http\Controllers\API\V1\AuthController::class, 'profile']);
        Route::put('/auth/profile', [App\Http\Controllers\API\V1\AuthController::class, 'updateProfile']);
        
        // User management
        Route::apiResource('users', App\Http\Controllers\API\V1\UserController::class)->middleware([
            'index' => 'check.privilege:users.view',
            'show' => 'check.privilege:users.view',
            'store' => 'check.privilege:users.create',
            'update' => 'check.privilege:users.update',
            'destroy' => 'check.privilege:users.delete'
        ]);
        
        // Role management
        Route::apiResource('roles', App\Http\Controllers\API\V1\RoleController::class)->middleware([
            'index' => 'check.privilege:roles.view',
            'show' => 'check.privilege:roles.view',
            'store' => 'check.privilege:roles.create',
            'update' => 'check.privilege:roles.update',
            'destroy' => 'check.privilege:roles.delete'
        ]);
        Route::post('roles/{role}/privileges', [App\Http\Controllers\API\V1\RoleController::class, 'attachPrivileges'])->middleware('check.privilege:roles.manage_privileges');
        Route::delete('roles/{role}/privileges', [App\Http\Controllers\API\V1\RoleController::class, 'detachPrivileges'])->middleware('check.privilege:roles.manage_privileges');
        
        // Privilege management
        Route::apiResource('privileges', App\Http\Controllers\API\V1\PrivilegeController::class)->middleware([
            'index' => 'check.privilege:privileges.view',
            'show' => 'check.privilege:privileges.view',
            'store' => 'check.privilege:privileges.create',
            'update' => 'check.privilege:privileges.update',
            'destroy' => 'check.privilege:privileges.delete'
        ]);
        
        // Merchant management
        Route::apiResource('merchants', App\Http\Controllers\API\V1\MerchantController::class)->middleware([
            'index' => 'check.privilege:merchants.view',
            'show' => 'check.privilege:merchants.view',
            'store' => 'check.privilege:merchants.create',
            'update' => 'check.privilege:merchants.update',
            'destroy' => 'check.privilege:merchants.delete'
        ]);
        Route::post('merchants/{merchant}/users', [App\Http\Controllers\API\V1\MerchantController::class, 'attachUsers'])->middleware('check.privilege:merchants.manage_users');
        Route::delete('merchants/{merchant}/users', [App\Http\Controllers\API\V1\MerchantController::class, 'detachUsers'])->middleware('check.privilege:merchants.manage_users');
        
        // Article management
        Route::apiResource('articles', App\Http\Controllers\API\V1\ArticleController::class)->middleware([
            'index' => 'check.privilege:articles.view',
            'show' => 'check.privilege:articles.view',
            'store' => 'check.privilege:articles.create',
            'update' => 'check.privilege:articles.update',
            'destroy' => 'check.privilege:articles.delete'
        ]);
        
        // Stock management
        Route::apiResource('stocks', App\Http\Controllers\API\V1\StockController::class)->middleware([
            'index' => 'check.privilege:stocks.view',
            'show' => 'check.privilege:stocks.view',
            'store' => 'check.privilege:stocks.create',
            'update' => 'check.privilege:stocks.update',
            'destroy' => 'check.privilege:stocks.delete'
        ]);
        Route::post('stocks/{stock}/add', [App\Http\Controllers\API\V1\StockController::class, 'addStock'])->middleware('check.privilege:stocks.manage');
        Route::post('stocks/{stock}/withdraw', [App\Http\Controllers\API\V1\StockController::class, 'withdrawStock'])->middleware('check.privilege:stocks.manage');
        Route::get('stocks/{stock}/history', [App\Http\Controllers\API\V1\StockController::class, 'history'])->middleware('check.privilege:stocks.view');
        
        // Order management
        Route::apiResource('orders', App\Http\Controllers\API\V1\OrderController::class)->middleware([
            'index' => 'check.privilege:orders.view',
            'show' => 'check.privilege:orders.view',
            'store' => 'check.privilege:orders.create',
            'update' => 'check.privilege:orders.update',
            'destroy' => 'check.privilege:orders.delete'
        ]);
        Route::post('orders/{order}/calculate', [App\Http\Controllers\API\V1\OrderController::class, 'calculateAmount'])->middleware('check.privilege:orders.manage');
        
        // Cart management
        Route::apiResource('carts', App\Http\Controllers\API\V1\CartController::class)->middleware([
            'index' => 'check.privilege:carts.view',
            'show' => 'check.privilege:carts.view',
            'store' => 'check.privilege:carts.create',
            'update' => 'check.privilege:carts.update',
            'destroy' => 'check.privilege:carts.delete'
        ]);
        
        // Payment management
        Route::apiResource('payments', App\Http\Controllers\API\V1\PaymentController::class)->middleware([
            'index' => 'check.privilege:payments.view',
            'show' => 'check.privilege:payments.view',
            'store' => 'check.privilege:payments.create',
            'update' => 'check.privilege:payments.update',
            'destroy' => 'check.privilege:payments.delete'
        ]);
        Route::post('payments/{payment}/confirm', [App\Http\Controllers\API\V1\PaymentController::class, 'confirmPayment'])->middleware('check.privilege:payments.manage');
        
        // File management
        Route::post('/files/upload', [App\Http\Controllers\API\V1\FileController::class, 'upload']);
        Route::post('/files/move', [App\Http\Controllers\API\V1\FileController::class, 'move']);
        Route::delete('/files', [App\Http\Controllers\API\V1\FileController::class, 'delete']);
        Route::get('/files/info', [App\Http\Controllers\API\V1\FileController::class, 'info']);
        
        // Export management
        Route::post('/exports/generate', [App\Http\Controllers\API\V1\ExportController::class, 'generate']);
        Route::get('/exports/history', [App\Http\Controllers\API\V1\ExportController::class, 'history']);
        
    });
});

// Export download route (unprotected, uses token authentication)
Route::get('/v1/exports/download/{token}', [App\Http\Controllers\API\V1\ExportController::class, 'download']);
