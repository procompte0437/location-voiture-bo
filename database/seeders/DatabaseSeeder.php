<?php

namespace Database\Seeders;

use App\Enums\PartnerStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::updateOrCreate(
            ['email' => 'admin@locagabon.ga'],
            [
                'name' => 'Super Admin',
                'phone' => '+24106000001',
                'password' => Hash::make('password'),
                'role' => UserRole::SuperAdmin,
                'status' => 'active',
                'locale' => 'fr',
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'client@locagabon.ga'],
            [
                'name' => 'Client Démo',
                'phone' => '+24106000002',
                'password' => Hash::make('password'),
                'role' => UserRole::Customer,
                'status' => 'active',
                'locale' => 'fr',
                'email_verified_at' => now(),
            ]
        );

        $locations = [
            ['name' => 'Aéroport Léon-Mba', 'slug' => 'aeroport-leon-mba', 'type' => 'airport', 'city' => 'Libreville', 'is_popular' => true, 'latitude' => 0.4586, 'longitude' => 9.4123],
            ['name' => 'Libreville Centre', 'slug' => 'libreville-centre', 'type' => 'city', 'city' => 'Libreville', 'is_popular' => true, 'latitude' => 0.4162, 'longitude' => 9.4673],
            ['name' => 'Port-Gentil', 'slug' => 'port-gentil', 'type' => 'city', 'city' => 'Port-Gentil', 'is_popular' => true, 'latitude' => -0.7193, 'longitude' => 8.7815],
            ['name' => 'Franceville', 'slug' => 'franceville', 'type' => 'city', 'city' => 'Franceville', 'is_popular' => true, 'latitude' => -1.6333, 'longitude' => 13.5833],
            ['name' => 'Oyem', 'slug' => 'oyem', 'type' => 'city', 'city' => 'Oyem', 'is_popular' => true, 'latitude' => 1.5995, 'longitude' => 11.5793],
            ['name' => 'Lambaréné', 'slug' => 'lambarene', 'type' => 'city', 'city' => 'Lambaréné', 'is_popular' => true, 'latitude' => -0.7001, 'longitude' => 10.2406],
            ['name' => 'Moanda', 'slug' => 'moanda', 'type' => 'city', 'city' => 'Moanda', 'is_popular' => true, 'latitude' => -1.5667, 'longitude' => 13.2000],
            ['name' => 'Mouila', 'slug' => 'mouila', 'type' => 'city', 'city' => 'Mouila', 'is_popular' => false, 'latitude' => -1.8685, 'longitude' => 11.0559],
            ['name' => 'Batterie IV', 'slug' => 'batterie-iv', 'type' => 'district', 'city' => 'Libreville', 'is_popular' => false, 'latitude' => 0.3900, 'longitude' => 9.4500],
            ['name' => 'Akanda', 'slug' => 'akanda', 'type' => 'district', 'city' => 'Libreville', 'is_popular' => false, 'latitude' => 0.5200, 'longitude' => 9.4200],
        ];

        foreach ($locations as $loc) {
            Location::updateOrCreate(['slug' => $loc['slug']], [...$loc, 'is_active' => true]);
        }

        $pendingUser = User::updateOrCreate(
            ['email' => 'pending@locagabon.ga'],
            [
                'name' => 'Nouveau Partenaire',
                'phone' => '+24106000004',
                'password' => Hash::make('password'),
                'role' => UserRole::Partner,
                'status' => 'active',
                'locale' => 'fr',
            ]
        );

        Partner::updateOrCreate(
            ['owner_user_id' => $pendingUser->id],
            [
                'type' => 'company',
                'company_name' => 'Okoume Cars',
                'manager_name' => 'Marie Ndong',
                'address' => 'Owendo',
                'city' => 'Libreville',
                'phone' => '+24106000004',
                'status' => PartnerStatus::Pending,
                'trust_level' => 'new',
                'commission_rate' => 0,
            ]
        );

        $this->call([
            VehicleCategorySeeder::class,
            SiteContentSeeder::class,
            VehicleSeeder::class,
        ]);

        unset($superAdmin);
    }
}
