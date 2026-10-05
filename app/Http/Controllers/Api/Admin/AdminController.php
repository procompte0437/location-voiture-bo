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

        $partners = Partner::with('owner')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

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
        $users = User::query()
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $request->query('role')))
            ->latest()
            ->paginate(20);

        return response()->json($users);
    }

    public function bookings(): JsonResponse
    {
        $bookings = Booking::with(['vehicle', 'partner', 'customer'])->latest()->paginate(20);

        return response()->json($bookings);
    }

    public function auditLogs(): JsonResponse
    {
        $logs = AuditLog::with('actor')->latest()->paginate(50);

        return response()->json($logs);
    }
}
