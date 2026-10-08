<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Enums\PartnerStatus;
use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\ReferenceList;
use App\Models\Vehicle;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * API espace partenaire : inscription, tableau de bord, flotte et réservations.
 */
class ControleurPartenaire extends Controller
{
    /** Types de référentiel gérés depuis l’espace partenaire. */
    private const TYPES_REFERENTIEL = ['marque', 'modele'];

    public function __construct(private AuditService $audit) {}

    /**
     * Retourne strictement le partenaire lié au compte connecté (isolation multi-tenant).
     */
    private function partenaireDuCompte(Request $requete): Partner
    {
        $partenaire = $requete->user()?->ownedPartner;

        if (! $partenaire) {
            abort(response()->json([
                'message' => 'Aucun compte partenaire associé à cet utilisateur.',
            ], 404));
        }

        return $partenaire;
    }

    /**
     * Enregistre le dossier partenaire de l’utilisateur connecté (statut « en attente »).
     */
    public function inscrire(Request $requete): JsonResponse
    {
        // Types autorisés issus du référentiel admin (fallback MVP si vide).
        $typesPartenaire = ReferenceList::query()
            ->where('type', 'partner_type')
            ->where('is_active', true)
            ->pluck('slug')
            ->all();

        if ($typesPartenaire === []) {
            $typesPartenaire = ['agency', 'company', 'individual'];
        }

        $donnees = $requete->validate([
            'type' => ['required', Rule::in($typesPartenaire)],
            'company_name' => ['nullable', 'string', 'max:190'],
            'rccm' => ['nullable', 'string', 'max:100'],
            'nif' => ['nullable', 'string', 'max:100'],
            'manager_name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:190'],
        ]);

        $utilisateur = $requete->user();

        // Un compte ne peut posséder qu’un seul dossier partenaire.
        if ($utilisateur->ownedPartner) {
            return response()->json(['message' => 'Vous avez déjà un compte partenaire.'], 422);
        }

        $partenaire = Partner::create([
            ...$donnees,
            'owner_user_id' => $utilisateur->id,
            'status' => PartnerStatus::Pending,
            'trust_level' => 'new',
        ]);

        $utilisateur->update(['role' => UserRole::Partner]);

        $this->audit->log('partner.registered', $partenaire, null, $partenaire->toArray(), $utilisateur->id);

