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
        ] as $row) {
            ReferenceList::updateOrCreate(
                ['type' => $row['type'], 'slug' => $row['slug']],
                [...$row, 'is_active' => true]
            );
        }
    }
}
