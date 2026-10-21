<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DispensingController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\ResidentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\ExpireUnclaimedRequests;
use Illuminate\Support\Facades\Route;

// Any one of these lets a user load the medicine list (see EnsurePermission).
$medicineList = 'permission:medicines.view|medicines.manage|inventory.view|inventory.manage|dispensing.dispense';

// ---- Public: resident registration and login
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
Route::get('/barangays', fn () => config('botika.barangays'));

Route::middleware(['auth:sanctum', EnsurePasswordChanged::class, ExpireUnclaimedRequests::class])->group(function () use ($medicineList) {

    // ---- All roles
    Route::middleware('role:admin,staff,resident')->group(function () use ($medicineList) {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/account/password', [AccountController::class, 'changePassword']);
        Route::get('/dashboard', DashboardController::class);

        // The medicine list is also used by Stock & Batches and Dispensing (staff) and by the request form (residents).
        Route::middleware($medicineList)->group(function () {
            Route::get('/medicines', [MedicineController::class, 'index']);
            Route::get('/medicines/categories', [MedicineController::class, 'categories']);
            Route::get('/medicines/dosage-forms', [MedicineController::class, 'dosageForms']);
        });
        Route::middleware('permission:requests.view')->group(function () {
            Route::get('/requests', [RequestController::class, 'index']);
            Route::get('/requests/{medicineRequest}', [RequestController::class, 'show'])->whereNumber('medicineRequest');
            Route::get('/requests/{medicineRequest}/prescription', [RequestController::class, 'showPrescription'])->whereNumber('medicineRequest');
        });
        Route::get('/dispensing', [DispensingController::class, 'index'])->middleware('permission:dispensing.records|history.view');
        Route::get('/notifications', [NotificationController::class, 'index'])->middleware('permission:notifications.view');
    });

    // ---- Resident
    Route::middleware('role:resident')->group(function () {
        // Medicine or restock request: the type is checked again in RequestController::store.
        Route::post('/requests', [RequestController::class, 'store'])->middleware('permission:requests.create|restock.create');
        Route::put('/requests/{medicineRequest}', [RequestController::class, 'update'])->middleware('permission:requests.create');
        Route::post('/requests/{medicineRequest}/cancel', [RequestController::class, 'cancel'])->middleware('permission:requests.cancel');
        Route::post('/requests/{medicineRequest}/prescription', [RequestController::class, 'uploadPrescription'])->middleware('permission:requests.create');

        Route::middleware('permission:notifications.view')->group(function () {
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->whereNumber('notification');
        });

        // Viewing the profile (with the QR code needed at pickup) is always allowed.
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::middleware('permission:profile.edit')->group(function () {
            Route::put('/profile', [ProfileController::class, 'update']);
            Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
            Route::post('/profile/photo', [ProfileController::class, 'updatePhoto']);
            Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto']);
        });
    });

    // ---- Administrator + Pharmacy staff
    Route::middleware('role:admin,staff')->group(function () {
        Route::middleware('permission:medicines.manage')->group(function () {
            Route::post('/medicines', [MedicineController::class, 'store']);
            Route::put('/medicines/{medicine}', [MedicineController::class, 'update']);
            Route::post('/medicines/{medicine}/confirm-no-stock', [MedicineController::class, 'confirmNoStock']);
        });

        Route::middleware('permission:inventory.view|inventory.manage')->group(function () {
            Route::get('/inventory', [InventoryController::class, 'index']);
            Route::get('/inventory/alerts', [InventoryController::class, 'alerts']);
            Route::get('/inventory/transactions', [InventoryController::class, 'transactions']);
        });
        Route::middleware('permission:inventory.manage')->group(function () {
            Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn']);
            Route::post('/inventory/{inventory}/stock-out', [InventoryController::class, 'stockOut']);
        });

        Route::get('/requests/counts', [RequestController::class, 'counts'])->middleware('permission:requests.view');
        Route::middleware('permission:requests.review')->group(function () {
            Route::post('/requests/{medicineRequest}/approve', [RequestController::class, 'approve']);
            Route::post('/requests/{medicineRequest}/reject', [RequestController::class, 'reject']);
        });

        Route::middleware('permission:dispensing.dispense|residents.register')->group(function () {
            Route::get('/residents', [ResidentController::class, 'index']);
            Route::get('/residents/lookup', [ResidentController::class, 'lookup']);
            // Own limit (see AppServiceProvider), so the searches made while typing do not use up other limits.
            Route::get('/residents/search', [ResidentController::class, 'search'])->middleware('throttle:resident-search');
            Route::post('/residents/{resident}/verify', [ResidentController::class, 'verify'])->whereNumber('resident');
        });
        Route::post('/residents', [ResidentController::class, 'store'])->middleware('permission:residents.register'); // walk-in resident registered by staff

        Route::middleware('permission:dispensing.dispense')->group(function () {
            Route::post('/dispensing', [DispensingController::class, 'store']);
            Route::post('/dispensing/walk-in', [DispensingController::class, 'walkIn']);
        });
    });

    // ---- Administrator only
    Route::middleware('role:admin')->group(function () {
        Route::delete('/medicines/{medicine}', [MedicineController::class, 'destroy']);

        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::put('/permissions/{role}', [PermissionController::class, 'update'])->whereIn('role', ['staff', 'resident']);
        Route::post('/permissions/{role}/reset', [PermissionController::class, 'reset'])->whereIn('role', ['staff', 'resident']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

        Route::post('/notifications/announce', [NotificationController::class, 'announce']);

        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/{type}', [ReportController::class, 'generate']);

        Route::get('/forecasts', [ForecastController::class, 'index']);
        Route::post('/forecasts/generate', [ForecastController::class, 'generate']);
        Route::get('/forecasts/{medicine}', [ForecastController::class, 'show']);
    });
});
