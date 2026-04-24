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
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'API is healthy',
            'data' => ['status' => 'ok'],
        ]);
    });
    Route::post('/auth/login', [App\Http\Controllers\API\V1\AuthController::class, 'login']);
    Route::post('/auth/register', [App\Http\Controllers\API\V1\AuthController::class, 'register']);
    Route::post('/auth/verify-2fa', [App\Http\Controllers\API\V1\AuthController::class, 'verify2FA']);
    Route::post('/auth/setup-2fa', [App\Http\Controllers\API\V1\AuthController::class, 'setup2FA']);
    Route::post('/auth/confirm-2fa', [App\Http\Controllers\API\V1\AuthController::class, 'confirm2FA']);
    Route::get('/auth/registration-merchants', [App\Http\Controllers\API\V1\AuthController::class, 'registrationMerchants']);
    Route::post('/auth/forgot-password', [App\Http\Controllers\API\V1\AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password/precheck', [App\Http\Controllers\API\V1\AuthController::class, 'resetPasswordPrecheck']);
    Route::post('/auth/reset-password/verify-otp', [App\Http\Controllers\API\V1\AuthController::class, 'verifyResetOtp']);
    Route::post('/auth/reset-password', [App\Http\Controllers\API\V1\AuthController::class, 'resetPassword']);
    
    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        
        // Auth routes
        Route::post('/auth/logout', [App\Http\Controllers\API\V1\AuthController::class, 'logout']);
        Route::get('/auth/profile', [App\Http\Controllers\API\V1\AuthController::class, 'profile']);
        Route::put('/auth/profile', [App\Http\Controllers\API\V1\AuthController::class, 'updateProfile']);
        Route::get('/notifications', [App\Http\Controllers\API\V1\NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [App\Http\Controllers\API\V1\NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [App\Http\Controllers\API\V1\NotificationController::class, 'readAll']);
        Route::patch('/notifications/{notification}/read', [App\Http\Controllers\API\V1\NotificationController::class, 'markRead']);

        // Dashboard statistics
        Route::get('/stats/overview', [App\Http\Controllers\API\V1\StatsController::class, 'overview']);
        
        // User management
        Route::apiResource('users', App\Http\Controllers\API\V1\UserController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:users.view')
            ->middlewareFor('store', 'check.privilege:users.create')
            ->middlewareFor('update', 'check.privilege:users.update')
            ->middlewareFor('destroy', 'check.privilege:users.delete');
        
        // Role management
        Route::apiResource('roles', App\Http\Controllers\API\V1\RoleController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:roles.view')
            ->middlewareFor('store', 'check.privilege:roles.create')
            ->middlewareFor('update', 'check.privilege:roles.update')
            ->middlewareFor('destroy', 'check.privilege:roles.delete');
        Route::post('roles/{role}/privileges', [App\Http\Controllers\API\V1\RoleController::class, 'attachPrivileges'])->middleware('check.privilege:roles.manage_privileges');
        Route::delete('roles/{role}/privileges', [App\Http\Controllers\API\V1\RoleController::class, 'detachPrivileges'])->middleware('check.privilege:roles.manage_privileges');
        
        // Privilege management
        Route::apiResource('privileges', App\Http\Controllers\API\V1\PrivilegeController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:privileges.view')
            ->middlewareFor('store', 'check.privilege:privileges.create')
            ->middlewareFor('update', 'check.privilege:privileges.update')
            ->middlewareFor('destroy', 'check.privilege:privileges.delete');
        
        // Merchant management
        Route::apiResource('merchants', App\Http\Controllers\API\V1\MerchantController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:merchants.view')
            ->middlewareFor('store', 'check.privilege:merchants.create')
            ->middlewareFor('update', 'check.privilege:merchants.update')
            ->middlewareFor('destroy', 'check.privilege:merchants.delete');
        Route::post('merchants/{merchant}/users', [App\Http\Controllers\API\V1\MerchantController::class, 'attachUsers'])->middleware('check.privilege:merchants.manage_users');
        Route::delete('merchants/{merchant}/users', [App\Http\Controllers\API\V1\MerchantController::class, 'detachUsers'])->middleware('check.privilege:merchants.manage_users');
        
        // Article management
        Route::apiResource('articles', App\Http\Controllers\API\V1\ArticleController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:articles.view')
            ->middlewareFor('store', 'check.privilege:articles.create')
            ->middlewareFor('update', 'check.privilege:articles.update')
            ->middlewareFor('destroy', 'check.privilege:articles.delete');
        
        // Stock management
        Route::apiResource('stocks', App\Http\Controllers\API\V1\StockController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:stocks.view')
            ->middlewareFor('store', 'check.privilege:stocks.create')
            ->middlewareFor('update', 'check.privilege:stocks.update')
            ->middlewareFor('destroy', 'check.privilege:stocks.delete');
        Route::post('stocks/{stock}/add', [App\Http\Controllers\API\V1\StockController::class, 'addStock'])->middleware('check.privilege:stocks.manage');
        Route::post('stocks/{stock}/withdraw', [App\Http\Controllers\API\V1\StockController::class, 'withdrawStock'])->middleware('check.privilege:stocks.manage');
        Route::get('stocks/{stock}/history', [App\Http\Controllers\API\V1\StockController::class, 'history'])->middleware('check.privilege:stocks.view');
        
        // Order management
        Route::apiResource('orders', App\Http\Controllers\API\V1\OrderController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:orders.view')
            ->middlewareFor('store', 'check.privilege:orders.create')
            ->middlewareFor('update', 'check.privilege:orders.update')
            ->middlewareFor('destroy', 'check.privilege:orders.delete');
        Route::post('orders/{order}/calculate', [App\Http\Controllers\API\V1\OrderController::class, 'calculateAmount'])->middleware('check.privilege:orders.manage');
        Route::get('orders/{order}/history', [App\Http\Controllers\API\V1\OrderController::class, 'history'])->middleware('check.privilege:orders.view');
        
        // Cart management
        Route::apiResource('carts', App\Http\Controllers\API\V1\CartController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:carts.view')
            ->middlewareFor('store', 'check.privilege:carts.create')
            ->middlewareFor('update', 'check.privilege:carts.update')
            ->middlewareFor('destroy', 'check.privilege:carts.delete');
        
        // Payment management
        Route::apiResource('payments', App\Http\Controllers\API\V1\PaymentController::class)
            ->middlewareFor(['index', 'show'], 'check.privilege:payments.view')
            ->middlewareFor('store', 'check.privilege:payments.create')
            ->middlewareFor('update', 'check.privilege:payments.update')
            ->middlewareFor('destroy', 'check.privilege:payments.delete');
        Route::post('payments/{payment}/confirm', [App\Http\Controllers\API\V1\PaymentController::class, 'confirmPayment'])->middleware('check.privilege:payments.manage');
        
        // File management
        Route::post('/files/upload', [App\Http\Controllers\API\V1\FileController::class, 'upload']);
        Route::post('/files/move', [App\Http\Controllers\API\V1\FileController::class, 'move']);
        Route::delete('/files', [App\Http\Controllers\API\V1\FileController::class, 'delete']);
        Route::get('/files/info', [App\Http\Controllers\API\V1\FileController::class, 'info']);
        
        // Export management
        Route::post('/exports/generate', [App\Http\Controllers\API\V1\ExportController::class, 'generate']);
        Route::get('/exports/history', [App\Http\Controllers\API\V1\ExportController::class, 'history']);
        Route::delete('/exports/history/{id}', [App\Http\Controllers\API\V1\ExportController::class, 'destroyHistory']);
        
    });
});

// Export download route (unprotected, uses token authentication)
Route::get('/v1/exports/download/{token}', [App\Http\Controllers\API\V1\ExportController::class, 'download']);
