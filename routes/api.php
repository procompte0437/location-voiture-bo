<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ControleurContact;
use App\Http\Controllers\Api\ControleurMotDePasse;
use App\Http\Controllers\Api\ControleurPaiement;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\Partenaire\ControleurGestionLoueur;
use App\Http\Controllers\Api\Partenaire\ControleurPartenaire;
use App\Http\Controllers\Api\Partenaire\ControleurReservationsPartenaire;
use App\Http\Controllers\Api\VehicleSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'app' => 'DOTO API',
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
    Route::post('/contact', [ControleurContact::class, 'enregistrer'])->middleware('throttle:8,1');
    Route::get('/catalog/provinces', [CatalogController::class, 'provinces']);
    Route::get('/catalog/provinces/{slug}', [CatalogController::class, 'province']);

    Route::get('/vehicles/search', [VehicleSearchController::class, 'search']);
    Route::get('/vehicules/disponibilite', [VehicleSearchController::class, 'disponibilite']);
    Route::get('/vehicles/{id}', [VehicleSearchController::class, 'show']);
    Route::get('/vehicles/{id}/availability', [VehicleSearchController::class, 'availability']);

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/mot-de-passe-oublie', [ControleurMotDePasse::class, 'demander'])->middleware('throttle:5,1');
    Route::post('/auth/reinitialiser-mot-de-passe', [ControleurMotDePasse::class, 'reinitialiser'])->middleware('throttle:10,1');

    Route::post('/bookings/guest-lookup', [BookingController::class, 'guestLookup']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/bookings/{id}/pay', [BookingController::class, 'pay']);

    Route::get('/catalog/paiement', [ControleurPaiement::class, 'configuration']);
    Route::post('/bookings/{id}/paiement-mobile', [ControleurPaiement::class, 'lancer'])->middleware('throttle:10,1');
    Route::get('/bookings/{id}/paiement', [ControleurPaiement::class, 'statut'])->middleware('throttle:40,1');
    Route::post('/paiements/pvit/notification', [ControleurPaiement::class, 'notification'])->middleware('throttle:120,1');

    // Authenticated
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/profil', [AuthController::class, 'mettreAJourProfil']);
        Route::post('/auth/mot-de-passe', [AuthController::class, 'changerMotDePasse']);
        Route::post('/auth/photo', [AuthController::class, 'mettreAJourPhoto']);
        Route::delete('/auth/photo', [AuthController::class, 'supprimerPhoto']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/bookings/{id}', [BookingController::class, 'show']);
        Route::get('/bookings/{id}/contrat', [BookingController::class, 'recapitulatif']);
        Route::post('/bookings/{id}/annuler', [BookingController::class, 'annuler']);
        Route::delete('/bookings/{id}', [BookingController::class, 'supprimer']);

        // Inscription dossier : utilisateur authentifié (devient partenaire).
        Route::post('/partenaires/inscription', [ControleurPartenaire::class, 'inscrire']);
        Route::post('/partners/register', [ControleurPartenaire::class, 'inscrire']);

        // Espace partenaire : uniquement rôle partner / partner_agent, données scoped à « moi ».
        $routesPartenaire = function () {
            Route::get('/moi', [ControleurPartenaire::class, 'moi']);
            Route::get('/moi/tableau-de-bord', [ControleurPartenaire::class, 'tableauDeBord']);
            Route::get('/moi/vehicules', [ControleurPartenaire::class, 'vehicules']);
            Route::post('/moi/vehicules', [ControleurPartenaire::class, 'enregistrerVehicule']);
            Route::post('/moi/vehicules/{id}/publier', [ControleurPartenaire::class, 'publierVehicule']);
            Route::get('/moi/reservations', [ControleurPartenaire::class, 'reservations']);
            // Référentiel marques/modèles partagé (création par tout partenaire, visible partout).
            Route::get('/moi/referentiel', [ControleurPartenaire::class, 'listerReferentiel']);
            Route::post('/moi/referentiel', [ControleurPartenaire::class, 'creerReferentiel']);

            // Dossier de location : réponse à la demande, départ, retour, règlements, caution.
            Route::get('/moi/reservations/{id}', [ControleurReservationsPartenaire::class, 'afficher'])->whereNumber('id');
            Route::post('/moi/reservations/{id}/accepter', [ControleurReservationsPartenaire::class, 'accepter'])->whereNumber('id');
            Route::post('/moi/reservations/{id}/refuser', [ControleurReservationsPartenaire::class, 'refuser'])->whereNumber('id');
            Route::post('/moi/reservations/{id}/sortie', [ControleurReservationsPartenaire::class, 'sortie'])->whereNumber('id');
            Route::post('/moi/reservations/{id}/retour', [ControleurReservationsPartenaire::class, 'retour'])->whereNumber('id');
            Route::post('/moi/reservations/{id}/reglements', [ControleurReservationsPartenaire::class, 'reglement'])->whereNumber('id');
            Route::post('/moi/reservations/{id}/cautions', [ControleurReservationsPartenaire::class, 'caution'])->whereNumber('id');
            Route::get('/moi/reservations/{id}/contrat', [ControleurReservationsPartenaire::class, 'contrat'])->whereNumber('id');
            Route::get('/moi/reservations/{id}/etat-des-lieux', [ControleurReservationsPartenaire::class, 'etatDesLieux'])->whereNumber('id');

            // Gestion : finances, dépenses, entretiens, équipe, exports CSV.
            Route::get('/moi/finances', [ControleurGestionLoueur::class, 'finances']);
            Route::post('/moi/depenses', [ControleurGestionLoueur::class, 'enregistrerDepense']);
            Route::get('/moi/entretiens', [ControleurGestionLoueur::class, 'entretiens']);
            Route::post('/moi/entretiens/plans', [ControleurGestionLoueur::class, 'creerPlan']);
            Route::post('/moi/entretiens/interventions/{id}/realiser', [ControleurGestionLoueur::class, 'realiserIntervention'])->whereNumber('id');
            Route::get('/moi/membres', [ControleurGestionLoueur::class, 'membres']);
            Route::post('/moi/membres', [ControleurGestionLoueur::class, 'inviterMembre']);
            Route::delete('/moi/membres/{id}', [ControleurGestionLoueur::class, 'retirerMembre'])->whereNumber('id');
            Route::get('/moi/exports/{type}', [ControleurGestionLoueur::class, 'exporter'])->whereIn('type', ['reservations', 'mouvements', 'finances', 'entretiens']);

            Route::get('/me', [ControleurPartenaire::class, 'moi']);
            Route::get('/me/dashboard', [ControleurPartenaire::class, 'tableauDeBord']);
            Route::get('/me/vehicles', [ControleurPartenaire::class, 'vehicules']);
            Route::post('/me/vehicles', [ControleurPartenaire::class, 'enregistrerVehicule']);
            Route::post('/me/vehicles/{id}/publish', [ControleurPartenaire::class, 'publierVehicule']);
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
            Route::post('/bookings/{id}/confirm', [AdminController::class, 'confirmBooking']);
            Route::post('/bookings/{id}/cancel', [AdminController::class, 'cancelBooking']);
            Route::delete('/bookings/{id}', [AdminController::class, 'destroyBooking']);
            Route::post('/bookings/{id}/complete', [AdminController::class, 'completeBooking']);
            Route::get('/locations', [AdminController::class, 'locations']);
            Route::post('/locations', [AdminController::class, 'storeLocation']);
            Route::patch('/locations/{id}', [AdminController::class, 'updateLocation']);
            Route::delete('/locations/{id}', [AdminController::class, 'deleteLocation']);
            Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
            Route::get('/referentiel/modele', [AdminController::class, 'modeleReferentielExcel']);
            Route::get('/vehicules/modele', [AdminController::class, 'modeleVehiculesExcel']);
            Route::post('/referentiel/apercu', [AdminController::class, 'apercuReferentiel']);
            Route::post('/referentiel/import', [AdminController::class, 'importerReferentiel']);
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
            Route::get('/evaluation-partenaires', [AdminController::class, 'evaluationPartenaires']);
            Route::get('/partners', [AdminController::class, 'partners']);
            Route::get('/partners/{id}', [AdminController::class, 'showPartner']);
            Route::post('/partners/{id}/approve', [AdminController::class, 'approvePartner']);
            Route::post('/partners/{id}/reject', [AdminController::class, 'rejectPartner']);
            Route::post('/partners/{id}/request-info', [AdminController::class, 'requestPartnerInfo']);
            Route::post('/partners/{id}/suspend', [AdminController::class, 'suspendPartner']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::patch('/users/{id}/status', [AdminController::class, 'updateUserStatus']);
            // Agences par province (carte accueil) — super admin.
            Route::post('/vehicules/import', [AdminController::class, 'importerVehicules']);
            Route::get('/agences', [AdminController::class, 'agences']);
            Route::post('/agences', [AdminController::class, 'storeAgence']);
            Route::patch('/agences/{id}', [AdminController::class, 'updateAgence']);
            Route::delete('/agences/{id}', [AdminController::class, 'deleteAgence']);
        });
    });
});
