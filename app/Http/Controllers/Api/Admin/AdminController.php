<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PartnerStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'data' => [
                'users_count' => User::count(),
                'customers_count' => User::where('role', 'customer')->count(),
                'partners_pending' => Partner::where('status', PartnerStatus::Pending)->count(),
                'partners_approved' => Partner::where('status', PartnerStatus::Approved)->count(),
                'vehicles_published' => Vehicle::where('status', VehicleStatus::Published)->count(),
                'vehicles_pending' => Vehicle::where('status', VehicleStatus::PendingValidation)->count(),
                'bookings_count' => Booking::count(),
                'gmv' => (int) Booking::whereIn('status', ['confirmed', 'ongoing', 'completed'])->sum('total_amount'),
                'commissions' => (int) Booking::whereIn('status', ['confirmed', 'ongoing', 'completed'])->sum('commission_amount'),
            ],
        ]);
    }

    public function partners(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $partners = Partner::with('owner')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate($perPage);

        return response()->json($partners);
    }

    public function showPartner(int $id): JsonResponse
    {
        $partner = Partner::with(['owner', 'documents', 'vehicles.media', 'agencies'])
            ->findOrFail($id);

        return response()->json(['data' => $partner]);
    }

    public function approvePartner(Request $request, int $id): JsonResponse
    {
        $partner = Partner::findOrFail($id);
        $before = $partner->toArray();

        $partner->update([
            'status' => PartnerStatus::Approved,
            'validated_by' => $request->user()->id,
            'validated_at' => now(),
            'status_reason' => null,
        ]);

        $this->audit->log('partner.approved', $partner, $before, $partner->toArray());

        return response()->json([
            'data' => $partner,
            'message' => 'Partenaire validé.',
        ]);
    }

    public function rejectPartner(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $partner = Partner::findOrFail($id);
        $before = $partner->toArray();

        $partner->update([
            'status' => PartnerStatus::Rejected,
            'status_reason' => $data['reason'],
            'validated_by' => $request->user()->id,
            'validated_at' => now(),
        ]);

        $this->audit->log('partner.rejected', $partner, $before, $partner->toArray());

        return response()->json(['data' => $partner, 'message' => 'Partenaire refusé.']);
    }

    public function requestPartnerInfo(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $partner = Partner::findOrFail($id);
        $before = $partner->toArray();

        $partner->update([
            'status' => PartnerStatus::InfoRequested,
            'status_reason' => $data['message'],
        ]);

        $this->audit->log('partner.info_requested', $partner, $before, $partner->toArray());

        return response()->json(['data' => $partner]);
    }

    public function suspendPartner(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $partner = Partner::findOrFail($id);
        $before = $partner->toArray();

        $partner->update([
            'status' => PartnerStatus::Suspended,
            'status_reason' => $data['reason'],
        ]);

        $partner->vehicles()
            ->where('status', VehicleStatus::Published)
            ->update(['status' => VehicleStatus::Suspended]);

        $this->audit->log('partner.suspended', $partner, $before, $partner->toArray());

        return response()->json(['data' => $partner, 'message' => 'Partenaire suspendu. Annonces dépubliées.']);
    }

    public function approveVehicle(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $before = $vehicle->toArray();

        $vehicle->update(['status' => VehicleStatus::Published]);
        $this->audit->log('vehicle.approved', $vehicle, $before, $vehicle->toArray());

        return response()->json(['data' => $vehicle]);
    }

    public function rejectVehicle(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $vehicle = Vehicle::findOrFail($id);
        $before = $vehicle->toArray();

        $vehicle->update(['status' => VehicleStatus::Draft]);
        $this->audit->log('vehicle.rejected', $vehicle, $before, [
            ...$vehicle->toArray(),
            'reason' => $data['reason'],
        ]);

        return response()->json(['data' => $vehicle]);
    }

    public function users(Request $request): JsonResponse
    {
        $role = $request->query('role');

        $users = User::query()
            ->when($role, fn ($q) => $q->where('role', $role))
            ->with('ownedPartner')
            ->latest()
            ->paginate(30);

        return response()->json($users);
    }

    public function vehicles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'partner_id' => ['nullable', 'integer', 'exists:partenaires,id'],
            'transmission' => ['nullable', 'in:manual,automatic'],
            'fuel' => ['nullable', 'in:petrol,diesel,hybrid,electric'],
            'q' => ['nullable', 'string', 'max:120'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $vehicles = Vehicle::with(['partner', 'media', 'categoryInfo', 'agency'])
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['category']), fn ($q) => $q->where('category', $data['category']))
            ->when(! empty($data['partner_id']), fn ($q) => $q->where('partner_id', $data['partner_id']))
            ->when(! empty($data['transmission']), fn ($q) => $q->where('transmission', $data['transmission']))
            ->when(! empty($data['fuel']), fn ($q) => $q->where('fuel', $data['fuel']))
            ->when(isset($data['min_price']), fn ($q) => $q->where('price_per_day', '>=', $data['min_price']))
            ->when(isset($data['max_price']), fn ($q) => $q->where('price_per_day', '<=', $data['max_price']))
            ->when(! empty($data['q']), function ($q) use ($data) {
                $term = $data['q'];
                $q->where(function ($inner) use ($term) {
                    $inner->where('brand', 'ilike', "%{$term}%")
                        ->orWhere('model', 'ilike', "%{$term}%")
                        ->orWhere('plate_number', 'ilike', "%{$term}%");
                });
            })
            ->latest()
            ->paginate($data['per_page'] ?? 12);

        return response()->json($vehicles);
    }

    public function showVehicle(int $id): JsonResponse
    {
        $vehicle = Vehicle::with(['partner.owner', 'media', 'categoryInfo', 'agency'])
            ->findOrFail($id);

        return response()->json(['data' => $vehicle]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $data = $this->validateVehicle($request);
        $coverUrl = $data['cover_url'] ?? null;
        unset($data['cover_url']);
        $data['status'] = $data['status'] ?? VehicleStatus::Published->value;

        $vehicle = Vehicle::create($data);

        if ($coverUrl) {
            $vehicle->media()->create([
                'url' => $coverUrl,
                'sort_order' => 0,
                'is_cover' => true,
            ]);
        }

        $this->audit->log('vehicle.created', $vehicle, null, $vehicle->toArray());

        return response()->json(['data' => $vehicle->load(['partner', 'media', 'categoryInfo'])], 201);
    }

    public function updateVehicle(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::with('media')->findOrFail($id);
        $before = $vehicle->toArray();
        $data = $this->validateVehicle($request, $vehicle->id);
        $coverUrl = $data['cover_url'] ?? null;
        unset($data['cover_url']);

        $vehicle->update($data);

        if ($coverUrl !== null) {
            $cover = $vehicle->media()->where('is_cover', true)->first()
                ?? $vehicle->media()->first();

            if ($cover) {
                $cover->update(['url' => $coverUrl, 'is_cover' => true]);
            } elseif ($coverUrl !== '') {
                $vehicle->media()->create([
                    'url' => $coverUrl,
                    'sort_order' => 0,
                    'is_cover' => true,
                ]);
            }
        }

        $vehicle->load(['partner', 'media', 'categoryInfo', 'agency']);
        $this->audit->log('vehicle.updated', $vehicle, $before, $vehicle->toArray());

        return response()->json(['data' => $vehicle]);
    }

    public function destroyVehicle(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $before = $vehicle->toArray();
        $vehicle->media()->delete();
        $vehicle->delete();
        $this->audit->log('vehicle.deleted', null, $before, null);

        return response()->json(['message' => 'Véhicule supprimé.']);
    }

    private function validateVehicle(Request $request, ?int $vehicleId = null): array
    {
        $plateRule = $vehicleId
            ? 'unique:vehicules,plate_number,'.$vehicleId
            : 'unique:vehicules,plate_number';

        return $request->validate([
            'partner_id' => [$vehicleId ? 'sometimes' : 'required', 'integer', 'exists:partenaires,id'],
            'agency_id' => ['nullable', 'integer', 'exists:agences,id'],
            'brand' => [$vehicleId ? 'sometimes' : 'required', 'string', 'max:80'],
            'model' => [$vehicleId ? 'sometimes' : 'required', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'category' => [$vehicleId ? 'sometimes' : 'required', 'in:city_car,sedan,suv,4x4,pickup,minibus,utility,luxury'],
            'plate_number' => [$vehicleId ? 'sometimes' : 'required', 'string', 'max:30', $plateRule],
            'color' => ['nullable', 'string', 'max:40'],
            'seats' => [$vehicleId ? 'sometimes' : 'required', 'integer', 'min:2', 'max:50'],
            'doors' => ['nullable', 'integer', 'min:2', 'max:6'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:20'],
            'transmission' => [$vehicleId ? 'sometimes' : 'required', 'in:manual,automatic'],
            'fuel' => [$vehicleId ? 'sometimes' : 'required', 'in:petrol,diesel,hybrid,electric'],
            'air_conditioning' => ['boolean'],
            'included_km' => ['nullable', 'integer', 'min:0'],
            'deposit_amount' => ['nullable', 'integer', 'min:0'],
            'min_driver_age' => ['nullable', 'integer', 'min:18', 'max:80'],
            'min_license_years' => ['nullable', 'integer', 'min:0', 'max:20'],
            'booking_mode' => ['nullable', 'in:instant,on_request'],
            'cancellation_policy' => ['nullable', 'in:flexible,moderate,strict'],
            'price_per_day' => [$vehicleId ? 'sometimes' : 'required', 'integer', 'min:1000'],
            'description' => ['nullable', 'string'],
            'airport_delivery' => ['boolean'],
            'free_cancellation' => ['boolean'],
            'with_driver_available' => ['boolean'],
            'status' => ['nullable', 'in:draft,pending_validation,published,suspended,archived'],
            'cover_url' => ['nullable', 'url', 'max:500'],
        ]);
    }

    public function locations(): JsonResponse
    {
        $locations = \App\Models\Location::query()
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $locations]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'unique:lieux,slug'],
            'type' => ['required', 'in:city,airport,district,agency_point'],
            'city' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'is_popular' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = \Illuminate\Support\Str::slug($data['name']);
        }

        $location = \App\Models\Location::create([
            ...$data,
            'is_popular' => $data['is_popular'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit->log('location.created', $location, null, $location->toArray());

        return response()->json(['data' => $location], 201);
    }

    public function updateLocation(Request $request, int $id): JsonResponse
    {
        $location = \App\Models\Location::findOrFail($id);
        $before = $location->toArray();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'slug' => ['sometimes', 'string', 'max:190', 'unique:lieux,slug,'.$id],
            'type' => ['sometimes', 'in:city,airport,district,agency_point'],
            'city' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'is_popular' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $location->update($data);
        $this->audit->log('location.updated', $location, $before, $location->toArray());

        return response()->json(['data' => $location]);
    }

    public function deleteLocation(int $id): JsonResponse
    {
        $location = \App\Models\Location::findOrFail($id);
        $before = $location->toArray();
        $location->delete();
        $this->audit->log('location.deleted', null, $before, null);

        return response()->json(['message' => 'Lieu supprimé.']);
    }

    public function updateUserStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,banned'],
        ]);

        $user = User::findOrFail($id);
        $before = $user->only(['id', 'email', 'status', 'role']);
        $user->update(['status' => $data['status']]);
        $this->audit->log('user.status_updated', $user, $before, $user->only(['id', 'email', 'status', 'role']));

        return response()->json(['data' => $user]);
    }

    public function unpublishVehicle(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $before = $vehicle->toArray();
        $vehicle->update(['status' => VehicleStatus::Suspended]);
        $this->audit->log('vehicle.unpublished', $vehicle, $before, $vehicle->toArray());

        return response()->json(['data' => $vehicle]);
    }

    public function bookings(): JsonResponse
    {
        $bookings = Booking::with(['vehicle', 'partner', 'customer', 'pickupLocation'])
            ->latest()
            ->paginate(30);

        return response()->json($bookings);
    }

    public function auditLogs(): JsonResponse
    {
        $logs = AuditLog::with('actor')->latest()->paginate(50);

        return response()->json($logs);
    }
}
