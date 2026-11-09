<?php

namespace Database\Seeders;

use App\Enums\PartnerStatus;
use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Location;
use App\Models\Partner;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ClasseurExcel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crée Bané Service Auto et sa flotte (67 véhicules de sa base).
 *
 * Sans danger en production : ce qui existe déjà n’est jamais écrasé, un tarif
 * corrigé dans l’application survit donc à une nouvelle exécution.
 *
 * Seules les immatriculations et les modèles viennent de l’entreprise. Tarifs,
 * cautions et caractéristiques sont des estimations à faire valider : les
 * réservations restent « sur demande » pour que chaque prix soit confirmé.
 */
class BaneServiceAutoSeeder extends Seeder
{
    public const ENTREPRISE = 'Bané Service Auto';

    public const AGENCE = 'Agence Louis - Libreville';

    private const EMAIL = 'baneservicesauto@gmail.com';

    private const TELEPHONE = '+24174555522';

    private const DOSSIER_PHOTOS = 'images/vehicules/bane/';

    /**
     * marque, modèle, catégorie, places, portes, bagages, transmission, carburant, prix/jour, photo
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: string, 7: string, 8: int, 9: string}>
     */
    private const MODELES = [
        'avante' => ['Hyundai', 'Avante', 'sedan', 5, 4, 2, 'automatic', 'petrol', 50000, 'hyundai-avante'],
        'genesis' => ['Hyundai', 'Genesis', 'sedan', 5, 4, 3, 'automatic', 'petrol', 70000, 'hyundai-genesis'],
        'i30' => ['Hyundai', 'i30', 'city_car', 5, 5, 2, 'automatic', 'petrol', 50000, 'hyundai-i30'],
        'i30_gdi' => ['Hyundai', 'i30 GDI', 'city_car', 5, 5, 2, 'automatic', 'petrol', 50000, 'hyundai-i30'],
        'sonata_cvvl' => ['Hyundai', 'Sonata CVVL', 'sedan', 5, 4, 3, 'automatic', 'petrol', 60000, 'hyundai-sonata'],
        'sonata_y20' => ['Hyundai', 'Sonata Y20', 'sedan', 5, 4, 3, 'automatic', 'petrol', 60000, 'hyundai-sonata'],
        'tucson' => ['Hyundai', 'Tucson', 'suv', 5, 5, 3, 'automatic', 'petrol', 65000, 'hyundai-tucson'],
        'jetour' => ['Jetour', 'SUV', 'suv', 5, 5, 3, 'automatic', 'petrol', 70000, 'jetour-x70'],
        'jetour_x70' => ['Jetour', 'X70', 'suv', 7, 5, 3, 'automatic', 'petrol', 70000, 'jetour-x70'],
        'k5' => ['Kia', 'K5', 'sedan', 5, 4, 3, 'automatic', 'petrol', 60000, 'kia-k5'],
        'k7' => ['Kia', 'K7', 'sedan', 5, 4, 3, 'automatic', 'petrol', 70000, 'kia-k7'],
        'sportage' => ['Kia', 'Sportage', 'suv', 5, 5, 3, 'automatic', 'petrol', 65000, 'kia-sportage'],
        'mg' => ['MG', 'Modèle à préciser', 'suv', 5, 5, 3, 'automatic', 'petrol', 60000, 'mg'],
        'l200' => ['Mitsubishi', 'L200', 'pickup', 5, 4, 2, 'manual', 'diesel', 80000, 'mitsubishi-l200'],
        'montero' => ['Mitsubishi', 'Montero', '4x4', 7, 5, 4, 'automatic', 'diesel', 90000, 'mitsubishi-montero'],
        'pajero' => ['Mitsubishi', 'Pajero', '4x4', 7, 5, 4, 'automatic', 'diesel', 90000, 'mitsubishi-pajero'],
        'hilux' => ['Toyota', 'Hilux', 'pickup', 5, 4, 2, 'manual', 'diesel', 80000, 'toyota-hilux'],
        'rav4' => ['Toyota', 'RAV4', 'suv', 5, 5, 3, 'automatic', 'petrol', 65000, 'toyota-rav4'],
    ];

