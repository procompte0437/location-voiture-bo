<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\ReassuranceArgument;
use App\Models\ReferenceList;
use App\Models\SiteContent;
use Illuminate\Database\Seeder;

class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        $contents = [
            ['key' => 'home.hero_title', 'group' => 'home', 'value' => 'LocaGabon'],
            ['key' => 'home.hero_eyebrow', 'group' => 'home', 'value' => 'Marketplace de location au Gabon'],
            ['key' => 'home.hero_subtitle', 'group' => 'home', 'value' => 'Comparez, réservez et payez votre véhicule en moins de 3 minutes — partenaires locaux vérifiés.'],
            ['key' => 'home.hero_promises', 'group' => 'home', 'value' => 'Véhicules récents · Assurance incluse · Livraison aéroport Léon-Mba / hôtel / domicile'],
            ['key' => 'home.hero_image_url', 'group' => 'home', 'value' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=1800&q=80'],
            ['key' => 'home.destinations_title', 'group' => 'home', 'value' => 'Agence de location de voiture au Gabon'],
            ['key' => 'home.destinations_subtitle', 'group' => 'home', 'value' => 'Destinations populaires pour démarrer votre recherche.'],
            ['key' => 'home.categories_title', 'group' => 'home', 'value' => 'Catégories'],
            ['key' => 'home.categories_subtitle', 'group' => 'home', 'value' => 'De la citadine au 4x4 pour l’intérieur du pays.'],
            ['key' => 'home.featured_title', 'group' => 'home', 'value' => 'Véhicules vedettes'],
            ['key' => 'home.featured_subtitle', 'group' => 'home', 'value' => 'Offres de partenaires validés par notre équipe.'],
        ];

        foreach ($contents as $row) {
            SiteContent::updateOrCreate(['key' => $row['key']], $row);
        }

        ReassuranceArgument::query()->delete();
        foreach ([
            ['title' => 'Prix final garanti', 'text' => 'Aucun frais caché à la prise en charge', 'sort_order' => 1],
            ['title' => 'Assurance incluse', 'text' => 'Véhicules couverts pendant la location', 'sort_order' => 2],
            ['title' => 'Mobile Money', 'text' => 'Airtel Money & Moov Money acceptés', 'sort_order' => 3],
            ['title' => 'Support WhatsApp', 'text' => 'Assistance locale réactive', 'sort_order' => 4],
        ] as $item) {
            ReassuranceArgument::create([...$item, 'is_active' => true]);
        }

        Faq::query()->delete();
        foreach ([
            [
                'question' => 'Comment réserver ?',
                'answer' => 'Choisissez un lieu, des dates et l’âge du conducteur, comparez les offres, puis payez en Mobile Money ou carte.',
                'sort_order' => 1,
            ],
            [
                'question' => 'Qui peut devenir partenaire ?',
                'answer' => 'Toute agence, entreprise ou particulier. Le dossier est étudié par le super admin avant publication.',
                'sort_order' => 2,
            ],
            [
                'question' => 'Quels moyens de paiement ?',
                'answer' => 'Airtel Money, Moov Money, carte bancaire, et paiement à l’agence avec acompte si le partenaire l’autorise.',
                'sort_order' => 3,
            ],
        ] as $item) {
            Faq::create([...$item, 'is_active' => true]);
        }

        foreach ([
            ['type' => 'fuel', 'slug' => 'petrol', 'label' => 'Essence', 'sort_order' => 1],
            ['type' => 'fuel', 'slug' => 'diesel', 'label' => 'Diesel', 'sort_order' => 2],
            ['type' => 'fuel', 'slug' => 'hybrid', 'label' => 'Hybride', 'sort_order' => 3],
            ['type' => 'fuel', 'slug' => 'electric', 'label' => 'Électrique', 'sort_order' => 4],
            ['type' => 'transmission', 'slug' => 'manual', 'label' => 'Manuelle', 'sort_order' => 1],
            ['type' => 'transmission', 'slug' => 'automatic', 'label' => 'Automatique', 'sort_order' => 2],
            ['type' => 'partner_type', 'slug' => 'agency', 'label' => 'Agence', 'sort_order' => 1],
            ['type' => 'partner_type', 'slug' => 'company', 'label' => 'Entreprise', 'sort_order' => 2],
            ['type' => 'partner_type', 'slug' => 'individual', 'label' => 'Particulier', 'sort_order' => 3],
            // Types de lieux (formulaire admin lieux)
            ['type' => 'location_type', 'slug' => 'city', 'label' => 'Ville', 'sort_order' => 1],
            ['type' => 'location_type', 'slug' => 'airport', 'label' => 'Aéroport', 'sort_order' => 2],
            ['type' => 'location_type', 'slug' => 'district', 'label' => 'Quartier', 'sort_order' => 3],
            ['type' => 'location_type', 'slug' => 'agency_point', 'label' => 'Point agence', 'sort_order' => 4],
            // Âges conducteur (slug = valeur numérique envoyée à la recherche)
            ['type' => 'driver_age', 'slug' => '21', 'label' => '18-24 ans', 'sort_order' => 1],
            ['type' => 'driver_age', 'slug' => '25', 'label' => '25-69 ans', 'sort_order' => 2],
            ['type' => 'driver_age', 'slug' => '70', 'label' => '70 ans et plus', 'sort_order' => 3],
            // Modes réservation / annulation / paiement
            ['type' => 'booking_mode', 'slug' => 'instant', 'label' => 'Instantanée', 'sort_order' => 1],
            ['type' => 'booking_mode', 'slug' => 'on_request', 'label' => 'Sur demande', 'sort_order' => 2],
            ['type' => 'cancellation_policy', 'slug' => 'flexible', 'label' => 'Flexible', 'sort_order' => 1],
            ['type' => 'cancellation_policy', 'slug' => 'moderate', 'label' => 'Modérée', 'sort_order' => 2],
            ['type' => 'cancellation_policy', 'slug' => 'strict', 'label' => 'Stricte', 'sort_order' => 3],
            ['type' => 'payment_method', 'slug' => 'airtel_money', 'label' => 'Airtel Money', 'sort_order' => 1],
            ['type' => 'payment_method', 'slug' => 'moov_money', 'label' => 'Moov Money', 'sort_order' => 2],
            ['type' => 'payment_method', 'slug' => 'card', 'label' => 'Carte bancaire', 'sort_order' => 3],
            ['type' => 'payment_method', 'slug' => 'agency_cash', 'label' => 'Acompte + paiement agence', 'sort_order' => 4],
            // Marques
            ['type' => 'marque', 'slug' => 'toyota', 'label' => 'Toyota', 'sort_order' => 10],
            ['type' => 'marque', 'slug' => 'hyundai', 'label' => 'Hyundai', 'sort_order' => 20],
            ['type' => 'marque', 'slug' => 'kia', 'label' => 'Kia', 'sort_order' => 30],
            ['type' => 'marque', 'slug' => 'peugeot', 'label' => 'Peugeot', 'sort_order' => 40],
            ['type' => 'marque', 'slug' => 'renault', 'label' => 'Renault', 'sort_order' => 50],
            ['type' => 'marque', 'slug' => 'mercedes', 'label' => 'Mercedes-Benz', 'sort_order' => 60],
            // Modèles (liés via parent_slug)
            ['type' => 'modele', 'slug' => 'corolla', 'label' => 'Corolla', 'parent_slug' => 'toyota', 'sort_order' => 1],
            ['type' => 'modele', 'slug' => 'rav4', 'label' => 'RAV4', 'parent_slug' => 'toyota', 'sort_order' => 2],
            ['type' => 'modele', 'slug' => 'hilux', 'label' => 'Hilux', 'parent_slug' => 'toyota', 'sort_order' => 3],
            ['type' => 'modele', 'slug' => 'tucson', 'label' => 'Tucson', 'parent_slug' => 'hyundai', 'sort_order' => 1],
            ['type' => 'modele', 'slug' => 'accent', 'label' => 'Accent', 'parent_slug' => 'hyundai', 'sort_order' => 2],
            ['type' => 'modele', 'slug' => 'sportage', 'label' => 'Sportage', 'parent_slug' => 'kia', 'sort_order' => 1],
            ['type' => 'modele', 'slug' => 'picanto', 'label' => 'Picanto', 'parent_slug' => 'kia', 'sort_order' => 2],
            ['type' => 'modele', 'slug' => '208', 'label' => '208', 'parent_slug' => 'peugeot', 'sort_order' => 1],
            ['type' => 'modele', 'slug' => '3008', 'label' => '3008', 'parent_slug' => 'peugeot', 'sort_order' => 2],
            ['type' => 'modele', 'slug' => 'clio', 'label' => 'Clio', 'parent_slug' => 'renault', 'sort_order' => 1],
            ['type' => 'modele', 'slug' => 'duster', 'label' => 'Duster', 'parent_slug' => 'renault', 'sort_order' => 2],
            ['type' => 'modele', 'slug' => 'classe-c', 'label' => 'Classe C', 'parent_slug' => 'mercedes', 'sort_order' => 1],
            ['type' => 'modele', 'slug' => 'gle', 'label' => 'GLE', 'parent_slug' => 'mercedes', 'sort_order' => 2],
        ] as $row) {
            ReferenceList::updateOrCreate(
                ['type' => $row['type'], 'slug' => $row['slug']],
                [...$row, 'is_active' => true, 'parent_slug' => $row['parent_slug'] ?? null]
            );
        }
    }
}
