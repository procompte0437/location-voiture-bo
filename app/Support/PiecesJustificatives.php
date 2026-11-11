<?php

namespace App\Support;

use App\Models\Partner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pièces du dossier partenaire et des véhicules (cahier des charges F-PAR-01, F-PAR-03).
 * Les fichiers vont sur le disque privé : ils ne sont jamais servis par une adresse publique.
 */
class PiecesJustificatives
{
    /** @var array<string, array{libelle: string, pour: list<string>, expiration: bool}> */
    public const PARTENAIRE = [
        'piece_identite' => ['libelle' => 'Pièce d’identité du représentant (CNI ou passeport)', 'pour' => ['company', 'agency', 'individual'], 'expiration' => true],
        'rccm' => ['libelle' => 'RCCM', 'pour' => ['company', 'agency'], 'expiration' => true],
        'nif' => ['libelle' => 'NIF', 'pour' => ['company', 'agency'], 'expiration' => false],
        'justificatif_domicile' => ['libelle' => 'Justificatif de domicile ou de local', 'pour' => ['company', 'agency', 'individual'], 'expiration' => false],
        'coordonnees_paiement' => ['libelle' => 'RIB ou attestation Mobile Money (reversements)', 'pour' => ['company', 'agency', 'individual'], 'expiration' => false],
    ];

    /** @var array<string, array{libelle: string, expiration: bool}> */
    public const VEHICULE = [
        'carte_grise' => ['libelle' => 'Carte grise', 'expiration' => false],
        'assurance' => ['libelle' => 'Attestation d’assurance', 'expiration' => true],
        'visite_technique' => ['libelle' => 'Visite technique', 'expiration' => true],
    ];

    /** Une de ces pièces expirée retire le véhicule de la recherche. */
    public const VEHICULE_BLOQUANTES = ['assurance', 'visite_technique'];

    /** Délai d’alerte avant expiration. */
    public const JOURS_ALERTE = 30;

    public const REGLES_FICHIER = ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'];

    /**
     * @return list<string>
     */
    public static function requisesPour(Partner $partenaire): array
    {
        // Un type ajouté au référentiel est traité comme une entreprise : dossier complet.
        $type = $partenaire->type === 'individual' ? 'individual' : 'company';

        return array_keys(array_filter(self::PARTENAIRE, fn ($p) => in_array($type, $p['pour'], true)));
    }

    public static function ranger(UploadedFile $fichier, string $dossier): string
    {
        $extension = strtolower($fichier->guessExtension() ?: $fichier->getClientOriginalExtension() ?: 'bin');

        return $fichier->storeAs($dossier, Str::uuid()->toString().'.'.$extension, 'local');
    }

    public static function supprimer(?string $chemin): void
    {
        if ($chemin) {
            Storage::disk('local')->delete($chemin);
        }
    }

    public static function telecharger(string $chemin, string $nom): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($chemin), 404, 'Fichier introuvable sur le serveur.');
        $extension = pathinfo($chemin, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($chemin, Str::slug($nom).'.'.$extension);
    }

    /** État d’une échéance : à jour, bientôt expirée, expirée. */
    public static function echeance(?\DateTimeInterface $expiration): ?string
    {
        if (! $expiration) {
            return null;
        }
        $jour = \Illuminate\Support\Carbon::instance($expiration)->startOfDay();
        if ($jour->lt(today())) {
            return 'expiree';
        }

        return $jour->lte(today()->addDays(self::JOURS_ALERTE)) ? 'bientot' : 'a_jour';
    }
}
