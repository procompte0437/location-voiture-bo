<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerDocument;
use App\Models\VehicleDocument;
use App\Services\AuditService;
use App\Support\PiecesJustificatives as Pieces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Revue des pièces par le super admin : consulter, accepter, refuser avec motif.
 * {genre} vaut « partenaire » ou « vehicule ».
 */
class ControleurDocumentsAdmin extends Controller
{
    public function __construct(private AuditService $audit) {}

    /** Dossier complet d’un partenaire : ses pièces et celles de chaque véhicule. */
    public function dossier(int $id): JsonResponse
    {
        $partenaire = Partner::with(['owner:id,name,email,phone', 'documents', 'vehicles.documents'])->findOrFail($id);
        $requises = Pieces::requisesPour($partenaire);
        $deposees = $partenaire->documents->keyBy('type');

        return response()->json(['data' => [
            'id' => $partenaire->id,
            'nom' => $partenaire->company_name ?: $partenaire->manager_name,
            'statut' => $partenaire->status?->value ?? $partenaire->status,
            'motif' => $partenaire->status_reason,
            'responsable' => $partenaire->owner,
            'pieces' => collect(Pieces::PARTENAIRE)->map(fn ($p, $type) => [
                'type' => $type,
                'libelle' => $p['libelle'],
                'requise' => in_array($type, $requises, true),
                'document' => $this->presenter($deposees->get($type)),
            ])->values(),
            'vehicules' => $partenaire->vehicles->filter(fn ($v) => $v->documents->isNotEmpty())->map(fn ($v) => [
                'id' => $v->id,
                'libelle' => trim($v->brand.' '.$v->model).' · '.$v->plate_number,
                'pieces' => $v->documents->map(fn ($d) => [
                    'type' => $d->type,
                    'libelle' => Pieces::VEHICULE[$d->type]['libelle'] ?? $d->type,
                    'document' => $this->presenter($d),
                ])->values(),
            ])->values(),
        ]]);
    }

    public function telecharger(string $genre, int $id): StreamedResponse
    {
        $document = $this->document($genre, $id);
        $this->audit->log('document.viewed_by_admin', $document, null, null, null, ['genre' => $genre]);

        return Pieces::telecharger($document->file_path, $genre.'-'.$document->type);
    }

    public function accepter(Request $requete, string $genre, int $id): JsonResponse
    {
        $document = $this->document($genre, $id);
        $avant = $document->toArray();
        $document->update(['status' => 'accepted', 'rejection_reason' => null]);
        $this->audit->log('document.accepted', $document, $avant, $document->fresh()->toArray());

        return response()->json(['message' => 'Pièce acceptée.', 'data' => $this->presenter($document->fresh())]);
    }

    public function refuser(Request $requete, string $genre, int $id): JsonResponse
    {
        $donnees = $requete->validate(['motif' => ['required', 'string', 'min:3', 'max:1000']], [
            'motif.required' => 'Expliquez au partenaire ce qui ne va pas dans cette pièce.',
        ]);
        $document = $this->document($genre, $id);
        $avant = $document->toArray();
        $document->update(['status' => 'rejected', 'rejection_reason' => $donnees['motif']]);
        $this->audit->log('document.rejected', $document, $avant, $document->fresh()->toArray());
        $partenaire = $document instanceof PartnerDocument ? $document->partner : $document->vehicle?->partner;
        $piece = $document instanceof PartnerDocument
            ? (Pieces::PARTENAIRE[$document->type]['libelle'] ?? $document->type)
            : (Pieces::VEHICULE[$document->type]['libelle'] ?? $document->type).' — '.trim($document->vehicle?->brand.' '.$document->vehicle?->model).' ('.$document->vehicle?->plate_number.')';
        if ($partenaire) {
            app(\App\Services\Notificateur::class)->pieceRefusee($partenaire->loadMissing('owner'), $piece, $donnees['motif']);
        }

        return response()->json(['message' => 'Pièce refusée : le partenaire voit le motif.', 'data' => $this->presenter($document->fresh())]);
    }

    private function document(string $genre, int $id): PartnerDocument|VehicleDocument
    {
        return match ($genre) {
            'partenaire' => PartnerDocument::findOrFail($id),
            'vehicule' => VehicleDocument::findOrFail($id),
            default => abort(404),
        };
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
}
