<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\Partner\PartnerController;
use App\Http\Controllers\Api\VehicleSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'app' => 'LocaGabon API',
        'version' => '1.0.0',
    ]));

    Route::get('/locations/autocomplete', [LocationController::class, 'autocomplete']);
    Route::get('/locations/popular', [LocationController::class, 'popular']);

    Route::get('/catalog/home', [CatalogController::class, 'home']);
    Route::get('/catalog/categories', [CatalogController::class, 'categories']);
    Route::get('/catalog/labels', [CatalogController::class, 'labels']);
    Route::get('/catalog/faq', [CatalogController::class, 'faq']);

    Route::get('/vehicles/search', [VehicleSearchController::class, 'search']);
    Route::get('/vehicles/{id}', [VehicleSearchController::class, 'show']);
    Route::get('/vehicles/{id}/availability', [VehicleSearchController::class, 'availability']);

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::post('/bookings/guest-lookup', [BookingController::class, 'guestLookup']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/bookings/{id}/pay', [BookingController::class, 'pay']);

    // Authenticated
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/bookings/{id}', [BookingController::class, 'show']);

        // Partner
        Route::prefix('partners')->group(function () {
            Route::post('/register', [PartnerController::class, 'register']);
            Route::get('/me', [PartnerController::class, 'me']);
            Route::get('/me/dashboard', [PartnerController::class, 'dashboard']);
            Route::get('/me/vehicles', [PartnerController::class, 'vehicles']);
            Route::post('/me/vehicles', [PartnerController::class, 'storeVehicle']);
            Route::get('/me/bookings', [PartnerController::class, 'bookings']);
        });

        // Admin / Super Admin
        Route::middleware('role:admin,super_admin')->prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/partners', [AdminController::class, 'partners']);
            Route::get('/partners/{id}', [AdminController::class, 'showPartner']);
            Route::post('/partners/{id}/approve', [AdminController::class, 'approvePartner']);
            Route::post('/partners/{id}/reject', [AdminController::class, 'rejectPartner']);
            Route::post('/partners/{id}/request-info', [AdminController::class, 'requestPartnerInfo']);
            Route::post('/partners/{id}/suspend', [AdminController::class, 'suspendPartner']);
            Route::get('/vehicles', [AdminController::class, 'vehicles']);
            Route::post('/vehicles', [AdminController::class, 'storeVehicle']);
            Route::get('/vehicles/{id}', [AdminController::class, 'showVehicle']);
            Route::put('/vehicles/{id}', [AdminController::class, 'updateVehicle']);
            Route::patch('/vehicles/{id}', [AdminController::class, 'updateVehicle']);
            Route::delete('/vehicles/{id}', [AdminController::class, 'destroyVehicle']);
            Route::post('/vehicles/{id}/approve', [AdminController::class, 'approveVehicle']);
            Route::post('/vehicles/{id}/reject', [AdminController::class, 'rejectVehicle']);
            Route::post('/vehicles/{id}/unpublish', [AdminController::class, 'unpublishVehicle']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::patch('/users/{id}/status', [AdminController::class, 'updateUserStatus']);
            Route::get('/bookings', [AdminController::class, 'bookings']);
            Route::get('/locations', [AdminController::class, 'locations']);
            Route::post('/locations', [AdminController::class, 'storeLocation']);
            Route::patch('/locations/{id}', [AdminController::class, 'updateLocation']);
            Route::delete('/locations/{id}', [AdminController::class, 'deleteLocation']);
            Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
        });
    });
});
