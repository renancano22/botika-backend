<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DispensingController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\ResidentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\ExpireUnclaimedRequests;
use Illuminate\Support\Facades\Route;

// ---- Public: resident registration and login
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
Route::get('/barangays', fn () => config('botika.barangays'));

Route::middleware(['auth:sanctum', ExpireUnclaimedRequests::class])->group(function () {

    // ---- All roles
    Route::middleware('role:admin,staff,resident')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', DashboardController::class);
        Route::get('/medicines', [MedicineController::class, 'index']);
        Route::get('/medicines/categories', [MedicineController::class, 'categories']);
        Route::get('/requests', [RequestController::class, 'index']);
        Route::get('/requests/{medicineRequest}', [RequestController::class, 'show']);
        Route::get('/dispensing', [DispensingController::class, 'index']);
        Route::get('/notifications', [NotificationController::class, 'index']);
    });

    // ---- Resident
    Route::middleware('role:resident')->group(function () {
        Route::post('/requests', [RequestController::class, 'store']);
        Route::put('/requests/{medicineRequest}', [RequestController::class, 'update']);
        Route::post('/requests/{medicineRequest}/cancel', [RequestController::class, 'cancel']);

        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::post('/profile/photo', [ProfileController::class, 'updatePhoto']);
        Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto']);
    });

    // ---- Administrator + Pharmacy staff
    Route::middleware('role:admin,staff')->group(function () {
        Route::post('/medicines', [MedicineController::class, 'store']);
        Route::put('/medicines/{medicine}', [MedicineController::class, 'update']);

        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/alerts', [InventoryController::class, 'alerts']);
        Route::get('/inventory/transactions', [InventoryController::class, 'transactions']);
        Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn']);
        Route::post('/inventory/{inventory}/stock-out', [InventoryController::class, 'stockOut']);

        Route::post('/requests/{medicineRequest}/approve', [RequestController::class, 'approve']);
        Route::post('/requests/{medicineRequest}/reject', [RequestController::class, 'reject']);

        Route::get('/residents', [ResidentController::class, 'index']);
        Route::get('/residents/lookup', [ResidentController::class, 'lookup']);

        Route::post('/dispensing', [DispensingController::class, 'store']);
        Route::post('/dispensing/walk-in', [DispensingController::class, 'walkIn']);
    });

    // ---- Administrator only
    Route::middleware('role:admin')->group(function () {
        Route::delete('/medicines/{medicine}', [MedicineController::class, 'destroy']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);

        Route::post('/notifications/announce', [NotificationController::class, 'announce']);

        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/{type}', [ReportController::class, 'generate']);

        Route::get('/forecasts', [ForecastController::class, 'index']);
        Route::get('/forecasts/{medicine}', [ForecastController::class, 'show']);
    });
});
