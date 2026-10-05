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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@locagabon.ga',
            'phone' => '+24106000001',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
            'status' => 'active',
            'locale' => 'fr',
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Client Démo',
            'email' => 'client@locagabon.ga',
            'phone' => '+24106000002',
            'password' => Hash::make('password'),
            'role' => UserRole::Customer,
            'status' => 'active',
            'locale' => 'fr',
            'email_verified_at' => now(),
        ]);

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
            Location::create($loc);
        }

        $partnerUser = User::create([
            'name' => 'Gabon Auto Location',
            'email' => 'partenaire@locagabon.ga',
            'phone' => '+24106000003',
            'password' => Hash::make('password'),
            'role' => UserRole::Partner,
            'status' => 'active',
            'locale' => 'fr',
            'email_verified_at' => now(),
        ]);

        $partner = Partner::create([
            'owner_user_id' => $partnerUser->id,
            'type' => 'agency',
            'company_name' => 'Gabon Auto Location',
            'rccm' => 'GA-LBV-2024-A-1234',
            'nif' => 'NIF-2024-5678',
            'manager_name' => 'Jean Okome',
            'address' => 'Boulevard Triomphal',
            'city' => 'Libreville',
            'phone' => '+24106000003',
            'whatsapp' => '+24106000003',
            'status' => PartnerStatus::Approved,
            'trust_level' => 'verified',
            'commission_rate' => 15,
            'average_rating' => 4.6,
            'reviews_count' => 48,
            'validated_by' => $superAdmin->id,
            'validated_at' => now(),
        ]);

        $airport = Location::where('slug', 'aeroport-leon-mba')->first();

        $agency = Agency::create([
            'partner_id' => $partner->id,
            'location_id' => $airport->id,
            'name' => 'Agence Aéroport Léon-Mba',
            'address' => 'Aéroport international Léon-Mba',
            'city' => 'Libreville',
            'latitude' => 0.4586,
            'longitude' => 9.4123,
            'opening_hours' => [
                'mon' => ['08:00', '20:00'],
                'tue' => ['08:00', '20:00'],
                'wed' => ['08:00', '20:00'],
                'thu' => ['08:00', '20:00'],
                'fri' => ['08:00', '20:00'],
                'sat' => ['08:00', '18:00'],
                'sun' => ['09:00', '17:00'],
            ],
            'is_active' => true,
        ]);

        $fleet = [
            [
                'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2023, 'category' => 'sedan',
                'plate_number' => 'GA-101-A', 'seats' => 5, 'transmission' => 'automatic',
                'fuel' => 'petrol', 'price_per_day' => 35000, 'deposit_amount' => 200000,
                'airport_delivery' => true, 'free_cancellation' => true,
                'cover' => 'https://images.unsplash.com/photo-1623869675781-80aa31012a5a?w=800&q=80',
            ],
            [
                'brand' => 'Toyota', 'model' => 'RAV4', 'year' => 2022, 'category' => 'suv',
                'plate_number' => 'GA-102-A', 'seats' => 5, 'transmission' => 'automatic',
                'fuel' => 'petrol', 'price_per_day' => 55000, 'deposit_amount' => 300000,
                'airport_delivery' => true, 'free_cancellation' => true,
                'cover' => 'https://images.unsplash.com/photo-1519641471654-76ce0107ad1b?w=800&q=80',
            ],
            [
                'brand' => 'Toyota', 'model' => 'Land Cruiser Prado', 'year' => 2021, 'category' => '4x4',
                'plate_number' => 'GA-103-A', 'seats' => 7, 'transmission' => 'automatic',
                'fuel' => 'diesel', 'price_per_day' => 85000, 'deposit_amount' => 500000,
                'airport_delivery' => true, 'with_driver_available' => true,
                'cover' => 'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=800&q=80',
            ],
            [
                'brand' => 'Hyundai', 'model' => 'i10', 'year' => 2024, 'category' => 'city_car',
                'plate_number' => 'GA-104-A', 'seats' => 4, 'transmission' => 'manual',
                'fuel' => 'petrol', 'price_per_day' => 22000, 'deposit_amount' => 100000,
                'airport_delivery' => true, 'free_cancellation' => true,
                'cover' => 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?w=800&q=80',
            ],
            [
                'brand' => 'Mercedes', 'model' => 'Classe E', 'year' => 2023, 'category' => 'luxury',
                'plate_number' => 'GA-105-A', 'seats' => 5, 'transmission' => 'automatic',
                'fuel' => 'petrol', 'price_per_day' => 120000, 'deposit_amount' => 800000,
                'airport_delivery' => true, 'with_driver_available' => true,
                'cover' => 'https://images.unsplash.com/photo-1618843479313-40f8afb4b4d8?w=800&q=80',
            ],
            [
                'brand' => 'Toyota', 'model' => 'Hiace', 'year' => 2022, 'category' => 'minibus',
                'plate_number' => 'GA-106-A', 'seats' => 14, 'transmission' => 'manual',
                'fuel' => 'diesel', 'price_per_day' => 75000, 'deposit_amount' => 400000,
                'with_driver_available' => true,
                'cover' => 'https://images.unsplash.com/photo-1527786356703-4ac100ceac52?w=800&q=80',
            ],
        ];

        foreach ($fleet as $item) {
            $cover = $item['cover'];
            unset($item['cover']);

            $vehicle = Vehicle::create([
                ...$item,
                'partner_id' => $partner->id,
                'agency_id' => $agency->id,
                'doors' => 4,
                'luggage' => $item['seats'] >= 7 ? 4 : 2,
                'air_conditioning' => true,
                'included_km' => 200,
                'min_driver_age' => 21,
                'min_license_years' => 1,
                'booking_mode' => 'instant',
                'cancellation_policy' => 'moderate',
                'fuel_policy' => 'full_to_full',
                'features' => ['GPS', 'Bluetooth', 'USB'],
                'status' => VehicleStatus::Published,
                'description' => "Véhicule récent, entretenu et assuré. Idéal pour vos déplacements au Gabon.",
                'with_driver_available' => $item['with_driver_available'] ?? false,
                'airport_delivery' => $item['airport_delivery'] ?? false,
                'free_cancellation' => $item['free_cancellation'] ?? false,
            ]);

            $vehicle->media()->create([
                'url' => $cover,
                'sort_order' => 0,
                'is_cover' => true,
            ]);
        }

        // Pending partner for admin queue demo
        $pendingUser = User::create([
            'name' => 'Nouveau Partenaire',
            'email' => 'pending@locagabon.ga',
            'phone' => '+24106000004',
            'password' => Hash::make('password'),
            'role' => UserRole::Partner,
            'status' => 'active',
            'locale' => 'fr',
        ]);

        Partner::create([
            'owner_user_id' => $pendingUser->id,
            'type' => 'company',
            'company_name' => 'Okoume Cars',
            'manager_name' => 'Marie Ndong',
            'address' => 'Owendo',
            'city' => 'Libreville',
            'phone' => '+24106000004',
            'status' => PartnerStatus::Pending,
            'trust_level' => 'new',
        ]);
    }
}
