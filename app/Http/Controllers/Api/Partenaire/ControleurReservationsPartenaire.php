<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\EtatDesLieux;
use App\Models\MouvementCaution;
use App\Models\Partner;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\GenerateurRecapitulatifReservation;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dossier de location côté loueur : accepter ou refuser la demande,
 * noter le départ et le retour, encaisser en agence, suivre la caution.
 */
class ControleurReservationsPartenaire extends Controller
{
    use ResoutPartenaire;

    private const CARBURANT = ['vide', 'un_quart', 'moitie', 'trois_quarts', 'plein'];

    public function __construct(private AuditService $audit) {}

    public function afficher(Request $requete, int $id): JsonResponse
    {
        $reservation = $this->reservation($requete, $id);

        return response()->json(['data' => $this->dossier($reservation)]);
    }

    public function accepter(Request $requete, int $id): JsonResponse
    {
        $reservation = $this->reservation($requete, $id, ['partner_agent']);
        $this->exigerStatut($reservation, [BookingStatus::PendingPartner, BookingStatus::PendingPayment, BookingStatus::Locked], 'Cette demande n’est plus en attente de réponse.');

        if ($reservation->pickup_at->lt(now())) {
            throw ValidationException::withMessages(['status' => ['Le départ est passé : la demande ne peut plus être acceptée.']]);
        }

        return $this->changer($reservation, 'booking.accepted_by_partner', [
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => now(),
            'locked_until' => null,
        ]);
    }

    public function refuser(Request $requete, int $id): JsonResponse
    {
        $reservation = $this->reservation($requete, $id, ['partner_agent']);
        $donnees = $requete->validate(['motif' => ['required', 'string', 'min:3', 'max:500']], [
            'motif.required' => 'Indiquez le motif du refus : le client le verra.',
        ]);
        $this->exigerStatut($reservation, [BookingStatus::PendingPartner, BookingStatus::PendingPayment, BookingStatus::Locked], 'Cette demande n’est plus en attente de réponse.');

        // Un paiement déjà reçu reste à rembourser par l’administration : le journal le signale.
        return $this->changer($reservation, 'booking.refused_by_partner', [
            'status' => BookingStatus::Refused,
            'cancelled_at' => now(),
            'cancellation_reason' => $donnees['motif'],
            'locked_until' => null,
        ], ['paiement_a_rembourser' => $this->totalRegle($reservation) > 0]);
    }

