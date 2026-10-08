<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PartnerStatus;
use App\Enums\VehicleStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(private AuditService $audit) {}

    public function calculateDays(Carbon $pickup, Carbon $return, int $toleranceHours = 1): int
    {
        $hours = $pickup->diffInHours($return);
        $days = (int) ceil(max($hours - $toleranceHours, 1) / 24);

        return max($days, 1);
    }

    public function isAvailable(Vehicle $vehicle, Carbon $pickup, Carbon $return, ?int $excludeBookingId = null): bool
    {
        $overlapBookings = Booking::query()
            ->where('vehicle_id', $vehicle->id)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->whereIn('status', [
                BookingStatus::Locked->value,
                BookingStatus::PendingPartner->value,
                BookingStatus::Confirmed->value,
                BookingStatus::Ongoing->value,
            ])
            ->where('pickup_at', '<', $return)
            ->where('return_at', '>', $pickup)
            ->exists();

        if ($overlapBookings) {
            return false;
        }

        return ! $vehicle->blocks()
            ->where('starts_at', '<', $return)
            ->where('ends_at', '>', $pickup)
            ->exists();
    }

    public function create(array $data, ?int $customerId = null): Booking
    {
        $vehicle = Vehicle::with('partner')->findOrFail($data['vehicle_id']);

        if ($vehicle->status !== VehicleStatus::Published
            || $vehicle->partner->status !== PartnerStatus::Approved) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Ce véhicule n\'est pas disponible à la réservation.',
            ]);
        }

        $pickup = Carbon::parse($data['pickup_at']);
        $return = Carbon::parse($data['return_at']);

        if ($return->lte($pickup)) {
            throw ValidationException::withMessages([
                'return_at' => 'La date de retour doit être postérieure au départ.',
            ]);
        }

        $driverAge = (int) ($data['driver_age'] ?? 25);
        if ($driverAge < $vehicle->min_driver_age) {
            throw ValidationException::withMessages([
                'driver_age' => "Âge minimum requis : {$vehicle->min_driver_age} ans.",
            ]);
        }

        if (! $this->isAvailable($vehicle, $pickup, $return)) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Ce véhicule n\'est pas libre sur ces dates.',
            ]);
        }

        $days = $this->calculateDays($pickup, $return);
        $subtotal = $days * $vehicle->price_per_day;
        $commissionRate = (float) $vehicle->partner->commission_rate;
        $commission = (int) round($subtotal * ($commissionRate / 100));

        return DB::transaction(function () use (
            $data, $customerId, $vehicle, $pickup, $return, $days,
            $subtotal, $commission, $commissionRate, $driverAge
        ) {
            $booking = Booking::create([
                'reference' => 'LG-'.strtoupper(Str::random(8)),
                'customer_id' => $customerId,
                'guest_email' => $data['guest_email'] ?? null,
                'guest_phone' => $data['guest_phone'] ?? null,
                'guest_name' => $data['guest_name'] ?? null,
                'vehicle_id' => $vehicle->id,
                'partner_id' => $vehicle->partner_id,
                'pickup_location_id' => $data['pickup_location_id'] ?? null,
                'return_location_id' => $data['return_location_id'] ?? $data['pickup_location_id'] ?? null,
                'pickup_at' => $pickup,
                'return_at' => $return,
                'driver_age' => $driverAge,
                'status' => BookingStatus::Locked,
                'days_count' => $days,
                'subtotal' => $subtotal,
                'extras_total' => 0,
                'total_amount' => $subtotal,
                'deposit_amount' => $vehicle->deposit_amount,
                'commission_amount' => $commission,
                'commission_rate' => $commissionRate,
                'cancellation_policy' => $vehicle->cancellation_policy,
                'locked_until' => now()->addMinutes(10),
            ]);

            $this->audit->log('booking.created', $booking, null, $booking->toArray(), $customerId);

            return $booking->load(['vehicle.media', 'partner', 'pickupLocation', 'returnLocation']);
        });
    }

    public function confirmPayment(Booking $booking, string $method, string $gateway = 'mock'): Payment
    {
        return DB::transaction(function () use ($booking, $method, $gateway) {
            $payment = Payment::create([
                'booking_id' => $booking->id,
                'gateway' => $gateway,
                'method' => $method,
                'amount' => $booking->total_amount,
                'currency' => 'XAF',
                'status' => 'paid',
                'transaction_ref' => 'TX-'.strtoupper(Str::random(12)),
                'paid_at' => now(),
            ]);

            // Après paiement / demande : en attente du commercial / partenaire.
            $newStatus = BookingStatus::PendingPartner;

            $booking->update([
                'status' => $newStatus,
                'confirmed_at' => null,
                'locked_until' => null,
            ]);

            $this->audit->log('payment.confirmed', $booking, null, [
                'payment_id' => $payment->id,
                'status' => $newStatus->value,
            ]);

            return $payment;
        });
    }
}
