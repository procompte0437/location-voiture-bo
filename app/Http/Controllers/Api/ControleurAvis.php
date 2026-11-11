<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Api\Partenaire\ResoutPartenaire;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Partner;
use App\Models\Review;
use App\Models\Vehicle;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Avis clients (cahier des charges F-CLI-04) : seul un client dont la location est terminée note,
 * le loueur répond, le super admin modère. La note publique du loueur ne compte que les avis publiés.
 * Côté public, le nom du loueur n’apparaît jamais : les avis s’affichent sous le véhicule.
 */
class ControleurAvis extends Controller
{
    use ResoutPartenaire;

    public const CRITERES = [
        'rating_cleanliness' => 'Propreté',
        'rating_condition' => 'État du véhicule',
        'rating_welcome' => 'Accueil',
        'rating_value' => 'Rapport qualité/prix',
    ];

    public function __construct(private AuditService $audit) {}

    public function deposer(Request $requete, int $id): JsonResponse
    {
        $utilisateur = $requete->user();
        $reservation = Booking::query()
            ->where(fn ($q) => $q->where('customer_id', $utilisateur->id)->orWhere('guest_email', $utilisateur->email))
            ->findOrFail($id);

        $regles = array_map(fn () => ['required', 'integer', 'between:1,5'], self::CRITERES);
        $donnees = $requete->validate([...$regles, 'comment' => ['nullable', 'string', 'max:2000']], [
            '*.required' => 'Notez chaque critère de 1 à 5 étoiles.',
            '*.between' => 'Chaque note va de 1 à 5 étoiles.',
        ]);

        if ($reservation->status !== BookingStatus::Completed) {
            throw ValidationException::withMessages(['booking' => ['Vous pourrez noter cette location une fois le véhicule rendu.']]);
        }
        if ($reservation->review()->exists()) {
            throw ValidationException::withMessages(['booking' => ['Vous avez déjà donné votre avis sur cette location.']]);
        }

        $notes = array_intersect_key($donnees, self::CRITERES);
        $avis = Review::create([
            ...$notes,
            'booking_id' => $reservation->id,
            'customer_id' => $utilisateur->id,
            'partner_id' => $reservation->partner_id,
            'rating_overall' => (int) round(array_sum($notes) / count($notes)),
            'comment' => isset($donnees['comment']) ? trim($donnees['comment']) ?: null : null,
            'moderation_status' => 'published',
        ]);
        self::recalculer($reservation->partner);
        $this->audit->log('review.created', $avis, null, $avis->toArray());

        return response()->json(['message' => 'Merci pour votre avis !', 'data' => $this->presenterPublic($avis)], 201);
    }

    /** Avis publiés sur le loueur du véhicule, sans le nommer. */
    public function duVehicule(int $id): JsonResponse
    {
        $vehicule = Vehicle::query()->findOrFail($id);
        $avis = Review::query()
            ->where('partner_id', $vehicule->partner_id)
            ->where('moderation_status', 'published')
            ->with(['customer:id,name', 'booking.vehicle:id,brand,model'])
            ->latest()
            ->limit(20)
            ->get();

        $publies = Review::query()->where('partner_id', $vehicule->partner_id)->where('moderation_status', 'published');

        return response()->json(['data' => [
            'moyenne' => round(self::moyenne($publies), 1),
            'nombre' => (clone $publies)->count(),
            'criteres' => collect(self::CRITERES)->map(fn ($libelle, $champ) => [
                'libelle' => $libelle,
                'moyenne' => round((float) (clone $publies)->avg($champ), 1),
            ])->values(),
            'avis' => $avis->map(fn (Review $a) => $this->presenterPublic($a))->values(),
        ]]);
    }

    public function duPartenaire(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent']);
        $avis = Review::query()->where('partner_id', $partenaire->id)
            ->with(['customer:id,name', 'booking:id,reference,vehicle_id', 'booking.vehicle:id,brand,model,plate_number'])
            ->latest()->limit(500)->get();

        return response()->json(['data' => [
            'moyenne' => (float) $partenaire->average_rating,
            'nombre' => (int) $partenaire->reviews_count,
            'avis' => $avis->map(fn (Review $a) => $this->presenterInterne($a))->values(),
        ]]);
    }

    public function repondre(Request $requete, int $id): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent']);
        $avis = Review::query()->where('partner_id', $partenaire->id)->findOrFail($id);
        $donnees = $requete->validate(['reponse' => ['required', 'string', 'min:2', 'max:2000']], [
            'reponse.required' => 'Écrivez votre réponse au client.',
        ]);

