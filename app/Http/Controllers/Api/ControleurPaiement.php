<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaiementMobile;
use App\Services\PasserellePvit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Paiement Mobile Money d’une réservation. La référence de réservation, aléatoire et connue du seul client,
 * autorise aussi les réservations faites sans compte.
 */
class ControleurPaiement extends Controller
{
    public function __construct(
        private PaiementMobile $paiements,
        private PasserellePvit $pvit,
    ) {}

    public function configuration(): JsonResponse
    {
        return response()->json([
            'data' => [
                'en_ligne' => $this->pvit->estActive(),
                'moyens' => array_keys((array) config('services.pvit.operateurs')),
                'montant_maximum' => $this->pvit->montantMaximum(),
            ],
        ]);
    }

    public function lancer(Request $requete, int $id): JsonResponse
    {
        $donnees = $requete->validate([
            'reference' => ['required', 'string', 'max:40'],
            'moyen' => ['required', 'string', 'max:80'],
            'numero' => ['required', 'string', 'max:20'],
        ]);

        $reservation = $this->reservation($id, $donnees['reference']);
        $paiement = $this->paiements->lancer($reservation, $donnees['moyen'], $this->numeroGabonais($donnees['numero']));

        return response()->json(['data' => $this->presenter($paiement)], 201);
    }

    public function statut(Request $requete, int $id): JsonResponse
    {
        $donnees = $requete->validate(['reference' => ['required', 'string', 'max:40']]);
        $reservation = $this->reservation($id, $donnees['reference']);

        $paiement = $reservation->payment()->where('gateway', 'pvit')->latest('id')->first();
        if (! $paiement) {
            return response()->json(['data' => ['statut' => 'aucun']]);
        }

        return response()->json(['data' => $this->presenter($this->paiements->actualiser($paiement))]);
    }

    /**
     * Webhook PVit. Ces notifications ne sont pas signées : le corps sert seulement à savoir quelle
     * transaction relire. Le statut appliqué vient de l’API de PVit.
     */
    public function notification(Request $requete): JsonResponse
    {
        $reference = (string) $requete->input('merchantReferenceId', '');
        $paiement = $reference === '' ? null : $this->paiements->retrouver($reference);

        if ($paiement) {
            $charge = $paiement->gateway_payload ?? [];
            $charge['reference_id'] = $charge['reference_id'] ?? (string) $requete->input('transactionId', '');
            $paiement->update(['gateway_payload' => $charge]);
            $this->paiements->actualiser($paiement);
        }

        return response()->json([
            'transactionId' => $requete->input('transactionId'),
            'responseCode' => $requete->input('code', 200),
        ]);
    }

    private function reservation(int $id, string $reference): Booking
    {
        return Booking::query()->where('reference', $reference)->findOrFail($id);
    }

    /** PVit attend le numéro local à neuf chiffres, par exemple 074111111. */
    private function numeroGabonais(string $numero): string
    {
        $chiffres = preg_replace('/\D/', '', $numero) ?? '';
        if (str_starts_with($chiffres, '00241')) {
            $chiffres = substr($chiffres, 5);
        } elseif (str_starts_with($chiffres, '241') && strlen($chiffres) > 9) {
            $chiffres = substr($chiffres, 3);
        }
        if (strlen($chiffres) === 8) {
            $chiffres = '0'.$chiffres;
        }

        if (! preg_match('/^0[0-9]{8}$/', $chiffres)) {
            throw ValidationException::withMessages(['numero' => 'Indiquez un numéro gabonais à 9 chiffres, par exemple 074 11 11 11.']);
        }

        return $chiffres;
    }

    /**
     * @return array<string, mixed>
     */
    private function presenter(Payment $paiement): array
    {
        return [
            'statut' => $paiement->status,
            'moyen' => $paiement->method,
            'montant' => (int) $paiement->amount,
            'reference' => $paiement->transaction_ref,
        ];
    }
}
