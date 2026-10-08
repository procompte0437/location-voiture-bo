<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\Partenaire\ControleurPartenaire;
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
    Route::get('/catalog/references', [CatalogController::class, 'references']);
    Route::get('/catalog/vehicles', [CatalogController::class, 'vehicles']);
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

        // Inscription dossier : utilisateur authentifié (devient partenaire).
        Route::post('/partenaires/inscription', [ControleurPartenaire::class, 'inscrire']);
        Route::post('/partners/register', [ControleurPartenaire::class, 'inscrire']);

        // Espace partenaire : uniquement rôle partner / partner_agent, données scoped à « moi ».
        $routesPartenaire = function () {
            Route::get('/moi', [ControleurPartenaire::class, 'moi']);
            Route::get('/moi/tableau-de-bord', [ControleurPartenaire::class, 'tableauDeBord']);
            Route::get('/moi/vehicules', [ControleurPartenaire::class, 'vehicules']);
            Route::post('/moi/vehicules', [ControleurPartenaire::class, 'enregistrerVehicule']);
            Route::get('/moi/reservations', [ControleurPartenaire::class, 'reservations']);
            // Référentiel marques/modèles partagé (création par tout partenaire, visible partout).
            Route::get('/moi/referentiel', [ControleurPartenaire::class, 'listerReferentiel']);
            Route::post('/moi/referentiel', [ControleurPartenaire::class, 'creerReferentiel']);

            Route::get('/me', [ControleurPartenaire::class, 'moi']);
            Route::get('/me/dashboard', [ControleurPartenaire::class, 'tableauDeBord']);
            Route::get('/me/vehicles', [ControleurPartenaire::class, 'vehicules']);
            Route::post('/me/vehicles', [ControleurPartenaire::class, 'enregistrerVehicule']);
            Route::get('/me/bookings', [ControleurPartenaire::class, 'reservations']);
            Route::get('/me/references', [ControleurPartenaire::class, 'listerReferentiel']);
            Route::post('/me/references', [ControleurPartenaire::class, 'creerReferentiel']);
        };

        Route::middleware('partenaire')->group(function () use ($routesPartenaire) {
            Route::prefix('partenaires')->group($routesPartenaire);
            Route::prefix('partners')->group($routesPartenaire);
        });

        // Console admin / super admin (catalogue, audit, etc.)
        Route::middleware('role:admin,super_admin')->prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/vehicles', [AdminController::class, 'vehicles']);
            Route::post('/vehicles', [AdminController::class, 'storeVehicle']);
            Route::get('/vehicles/{id}', [AdminController::class, 'showVehicle']);
            Route::put('/vehicles/{id}', [AdminController::class, 'updateVehicle']);
            Route::patch('/vehicles/{id}', [AdminController::class, 'updateVehicle']);
            Route::delete('/vehicles/{id}', [AdminController::class, 'destroyVehicle']);
            Route::post('/vehicles/{id}/approve', [AdminController::class, 'approveVehicle']);
            Route::post('/vehicles/{id}/reject', [AdminController::class, 'rejectVehicle']);
            Route::post('/vehicles/{id}/unpublish', [AdminController::class, 'unpublishVehicle']);
            Route::get('/bookings', [AdminController::class, 'bookings']);
            Route::get('/locations', [AdminController::class, 'locations']);
            Route::post('/locations', [AdminController::class, 'storeLocation']);
            Route::patch('/locations/{id}', [AdminController::class, 'updateLocation']);
            Route::delete('/locations/{id}', [AdminController::class, 'deleteLocation']);
            Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
            Route::get('/references', [AdminController::class, 'references']);
            Route::post('/references', [AdminController::class, 'storeReference']);
            Route::patch('/references/{id}', [AdminController::class, 'updateReference']);
            Route::delete('/references/{id}', [AdminController::class, 'deleteReference']);
            Route::get('/vehicle-categories', [AdminController::class, 'vehicleCategories']);
            Route::post('/vehicle-categories', [AdminController::class, 'storeVehicleCategory']);
            Route::patch('/vehicle-categories/{id}', [AdminController::class, 'updateVehicleCategory']);
            Route::delete('/vehicle-categories/{id}', [AdminController::class, 'deleteVehicleCategory']);
        });

        // Gestion des comptes (partenaires + utilisateurs) : super admin uniquement.
        Route::middleware('role:super_admin')->prefix('admin')->group(function () {
            Route::get('/partners', [AdminController::class, 'partners']);
            Route::get('/partners/{id}', [AdminController::class, 'showPartner']);
            Route::post('/partners/{id}/approve', [AdminController::class, 'approvePartner']);
            Route::post('/partners/{id}/reject', [AdminController::class, 'rejectPartner']);
            Route::post('/partners/{id}/request-info', [AdminController::class, 'requestPartnerInfo']);
            Route::post('/partners/{id}/suspend', [AdminController::class, 'suspendPartner']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::patch('/users/{id}/status', [AdminController::class, 'updateUserStatus']);
        });
    });
});
