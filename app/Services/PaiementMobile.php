<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Encaissement Mobile Money d’une réservation.
 * Une réservation n’est payée que sur le statut lu auprès de PVit, jamais sur le corps d’un webhook.
 */
class PaiementMobile
{
    private const GATEWAY = 'pvit';

    /** Une demande restée sans réponse ne bloque plus une nouvelle tentative après ce délai. */
    private const ATTENTE_MINUTES = 5;

    public function __construct(
        private PasserellePvit $pvit,
        private BookingService $reservations,
        private AuditService $audit,
    ) {}

    public function lancer(Booking $reservation, string $moyen, string $numero): Payment
    {
        if (! $this->pvit->estActive()) {
            throw ValidationException::withMessages(['moyen' => 'Le paiement en ligne n’est pas encore ouvert.']);
        }

        $operateur = $this->pvit->codeOperateur($moyen);
        if ($operateur === null) {
            throw ValidationException::withMessages(['moyen' => 'Ce moyen de paiement ne se règle pas en ligne.']);
        }

        $statut = $reservation->status instanceof BookingStatus ? $reservation->status : BookingStatus::tryFrom((string) $reservation->status);
        if (! in_array($statut, [BookingStatus::Locked, BookingStatus::PendingPayment], true)) {
            throw ValidationException::withMessages(['reservation' => 'Cette réservation n’attend plus de paiement.']);
        }

        if ($reservation->total_amount > $this->pvit->montantMaximum()) {
            throw ValidationException::withMessages([
                'moyen' => 'Ce montant dépasse le plafond Mobile Money. Choisissez le règlement en agence.',
            ]);
        }

        $enCours = $reservation->payment()->where('gateway', self::GATEWAY)->where('status', 'pending')->latest()->first();
        if ($enCours) {
            $enCours = $this->actualiser($enCours);
            if ($enCours->status === 'paid') {
                return $enCours;
            }
            if ($enCours->status === 'pending' && $enCours->created_at->gt(now()->subMinutes(self::ATTENTE_MINUTES))) {
                return $enCours;
            }
            if ($enCours->status === 'pending') {
                $enCours->update(['status' => 'abandoned']);
            }
        }

        $reference = 'DT'.strtoupper(Str::random(16));
        $titulaire = $this->pvit->titulaire($numero, $operateur);
        $reponse = $this->pvit->initierPaiement(
            (int) $reservation->total_amount,
            $reference,
            $numero,
            $operateur,
            'Réservation '.$reservation->reference,
        );

        $paiement = Payment::create([
            'booking_id' => $reservation->id,
            'gateway' => self::GATEWAY,
            'method' => $moyen,
            'amount' => $reservation->total_amount,
            'currency' => 'XAF',
            'status' => 'pending',
            'transaction_ref' => $reference,
            'gateway_payload' => [
                'reference_id' => $reponse['reference_id'] ?? null,
                'operator' => $operateur,
                'titulaire' => $titulaire,
            ],
        ]);

        // La place reste retenue le temps que le client valide sur son téléphone.
        $reservation->update(['payment_method' => $moyen, 'locked_until' => now()->addMinutes(self::ATTENTE_MINUTES * 2)]);

        return $paiement;
    }

    /** Relit le statut chez PVit et l’applique, sans effet si le paiement est déjà clos. */
    public function actualiser(Payment $paiement): Payment
    {
        $identifiant = $paiement->gateway_payload['reference_id'] ?? null;
        if ($paiement->status !== 'pending' || ! is_string($identifiant) || $identifiant === '') {
            return $paiement;
        }

        $resultat = $this->pvit->statutTransaction($identifiant);
        if ($resultat === null) {
            return $paiement;
        }

        return $this->appliquer($paiement, $resultat);
    }

    /** Paiement correspondant à une référence reçue de l’extérieur. */
    public function retrouver(string $reference): ?Payment
    {
        return Payment::query()->where('gateway', self::GATEWAY)->where('transaction_ref', $reference)->first();
    }

    /**
     * @param  array<string, mixed>  $resultat
     */
    private function appliquer(Payment $paiement, array $resultat): Payment
    {
        $statut = strtoupper((string) ($resultat['status'] ?? ''));

        return DB::transaction(function () use ($paiement, $resultat, $statut) {
            $verrouille = Payment::query()->with('booking')->lockForUpdate()->findOrFail($paiement->id);
            if ($verrouille->status !== 'pending') {
                return $verrouille;
            }

            $reference = (string) ($resultat['merchant_reference_id'] ?? $verrouille->transaction_ref);
            if ($reference !== $verrouille->transaction_ref) {
                return $verrouille;
            }

            if ($statut === 'FAILED') {
                $verrouille->update(['status' => 'failed']);
                $this->audit->log('payment.failed', $verrouille->booking, null, ['payment_id' => $verrouille->id]);

                return $verrouille;
            }

            if ($statut !== 'SUCCESS') {
                return $verrouille;
            }

            $attendu = filled(config('services.pvit.montant_test'))
                ? (int) config('services.pvit.montant_test')
                : (int) $verrouille->amount;
            if ((int) ($resultat['amount'] ?? 0) !== $attendu) {
                $this->audit->log('payment.amount_mismatch', $verrouille->booking, null, [
                    'payment_id' => $verrouille->id,
                    'attendu' => $attendu,
                    'recu' => $resultat['amount'] ?? null,
                ]);

                return $verrouille;
            }

            $verrouille->update(['status' => 'paid', 'paid_at' => now()]);
            $this->reservations->marquerPayee($verrouille->booking, $verrouille);

            return $verrouille;
        });
    }
}