    public function sortie(Request $requete, int $id): JsonResponse
    {
        $reservation = $this->reservation($requete, $id, ['partner_agent']);
        $donnees = $requete->validate([
            'conducteur' => ['required', 'string', 'max:120'],
            'kilometrage' => ['required', 'integer', 'min:0', 'max:2000000'],
            'niveau_carburant' => ['required', Rule::in(self::CARBURANT)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['conducteur.required' => 'Indiquez le nom du conducteur qui prend le véhicule.']);
        $this->exigerStatut($reservation, [BookingStatus::Confirmed], 'Le départ se note sur une réservation confirmée.');

        return DB::transaction(function () use ($reservation, $donnees, $requete) {
            EtatDesLieux::create([...$donnees, 'booking_id' => $reservation->id, 'type' => 'depart', 'cree_par' => $requete->user()->id]);

            return $this->changer($reservation, 'booking.departure_recorded', ['status' => BookingStatus::Ongoing], $donnees);
        });
    }

    public function retour(Request $requete, int $id): JsonResponse
    {
        $reservation = $this->reservation($requete, $id, ['partner_agent']);
        $donnees = $requete->validate([
            'kilometrage' => ['required', 'integer', 'min:0', 'max:2000000'],
            'niveau_carburant' => ['required', Rule::in(self::CARBURANT)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->exigerStatut($reservation, [BookingStatus::Ongoing], 'Le retour se note sur une location en cours.');

        $depart = $reservation->etatsDesLieux()->where('type', 'depart')->first();
        if ($depart && $donnees['kilometrage'] < $depart->kilometrage) {
            throw ValidationException::withMessages([
                'kilometrage' => ['Le kilométrage au retour ne peut pas être inférieur à celui du départ ('.$depart->kilometrage.' km).'],
            ]);
        }

        return DB::transaction(function () use ($reservation, $donnees, $requete) {
            EtatDesLieux::create([...$donnees, 'booking_id' => $reservation->id, 'type' => 'retour', 'cree_par' => $requete->user()->id]);

            return $this->changer($reservation, 'booking.return_recorded', ['status' => BookingStatus::Completed], $donnees);
        });
    }

    /** Encaissement reçu en agence (espèces, Mobile Money direct, virement). */
    public function reglement(Request $requete, int $id): JsonResponse
    {
        $reservation = $this->reservation($requete, $id, ['partner_agent', 'finance_manager']);
        $donnees = $requete->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(['especes', 'mobile_money', 'virement', 'carte'])],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->exigerStatut($reservation, [BookingStatus::Confirmed, BookingStatus::Ongoing, BookingStatus::Completed], 'Un règlement s’enregistre sur une réservation confirmée.');

        $solde = $this->solde($reservation);
        if ($donnees['montant'] > $solde) {
            throw ValidationException::withMessages(['montant' => ['Le montant dépasse le solde restant ('.$solde.' FCFA).']]);
        }

        $paiement = Payment::create([
            'booking_id' => $reservation->id,
            'gateway' => 'agence',
            'method' => $donnees['mode'],
            'amount' => $donnees['montant'],
            'currency' => 'XAF',
            'status' => 'paid',
            'gateway_payload' => ['reference' => $donnees['reference'] ?? null, 'saisi_par' => $requete->user()->id],
            'paid_at' => now(),
        ]);
        $this->audit->log('booking.payment_recorded_by_partner', $reservation, null, $paiement->toArray());

        return response()->json(['message' => 'Règlement enregistré.', 'data' => $this->dossier($reservation->fresh())], 201);
    }

    /** La caution se suit à part : elle n’entre pas dans le chiffre d’affaires. */
    public function caution(Request $requete, int $id): JsonResponse
    {
        $reservation = $this->reservation($requete, $id, ['partner_agent', 'finance_manager']);
        $donnees = $requete->validate([
            'type' => ['required', Rule::in(['encaissement', 'restitution', 'affectation'])],
            'montant' => ['required', 'integer', 'min:1'],
            'motif' => ['nullable', 'string', 'max:500', 'required_unless:type,encaissement'],
        ], ['motif.required_unless' => 'Expliquez la restitution ou l’affectation de la caution.']);

        $detenue = $this->cautionDetenue($reservation);
        if ($donnees['type'] !== 'encaissement' && $donnees['montant'] > $detenue) {
            throw ValidationException::withMessages(['montant' => ['Seulement '.$detenue.' FCFA de caution sont détenus pour cette réservation.']]);
        }

        $mouvement = MouvementCaution::create([
            ...$donnees,
            'booking_id' => $reservation->id,
            'partner_id' => $reservation->partner_id,
            'cree_par' => $requete->user()->id,
        ]);
        $this->audit->log('booking.deposit_'.$donnees['type'], $reservation, null, $mouvement->toArray());

        return response()->json(['message' => 'Opération de caution enregistrée.', 'data' => $mouvement], 201);
    }

    public function contrat(Request $requete, int $id, GenerateurRecapitulatifReservation $generateur): Response
    {
        $reservation = $this->reservation($requete, $id);

        return $this->pdf($generateur->produire($reservation), 'contrat-'.($reservation->reference ?: $reservation->id).'.pdf');
    }

    public function etatDesLieux(Request $requete, int $id): Response
    {
        $reservation = $this->reservation($requete, $id)->load(['vehicle', 'etatsDesLieux', 'customer']);

        $html = view('pdf.etat-des-lieux', [
            'reservation' => $reservation,
            'depart' => $reservation->etatsDesLieux->firstWhere('type', 'depart'),
            'retour' => $reservation->etatsDesLieux->firstWhere('type', 'retour'),
            'client' => $this->nomClient($reservation),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $this->pdf($pdf->output(), 'etat-des-lieux-'.($reservation->reference ?: $reservation->id).'.pdf');
    }

    /**
     * @param  list<string>  $rolesAutorises
     */
    private function reservation(Request $requete, int $id, array $rolesAutorises = ['partner_agent', 'finance_manager']): Booking
    {
        $partenaire = $this->partenaire($requete, $rolesAutorises);

        return Booking::query()->where('partner_id', $partenaire->id)->findOrFail($id);
    }

    /**
     * @param  list<BookingStatus>  $autorises
     */
    private function exigerStatut(Booking $reservation, array $autorises, string $message): void
    {
        $statut = $reservation->status instanceof BookingStatus ? $reservation->status : BookingStatus::tryFrom((string) $reservation->status);
        if (! $statut || ! in_array($statut, $autorises, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function changer(Booking $reservation, string $action, array $valeurs, ?array $contexte = null): JsonResponse
    {
        $avant = $reservation->toArray();
        $reservation->update($valeurs);
        $this->audit->log($action, $reservation, $avant, $reservation->fresh()->toArray(), null, $contexte);

        return response()->json(['message' => 'Dossier mis à jour.', 'data' => $this->dossier($reservation->fresh())]);
    }

    /** Les coordonnées du client ne sont montrées qu’une fois la location confirmée. */
    private function dossier(Booking $reservation): array
    {
        $reservation->loadMissing(['vehicle', 'customer', 'pickupLocation', 'returnLocation', 'etatsDesLieux', 'mouvementsCaution', 'paiements']);
        $statut = $reservation->status instanceof BookingStatus ? $reservation->status->value : (string) $reservation->status;
        $contactVisible = in_array($statut, ['confirmed', 'ongoing', 'completed'], true);

        return [
            'id' => $reservation->id,
            'reference' => $reservation->reference,
            'status' => $statut,
            'pickup_at' => $reservation->pickup_at?->toIso8601String(),
            'return_at' => $reservation->return_at?->toIso8601String(),
            'days_count' => $reservation->days_count,
            'total_amount' => (int) $reservation->total_amount,
            'deposit_amount' => (int) $reservation->deposit_amount,
            'commission_amount' => (int) $reservation->commission_amount,
            'montant_regle' => $this->totalRegle($reservation),
            'solde_restant' => $this->solde($reservation),
            'caution_detenue' => $this->cautionDetenue($reservation),
            'regle_duree' => 'Durée facturée : '.$reservation->days_count.' jour(s), au-delà d’une heure de tolérance chaque nouvelle journée est due.',
            'cancellation_reason' => $reservation->cancellation_reason,
            'vehicle' => $reservation->vehicle?->only(['id', 'brand', 'model', 'plate_number']),
            'pickup_location' => $reservation->pickupLocation?->name,
            'return_location' => $reservation->returnLocation?->name,
            'client' => [
                'nom' => $this->nomClient($reservation),
                'email' => $contactVisible ? ($reservation->customer?->email ?? $reservation->guest_email) : null,
                'telephone' => $contactVisible ? ($reservation->customer?->phone ?? $reservation->guest_phone) : null,
            ],
            'etats_des_lieux' => $reservation->etatsDesLieux->values(),
            'cautions' => $reservation->mouvementsCaution->values(),
            'reglements' => $reservation->paiements->where('status', 'paid')->values(),
        ];
    }

    private function nomClient(Booking $reservation): string
    {
        return (string) ($reservation->customer?->name ?? $reservation->guest_name ?? 'Client');
    }

    private function totalRegle(Booking $reservation): int
    {
        return (int) $reservation->paiements()->where('status', 'paid')->sum('amount');
    }

    private function solde(Booking $reservation): int
    {
        return max(0, (int) $reservation->total_amount - $this->totalRegle($reservation));
    }

    public static function cautionDe(int $reservationId): int
    {
        $mouvements = MouvementCaution::query()->where('booking_id', $reservationId)->get();

        return (int) $mouvements->where('type', 'encaissement')->sum('montant')
            - (int) $mouvements->whereIn('type', ['restitution', 'affectation'])->sum('montant');
    }

    private function cautionDetenue(Booking $reservation): int
    {
        return self::cautionDe($reservation->id);
    }

    private function pdf(string $contenu, string $nom): Response
    {
        return response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nom.'"',
        ]);
    }
}
