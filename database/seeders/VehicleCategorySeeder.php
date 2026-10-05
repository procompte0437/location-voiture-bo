<?php

namespace Database\Seeders;

use App\Models\VehicleCategory;
use Illuminate\Database\Seeder;

class VehicleCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'city_car', 'label' => 'Citadine', 'description' => 'Compacte pour la ville', 'sort_order' => 1, 'show_on_home' => true],
            ['slug' => 'sedan', 'label' => 'Berline', 'description' => 'Confort et élégance', 'sort_order' => 2, 'show_on_home' => true],
            ['slug' => 'suv', 'label' => 'SUV', 'description' => 'Polyvalent famille & routes', 'sort_order' => 3, 'show_on_home' => true],
            ['slug' => '4x4', 'label' => '4x4', 'description' => 'Tout-terrain intérieur du pays', 'sort_order' => 4, 'show_on_home' => true],
            ['slug' => 'pickup', 'label' => 'Pick-up', 'description' => 'Utilitaire robuste', 'sort_order' => 5, 'show_on_home' => false],
            ['slug' => 'minibus', 'label' => 'Minibus', 'description' => 'Groupes et transferts', 'sort_order' => 6, 'show_on_home' => true],
            ['slug' => 'utility', 'label' => 'Utilitaire', 'description' => 'Transport de marchandises', 'sort_order' => 7, 'show_on_home' => false],
            ['slug' => 'luxury', 'label' => 'Luxe', 'description' => 'Haut de gamme avec chauffeur possible', 'sort_order' => 8, 'show_on_home' => true],
        ];

        foreach ($categories as $category) {
            VehicleCategory::updateOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'is_active' => true]
            );
        }
    }
}