    /**
     * Base véhicules de l’entreprise, dans l’ordre de son document.
     * Chaîne : le modèle. Tableau : le modèle et ce que la ligne précise en plus.
     *
     * @var array<string, string|array{0: string, year?: int, transmission?: string}>
     */
    private const FLOTTE = [
        'LY-927-AA' => 'avante',
        'KR-741-AA' => 'genesis',
        'KW-106-AA' => 'i30',
        'LN-768-AA' => 'i30',
        'MF-331-AA' => 'i30',
        'LW-201-AA' => 'i30',
        'MD-773-AA' => 'i30',
        'MD-771-AA' => 'i30',
        'MD-774-AA' => 'i30',
        'MC-492-AA' => 'i30',
        'MC-493-AA' => 'i30',
        'MJ-894-AA' => 'i30',
        'ML-988-AA' => 'i30',
        'LZ-560-AA' => 'i30',
        'MP-775-AA' => 'i30',
        'LT-576-AA' => 'i30',
        'LY-590-AA' => 'i30',
        'LY-371-AA' => 'i30',
        'MQ-283-AA' => 'i30',
        'MM-550-AA' => 'i30',
        'LV-511-AA' => 'i30',
        'MM-788-AA' => 'i30_gdi',
        'ME-754-AA' => 'i30',
        'MP-210-AA' => 'i30',
        'MH-939-AA' => 'i30',
        'LM-850-AA' => 'i30',
        'ME-253-AA' => 'i30',
        'MK-487-AA' => 'sonata_cvvl',
        'MA-655-AA' => 'sonata_y20',
        'LT-350-AA' => 'sonata_y20',
        'MF-003-AA' => 'tucson',
        'MG-568-AA' => 'tucson',
        'MF-782-AA' => 'tucson',
        'MK-674-AA' => 'tucson',
        'MM-998-AA' => 'jetour',
        'MP-883-AA' => 'jetour_x70',
        'LW-199-AA' => 'k5',
        'KZ-953-AA' => 'k5',
        'LY-918-AA' => 'k5',
        'LY-926-AA' => 'k5',
        'MP-967-AA' => 'k5',
        'MK-207-AA' => 'k7',
        'LR-950-AA' => 'k7',
        'LL-218-AA' => 'k7',
        'LW-200-AA' => 'k7',
        'LF-027-AA' => 'k7',
        'MK-753-AA' => 'k7',
        'MC-789-AA' => 'k7',
        'ML-910-AA' => ['k7', 'year' => 2018],
        'LZ-978-AA' => 'k7',
        'LP-790-AA' => 'sportage',
        'LT-796-AA' => 'sportage',
        'MQ-814-AA' => 'mg',
        'LF-976-AA' => 'l200',
        'MJ-876-AA' => 'montero',
        'LS-792-AA' => 'montero',
        'MC-871-AA' => 'l200',
        'LT-851-AA' => 'montero',
        'MA-131-AA' => 'montero',
        'LY-958-AA' => 'montero',
        'MJ-877-AA' => 'pajero',
        'MS-950-AA' => 'hilux',
        'HX-476-AA' => ['rav4', 'transmission' => 'manual'],
        'KW-058-AA' => 'rav4',
        'JS-051-AA' => 'rav4',
        'MA-890-AA' => 'rav4',
        'ME-876-AA' => 'jetour',
    ];

