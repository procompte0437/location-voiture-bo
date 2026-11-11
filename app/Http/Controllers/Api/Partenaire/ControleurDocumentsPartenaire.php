<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Enums\PartnerStatus;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerDocument;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\AuditService;
use App\Support\PiecesJustificatives as Pieces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dossier du loueur : ses pièces et celles de ses véhicules.
 * Un nouveau dépôt remplace la pièce du même type et repart en vérification.
 */
class ControleurDocumentsPartenaire extends Controller
{
    use ResoutPartenaire;

    public function __construct(private AuditService $audit) {}

    public function index(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent', 'finance_manager']);
        $deposees = $partenaire->documents()->latest()->get()->keyBy('type');
        $requises = Pieces::requisesPour($partenaire);

        $pieces = collect(Pieces::PARTENAIRE)->map(fn ($p, $type) => [
            'type' => $type,
            'libelle' => $p['libelle'],
            'requise' => in_array($type, $requises, true),
            'avec_expiration' => $p['expiration'],
            'document' => $this->presenter($deposees->get($type)),
        ])->values();

        $vehicules = $partenaire->vehicles()->with('documents')->orderBy('brand')->orderBy('model')->get()
            ->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'libelle' => trim($v->brand.' '.$v->model),
                'plate_number' => $v->plate_number,
                'masque' => $v->documents->contains(fn ($d) => in_array($d->type, Pieces::VEHICULE_BLOQUANTES, true)
                    && Pieces::echeance($d->expires_at) === 'expiree'),
                'pieces' => collect(Pieces::VEHICULE)->map(fn ($p, $type) => [
                    'type' => $type,
                    'libelle' => $p['libelle'],
                    'avec_expiration' => $p['expiration'],
                    'document' => $this->presenter($v->documents->where('type', $type)->sortByDesc('id')->first()),
                ])->values(),
            ]);

        return response()->json(['data' => [
            'statut_dossier' => $partenaire->status instanceof PartnerStatus ? $partenaire->status->value : $partenaire->status,
            'motif_dossier' => $partenaire->status_reason,
            'pieces' => $pieces,
            'complet' => $pieces->where('requise', true)->every(fn ($p) => $p['document'] && $p['document']['status'] !== 'rejected'),
            'vehicules' => $vehicules,
            'alertes' => $this->alertes($pieces->all(), $vehicules->all()),
        ]]);
    }

    public function deposer(Request $requete): JsonResponse
    {
        $partenaire = $this->partenaire($requete, []);
        $donnees = $requete->validate([
            'type' => ['required', Rule::in(array_keys(Pieces::PARTENAIRE))],
            'fichier' => Pieces::REGLES_FICHIER,
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], $this->messages());

        $document = DB::transaction(function () use ($partenaire, $donnees, $requete) {
            $ancien = $partenaire->documents()->where('type', $donnees['type'])->latest()->first();
            $chemin = Pieces::ranger($requete->file('fichier'), 'documents/partenaires/'.$partenaire->id);
            $valeurs = [
                'file_path' => $chemin,
                'nom_original' => mb_substr($requete->file('fichier')->getClientOriginalName(), 0, 190),
                'status' => 'pending',
                'rejection_reason' => null,
                'expires_at' => $donnees['expires_at'] ?? null,
            ];

            if ($ancien) {
                Pieces::supprimer($ancien->file_path);
                $ancien->update($valeurs);
            }
            $document = $ancien ?? PartnerDocument::create([...$valeurs, 'partner_id' => $partenaire->id, 'type' => $donnees['type']]);

            // « Complément demandé » : le dossier corrigé repart dans la file de validation.
            if ($partenaire->status === PartnerStatus::InfoRequested) {
                $partenaire->update(['status' => PartnerStatus::Pending]);
            }

            return $document;
        });
        $this->audit->log('partner.document_uploaded', $partenaire, null, $document->toArray());

        return response()->json(['message' => 'Pièce envoyée : elle sera vérifiée par l’équipe DOTO.', 'data' => $this->presenter($document)], 201);
    }

    public function telecharger(Request $requete, int $id): StreamedResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent', 'finance_manager']);
        $document = $partenaire->documents()->findOrFail($id);

        return Pieces::telecharger($document->file_path, $document->type);
    }

    public function deposerVehicule(Request $requete, int $id): JsonResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent']);
        $vehicule = $partenaire->vehicles()->findOrFail($id);
        $avecExpiration = array_keys(array_filter(Pieces::VEHICULE, fn ($p) => $p['expiration']));
        $donnees = $requete->validate([
            'type' => ['required', Rule::in(array_keys(Pieces::VEHICULE))],
            'fichier' => Pieces::REGLES_FICHIER,
            'expires_at' => ['nullable', 'date', 'after:today', Rule::requiredIf(in_array($requete->input('type'), $avecExpiration, true))],
        ], $this->messages());

        $document = DB::transaction(function () use ($vehicule, $donnees, $requete, $partenaire) {
            $ancien = $vehicule->documents()->where('type', $donnees['type'])->latest()->first();
            $chemin = Pieces::ranger($requete->file('fichier'), 'documents/vehicules/'.$partenaire->id.'/'.$vehicule->id);
            $valeurs = [
                'file_path' => $chemin,
                'nom_original' => mb_substr($requete->file('fichier')->getClientOriginalName(), 0, 190),
                'status' => 'pending',
                'rejection_reason' => null,
                'expires_at' => $donnees['expires_at'] ?? null,
            ];
            if ($ancien) {
                Pieces::supprimer($ancien->file_path);
                $ancien->update($valeurs);

                return $ancien;
            }

            return VehicleDocument::create([...$valeurs, 'vehicle_id' => $vehicule->id, 'type' => $donnees['type']]);
        });
        $this->audit->log('vehicle.document_uploaded', $vehicule, null, $document->toArray());

        return response()->json(['message' => 'Pièce du véhicule envoyée.', 'data' => $this->presenter($document)], 201);
    }

    public function telechargerVehicule(Request $requete, int $id): StreamedResponse
    {
        $partenaire = $this->partenaire($requete, ['partner_agent', 'finance_manager']);
        $document = VehicleDocument::query()
            ->whereHas('vehicle', fn ($q) => $q->where('partner_id', $partenaire->id))
            ->findOrFail($id);

        return Pieces::telecharger($document->file_path, $document->type);
    }

    private function presenter(PartnerDocument|VehicleDocument|null $document): ?array
    {
        if (! $document) {
            return null;
        }

        return [
            'id' => $document->id,
            'status' => $document->status,
            'rejection_reason' => $document->rejection_reason,
            'expires_at' => $document->expires_at?->format('Y-m-d'),
            'echeance' => Pieces::echeance($document->expires_at),
            'nom_original' => $document->nom_original,
            'depose_le' => $document->updated_at?->format('Y-m-d'),
        ];
    }

    /**
     * @return list<string>
     */
    private function alertes(array $pieces, array $vehicules): array
    {
        $alertes = [];
        foreach ($pieces as $p) {
            $d = $p['document'];
            if ($p['requise'] && ! $d) {
                $alertes[] = 'À déposer : '.$p['libelle'].'.';
            } elseif ($d && $d['status'] === 'rejected') {
                $alertes[] = 'Refusée : '.$p['libelle'].($d['rejection_reason'] ? ' — '.$d['rejection_reason'] : '').'.';
            } elseif ($d && $d['echeance'] === 'expiree') {
                $alertes[] = 'Expirée : '.$p['libelle'].'.';
            } elseif ($d && $d['echeance'] === 'bientot') {
                $alertes[] = $p['libelle'].' expire le '.$d['expires_at'].'.';
            }
        }
        foreach ($vehicules as $v) {
            foreach ($v['pieces'] as $p) {
                $d = $p['document'];
                $nom = $v['libelle'].' ('.$v['plate_number'].')';
                if ($d && $d['echeance'] === 'expiree' && in_array($p['type'], Pieces::VEHICULE_BLOQUANTES, true)) {
                    $alertes[] = $nom.' : '.mb_strtolower($p['libelle']).' expirée, le véhicule est retiré de la recherche.';
                } elseif ($d && $d['echeance'] === 'bientot') {
                    $alertes[] = $nom.' : '.mb_strtolower($p['libelle']).' expire le '.$d['expires_at'].'.';
                } elseif ($d && $d['status'] === 'rejected') {
                    $alertes[] = $nom.' : '.mb_strtolower($p['libelle']).' refusée'.($d['rejection_reason'] ? ' — '.$d['rejection_reason'] : '').'.';
                }
            }
        }

        return $alertes;
    }

    private function messages(): array
    {
        return [
            'fichier.required' => 'Choisissez le fichier à envoyer.',
            'fichier.max' => 'Le fichier dépasse 5 Mo.',
            'fichier.mimes' => 'Envoyez un PDF ou une photo (JPG, PNG, WebP).',
            'expires_at.after' => 'La date d’expiration doit être dans le futur.',
            'expires_at.required' => 'Indiquez la date d’expiration de cette pièce.',
        ];
    }
}