        return response()->json([
            'data' => $partenaire,
            'message' => 'Dossier soumis. Statut : en attente de validation.',
        ], 201);
    }

    /**
     * Retourne le profil partenaire de l’utilisateur connecté uniquement.
     */
    public function moi(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaireDuCompte($requete);

        return response()->json([
            'data' => $partenaire->load(['documents', 'vehicles.media', 'agencies']),
        ]);
    }

    /**
     * Indicateurs d’activité (statut, CA, flotte, réservations) — scoped au partenaire connecté.
     */
    public function tableauDeBord(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaireDuCompte($requete);

        $reservationsDuJour = $partenaire->bookings()->whereDate('pickup_at', today())->count();
        $confirmees = $partenaire->bookings()->where('status', 'confirmed')->count();
        $chiffreAffaires = $partenaire->bookings()
            ->whereIn('status', ['confirmed', 'ongoing', 'completed'])
            ->sum('total_amount');
        $commission = $partenaire->bookings()
            ->whereIn('status', ['confirmed', 'ongoing', 'completed'])
            ->sum('commission_amount');

        return response()->json([
            'data' => [
                'status' => $partenaire->status,
                'trust_level' => $partenaire->trust_level,
                'average_rating' => $partenaire->average_rating,
                'today_bookings' => $reservationsDuJour,
                'confirmed_bookings' => $confirmees,
                'gross_revenue' => (int) $chiffreAffaires,
                'commission_total' => (int) $commission,
                'net_revenue' => (int) ($chiffreAffaires - $commission),
                'vehicles_count' => $partenaire->vehicles()->count(),
                'published_vehicles' => $partenaire->vehicles()->where('status', VehicleStatus::Published)->count(),
            ],
        ]);
    }

    /**
     * Crée un véhicule pour le partenaire (publication selon niveau de confiance).
     */
    public function enregistrerVehicule(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaireDuCompte($requete);

        if (! $partenaire->isApproved()) {
            return response()->json([
                'message' => 'Votre compte partenaire doit être validé pour publier des véhicules.',
            ], 403);
        }

        // Champs alignés sur les colonnes actuelles de `vehicules`.
        $donnees = $requete->validate([
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'category' => ['required', 'in:city_car,sedan,suv,4x4,pickup,minibus,utility,luxury'],
            'plate_number' => ['required', 'string', 'max:30', 'unique:vehicules,plate_number'],
            'color' => ['nullable', 'string', 'max:40'],
            'seats' => ['required', 'integer', 'min:2', 'max:50'],
            'doors' => ['nullable', 'integer', 'min:2', 'max:6'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:20'],
            'transmission' => ['required', 'in:manual,automatic'],
            'fuel' => ['required', 'in:petrol,diesel,hybrid,electric'],
            'air_conditioning' => ['boolean'],
            'included_km' => ['nullable', 'integer', 'min:0'],
            'deposit_amount' => ['nullable', 'integer', 'min:0'],
            'min_driver_age' => ['nullable', 'integer', 'min:18', 'max:80'],
            'min_license_years' => ['nullable', 'integer', 'min:0', 'max:20'],
            'booking_mode' => ['nullable', 'in:instant,on_request'],
            'cancellation_policy' => ['nullable', 'in:flexible,moderate,strict'],
            'price_per_day' => ['required', 'integer', 'min:1000'],
            'description' => ['nullable', 'string'],
            'airport_delivery' => ['boolean'],
            'free_cancellation' => ['boolean'],
            'with_driver_available' => ['boolean'],
            'agency_id' => ['nullable', 'exists:agences,id'],
            'cover_url' => ['nullable', 'url'],
            'cover' => ['nullable', 'image', 'max:5120'], // max 5 Mo
        ]);

        // Vérifie que marque / modèle existent dans le référentiel (libelle ou slug).
        $marque = ReferenceList::query()
            ->where('type', 'marque')
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('label', $donnees['brand'])->orWhere('slug', $donnees['brand']))
            ->first();

        if (! $marque) {
            return response()->json(['message' => 'Marque inconnue. Ajoutez-la dans Référentiel.'], 422);
        }

        $modele = ReferenceList::query()
            ->where('type', 'modele')
            ->where('is_active', true)
            ->where('parent_slug', $marque->slug)
            ->where(fn ($q) => $q->where('label', $donnees['model'])->orWhere('slug', $donnees['model']))
            ->first();

        if (! $modele) {
            return response()->json(['message' => 'Modèle inconnu pour cette marque. Ajoutez-le dans Référentiel.'], 422);
        }

        // On stocke les libellés lisibles en base véhicule.
        $donnees['brand'] = $marque->label;
        $donnees['model'] = $modele->label;

        $necessiteValidation = in_array($partenaire->trust_level, ['new', 'verified'], true);

        $vehicule = Vehicle::create([
            ...collect($donnees)->except(['cover_url', 'cover'])->all(),
            'partner_id' => $partenaire->id,
            'status' => $necessiteValidation
                ? VehicleStatus::PendingValidation
                : VehicleStatus::Published,
        ]);

        // Priorité : fichier uploadé, sinon URL manuelle.
        $urlCouverture = null;
        if ($requete->hasFile('cover')) {
            $chemin = $requete->file('cover')->store('vehicules/'.$partenaire->id, 'public');
            $urlCouverture = Storage::disk('public')->url($chemin);
        } elseif (! empty($donnees['cover_url'])) {
            $urlCouverture = $donnees['cover_url'];
        }

        if ($urlCouverture) {
            $vehicule->media()->create([
                'url' => $urlCouverture,
                'sort_order' => 0,
                'is_cover' => true,
            ]);
        }

        $this->audit->log('vehicle.created', $vehicule, null, $vehicule->toArray());

        return response()->json(['data' => $vehicule->load('media')], 201);
    }

    /**
     * Liste paginée des véhicules du partenaire connecté uniquement.
     */
    public function vehicules(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaireDuCompte($requete);

        $vehicules = $partenaire->vehicles()->with('media')->latest()->paginate(20);

        return response()->json($vehicules);
    }

    /**
     * Liste paginée des réservations du partenaire connecté uniquement.
     */
    public function reservations(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaireDuCompte($requete);

        $reservations = $partenaire->bookings()
            ->with(['vehicle', 'customer'])
            ->latest()
            ->paginate(20);

        return response()->json($reservations);
    }

    /**
     * Liste le référentiel partagé marques/modèles (visible par tous les partenaires).
     */
    public function listerReferentiel(Request $requete): JsonResponse
    {
        $this->partenaireDuCompte($requete);

        $type = $requete->query('type');
        $parentSlug = $requete->query('parent_slug');

        $elements = ReferenceList::query()
            ->whereIn('type', self::TYPES_REFERENTIEL)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($parentSlug, fn ($q) => $q->where('parent_slug', $parentSlug))
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        return response()->json([
            'data' => $elements,
            'meta' => ['types' => self::TYPES_REFERENTIEL],
        ]);
    }

    /**
     * Ajoute une marque ou un modèle au référentiel global (partagé entre tous).
     */
    public function creerReferentiel(Request $requete): JsonResponse
    {
        $this->partenaireDuCompte($requete);

        $data = $requete->validate([
            'type' => ['required', Rule::in(self::TYPES_REFERENTIEL)],
            'label' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80'],
            'parent_slug' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        if ($data['type'] === 'modele' && empty($data['parent_slug'])) {
            return response()->json([
                'message' => 'Un modèle doit être rattaché à une marque (parent_slug).',
            ], 422);
        }

        if ($data['type'] === 'modele') {
            $marqueExiste = ReferenceList::query()
                ->where('type', 'marque')
                ->where('slug', $data['parent_slug'])
                ->exists();

            if (! $marqueExiste) {
                return response()->json(['message' => 'Marque parente introuvable.'], 422);
            }
        }

        $slug = $data['slug'] ?: \Illuminate\Support\Str::slug($data['label']);
        if ($slug === '') {
            $slug = \Illuminate\Support\Str::slug($data['type'].'-'.uniqid());
        }

        $existe = ReferenceList::query()
            ->where('type', $data['type'])
            ->where('slug', $slug)
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'Cet élément existe déjà dans le référentiel.'], 422);
        }

        $element = ReferenceList::create([
            'type' => $data['type'],
            'slug' => $slug,
            'label' => $data['label'],
            'parent_slug' => $data['type'] === 'modele' ? ($data['parent_slug'] ?? null) : null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        $this->audit->log('reference.created_by_partner', $element, null, $element->toArray());

        return response()->json(['data' => $element], 201);
    }
}
