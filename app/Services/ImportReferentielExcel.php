<?php

namespace App\Services;

use App\Models\Location;
use App\Models\ReferenceList;
use App\Models\VehicleCategory;
use App\Support\ClasseurExcel;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Charge le référentiel admin depuis un classeur Excel.
 * Un code déjà présent est mis à jour, pour pouvoir rejouer le même fichier.
 */
class ImportReferentielExcel
{
    /** @var array<string, string> Libellé normalisé ou code technique → liste interne. */
    private const LISTES = [
        'lieux' => 'lieux',
        'lieu' => 'lieux',
        'categories' => 'categories',
        'categorie' => 'categories',
        'categories_vehicules' => 'categories',
        'categorie_vehicule' => 'categories',
        'partner_type' => 'partner_type',
        'types_de_partenaires' => 'partner_type',
        'type_de_partenaire' => 'partner_type',
        'fuel' => 'fuel',
        'carburants' => 'fuel',
        'carburant' => 'fuel',
        'transmission' => 'transmission',
        'transmissions' => 'transmission',
        'marque' => 'marque',
        'marques' => 'marque',
        'modele' => 'modele',
        'modeles' => 'modele',
        'location_type' => 'location_type',
        'types_de_lieux' => 'location_type',
        'type_de_lieu' => 'location_type',
        'driver_age' => 'driver_age',
        'ages_conducteur' => 'driver_age',
        'age_conducteur' => 'driver_age',
        'booking_mode' => 'booking_mode',
        'modes_de_reservation' => 'booking_mode',
        'mode_de_reservation' => 'booking_mode',
        'cancellation_policy' => 'cancellation_policy',
        'politiques_dannulation' => 'cancellation_policy',
        'politique_dannulation' => 'cancellation_policy',
        'payment_method' => 'payment_method',
        'moyens_de_paiement' => 'payment_method',
        'moyen_de_paiement' => 'payment_method',
        'vehicle_sort' => 'vehicle_sort',
        'tris_catalogue' => 'vehicle_sort',
        'tri_catalogue' => 'vehicle_sort',
        'contact_subject' => 'contact_subject',
        'objets_de_message' => 'contact_subject',
        'objet_de_message' => 'contact_subject',
    ];

    public function __construct(private AuditService $audit) {}

    public function modele(): string
    {
        $entete = ['Liste', 'Libellé', 'Code', 'Ordre', 'Actif', 'Parent', 'Ville', 'Type de lieu', 'Description', 'Accueil', 'Populaire'];

        return ClasseurExcel::ecrire([
            'Référentiel' => [
                $entete,
                ['Objets de message', 'Une réservation', 'reservation', '10', 'oui', '', '', '', '', '', ''],
                ['Marques', 'Toyota', 'toyota', '10', 'oui', '', '', '', '', '', ''],
                ['Modèles', 'Yaris', 'yaris', '10', 'oui', 'toyota', '', '', '', '', ''],
                ['Types de lieux', 'Ville', 'city', '10', 'oui', '', '', '', '', '', ''],
                ['Lieux', 'Libreville Centre', 'libreville-centre', '10', 'oui', '', 'Libreville', 'Ville', '', '', 'oui'],
                ['Catégories véhicules', 'Citadine', 'citadine', '10', 'oui', '', '', '', 'Petite voiture de ville', 'oui', ''],
                ['Carburants', 'Essence', 'petrol', '10', 'oui', '', '', '', '', '', ''],
            ],
            'Notice' => [
                ['Colonne', 'Rôle'],
                ['Liste', 'Nom de l’onglet du référentiel (Lieux, Marques, Objets de message, …) ou son code technique.'],
                ['Libellé', 'Texte affiché dans les menus. Obligatoire.'],
                ['Code', 'Identifiant stable. S’il existe déjà, la ligne est mise à jour au lieu d’être dupliquée.'],
                ['Ordre', 'Position dans la liste. Vide = ordre du fichier.'],
                ['Actif', 'oui ou non. Vide = oui.'],
                ['Parent', 'Pour un modèle : code ou libellé de la marque.'],
                ['Ville', 'Pour un lieu : ville liée.'],
                ['Type de lieu', 'Pour un lieu : Ville, Aéroport, Quartier ou Point agence.'],
                ['Description', 'Pour une catégorie de véhicule.'],
                ['Accueil', 'Pour une catégorie : oui si elle apparaît sur l’accueil.'],
                ['Populaire', 'Pour un lieu : oui s’il est mis en avant.'],
                ['', 'Supprimez les lignes d’exemple de la feuille Référentiel avant un import réel, ou gardez-les : elles seront seulement mises à jour.'],
            ],
        ]);
    }

