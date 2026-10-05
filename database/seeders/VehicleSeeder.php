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

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@locagabon.ga')->first();

        $partnerUser = User::firstOrCreate(
            ['email' => 'partenaire@locagabon.ga'],
            [
                'name' => 'Gabon Auto Location',
                'phone' => '+24106000003',
                'password' => Hash::make('password'),
                'role' => UserRole::Partner,
                'status' => 'active',
                'locale' => 'fr',
                'email_verified_at' => now(),
            ]
        );

        $partner = Partner::firstOrCreate(
            ['owner_user_id' => $partnerUser->id],
            [
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
                'commission_rate' => 0,
                'average_rating' => 4.6,
                'reviews_count' => 48,
                'validated_by' => $admin?->id,
                'validated_at' => now(),
            ]
        );

        $airport = Location::where('slug', 'aeroport-leon-mba')->first();

        $agency = Agency::firstOrCreate(
            ['partner_id' => $partner->id, 'name' => 'Agence Aéroport Léon-Mba'],
            [
                'location_id' => $airport?->id,
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
            ]
        );

        // Au moins un véhicule par catégorie active de la plateforme
        $fleet = [
            // Citadine
            [
                'brand' => 'Hyundai', 'model' => 'i10', 'year' => 2024, 'category' => 'city_car',
                'plate_number' => 'GA-104-A', 'seats' => 4, 'transmission' => 'manual', 'fuel' => 'petrol',
                'price_per_day' => 22000, 'deposit_amount' => 100000, 'airport_delivery' => true,
                'free_cancellation' => true, 'cover' => 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?w=800&q=80',
            ],
            [
                'brand' => 'Toyota', 'model' => 'Yaris', 'year' => 2023, 'category' => 'city_car',
                'plate_number' => 'GA-204-A', 'seats' => 5, 'transmission' => 'automatic', 'fuel' => 'petrol',
                'price_per_day' => 25000, 'deposit_amount' => 120000, 'airport_delivery' => true,
                'free_cancellation' => true, 'cover' => 'https://images.unsplash.com/photo-1552519507-da3b142c6e3d?w=800&q=80',
            ],
            // Berline
            [
                'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2023, 'category' => 'sedan',
                'plate_number' => 'GA-101-A', 'seats' => 5, 'transmission' => 'automatic', 'fuel' => 'petrol',
                'price_per_day' => 35000, 'deposit_amount' => 200000, 'airport_delivery' => true,
                'free_cancellation' => true, 'cover' => 'https://images.unsplash.com/photo-1623869675781-80aa31012a5a?w=800&q=80',
            ],
            [
                'brand' => 'Honda', 'model' => 'Civic', 'year' => 2022, 'category' => 'sedan',
                'plate_number' => 'GA-201-A', 'seats' => 5, 'transmission' => 'automatic', 'fuel' => 'petrol',
                'price_per_day' => 38000, 'deposit_amount' => 220000, 'airport_delivery' => true,
                'cover' => 'https://images.unsplash.com/photo-1590362891991-f776e747a588?w=800&q=80',
            ],
            // SUV
            [
                'brand' => 'Toyota', 'model' => 'RAV4', 'year' => 2022, 'category' => 'suv',
                'plate_number' => 'GA-102-A', 'seats' => 5, 'transmission' => 'automatic', 'fuel' => 'petrol',
                'price_per_day' => 55000, 'deposit_amount' => 300000, 'airport_delivery' => true,
                'free_cancellation' => true, 'cover' => 'https://images.unsplash.com/photo-1519641471654-76ce0107ad1b?w=800&q=80',
            ],
            [
                'brand' => 'Hyundai', 'model' => 'Tucson', 'year' => 2023, 'category' => 'suv',
                'plate_number' => 'GA-202-A', 'seats' => 5, 'transmission' => 'automatic', 'fuel' => 'petrol',
                'price_per_day' => 58000, 'deposit_amount' => 320000, 'airport_delivery' => true,
                'cover' => 'https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?w=800&q=80',
            ],
            // 4x4
            [
                'brand' => 'Toyota', 'model' => 'Land Cruiser Prado', 'year' => 2021, 'category' => '4x4',
                'plate_number' => 'GA-103-A', 'seats' => 7, 'transmission' => 'automatic', 'fuel' => 'diesel',
                'price_per_day' => 85000, 'deposit_amount' => 500000, 'airport_delivery' => true,
                'with_driver_available' => true, 'cover' => 'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=800&q=80',
            ],
            [
                'brand' => 'Toyota', 'model' => 'Fortuner', 'year' => 2022, 'category' => '4x4',
                'plate_number' => 'GA-203-A', 'seats' => 7, 'transmission' => 'automatic', 'fuel' => 'diesel',
                'price_per_day' => 80000, 'deposit_amount' => 480000, 'with_driver_available' => true,
                'cover' => 'https://images.unsplash.com/photo-1606220945770-b5b6c2c55bf1?w=800&q=80',
            ],
            // Pick-up
            [
                'brand' => 'Toyota', 'model' => 'Hilux', 'year' => 2022, 'category' => 'pickup',
                'plate_number' => 'GA-107-A', 'seats' => 5, 'transmission' => 'manual', 'fuel' => 'diesel',
                'price_per_day' => 65000, 'deposit_amount' => 400000, 'with_driver_available' => true,
                'cover' => 'https://images.unsplash.com/photo-1559416523-140ddc3d238c?w=800&q=80',
            ],
            // Minibus
            [
                'brand' => 'Toyota', 'model' => 'Hiace', 'year' => 2022, 'category' => 'minibus',
                'plate_number' => 'GA-106-A', 'seats' => 14, 'transmission' => 'manual', 'fuel' => 'diesel',
                'price_per_day' => 75000, 'deposit_amount' => 400000, 'with_driver_available' => true,
                'cover' => 'https://images.unsplash.com/photo-1527786356703-4ac100ceac52?w=800&q=80',
            ],
            [
                'brand' => 'Mercedes', 'model' => 'Sprinter', 'year' => 2021, 'category' => 'minibus',
                'plate_number' => 'GA-206-A', 'seats' => 16, 'transmission' => 'automatic', 'fuel' => 'diesel',
                'price_per_day' => 95000, 'deposit_amount' => 500000, 'with_driver_available' => true,
                'cover' => 'https://images.unsplash.com/photo-1544620341-11bb3f7b1f3d?w=800&q=80',
            ],
            // Utilitaire
            [
                'brand' => 'Renault', 'model' => 'Kangoo', 'year' => 2021, 'category' => 'utility',
                'plate_number' => 'GA-108-A', 'seats' => 2, 'transmission' => 'manual', 'fuel' => 'diesel',
                'price_per_day' => 30000, 'deposit_amount' => 150000,
                'cover' => 'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?w=800&q=80',
            ],
            // Luxe
            [
                'brand' => 'Mercedes', 'model' => 'Classe E', 'year' => 2023, 'category' => 'luxury',
                'plate_number' => 'GA-105-A', 'seats' => 5, 'transmission' => 'automatic', 'fuel' => 'petrol',
                'price_per_day' => 120000, 'deposit_amount' => 800000, 'airport_delivery' => true,
                'with_driver_available' => true, 'cover' => 'https://images.unsplash.com/photo-1618843479313-40f8afb4b4d8?w=800&q=80',
            ],
            [
                'brand' => 'BMW', 'model' => 'Série 5', 'year' => 2023, 'category' => 'luxury',
                'plate_number' => 'GA-205-A', 'seats' => 5, 'transmission' => 'automatic', 'fuel' => 'petrol',
                'price_per_day' => 130000, 'deposit_amount' => 850000, 'airport_delivery' => true,
                'with_driver_available' => true, 'cover' => 'https://images.unsplash.com/photo-1555215695-3004980ad54e?w=800&q=80',
            ],
        ];

        foreach ($fleet as $item) {
            $cover = $item['cover'];
            unset($item['cover']);

            $vehicle = Vehicle::updateOrCreate(
                ['plate_number' => $item['plate_number']],
                [
                    ...$item,
                    'partner_id' => $partner->id,
                    'agency_id' => $agency->id,
                    'doors' => 4,
                    'luggage' => ($item['seats'] ?? 5) >= 7 ? 4 : 2,
                    'air_conditioning' => true,
                    'included_km' => 200,
                    'min_driver_age' => 21,
                    'min_license_years' => 1,
                    'booking_mode' => 'instant',
                    'cancellation_policy' => 'moderate',
                    'fuel_policy' => 'full_to_full',
                    'features' => ['GPS', 'Bluetooth', 'USB'],
                    'status' => VehicleStatus::Published,
                    'description' => 'Véhicule récent, entretenu et assuré. Idéal pour vos déplacements au Gabon.',
                    'with_driver_available' => $item['with_driver_available'] ?? false,
                    'airport_delivery' => $item['airport_delivery'] ?? false,
                    'free_cancellation' => $item['free_cancellation'] ?? false,
                ]
            );

            $vehicle->media()->delete();
            $vehicle->media()->create([
                'url' => $cover,
                'sort_order' => 0,
                'is_cover' => true,
            ]);
        }
    }
}
