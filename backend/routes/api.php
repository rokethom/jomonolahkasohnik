<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\AdminChatController;
use App\Http\Controllers\Api\Admin\InternalChatController;
use App\Http\Controllers\Api\Admin\InternalNoteController;
use App\Http\Controllers\Api\Admin\OperHandleApprovalController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CancelRequestController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DriverAuthController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\GeofenceController;
use App\Http\Controllers\Api\GeocodingController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\JojoBotController;
use App\Http\Controllers\Api\KeywordParserController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MapProviderController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicMediaController;
use App\Http\Controllers\Api\PushDeviceTokenController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\UserLocationController;

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

Route::middleware('auth:sanctum')->get('/user', [ProfileController::class, 'show']);
Route::middleware('auth:sanctum')->get('/me', [ProfileController::class, 'show']);

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/auth/login', fn () => response()->json([
    'message' => 'Use POST /api/auth/login with email and password.',
    'method' => 'POST',
], 200));
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/driver/auth/google', [DriverAuthController::class, 'google'])->middleware('throttle:driver-google-login');
Route::get('/home', HomeController::class);
Route::get('/map/provider', MapProviderController::class);
Route::get('/settings/public', [SettingsController::class, 'publicSettings']);
Route::get('/settings', [SettingsController::class, 'publicSettings']);
Route::get('/media/{path}', PublicMediaController::class)->where('path', '.*');
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/keyword-parsers', [KeywordParserController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/user/profile', [ProfileController::class, 'update']);
    Route::post('/user/profile', [ProfileController::class, 'update']);
    Route::post('/user/location', [UserLocationController::class, 'store']);
    Route::post('/push/device-token', [PushDeviceTokenController::class, 'store']);
    Route::delete('/push/device-token', [PushDeviceTokenController::class, 'destroy']);

    Route::prefix('admin')->middleware('role:admin,gm,hrd,manager,spv,operator,eksekutor')->group(function () {
        Route::get('/bootstrap', [AdminController::class, 'bootstrap']);
        Route::get('/monitoring', [AdminController::class, 'monitoring'])->middleware('permission:view_report');
        Route::patch('/system-settings', [AdminController::class, 'updateSystemSettings']);
        Route::get('/users', [AdminController::class, 'users'])->middleware('permission:create_user');
        Route::post('/users', [AdminController::class, 'storeUser'])->middleware('permission:create_user');
        Route::put('/users/{user}', [AdminController::class, 'updateUser'])->middleware('permission:create_user');
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser'])->middleware('permission:create_user');
        Route::post('/users/{user}/reset-password', [AdminController::class, 'resetPassword'])->middleware('permission:create_user');
        Route::post('/users/{user}/reset-token', [AdminController::class, 'resetUserToken'])->middleware('permission:create_user');
        Route::get('/orders', [AdminController::class, 'orders'])->middleware('permission:monitor_live_order');
        Route::post('/orders/{order}/assign-driver', [AdminController::class, 'assignDriver'])->middleware('permission:assign_driver');
        Route::post('/orders/{order}/broadcast-drivers', [AdminController::class, 'broadcastDrivers'])->middleware('permission:assign_driver');
        Route::post('/orders/manual/preview', [AdminController::class, 'previewManualOrder']);
        Route::post('/orders/manual', [AdminController::class, 'manualOrder']);
        Route::patch('/orders/{order}/price', [AdminController::class, 'updateOrderPrice'])->middleware('permission:edit_tarif');
        Route::post('/drivers/{driver}/suspend', [AdminController::class, 'suspendDriver'])->middleware('permission:suspend_driver');
        Route::post('/drivers/{driver}/release-suspend', [AdminController::class, 'releaseDriverSuspend'])->middleware('permission:unsuspend_driver');
        Route::post('/drivers/{driver}/deposit/paid', [AdminController::class, 'markDriverDepositPaid'])->middleware('permission:suspend_driver');
        Route::post('/drivers/{driver}/deposit/unpaid', [AdminController::class, 'markDriverDepositUnpaid'])->middleware('permission:suspend_driver');
        Route::post('/drivers/{driver}/reset-token', [AdminController::class, 'resetDriverToken'])->middleware('permission:suspend_driver');
        Route::patch('/drivers/{driver}/google-auth', [AdminController::class, 'updateDriverGoogleAuth'])->middleware('permission:suspend_driver');
        Route::post('/drivers/{driver}/google-auth/reset-bind', [AdminController::class, 'resetDriverGoogleBind'])->middleware('permission:suspend_driver');
        Route::post('/drivers/{driver}/google-auth/suspend', [AdminController::class, 'suspendDriverGoogleAuth'])->middleware('permission:suspend_driver');
        Route::post('/drivers/{driver}/google-auth/unlock', [AdminController::class, 'unlockDriverGoogleAuth'])->middleware('permission:suspend_driver');
        Route::put('/drivers/{driver}/config', [AdminController::class, 'updateDriverConfig'])->middleware('permission:suspend_driver');
        Route::get('/price-settings', [AdminController::class, 'priceSettings'])->middleware('permission:edit_tarif');
        Route::post('/price-settings', [AdminController::class, 'storePriceSetting'])->middleware('permission:edit_tarif');
        Route::put('/price-settings/{priceSetting}', [AdminController::class, 'updatePriceSetting'])->middleware('permission:edit_tarif');
        Route::delete('/price-settings/{priceSetting}', [AdminController::class, 'destroyPriceSetting'])->middleware('permission:edit_tarif');
        Route::get('/ring-pricing-rules', [AdminController::class, 'ringPricingRules']);
        Route::post('/ring-pricing-rules', [AdminController::class, 'storeRingPricingRule']);
        Route::put('/ring-pricing-rules/{ringPricingRule}', [AdminController::class, 'updateRingPricingRule']);
        Route::delete('/ring-pricing-rules/{ringPricingRule}', [AdminController::class, 'destroyRingPricingRule']);
        Route::post('/ring-pricing-suggestions/{suggestion}/approve', [AdminController::class, 'approveRingPricingSuggestion']);
        Route::post('/ring-pricing-suggestions/{suggestion}/reject', [AdminController::class, 'rejectRingPricingSuggestion']);
        Route::get('/branches', [AdminController::class, 'branches']);
        Route::post('/branches', [AdminController::class, 'storeBranch']);
        Route::get('/geofences', [AdminController::class, 'geofences']);
        Route::get('/location-logs', [AdminController::class, 'locationLogs']);
        Route::get('/chats', [AdminController::class, 'chats'])->middleware('permission:monitor_live_chat');
        Route::get('/chat/{conversation}', [AdminChatController::class, 'show'])->middleware('permission:monitor_live_chat');
        Route::post('/send-message', [AdminChatController::class, 'sendMessage'])->middleware('permission:monitor_live_chat');
        Route::post('/chat/{conversation}/close', [AdminChatController::class, 'close'])->middleware('permission:monitor_live_chat');
        Route::post('/chat/cancel-requests/{cancelRequest}/approve', [AdminChatController::class, 'approveCancel'])->middleware('permission:approve_cancel_order');
        Route::post('/chat/cancel-requests/{cancelRequest}/reject', [AdminChatController::class, 'rejectCancel'])->middleware('permission:reject_cancel_order');
        Route::get('/internal-chat/rooms', [InternalChatController::class, 'rooms']);
        Route::post('/internal-chat/rooms', [InternalChatController::class, 'storeRoom']);
        Route::get('/internal-chat/rooms/{room}/messages', [InternalChatController::class, 'messages']);
        Route::post('/internal-chat/rooms/{room}/messages', [InternalChatController::class, 'sendMessage']);
        Route::get('/internal-notes', [InternalNoteController::class, 'index']);
        Route::post('/internal-notes', [InternalNoteController::class, 'store']);
        Route::patch('/internal-notes/{internalNote}', [InternalNoteController::class, 'update']);
        Route::post('/internal-notes/{internalNote}/replies', [InternalNoteController::class, 'reply']);
        Route::delete('/internal-notes/{internalNote}', [InternalNoteController::class, 'destroy']);
        Route::post('/oper-handles/{operHandle}/approve', [OperHandleApprovalController::class, 'approve']);
        Route::get('/reports', [AdminController::class, 'reports'])->middleware('permission:view_report');
        Route::get('/reports/driver-deposits', [AdminController::class, 'driverDepositReport'])->middleware('permission:view_report');
        Route::get('/reports/driver-deposits/export', [AdminController::class, 'exportDriverDepositReport'])->middleware('permission:export_report');
    });

    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/geocode', GeocodingController::class);
    Route::get('/geofences/{branchId}', [GeofenceController::class, 'index']);
    Route::post('/location/validate', [LocationController::class, 'validateLocation']);
    Route::post('/pricing/calculate', [PricingController::class, 'calculate'])->middleware('profile.complete');
    Route::post('/jojobot/preview', [JojoBotController::class, 'preview'])->middleware('profile.complete');

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/quote', [OrderController::class, 'quote'])->middleware('profile.complete');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('profile.complete');
    Route::post('/orders/{order}/find-driver', [OrderController::class, 'findDriver']);
    Route::get('/driver/bootstrap', [DriverController::class, 'bootstrap']);
    Route::get('/orders/{order}/can-accept', [DriverController::class, 'canAccept']);
    Route::post('/orders/{order}/accept', [DriverController::class, 'accept']);
    Route::get('/driver/profile', [DriverController::class, 'profile']);
    Route::get('/driver/finance', [DriverController::class, 'finance']);
    Route::get('/driver/performance', [DriverController::class, 'performance']);
    Route::post('/driver/availability', [DriverController::class, 'updateAvailability']);
    Route::put('/driver/profile', [DriverController::class, 'updateProfile']);
    Route::post('/driver/profile', [DriverController::class, 'updateProfile']);
    Route::post('/driver/request-order/preview', [DriverController::class, 'previewRequest']);
    Route::post('/driver/request-order', [DriverController::class, 'requestOrder']);
    Route::post('/orders/{order}/oper-handle', [DriverController::class, 'operHandle']);
    Route::post('/orders/{order}/adjustments', [DriverController::class, 'adjustOrder']);
    Route::post('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/{order}/complete', [OrderController::class, 'complete']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/rating', [RatingController::class, 'store']);

    Route::post('/chats/operator', [ChatController::class, 'startOperator']);
    Route::post('/orders/{order}/chat', [ChatController::class, 'startOrder']);
    Route::get('/orders/{order}/messages', [ChatController::class, 'orderMessages']);
    Route::post('/messages', [ChatController::class, 'sendOrderMessage']);
    Route::get('/chats/{conversation}/messages', [ChatController::class, 'messages']);
    Route::post('/chats/{conversation}/messages', [ChatController::class, 'send']);
    Route::post('/chats/{conversation}/typing', [ChatController::class, 'typing']);
    Route::post('/chats/{conversation}/read', [ChatController::class, 'read']);
    Route::post('/chats/{conversation}/operator-rating', [ChatController::class, 'rateOperator']);

    Route::post('/orders/{order}/cancel-request', [CancelRequestController::class, 'store']);
    Route::post('/cancel-requests/{cancelRequest}/approve', [CancelRequestController::class, 'approve'])->middleware('permission:approve_cancel_order');
    Route::post('/cancel-requests/{cancelRequest}/reject', [CancelRequestController::class, 'reject'])->middleware('permission:reject_cancel_order');
});
