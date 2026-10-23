<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\BookingService;
use App\Services\GenerateurRecapitulatifReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    public function __construct(
        private BookingService $bookings,
        private GenerateurRecapitulatifReservation $recapitulatifs,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $this->optionalUser($request);

        $data = $request->validate([
            'vehicle_id' => ['required', 'exists:vehicules,id'],
            'pickup_at' => ['required', 'date'],
            'return_at' => ['required', 'date', 'after:pickup_at'],
            'pickup_location_id' => ['nullable', 'exists:lieux,id'],
            'return_location_id' => ['nullable', 'exists:lieux,id'],
            'driver_age' => ['required', 'integer', 'min:18', 'max:99'],
            'guest_email' => [$user ? 'nullable' : 'required', 'email', 'max:190'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_name' => [$user ? 'nullable' : 'required', 'string', 'max:120'],
            'payment_method' => $this->regleMoyenPaiement(),
        ], [
            'payment_method.required' => 'Choisissez un moyen de paiement.',
            'payment_method.exists' => 'Ce moyen de paiement n’est plus proposé.',
        ]);

        $compteCree = false;
        $motDePasseGenerique = (string) config('locagabon.default_customer_password', 'LocaGabon2026!');
        $afficherIdentifiants = false;

        if ($user) {
            $data['guest_email'] = $data['guest_email'] ?? $user->email;
            $data['guest_name'] = $data['guest_name'] ?? $user->name;
            $data['guest_phone'] = $data['guest_phone'] ?? $user->phone;
        } else {
            // Compte client auto-créé à la réservation (mot de passe générique modifiable ensuite).
            [$user, $compteCree] = $this->assurerCompteClient(
                $data['guest_email'],
                $data['guest_name'],
                $data['guest_phone'] ?? null,
                $motDePasseGenerique,
            );
            $afficherIdentifiants = true;
        }

        $booking = $this->bookings->create($data, $user->id);

        return response()->json([
            'data' => $this->masquerProprietaire($booking),
            'meta' => [
                'account_created' => $compteCree,
                'show_credentials' => $afficherIdentifiants,
                // Toujours renvoyé pour les réservations invitées (connexion ensuite).
                'temporary_password' => $afficherIdentifiants ? $motDePasseGenerique : null,
                'login_email' => $user->email,
            ],
        ], 201);
    }

    /**
     * Crée ou retrouve le compte client à partir des infos de réservation.
     *
     * @return array{0: User, 1: bool}
     */
    private function assurerCompteClient(string $email, string $name, ?string $phone, string $motDePasse): array
    {
        $existant = User::query()->where('email', $email)->first();

        if ($existant) {
            if (! $existant->customerProfile && $existant->role === UserRole::Customer) {
                CustomerProfile::create([
                    'user_id' => $existant->id,
                    'first_name' => $name,
                ]);
            }

            return [$existant, false];
        }

        // Évite le conflit d’unicité téléphone si déjà utilisé par un autre compte.
        $telephone = $phone && ! User::query()->where('phone', $phone)->exists()
            ? $phone
            : null;

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'phone' => $telephone,
            'password' => $motDePasse,
            'role' => UserRole::Customer,
            'status' => 'active',
            'locale' => 'fr',
        ]);

        CustomerProfile::create([
            'user_id' => $user->id,
            'first_name' => $name,
        ]);

        return [$user, true];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->claimGuestBookings($user);

        $bookings = Booking::with(['vehicle.media', 'pickupLocation'])
            ->where(function ($q) use ($user) {
                $q->where('customer_id', $user->id)
                    ->orWhere('guest_email', $user->email);
            })
            ->latest()
            ->get()
            ->each(fn (Booking $reservation) => $this->masquerProprietaire($reservation));

        return response()->json(['data' => $bookings]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->claimGuestBookings($user);

        $booking = Booking::with(['vehicle.media', 'pickupLocation', 'returnLocation', 'payment'])
            ->where(function ($q) use ($user) {
                $q->where('customer_id', $user->id)
                    ->orWhere('guest_email', $user->email);
            })
            ->findOrFail($id);

        return response()->json(['data' => $this->masquerProprietaire($booking)]);
    }

    /** PDF du récapitulatif, réservé au client propriétaire de la demande. */
    public function recapitulatif(Request $request, int $id): Response
    {
        $user = $request->user();
        $this->claimGuestBookings($user);

        $reservation = Booking::query()
            ->where(function ($q) use ($user) {
                $q->where('customer_id', $user->id)
                    ->orWhere('guest_email', $user->email);
            })
            ->findOrFail($id);

        $nom = 'recapitulatif-'.($reservation->reference ?: $reservation->id).'.pdf';

        return response($this->recapitulatifs->produire($reservation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nom.'"',
        ]);
    }

    /** Annulation par le réservateur, uniquement tant que la demande est en validation. */
    public function annuler(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->claimGuestBookings($user);

        $booking = Booking::query()
            ->where(function ($q) use ($user) {
                $q->where('customer_id', $user->id)
                    ->orWhere('guest_email', $user->email);
            })
            ->findOrFail($id);

        $annulee = $this->bookings->annulerParClient($booking);

        return response()->json([
            'message' => 'Réservation annulée.',
            'data' => $this->masquerProprietaire($annulee),
        ]);
    }

    /** Suppression définitive, uniquement d’une réservation déjà annulée et appartenant au client. */
    public function supprimer(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->claimGuestBookings($user);

        $booking = Booking::query()
            ->where(function ($q) use ($user) {
                $q->where('customer_id', $user->id)
                    ->orWhere('guest_email', $user->email);
            })
            ->findOrFail($id);

        $this->bookings->supprimerDefinitivement($booking);

        return response()->json(['message' => 'Réservation supprimée définitivement.']);
    }

    public function guestLookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $booking = Booking::with(['vehicle.media', 'payment'])
            ->where('reference', $data['reference'])
            ->where(function ($q) use ($data) {
                $q->where('guest_email', $data['email'])
                    ->orWhereHas('customer', fn ($cq) => $cq->where('email', $data['email']));
            })
            ->firstOrFail();

        return response()->json(['data' => $this->masquerProprietaire($booking)]);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'method' => $this->regleMoyenPaiement(),
        ], [
            'method.required' => 'Choisissez un moyen de paiement.',
            'method.exists' => 'Ce moyen de paiement n’est plus proposé.',
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
                'booking' => $this->masquerProprietaire($booking->fresh(['vehicle', 'payment'])),
            ],
        ]);
    }

    /**
     * Un moyen est accepté seulement s’il est actif dans le référentiel admin.
     *
     * @return list<\Illuminate\Contracts\Validation\ValidationRule|string>
     */
    private function regleMoyenPaiement(): array
    {
        return [
            'required',
            'string',
            'max:80',
            Rule::exists('listes_reference', 'slug')->where(
                fn ($requete) => $requete->where('type', 'payment_method')->where('is_active', true)
            ),
        ];
    }

    private function optionalUser(Request $request): ?User
    {
        return $request->user('sanctum') ?? $request->user();
    }

    /**
     * Le client ne voit pas qui possède la voiture. Le partner_id reste en base.
     */
    private function masquerProprietaire(Booking $reservation): Booking
    {
        $reservation->makeHidden(['partner_id']);
        $reservation->unsetRelation('partner');

        $vehicule = $reservation->relationLoaded('vehicle') ? $reservation->vehicle : null;
        if ($vehicule) {
            $vehicule->makeHidden(['partner_id']);
            $vehicule->unsetRelation('partner');
        }

        return $reservation;
    }

    private function claimGuestBookings(User $user): void
    {
        Booking::query()
            ->whereNull('customer_id')
            ->where('guest_email', $user->email)
            ->update(['customer_id' => $user->id]);
    }
}