    /**
     * @return array{crees: int, mis_a_jour: int, erreurs: list<array{ligne: int, message: string}>}
     */
    public function importer(string $chemin): array
    {
        $lignes = ClasseurExcel::lireTableau($chemin);
        if ($lignes === []) {
            throw new RuntimeException('Le fichier ne contient aucune ligne à importer.');
        }
        if (count($lignes) > 2000) {
            throw new RuntimeException('Le fichier dépasse 2 000 lignes. Découpez-le.');
        }

        usort($lignes, function (array $gauche, array $droite): int {
            return $this->priorite($this->liste($gauche['liste'] ?? '')) <=> $this->priorite($this->liste($droite['liste'] ?? ''));
        });

        $crees = 0;
        $misAJour = 0;
        $erreurs = [];

        foreach ($lignes as $ligne) {
            $numero = (int) ($ligne['__ligne'] ?? 0);
            try {
                $resultat = $this->enregistrerLigne($ligne);
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

        $this->audit->log('referentiel.importe', null, null, [
            'crees' => $crees,
            'mis_a_jour' => $misAJour,
            'erreurs' => count($erreurs),
        ], null, ['entity_type' => 'referentiel']);

        return [
            'crees' => $crees,
            'mis_a_jour' => $misAJour,
            'erreurs' => $erreurs,
        ];
    }

    /**
     * @param  array<string, string>  $ligne
     */
    private function enregistrerLigne(array $ligne): string
    {
        $liste = $this->liste($ligne['liste'] ?? '');
        if ($liste === null) {
            throw new RuntimeException('Liste inconnue : « '.trim($ligne['liste'] ?? '').' ».');
        }

        $libelle = trim($ligne['libelle'] ?? '');
        if ($libelle === '') {
            throw new RuntimeException('Le libellé est obligatoire.');
        }
        if (mb_strlen($libelle) > ($liste === 'lieux' ? 190 : 120)) {
            throw new RuntimeException('Le libellé est trop long.');
        }

        $code = trim($ligne['code'] ?? '');
        $ordreBrut = trim($ligne['ordre'] ?? '');
        $ordre = $ordreBrut === '' ? max(0, ((int) ($ligne['__ligne'] ?? 1)) * 10) : max(0, (int) $ordreBrut);
        $actif = $this->oui($ligne['actif'] ?? '', true);

        if ($liste === 'lieux') {
            return $this->enregistrerLieu($libelle, $code, $ligne, $actif);
        }
        if ($liste === 'categories') {
            return $this->enregistrerCategorie($libelle, $code, $ordre, $ligne, $actif);
        }

        $slug = mb_substr($code !== '' ? Str::slug($code) : Str::slug($libelle), 0, 80);
        if ($slug === '') {
            $slug = Str::slug($liste.'-'.substr(md5($libelle), 0, 8));
        }

        $parent = null;
        if ($liste === 'modele') {
            $parent = $this->marque($ligne['parent'] ?? '');
        }

        $existant = ReferenceList::query()->where('type', $liste)->where('slug', $slug)->first();
        $valeurs = [
            'type' => $liste,
            'slug' => $slug,
            'label' => $libelle,
            'parent_slug' => $parent,
            'sort_order' => min($ordre, 9999),
            'is_active' => $actif,
        ];

        if ($existant) {
            $existant->update($valeurs);

            return 'mis_a_jour';
        }

        ReferenceList::query()->create($valeurs);

        return 'cree';
    }

    /**
     * @param  array<string, string>  $ligne
     */
    private function enregistrerLieu(string $nom, string $code, array $ligne, bool $actif): string
    {
        $slug = mb_substr($code !== '' ? Str::slug($code) : Str::slug($nom), 0, 190);
        if ($slug === '') {
            throw new RuntimeException('Impossible de calculer le code du lieu.');
        }

        $type = $this->typeLieu($ligne['type_de_lieu'] ?? '');
        $valeurs = [
            'name' => $nom,
            'slug' => $slug,
            'type' => $type,
            'city' => trim($ligne['ville'] ?? '') ?: null,
            'is_popular' => $this->oui($ligne['populaire'] ?? '', false),
            'is_active' => $actif,
        ];

        $existant = Location::query()->where('slug', $slug)->first();
        if ($existant) {
            $existant->update($valeurs);

            return 'mis_a_jour';
        }

        Location::query()->create($valeurs);

        return 'cree';
    }

    /**
     * @param  array<string, string>  $ligne
     */
    private function enregistrerCategorie(string $libelle, string $code, int $ordre, array $ligne, bool $actif): string
    {
        $slug = $code !== '' ? Str::slug($code) : Str::slug($libelle);
        if ($slug === '') {
            throw new RuntimeException('Impossible de calculer le code de la catégorie.');
        }

        $valeurs = [
            'slug' => $slug,
            'label' => $libelle,
            'description' => ($description = trim($ligne['description'] ?? '')) === '' ? null : mb_substr($description, 0, 500),
            'sort_order' => min($ordre, 9999),
            'is_active' => $actif,
            'show_on_home' => $this->oui($ligne['accueil'] ?? '', false),
        ];

        $existante = VehicleCategory::query()->where('slug', $slug)->first();
        if ($existante) {
            $existante->update($valeurs);

            return 'mis_a_jour';
        }

        VehicleCategory::query()->create($valeurs);

        return 'cree';
    }

    private function liste(string $valeur): ?string
    {
        $cle = $this->cle($valeur);
        if ($cle === '') {
            return null;
        }

        return self::LISTES[$cle] ?? null;
    }

    private function priorite(?string $liste): int
    {
        return match ($liste) {
            'marque', 'location_type' => 0,
            'modele', 'lieux' => 1,
            default => 2,
        };
    }

    private function marque(string $valeur): string
    {
        $texte = trim($valeur);
        if ($texte === '') {
            throw new RuntimeException('La colonne Parent est obligatoire pour un modèle (code ou libellé de la marque).');
        }

        $slug = Str::slug($texte);
        $marque = ReferenceList::query()
            ->where('type', 'marque')
            ->where(function ($requete) use ($slug, $texte) {
                $requete->where('slug', $slug)->orWhereRaw('LOWER(label) = ?', [mb_strtolower($texte)]);
            })
            ->first();

        if (! $marque) {
            throw new RuntimeException('Marque introuvable : « '.$texte.' ». Chargez la marque avant le modèle.');
        }

        return $marque->slug;
    }

    private function typeLieu(string $valeur): string
    {
        $texte = trim($valeur);
        if ($texte === '') {
            return 'city';
        }

        $connu = [
            'ville' => 'city',
            'city' => 'city',
            'aeroport' => 'airport',
            'airport' => 'airport',
            'quartier' => 'district',
            'district' => 'district',
            'point_agence' => 'agency_point',
            'point_dagence' => 'agency_point',
            'agency_point' => 'agency_point',
        ];
        $cle = $this->cle($texte);
        if (isset($connu[$cle])) {
            return $connu[$cle];
        }

        $slug = Str::slug($texte);
        $type = ReferenceList::query()
            ->where('type', 'location_type')
            ->where(function ($requete) use ($slug, $texte) {
                $requete->where('slug', $slug)->orWhereRaw('LOWER(label) = ?', [mb_strtolower($texte)]);
            })
            ->first();

        if (! $type) {
            throw new RuntimeException('Type de lieu inconnu : « '.$texte.' ».');
        }

        return $type->slug;
    }

    private function oui(string $valeur, bool $defaut): bool
    {
        $texte = trim($valeur);
        if ($texte === '') {
            return $defaut;
        }
        $cle = $this->cle($texte);
        if (in_array($cle, ['1', 'oui', 'vrai', 'true', 'yes', 'actif', 'o'], true)) {
            return true;
        }
        if (in_array($cle, ['0', 'non', 'faux', 'false', 'no', 'inactif', 'n'], true)) {
            return false;
        }

        return $defaut;
    }

    private function cle(string $valeur): string
    {
        $texte = mb_strtolower($valeur);
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
