<?php

namespace App\Http\Controllers\Api\Partner;

use App\Enums\PartnerStatus;
use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Vehicle;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:individual,company,agency'],
            'company_name' => ['nullable', 'string', 'max:190'],
            'rccm' => ['nullable', 'string', 'max:100'],
            'nif' => ['nullable', 'string', 'max:100'],
            'manager_name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:190'],
        ]);

        $user = $request->user();

        if ($user->ownedPartner) {
            return response()->json(['message' => 'Vous avez déjà un compte partenaire.'], 422);
        }

        $partner = Partner::create([
            ...$data,
            'owner_user_id' => $user->id,
            'status' => PartnerStatus::Pending,
            'trust_level' => 'new',
        ]);

        $user->update(['role' => UserRole::Partner]);

        $this->audit->log('partner.registered', $partner, null, $partner->toArray(), $user->id);

        return response()->json([
            'data' => $partner,
            'message' => 'Dossier soumis. Statut : en attente de validation.',
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $partner = $request->user()->ownedPartner;

        if (! $partner) {
            return response()->json(['message' => 'Aucun compte partenaire.'], 404);
        }

        return response()->json([
            'data' => $partner->load(['documents', 'vehicles.media', 'agencies']),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $partner = $request->user()->ownedPartner;

        if (! $partner) {
            return response()->json(['message' => 'Aucun compte partenaire.'], 404);
        }

        $todayBookings = $partner->bookings()->whereDate('pickup_at', today())->count();
        $confirmed = $partner->bookings()->where('status', 'confirmed')->count();
        $revenue = $partner->bookings()
            ->whereIn('status', ['confirmed', 'ongoing', 'completed'])
            ->sum('total_amount');
        $commission = $partner->bookings()
            ->whereIn('status', ['confirmed', 'ongoing', 'completed'])
            ->sum('commission_amount');

        return response()->json([
            'data' => [
                'status' => $partner->status,
                'trust_level' => $partner->trust_level,
                'average_rating' => $partner->average_rating,
                'today_bookings' => $todayBookings,
                'confirmed_bookings' => $confirmed,
                'gross_revenue' => (int) $revenue,
                'commission_total' => (int) $commission,
                'net_revenue' => (int) ($revenue - $commission),
                'vehicles_count' => $partner->vehicles()->count(),
                'published_vehicles' => $partner->vehicles()->where('status', VehicleStatus::Published)->count(),
            ],
        ]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $partner = $request->user()->ownedPartner;

        if (! $partner || ! $partner->isApproved()) {
            return response()->json([
                'message' => 'Votre compte partenaire doit être validé pour publier des véhicules.',
            ], 403);
        }

        $data = $request->validate([
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'category' => ['required', 'in:city_car,sedan,suv,4x4,pickup,minibus,utility,luxury'],
            'plate_number' => ['required', 'string', 'max:30', 'unique:vehicles,plate_number'],
            'color' => ['nullable', 'string', 'max:40'],
            'seats' => ['required', 'integer', 'min:2', 'max:50'],
            'doors' => ['nullable', 'integer', 'min:2', 'max:6'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:20'],
            'transmission' => ['required', 'in:manual,automatic'],
            'fuel' => ['required', 'in:petrol,diesel,hybrid,electric'],
            'air_conditioning' => ['boolean'],
            'included_km' => ['nullable', 'integer', 'min:0'],
            'deposit_amount' => ['nullable', 'integer', 'min:0'],
            'min_driver_age' => ['nullable', 'integer', 'min:18', 'max:80'],
            'min_license_years' => ['nullable', 'integer', 'min:0', 'max:20'],
            'booking_mode' => ['nullable', 'in:instant,on_request'],
            'cancellation_policy' => ['nullable', 'in:flexible,moderate,strict'],
            'price_per_day' => ['required', 'integer', 'min:1000'],
            'description' => ['nullable', 'string'],
            'airport_delivery' => ['boolean'],
            'free_cancellation' => ['boolean'],
            'with_driver_available' => ['boolean'],
            'agency_id' => ['nullable', 'exists:agencies,id'],
            'cover_url' => ['nullable', 'url'],
        ]);

        $needsValidation = in_array($partner->trust_level, ['new', 'verified'], true);

        $vehicle = Vehicle::create([
            ...collect($data)->except('cover_url')->all(),
            'partner_id' => $partner->id,
            'status' => $needsValidation
                ? VehicleStatus::PendingValidation
                : VehicleStatus::Published,
        ]);

        if (! empty($data['cover_url'])) {
            $vehicle->media()->create([
                'url' => $data['cover_url'],
                'sort_order' => 0,
                'is_cover' => true,
            ]);
        }

        $this->audit->log('vehicle.created', $vehicle, null, $vehicle->toArray());

        return response()->json(['data' => $vehicle->load('media')], 201);
    }

    public function vehicles(Request $request): JsonResponse
    {
        $partner = $request->user()->ownedPartner;

        if (! $partner) {
            return response()->json(['message' => 'Aucun compte partenaire.'], 404);
        }

        $vehicles = $partner->vehicles()->with('media')->latest()->paginate(20);

        return response()->json($vehicles);
    }

    public function bookings(Request $request): JsonResponse
    {
        $partner = $request->user()->ownedPartner;

        if (! $partner) {
            return response()->json(['message' => 'Aucun compte partenaire.'], 404);
        }

        $bookings = $partner->bookings()
            ->with(['vehicle', 'customer'])
            ->latest()
            ->paginate(20);

        return response()->json($bookings);
    }
}
