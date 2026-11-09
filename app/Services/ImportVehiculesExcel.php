<?php

namespace App\Services;

use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Partner;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\ClasseurExcel;
use RuntimeException;

/**
 * Charge ou remplace la flotte depuis un classeur Excel.
 * La colonne Propriétaire rattache chaque ligne à l’entreprise déjà en base.
 */
class ImportVehiculesExcel
{
    /** @var array<string, string> */
    private const CATEGORIES = [
        'city_car' => 'city_car',
        'citadine' => 'city_car',
        'sedan' => 'sedan',
        'berline' => 'sedan',
        'suv' => 'suv',
        '4x4' => '4x4',
        'pickup' => 'pickup',
        'pick_up' => 'pickup',
        'minibus' => 'minibus',
        'utility' => 'utility',
        'utilitaire' => 'utility',
        'luxury' => 'luxury',
        'luxe' => 'luxury',
    ];

    /** @var array<string, string> */
    private const TRANSMISSIONS = [
        'manual' => 'manual',
        'manuelle' => 'manual',
        'manuel' => 'manual',
        'automatic' => 'automatic',
        'automatique' => 'automatic',
    ];

    /** @var array<string, string> */
    private const CARBURANTS = [
        'petrol' => 'petrol',
        'essence' => 'petrol',
        'diesel' => 'diesel',
        'hybrid' => 'hybrid',
        'hybride' => 'hybrid',
        'electric' => 'electric',
        'electrique' => 'electric',
    ];

    /** @var array<string, string> */
    private const RESERVATIONS = [
        'instant' => 'instant',
        'immediat' => 'instant',
        'instantane' => 'instant',
        'on_request' => 'on_request',
        'sur_demande' => 'on_request',
        'a_la_demande' => 'on_request',
    ];

    /** @var array<string, string> */
    private const ANNULATIONS = [
        'flexible' => 'flexible',
        'souple' => 'flexible',
        'moderate' => 'moderate',
        'moderee' => 'moderate',
        'strict' => 'strict',
        'stricte' => 'strict',
    ];

    /** @var array<string, string> */
    private const STATUTS = [
        'draft' => 'draft',
        'brouillon' => 'draft',
        'pending_validation' => 'pending_validation',
        'a_valider' => 'pending_validation',
        'en_attente' => 'pending_validation',
        'published' => 'published',
        'publie' => 'published',
        'suspended' => 'suspended',
        'suspendu' => 'suspended',
        'archived' => 'archived',
        'archive' => 'archived',
    ];

    public function __construct(private AuditService $audit) {}