        $avant = $avis->toArray();
        $avis->update(['partner_reply' => trim($donnees['reponse']), 'partner_replied_at' => now()]);
        $this->audit->log('review.replied', $avis, $avant, $avis->fresh()->toArray());

        return response()->json(['message' => 'Réponse publiée.', 'data' => $this->presenterInterne($avis->fresh())]);
    }

    public function pourAdmin(Request $requete): JsonResponse
    {
        $statut = $requete->query('statut');
        $avis = Review::query()
            ->when(in_array($statut, ['published', 'hidden'], true), fn ($q) => $q->where('moderation_status', $statut))
            ->with(['customer:id,name,email', 'partner:id,company_name', 'booking:id,reference,vehicle_id', 'booking.vehicle:id,brand,model,plate_number'])
            ->latest()->limit(500)->get();

        return response()->json(['data' => $avis->map(fn (Review $a) => [
            ...$this->presenterInterne($a),
            'client_email' => $a->customer?->email,
            'partenaire' => $a->partner?->company_name,
        ])->values()]);
    }

    /** Masquer retire l’avis du site et de la note ; republier le rétablit. */
    public function moderer(Request $requete, int $id): JsonResponse
    {
        $donnees = $requete->validate([
            'statut' => ['required', Rule::in(['published', 'hidden'])],
            'motif' => ['nullable', 'string', 'max:500', 'required_if:statut,hidden'],
        ], ['motif.required_if' => 'Indiquez pourquoi l’avis est masqué (journal d’audit).']);

        $avis = Review::query()->findOrFail($id);
        $avant = $avis->toArray();
        $avis->update(['moderation_status' => $donnees['statut']]);
        self::recalculer($avis->partner);
        $this->audit->log('review.moderated', $avis, $avant, $avis->fresh()->toArray(), null, ['motif' => $donnees['motif'] ?? null]);

        return response()->json([
            'message' => $donnees['statut'] === 'hidden' ? 'Avis masqué.' : 'Avis republié.',
            'data' => $this->presenterInterne($avis->fresh()),
        ]);
    }

    public static function recalculer(?Partner $partenaire): void
    {
        if (! $partenaire) {
            return;
        }
        $publies = Review::query()->where('partner_id', $partenaire->id)->where('moderation_status', 'published');
        $partenaire->update([
            'average_rating' => round(self::moyenne($publies), 2),
            'reviews_count' => (clone $publies)->count(),
        ]);
    }

    /**
     * Moyenne des quatre critères : plus fidèle que la note globale arrondie à l’entier
     * (5, 4, 5, 4 donnent 4,5 et non 5).
     */
    private static function moyenne(\Illuminate\Database\Eloquent\Builder $avis): float
    {
        return (float) (clone $avis)->avg(\Illuminate\Support\Facades\DB::raw(
            '(coalesce(rating_cleanliness, rating_overall) + coalesce(rating_condition, rating_overall)'
            .' + coalesce(rating_welcome, rating_overall) + coalesce(rating_value, rating_overall)) / 4.0'
        ));
    }

    /** Le client est cité par son prénom et l’initiale de son nom. */
    private function presenterPublic(Review $avis): array
    {
        $nom = trim((string) $avis->customer?->name);
        $parties = preg_split('/\s+/', $nom) ?: [];
        $auteur = $nom === '' ? 'Client DOTO' : $parties[0].(isset($parties[1]) ? ' '.Str::upper(mb_substr($parties[1], 0, 1)).'.' : '');

        return [
            'id' => $avis->id,
            'auteur' => $auteur,
            'note' => (int) $avis->rating_overall,
            'notes' => collect(self::CRITERES)->map(fn ($l, $champ) => $avis->{$champ})->all(),
            'commentaire' => $avis->comment,
            'vehicule' => $avis->booking?->vehicle ? trim($avis->booking->vehicle->brand.' '.$avis->booking->vehicle->model) : null,
            'reponse' => $avis->partner_reply,
            'publie_le' => $avis->created_at?->format('Y-m-d'),
        ];
    }

    private function presenterInterne(Review $avis): array
    {
        return [
            ...$this->presenterPublic($avis),
            'statut' => $avis->moderation_status,
            'reference' => $avis->booking?->reference,
            'immatriculation' => $avis->booking?->vehicle?->plate_number,
            'repondu_le' => $avis->partner_replied_at?->format('Y-m-d'),
        ];
    }
}