    /**
     * Lignes à confirmer avec l’entreprise : le document ne permet pas de trancher.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const A_VERIFIER = [
        ['MQ-814-AA', 'MG', 'Modèle absent du document. Véhicule laissé « À valider », non visible en ligne.'],
        ['MM-998-AA', 'JETOUR', 'Modèle absent : affiché « Jetour SUV ».'],
        ['ME-876-AA', 'VP JETOUR', 'Modèle absent et « VP » non expliqué : affiché « Jetour SUV ».'],
        ['ME-253-AA', 'HYUNDAI I30-CISSE', '« CISSE » retiré : affiché « Hyundai i30 ».'],
        ['MS-950-AA', 'TH PICK-UP', '« TH » compris comme Toyota Hilux.'],
        ['MA-890-AA', 'TOYOTA RAV4 5', 'Le « 5 » (génération ?) n’est pas repris : affiché « Toyota RAV4 ».'],
        ['LT-576-AA', 'HYUNDAI I30', 'Ligne à moitié masquée dans le document : plaque à relire.'],
        ['KW-058-AA', 'TOYOTA RAV4', 'Ligne à moitié masquée dans le document : plaque à relire.'],
    ];

    public function run(): void
    {
        $admin = User::where('email', 'admin@doto241.com')->first();

        $motDePasse = env('BANE_MOT_DE_PASSE') ?: Str::password(20);
        $proprietaire = User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => self::ENTREPRISE,
                'phone' => self::TELEPHONE,
                'password' => Hash::make($motDePasse),
                'role' => UserRole::Partner,
                'status' => 'active',
                'locale' => 'fr',
                'email_verified_at' => now(),
            ]
        );
        if ($proprietaire->wasRecentlyCreated && ! env('BANE_MOT_DE_PASSE')) {
            $this->command?->warn('Compte '.self::EMAIL.' créé. Mot de passe (affiché une seule fois) : '.$motDePasse);
        }

        $partenaire = Partner::firstOrCreate(
            ['owner_user_id' => $proprietaire->id],
            [
                'type' => 'agency',
                'company_name' => self::ENTREPRISE,
                'manager_name' => self::ENTREPRISE,
                'address' => 'En face de VIP, Louis',
                'city' => 'Libreville',
                'phone' => self::TELEPHONE,
                'website' => 'https://baneservicesauto.com',
                'status' => PartnerStatus::Approved,
                'trust_level' => 'verified',
                'commission_rate' => 0,
                'validated_by' => $admin?->id,
                'validated_at' => now(),
            ]
        );

        $agence = Agency::firstOrCreate(
            ['partner_id' => $partenaire->id, 'name' => self::AGENCE],
            [
                'location_id' => Location::where('slug', 'libreville-centre')->value('id'),
                'address' => 'En face de VIP, Louis',
                'city' => 'Libreville',
                'province_slug' => 'estuaire',
                'is_active' => true,
            ]
        );

        foreach (self::vehicules() as $vehicule) {
            $photo = $vehicule['photo'];
            unset($vehicule['photo']);

            $enregistre = Vehicle::firstOrCreate(
                ['plate_number' => $vehicule['plate_number']],
                [...$vehicule, 'partner_id' => $partenaire->id, 'agency_id' => $agence->id]
            );

            if ($enregistre->wasRecentlyCreated) {
                $enregistre->media()->create([
                    'url' => url($photo),
                    'sort_order' => 0,
                    'is_cover' => true,
                ]);
            }
        }
    }

    /**
     * Classeur prêt pour l’import Excel de l’administration.
     * Il faut que l’entreprise et son agence existent, sous ces noms exacts.
     */
    public static function classeurExcel(): string
    {
        $lignes = [[
            'Propriétaire', 'Marque', 'Modèle', 'Année', 'Catégorie', 'Immatriculation', 'Couleur',
            'Places', 'Portes', 'Bagages', 'Transmission', 'Carburant', 'Climatisation',
            'Kilométrage inclus', 'Caution', 'Âge minimum', 'Années de permis',
            'Mode de réservation', 'Politique d’annulation', 'Prix par jour', 'Description',
            'Livraison aéroport', 'Annulation gratuite', 'Avec chauffeur', 'Statut', 'Agence', 'Photo',
        ]];

        foreach (self::vehicules() as $v) {
            $lignes[] = [
                self::ENTREPRISE, $v['brand'], $v['model'], (string) ($v['year'] ?? ''), $v['category'],
                $v['plate_number'], '', (string) $v['seats'], (string) $v['doors'], (string) $v['luggage'],
                $v['transmission'], $v['fuel'], 'oui', (string) $v['included_km'], (string) $v['deposit_amount'],
                (string) $v['min_driver_age'], (string) $v['min_license_years'], $v['booking_mode'],
                $v['cancellation_policy'], (string) $v['price_per_day'], $v['description'], 'non', 'non', 'non',
                $v['status']->value, self::AGENCE, $v['photo'],
            ];
        }

        return ClasseurExcel::ecrire([
            'Véhicules' => $lignes,
            'À vérifier' => [
                ['Immatriculation', 'Dans le document', 'Point à confirmer'],
                ...self::A_VERIFIER,
                ['Toutes', '', 'Prix, caution, année, couleur, places, transmission et carburant sont des estimations, sauf le prix de départ de 50 000 FCFA repris du site de l’entreprise.'],
            ],
            'Notice' => [
                ['Étape', 'Détail'],
                ['1', 'L’entreprise « '.self::ENTREPRISE.' » et son agence « '.self::AGENCE.' » doivent exister, sous ces noms exacts. Le seeder les crée.'],
                ['2', 'Administration : importer ce fichier dans le chargement Excel de la base véhicules.'],
                ['3', 'Importer de nouveau après correction : une immatriculation déjà connue met à jour le véhicule.'],
                ['Photo', 'Le chemin images/vehicules/bane/… désigne une image livrée avec le site.'],
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function vehicules(): array
    {
        $vehicules = [];

        foreach (self::FLOTTE as $plaque => $ligne) {
            $details = is_array($ligne) ? $ligne : [$ligne];
            [$marque, $modele, $categorie, $places, $portes, $bagages, $transmission, $carburant, $prix, $photo] = self::MODELES[$details[0]];

            $vehicules[] = [
                'plate_number' => $plaque,
                'brand' => $marque,
                'model' => $modele,
                'year' => $details['year'] ?? null,
                'category' => $categorie,
                'seats' => $places,
                'doors' => $portes,
                'luggage' => $bagages,
                'transmission' => $details['transmission'] ?? $transmission,
                'fuel' => $carburant,
                'air_conditioning' => true,
                'included_km' => 200,
                'deposit_amount' => $prix * 5,
                'min_driver_age' => 21,
                'min_license_years' => 1,
                'booking_mode' => 'on_request',
                'cancellation_policy' => 'moderate',
                'fuel_policy' => 'full_to_full',
                'price_per_day' => $prix,
                'description' => "{$marque} ".($details[0] === 'mg' ? '' : "{$modele} ").'proposé par '.self::ENTREPRISE.' à Libreville.',
                'status' => $details[0] === 'mg' ? VehicleStatus::PendingValidation : VehicleStatus::Published,
                'photo' => self::DOSSIER_PHOTOS.$photo.'.jpg',
            ];
        }

        return $vehicules;
    }
}
