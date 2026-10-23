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
        $statuts = [
            BookingStatus::Locked,
            BookingStatus::PendingPartner,
            BookingStatus::Confirmed,
            BookingStatus::Ongoing,
        ];

        if ($vehicle->relationLoaded('bookings')) {
            $chevauche = $vehicle->bookings->contains(function (Booking $reservation) use ($statuts, $pickup, $return, $excludeBookingId) {
                if ($excludeBookingId && $reservation->id === $excludeBookingId) {
                    return false;
                }
                $statut = $reservation->status instanceof BookingStatus
                    ? $reservation->status
                    : BookingStatus::tryFrom((string) $reservation->status);
                if (! $statut || ! in_array($statut, $statuts, true)) {
                    return false;
                }

                return $reservation->pickup_at->lt($return) && $reservation->return_at->gt($pickup);
            });
        } else {
            $chevauche = Booking::query()
                ->where('vehicle_id', $vehicle->id)
                ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
                ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, $statuts))
                ->where('pickup_at', '<', $return)
                ->where('return_at', '>', $pickup)
                ->exists();
        }

        if ($chevauche) {
            return false;
        }

        if ($vehicle->relationLoaded('blocks')) {
            return ! $vehicle->blocks->contains(
                fn ($blocage) => $blocage->starts_at->lt($return) && $blocage->ends_at->gt($pickup)
            );
        }

        return ! $vehicle->blocks()
            ->where('starts_at', '<', $return)
            ->where('ends_at', '>', $pickup)
            ->exists();
    }

    /**
     * Créneaux pris (réservations actives) et libres sur l’horizon, pour la liste des voitures.
     *
     * @return array{occupied: list<array{starts_at: string, ends_at: string}>, free: list<array{starts_at: string, ends_at: string}>}
     */
    public function calendrier(Vehicle $vehicle, int $horizonJours = 90): array
    {
        $statuts = [
            BookingStatus::Locked,
            BookingStatus::PendingPartner,
            BookingStatus::Confirmed,
            BookingStatus::Ongoing,
        ];

        $maintenant = now();
        $debut = $maintenant->copy()->startOfDay();
        $fin = $debut->copy()->addDays($horizonJours);

        $reservations = $vehicle->relationLoaded('bookings')
            ? $vehicle->bookings
            : $vehicle->bookings()
                ->whereIn('status', array_map(fn (BookingStatus $s) => $s->value, $statuts))
                ->where('return_at', '>', $maintenant)
                ->orderBy('pickup_at')
                ->get();

        $blocages = $vehicle->relationLoaded('blocks')
            ? $vehicle->blocks
            : $vehicle->blocks()
                ->where('ends_at', '>', $maintenant)
                ->orderBy('starts_at')
                ->get();

        $bruts = [];
        foreach ($reservations as $reservation) {
            $statut = $reservation->status instanceof BookingStatus
                ? $reservation->status
                : BookingStatus::tryFrom((string) $reservation->status);
            if (! $statut || ! in_array($statut, $statuts, true)) {
                continue;
            }
            if ($reservation->return_at->lte($maintenant)) {
                continue;
            }
            $bruts[] = [$reservation->pickup_at->copy(), $reservation->return_at->copy()];
        }
        foreach ($blocages as $blocage) {
            if ($blocage->ends_at->lte($maintenant)) {
                continue;
            }
            $bruts[] = [$blocage->starts_at->copy(), $blocage->ends_at->copy()];
        }

        usort($bruts, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $fusionnes = [];
        foreach ($bruts as [$depart, $retour]) {
            if ($fusionnes === []) {
                $fusionnes[] = [$depart, $retour];
                continue;
            }
            $dernier = count($fusionnes) - 1;
            if ($depart->lte($fusionnes[$dernier][1])) {
                if ($retour->gt($fusionnes[$dernier][1])) {
                    $fusionnes[$dernier][1] = $retour;
                }
                continue;
            }
            $fusionnes[] = [$depart, $retour];
        }

        $libres = [];
        $curseur = $debut->copy();
        foreach ($fusionnes as [$depart, $retour]) {
            if ($depart->gte($fin)) {
                break;
            }
            if ($depart->gt($curseur)) {
                $finLibre = $depart->lt($fin) ? $depart->copy() : $fin->copy();
                $libres[] = [$curseur->copy(), $finLibre];
            }
            if ($retour->gt($curseur)) {
                $curseur = $retour->copy();
            }
        }
        if ($curseur->lt($fin)) {
            $libres[] = [$curseur->copy(), $fin->copy()];
        }

        $formater = fn (Carbon $a, Carbon $b) => [
            'starts_at' => $a->toIso8601String(),
            'ends_at' => $b->toIso8601String(),
        ];

        return [
            'occupied' => array_map(fn ($p) => $formater($p[0], $p[1]), $fusionnes),
            'free' => array_map(fn ($p) => $formater($p[0], $p[1]), $libres),
        ];
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
                'payment_method' => $data['payment_method'] ?? null,
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

            return $booking->load(['vehicle.media', 'pickupLocation', 'returnLocation']);
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

    /** Confirmation admin / commercial : passe la réservation en confirmée. */
    public function confirmByAdmin(Booking $booking): Booking
    {
        $statut = $booking->status instanceof BookingStatus
            ? $booking->status
            : BookingStatus::tryFrom((string) $booking->status);

        $autorises = [
            BookingStatus::PendingPartner,
            BookingStatus::PendingPayment,
            BookingStatus::Locked,
        ];

        if ($statut && in_array($statut, $autorises, true) && $booking->pickup_at && $booking->pickup_at->lt(now())) {
            $this->archiver($booking);
            throw ValidationException::withMessages([
                'status' => ['Le délai est dépassé : la réservation a été archivée et ne peut plus être validée.'],
            ]);
        }

        if (! $statut || ! in_array($statut, $autorises, true)) {
            throw ValidationException::withMessages([
                'status' => ['Cette réservation ne peut plus être validée.'],
            ]);
        }

        $avant = $booking->toArray();
        $booking->update([
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => now(),
            'locked_until' => null,
        ]);

        $this->audit->log('booking.confirmed_by_admin', $booking, $avant, $booking->fresh()->toArray());

        return $booking->fresh(['vehicle', 'partner', 'customer', 'pickupLocation']);
    }

    /**
     * Le client annule tant que la demande n’est pas encore validée
     * (en cours de validation, paiement en attente ou attente partenaire).
     */
    public function annulerParClient(Booking $booking): Booking
    {
        $statut = $booking->status instanceof BookingStatus
            ? $booking->status
            : BookingStatus::tryFrom((string) $booking->status);

        $autorises = [
            BookingStatus::Locked,
            BookingStatus::PendingPayment,
            BookingStatus::PendingPartner,
        ];

        if (! $statut || ! in_array($statut, $autorises, true)) {
            throw ValidationException::withMessages([
                'status' => ['Cette réservation ne peut plus être annulée.'],
            ]);
        }

        $avant = $booking->toArray();
        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Annulée par le client.',
            'locked_until' => null,
        ]);

        $this->audit->log('booking.cancelled_by_customer', $booking, $avant, $booking->fresh()->toArray());

        return $booking->fresh(['vehicle', 'customer', 'pickupLocation']);
    }

    /**
     * Retire définitivement une réservation déjà annulée.
     * Une demande encore active doit d’abord être annulée pour rester dans l’historique.
     */
    public function supprimerDefinitivement(Booking $booking): void
    {
        $statut = $booking->status instanceof BookingStatus
            ? $booking->status
            : BookingStatus::tryFrom((string) $booking->status);

        if ($statut !== BookingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['Seule une réservation annulée peut être supprimée définitivement.'],
            ]);
        }

        $avant = $booking->toArray();
        $this->audit->log('booking.deleted', null, $avant, null);
        $booking->delete();
    }

    /** Annulation admin d’une réservation non terminée. */
    public function cancelByAdmin(Booking $booking, ?string $raison = null): Booking
    {
        $statut = $booking->status instanceof BookingStatus
            ? $booking->status
            : BookingStatus::tryFrom((string) $booking->status);

        $interdits = [
            BookingStatus::Completed,
            BookingStatus::Cancelled,
            BookingStatus::Refunded,
            BookingStatus::Expired,
            BookingStatus::Archived,
        ];

        if (! $statut || in_array($statut, $interdits, true)) {
            throw ValidationException::withMessages([
                'status' => ['Cette réservation ne peut pas être annulée.'],
            ]);
        }

        $avant = $booking->toArray();
        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $raison ?: 'Annulée par l’administration.',
            'locked_until' => null,
        ]);

        $this->audit->log('booking.cancelled_by_admin', $booking, $avant, $booking->fresh()->toArray());

        return $booking->fresh(['vehicle', 'partner', 'customer', 'pickupLocation']);
    }

    /** Passage en location terminée (après confirmation / en cours). */
    public function completeByAdmin(Booking $booking): Booking
    {
        $statut = $booking->status instanceof BookingStatus
            ? $booking->status
            : BookingStatus::tryFrom((string) $booking->status);

        $autorises = [BookingStatus::Confirmed, BookingStatus::Ongoing];

        if (! $statut || ! in_array($statut, $autorises, true)) {
            throw ValidationException::withMessages([
                'status' => ['Seule une réservation confirmée ou en cours peut être terminée.'],
            ]);
        }

        $avant = $booking->toArray();
        $booking->update(['status' => BookingStatus::Completed]);

        $this->audit->log('booking.completed_by_admin', $booking, $avant, $booking->fresh()->toArray());

        return $booking->fresh(['vehicle', 'partner', 'customer', 'pickupLocation']);
    }

    /**
     * Une demande encore non validée dont le départ est passé ne peut plus être honorée :
     * elle est archivée et sort du circuit de validation.
     */
    public function archiverDelaisDepasses(): int
    {
        $reservations = Booking::query()
            ->whereIn('status', [
                BookingStatus::PendingPartner->value,
                BookingStatus::PendingPayment->value,
                BookingStatus::Locked->value,
            ])
            ->where('pickup_at', '<', now())
            ->get();

        foreach ($reservations as $reservation) {
            $this->archiver($reservation);
        }

        return $reservations->count();
    }

    private function archiver(Booking $booking): void
    {
        $avant = $booking->toArray();
        $booking->update([
            'status' => BookingStatus::Archived,
            'locked_until' => null,
        ]);

        $this->audit->log('booking.archived', $booking, $avant, $booking->fresh()->toArray());
    }
}