    public function modele(): string
    {
        $entete = [
            'Propriétaire', 'Marque', 'Modèle', 'Année', 'Catégorie', 'Immatriculation', 'Couleur',
            'Places', 'Portes', 'Bagages', 'Transmission', 'Carburant', 'Climatisation',
            'Kilométrage inclus', 'Caution', 'Âge minimum', 'Années de permis',
            'Mode de réservation', 'Politique d’annulation', 'Prix par jour', 'Description',
            'Livraison aéroport', 'Annulation gratuite', 'Avec chauffeur', 'Statut', 'Agence', 'Photo',
        ];

        $lignes = [$entete];
        $vehicules = Vehicle::query()
            ->with(['partner', 'agency', 'categoryInfo', 'media'])
            ->orderBy('partner_id')
            ->orderBy('brand')
            ->orderBy('model')
            ->get();

        foreach ($vehicules as $vehicule) {
            $lignes[] = $this->ligneExport($vehicule);
        }

        return ClasseurExcel::ecrire([
            'Véhicules' => $lignes,
            'Notice' => [
                ['Colonne', 'Rôle'],
                ['Propriétaire', 'Nom de l’entreprise, ou à défaut le nom du responsable, tel qu’il est enregistré. Chaque ligne reste rattachée à ce propriétaire.'],
                ['Marque, Modèle, Immatriculation, Catégorie, Transmission, Carburant, Prix par jour', 'Obligatoires. Le prix est en FCFA, au moins 1 000.'],
                ['Immatriculation', 'Si elle existe déjà, la ligne remplace les informations du véhicule. Une immatriculation nouvelle crée le véhicule.'],
                ['Catégorie', 'Citadine, Berline, SUV, 4x4, Pick-up, Minibus, Utilitaire ou Luxe.'],
                ['Transmission', 'Manuelle ou Automatique.'],
                ['Carburant', 'Essence, Diesel, Hybride ou Électrique.'],
                ['Statut', 'Brouillon, À valider, Publié, Suspendu ou Archivé.'],
                ['Agence', 'Nom d’une agence de ce propriétaire. Vide si le véhicule n’est pas rattaché.'],
                ['Photo', 'Facultatif. Adresse complète (https://…) ou chemin d’une image du site (images/vehicules/…). Vide : la photo actuelle est conservée.'],
                ['', 'Le fichier contient déjà tous les véhicules en base. Modifiez une ligne pour la remplacer, ou ajoutez-en une pour créer un véhicule.'],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function ligneExport(Vehicle $vehicule): array
    {
        $partenaire = $vehicule->partner;
        $proprietaire = trim((string) ($partenaire?->company_name ?: $partenaire?->manager_name ?: ''));
        if ($proprietaire === '') {
            $proprietaire = $partenaire ? 'Partenaire #'.$partenaire->id : '';
        }

        $categorie = trim((string) ($vehicule->categoryInfo?->label ?: ''));
        if ($categorie === '') {
            $categorie = $this->libelle($vehicule->category, [
                'city_car' => 'Citadine',
                'sedan' => 'Berline',
                'suv' => 'SUV',
                '4x4' => '4x4',
                'pickup' => 'Pick-up',
                'minibus' => 'Minibus',
                'utility' => 'Utilitaire',
                'luxury' => 'Luxe',
            ]);
        }

        $statut = $vehicule->status instanceof VehicleStatus
            ? $vehicule->status->value
            : (string) $vehicule->status;

        return [
            $proprietaire,
            (string) $vehicule->brand,
            (string) $vehicule->model,
            $this->nombre($vehicule->year),
            $categorie,
            (string) $vehicule->plate_number,
            (string) ($vehicule->color ?? ''),
            $this->nombre($vehicule->seats),
            $this->nombre($vehicule->doors),
            $this->nombre($vehicule->luggage),
            $this->libelle($vehicule->transmission, ['manual' => 'Manuelle', 'automatic' => 'Automatique']),
            $this->libelle($vehicule->fuel, [
                'petrol' => 'Essence',
                'diesel' => 'Diesel',
                'hybrid' => 'Hybride',
                'electric' => 'Électrique',
            ]),
            $vehicule->air_conditioning ? 'oui' : 'non',
            $this->nombre($vehicule->included_km),
            $this->nombre($vehicule->deposit_amount),
            $this->nombre($vehicule->min_driver_age),
            $this->nombre($vehicule->min_license_years),
            $this->libelle($vehicule->booking_mode, ['instant' => 'Immédiat', 'on_request' => 'Sur demande']),
            $this->libelle($vehicule->cancellation_policy, [
                'flexible' => 'Souple',
                'moderate' => 'Modérée',
                'strict' => 'Stricte',
            ]),
            $this->nombre($vehicule->price_per_day),
            (string) ($vehicule->description ?? ''),
            $vehicule->airport_delivery ? 'oui' : 'non',
            $vehicule->free_cancellation ? 'oui' : 'non',
            $vehicule->with_driver_available ? 'oui' : 'non',
            $this->libelle($statut, [
                'draft' => 'Brouillon',
                'pending_validation' => 'À valider',
                'published' => 'Publié',
                'suspended' => 'Suspendu',
                'archived' => 'Archivé',
            ]),
            (string) ($vehicule->agency?->name ?? ''),
            (string) ($vehicule->media->firstWhere('is_cover', true)?->url ?? $vehicule->media->first()?->url ?? ''),
        ];
    }

    private function libelle(?string $valeur, array $libelles): string
    {
        $cle = (string) $valeur;

        return $libelles[$cle] ?? $cle;
    }

    private function nombre(mixed $valeur): string
    {
        if ($valeur === null || $valeur === '') {
            return '';
        }

        return (string) (int) $valeur;
    }

    /**
     * @return array{crees: int, mis_a_jour: int, partenaire: string, erreurs: list<array{ligne: int, message: string}>}
     */
    public function importer(string $chemin, ?int $partenaireId, ?int $acteurId): array
    {
        $lignes = ClasseurExcel::lireFeuille($chemin, ['marque', 'immatriculation']);
        if ($lignes === []) {
            throw new RuntimeException('Le fichier ne contient aucune ligne à importer.');
        }
        if (! isset($lignes[0]['marque'], $lignes[0]['immatriculation'])) {
            throw new RuntimeException('Le fichier doit contenir les colonnes Marque et Immatriculation. Téléchargez le modèle.');
        }
        if (count($lignes) > 2000) {
            throw new RuntimeException('Le fichier dépasse 2 000 véhicules. Découpez-le.');
        }

        $index = $this->indexPartenaires();
        $secours = $partenaireId ? Partner::query()->find($partenaireId) : null;
        $crees = 0;
        $misAJour = 0;
        $erreurs = [];
        $noms = [];

        foreach ($lignes as $ligne) {
            $numero = (int) ($ligne['__ligne'] ?? 0);
            try {
                $partenaire = $this->partenaireDeLaLigne($ligne, $index, $secours);
                $resultat = $this->enregistrer($ligne, $partenaire);
                $noms[$partenaire->id] = $partenaire->company_name ?: $partenaire->manager_name ?: 'Partenaire #'.$partenaire->id;
                if ($resultat === 'cree') {
                    $crees++;
                } else {
                    $misAJour++;
                }
            } catch (RuntimeException $exception) {
                $erreurs[] = ['ligne' => $numero, 'message' => $exception->getMessage()];
            } catch (\Throwable) {
                $erreurs[] = ['ligne' => $numero, 'message' => 'Cette ligne n’a pas pu être enregistrée.'];
            }
        }

        $this->audit->log('vehicules.importes', $secours, null, [
            'crees' => $crees,
            'mis_a_jour' => $misAJour,
            'erreurs' => count($erreurs),
        ], $acteurId);

        $resume = match (count($noms)) {
            0 => 'aucun propriétaire',
            1 => (string) reset($noms),
            default => count($noms).' propriétaires',
        };

        return [
            'crees' => $crees,
            'mis_a_jour' => $misAJour,
            'partenaire' => $resume,
            'erreurs' => $erreurs,
        ];
    }

    /**
     * Le nom d’entreprise (ou du responsable) du fichier désigne le propriétaire.
     * S’il est vide, on reprend le partenaire choisi dans l’écran d’import.
     *
     * @param  array<string, string>  $ligne
     * @param  array<string, Partner>  $index
     */
    private function partenaireDeLaLigne(array $ligne, array $index, ?Partner $secours): Partner
    {
        $indique = trim($ligne['proprietaire'] ?? $ligne['partenaire'] ?? '');
        if ($indique === '') {
            if ($secours) {
                return $secours;
            }

            throw new RuntimeException('Indiquez le propriétaire : le nom de l’entreprise tel qu’il figure dans le modèle.');
        }

        $partenaire = $index[$this->cle($indique)] ?? null;
        if (! $partenaire) {
            throw new RuntimeException('Propriétaire introuvable : « '.$indique.' ». Téléchargez le modèle pour reprendre le nom exact.');
        }

        return $partenaire;
    }

    /**
     * @return array<string, Partner>
     */
    private function indexPartenaires(): array
    {
        $index = [];
        foreach (Partner::query()->get() as $partenaire) {
            foreach ([$partenaire->company_name, $partenaire->manager_name] as $nom) {
                $cle = $this->cle((string) $nom);
                if ($cle !== '' && ! isset($index[$cle])) {
                    $index[$cle] = $partenaire;
                }
            }
        }

        return $index;
    }

    /**
     * @param  array<string, string>  $ligne
     */
    private function enregistrer(array $ligne, Partner $partenaire): string
    {
        $marque = trim($ligne['marque'] ?? '');
        $modele = trim($ligne['modele'] ?? '');
        $plaque = $this->plaque($ligne['immatriculation'] ?? '');
        if ($marque === '' || $modele === '' || $plaque === '') {
            throw new RuntimeException('La marque, le modèle et l’immatriculation sont obligatoires.');
        }

        $prix = $this->entier($ligne['prix_par_jour'] ?? '', null, 1000, 100000000, 'Le prix par jour');
        if ($prix === null) {
            throw new RuntimeException('Le prix par jour est obligatoire (au moins 1 000 FCFA).');
        }

        $photo = $this->photo($ligne['photo'] ?? '');

        $valeurs = [
            'partner_id' => $partenaire->id,
            'agency_id' => $this->agence($ligne['agence'] ?? '', $partenaire->id),
            'brand' => mb_substr($marque, 0, 80),
            'model' => mb_substr($modele, 0, 80),
            'year' => $this->entier($ligne['annee'] ?? '', null, 1990, 2100, 'L’année'),
            'category' => $this->categorie($ligne['categorie'] ?? ''),
            'plate_number' => $plaque,
            'color' => $this->texte($ligne['couleur'] ?? '', 40),
            'seats' => $this->entier($ligne['places'] ?? '', 5, 2, 50, 'Les places'),
            'doors' => $this->entier($ligne['portes'] ?? '', 4, 2, 6, 'Les portes'),
            'luggage' => $this->entier($ligne['bagages'] ?? '', 2, 0, 20, 'Les bagages'),
            'transmission' => $this->parmi($ligne['transmission'] ?? '', self::TRANSMISSIONS, 'manual', 'Transmission inconnue'),
            'fuel' => $this->parmi($ligne['carburant'] ?? '', self::CARBURANTS, 'petrol', 'Carburant inconnu'),
            'air_conditioning' => $this->oui($ligne['climatisation'] ?? '', true),
            'included_km' => $this->entier($ligne['kilometrage_inclus'] ?? '', 200, 0, 100000, 'Le kilométrage inclus'),
            'deposit_amount' => $this->entier($ligne['caution'] ?? '', 0, 0, 100000000, 'La caution'),
            'min_driver_age' => $this->entier($ligne['age_minimum'] ?? '', 21, 18, 80, 'L’âge minimum'),
            'min_license_years' => $this->entier($ligne['annees_de_permis'] ?? '', 1, 0, 20, 'Les années de permis'),
            'booking_mode' => $this->parmi($ligne['mode_de_reservation'] ?? '', self::RESERVATIONS, 'instant', 'Mode de réservation inconnu'),
            'cancellation_policy' => $this->parmi($ligne['politique_dannulation'] ?? '', self::ANNULATIONS, 'moderate', 'Politique d’annulation inconnue'),
            'price_per_day' => $prix,
            'description' => $this->texte($ligne['description'] ?? '', 5000),
            'airport_delivery' => $this->oui($ligne['livraison_aeroport'] ?? '', false),
            'free_cancellation' => $this->oui($ligne['annulation_gratuite'] ?? '', false),
            'with_driver_available' => $this->oui($ligne['avec_chauffeur'] ?? '', false),
            'status' => $this->parmi($ligne['statut'] ?? '', self::STATUTS, 'draft', 'Statut inconnu'),
        ];

        $existant = Vehicle::query()
            ->whereRaw('LOWER(plate_number) = ?', [mb_strtolower($plaque)])
            ->first();

        if ($existant && (int) $existant->partner_id !== (int) $partenaire->id) {
            throw new RuntimeException('L’immatriculation '.$plaque.' appartient déjà à un autre partenaire.');
        }

        if ($existant) {
            $existant->update($valeurs);
            $this->definirPhoto($existant, $photo);

            return 'mis_a_jour';
        }

        $this->definirPhoto(Vehicle::query()->create($valeurs), $photo);

        return 'cree';
    }

    /**
     * Le chemin d’une image du site devient une adresse complète :
     * le frontend n’est pas forcément servi par le même domaine que l’API.
     */
    private function photo(string $valeur): ?string
    {
        $photo = trim($valeur);
        if ($photo === '') {
            return null;
        }

        $estAdresse = preg_match('#^https?://#i', $photo) === 1 && filter_var($photo, FILTER_VALIDATE_URL) !== false;
        $estChemin = preg_match('#^/?[\w\-/]+(\.[\w\-]+)*\.(jpe?g|png|webp)$#i', $photo) === 1 && ! str_contains($photo, '..');
        if (! $estAdresse && ! $estChemin) {
            throw new RuntimeException('Photo invalide : indiquez une adresse https:// ou le chemin d’une image du site.');
        }

        $adresse = $estAdresse ? $photo : url(ltrim($photo, '/'));
        if (strlen($adresse) > 255) {
            throw new RuntimeException('L’adresse de la photo dépasse 255 caractères.');
        }

        return $adresse;
    }

    private function definirPhoto(Vehicle $vehicule, ?string $photo): void
    {
        if ($photo === null) {
            return;
        }

        $couverture = $vehicule->media()->where('is_cover', true)->first() ?? $vehicule->media()->first();
        if ($couverture) {
            $couverture->update(['url' => $photo, 'is_cover' => true]);

            return;
        }

        $vehicule->media()->create(['url' => $photo, 'sort_order' => 0, 'is_cover' => true]);
    }

    private function categorie(string $valeur): string
    {
        $cle = $this->cle($valeur);
        if ($cle === '') {
            throw new RuntimeException('La catégorie est obligatoire.');
        }
        if (isset(self::CATEGORIES[$cle])) {
            return self::CATEGORIES[$cle];
        }

        $categorie = VehicleCategory::query()
            ->where('slug', $cle)
            ->orWhereRaw('LOWER(label) = ?', [mb_strtolower(trim($valeur))])
            ->first();
        if ($categorie && isset(self::CATEGORIES[$categorie->slug])) {
            return self::CATEGORIES[$categorie->slug];
        }
        if ($categorie && in_array($categorie->slug, self::CATEGORIES, true)) {
            return $categorie->slug;
        }

        throw new RuntimeException('Catégorie inconnue : « '.trim($valeur).' ».');
    }

    private function agence(string $valeur, int $partenaireId): ?int
    {
        $nom = trim($valeur);
        if ($nom === '') {
            return null;
        }

        $agence = Agency::query()
            ->where('partner_id', $partenaireId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nom)])
            ->first();
        if (! $agence) {
            throw new RuntimeException('Agence introuvable pour ce partenaire : « '.$nom.' ».');
        }

        return $agence->id;
    }

    private function plaque(string $valeur): string
    {
        $texte = mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $valeur)));

