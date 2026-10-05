<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'pickup_at' => ['required', 'date'],
            'return_at' => ['required', 'date', 'after:pickup_at'],
            'pickup_location_id' => ['nullable', 'exists:locations,id'],
            'return_location_id' => ['nullable', 'exists:locations,id'],
            'driver_age' => ['required', 'integer', 'min:18', 'max:99'],
            'guest_email' => ['nullable', 'email'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $this->optionalUser($request);

        if ($user) {
            $data['guest_email'] = $data['guest_email'] ?? $user->email;
            $data['guest_name'] = $data['guest_name'] ?? $user->name;
            $data['guest_phone'] = $data['guest_phone'] ?? $user->phone;
        }

        $booking = $this->bookings->create($data, $user?->id);

        return response()->json(['data' => $booking], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->claimGuestBookings($user);

        $bookings = Booking::with(['vehicle.media', 'partner', 'pickupLocation'])
            ->where(function ($q) use ($user) {
                $q->where('customer_id', $user->id)
                    ->orWhere('guest_email', $user->email);
            })
            ->latest()
            ->paginate(15);

        return response()->json($bookings);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->claimGuestBookings($user);

        $booking = Booking::with(['vehicle.media', 'partner', 'pickupLocation', 'returnLocation', 'payment'])
            ->where(function ($q) use ($user) {
                $q->where('customer_id', $user->id)
                    ->orWhere('guest_email', $user->email);
            })
            ->findOrFail($id);

        return response()->json(['data' => $booking]);
    }

    public function guestLookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $booking = Booking::with(['vehicle.media', 'partner', 'payment'])
            ->where('reference', $data['reference'])
            ->where(function ($q) use ($data) {
                $q->where('guest_email', $data['email'])
                    ->orWhereHas('customer', fn ($cq) => $cq->where('email', $data['email']));
            })
            ->firstOrFail();

        return response()->json(['data' => $booking]);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:airtel_money,moov_money,card,agency_cash'],
        ]);

        $booking = Booking::with('vehicle')->findOrFail($id);
        $user = $this->optionalUser($request);

        if ($user && $booking->customer_id && $booking->customer_id !== $user->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($user && ! $booking->customer_id) {
            $booking->update([
                'customer_id' => $user->id,
                'guest_email' => $booking->guest_email ?: $user->email,
                'guest_name' => $booking->guest_name ?: $user->name,
                'guest_phone' => $booking->guest_phone ?: $user->phone,
            ]);
        }

        $payment = $this->bookings->confirmPayment($booking->fresh('vehicle'), $data['method']);

        return response()->json([
            'data' => [
                'payment' => $payment,
                'booking' => $booking->fresh(['vehicle', 'partner', 'payment']),
            ],
        ]);
    }

    private function optionalUser(Request $request): ?User
    {
        return $request->user('sanctum') ?? $request->user();
    }

    private function claimGuestBookings(User $user): void
    {
        Booking::query()
            ->whereNull('customer_id')
            ->where('guest_email', $user->email)
            ->update(['customer_id' => $user->id]);
    }
}