        return mb_substr($texte, 0, 30);
    }

    /**
     * @param  array<string, string>  $valeurs
     */
    private function parmi(string $valeur, array $valeurs, string $defaut, string $message): string
    {
        $cle = $this->cle($valeur);
        if ($cle === '') {
            return $defaut;
        }
        if (! isset($valeurs[$cle])) {
            throw new RuntimeException($message.' : « '.trim($valeur).' ».');
        }

        return $valeurs[$cle];
    }

    private function entier(string $valeur, ?int $defaut, int $min, int $max, string $nom): ?int
    {
        $texte = trim($valeur);
        if ($texte === '') {
            return $defaut;
        }
        if (! preg_match('/^\d+$/', $texte)) {
            throw new RuntimeException($nom.' doit être un nombre entier.');
        }
        $nombre = (int) $texte;
        if ($nombre < $min || $nombre > $max) {
            throw new RuntimeException($nom.' doit être entre '.$min.' et '.$max.'.');
        }

        return $nombre;
    }

    private function oui(string $valeur, bool $defaut): bool
    {
        $cle = $this->cle($valeur);
        if ($cle === '') {
            return $defaut;
        }
        if (in_array($cle, ['1', 'oui', 'vrai', 'true', 'yes', 'o'], true)) {
            return true;
        }
        if (in_array($cle, ['0', 'non', 'faux', 'false', 'no', 'n'], true)) {
            return false;
        }

        return $defaut;
    }

    private function texte(string $valeur, int $max): ?string
    {
        $texte = trim($valeur);

        return $texte === '' ? null : mb_substr($texte, 0, $max);
    }

    private function cle(string $valeur): string
    {
        $texte = mb_strtolower(trim($valeur));
        $texte = strtr($texte, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
            "'" => '', '’' => '',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $texte), '_');
    }
}
