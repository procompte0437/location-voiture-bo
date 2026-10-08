<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Vehicle;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleSearchController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['nullable', 'exists:lieux,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'pickup_at' => ['nullable', 'date'],
            'return_at' => ['nullable', 'date', 'after:pickup_at'],
            'driver_age' => ['nullable', 'integer', 'min:18', 'max:99'],
            'category' => ['nullable', 'string'],
            'transmission' => ['nullable', 'in:manual,automatic'],
            'fuel' => ['nullable', 'string'],
            'seats' => ['nullable', 'integer', 'min:2'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'with_driver' => ['nullable', 'boolean'],
            'airport_delivery' => ['nullable', 'boolean'],
            'free_cancellation' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:price,rating,popularity'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Vehicle::query()
            ->with(['partner', 'media', 'agency.location', 'categoryInfo'])
            ->where('status', VehicleStatus::Published)
            ->whereHas('partner', fn ($q) => $q->where('status', PartnerStatus::Approved));

        if (! empty($data['location_id'])) {
            $location = Location::find($data['location_id']);
            $query->where(function ($q) use ($location) {
                $q->whereHas('agency', function ($aq) use ($location) {
                    $aq->where('location_id', $location->id)
                        ->orWhere('city', $location->city ?? $location->name);
                })->orWhereHas('partner', function ($pq) use ($location) {
                    $pq->where('city', $location->city ?? $location->name);
                });
            });
        }

        if (! empty($data['q'])) {
            $term = $data['q'];
            $query->where(function ($q) use ($term) {
                $q->where('brand', 'like', "%{$term}%")
                    ->orWhere('model', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%");
            });
        }

        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (! empty($data['transmission'])) {
            $query->where('transmission', $data['transmission']);
        }
        if (! empty($data['fuel'])) {
            $query->where('fuel', $data['fuel']);
        }
        if (! empty($data['seats'])) {
            $query->where('seats', '>=', $data['seats']);
        }
        if (isset($data['min_price'])) {
            $query->where('price_per_day', '>=', $data['min_price']);
        }
        if (isset($data['max_price'])) {
            $query->where('price_per_day', '<=', $data['max_price']);
        }
        if (! empty($data['with_driver'])) {
            $query->where('with_driver_available', true);
        }
        if (! empty($data['airport_delivery'])) {
            $query->where('airport_delivery', true);
        }
        if (! empty($data['free_cancellation'])) {
            $query->where('free_cancellation', true);
        }
        if (! empty($data['driver_age'])) {
            $query->where('min_driver_age', '<=', $data['driver_age']);
        }

        $pickup = ! empty($data['pickup_at']) ? Carbon::parse($data['pickup_at']) : null;
        $return = ! empty($data['return_at']) ? Carbon::parse($data['return_at']) : null;

        if ($pickup && $return) {
            $vehicles = $query->get()->filter(
                fn (Vehicle $v) => $this->bookings->isAvailable($v, $pickup, $return)
            )->values();

            $days = $this->bookings->calculateDays($pickup, $return);

            $sort = $data['sort'] ?? 'price';
            $vehicles = match ($sort) {
                'rating' => $vehicles->sortByDesc(fn ($v) => $v->partner->average_rating)->values(),
                'popularity' => $vehicles->sortByDesc(fn ($v) => $v->partner->reviews_count)->values(),
                default => $vehicles->sortBy('price_per_day')->values(),
            };

            $perPage = $data['per_page'] ?? 12;
            $page = $data['page'] ?? 1;
            $total = $vehicles->count();
            $items = $vehicles->forPage($page, $perPage)->values()->map(
                fn (Vehicle $v) => $this->transformVehicle($v, $days)
            );

            return response()->json([
                'data' => $items,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'days' => $days,
                ],
            ]);
        }

        $sort = $data['sort'] ?? 'price';
        match ($sort) {
            'rating' => $query->orderByDesc(
                \App\Models\Partner::query()
                    ->select('average_rating')
                    ->whereColumn('partenaires.id', 'vehicules.partner_id')
                    ->limit(1)
            ),
            'popularity' => $query->orderByDesc(
                \App\Models\Partner::query()
                    ->select('reviews_count')
                    ->whereColumn('partenaires.id', 'vehicules.partner_id')
                    ->limit(1)
            ),
            default => $query->orderBy('price_per_day'),
        };

        $paginator = $query->paginate($data['per_page'] ?? 12);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Vehicle $v) => $this->transformVehicle($v)),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'days' => null,
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $vehicle = Vehicle::with(['partner', 'media', 'agency.location', 'pricingRules', 'categoryInfo'])
            ->where('status', VehicleStatus::Published)
            ->findOrFail($id);

        return response()->json(['data' => $this->transformVehicle($vehicle)]);
    }

    public function availability(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'pickup_at' => ['required', 'date'],
            'return_at' => ['required', 'date', 'after:pickup_at'],
        ]);

        $vehicle = Vehicle::findOrFail($id);
        $available = $this->bookings->isAvailable(
            $vehicle,
            Carbon::parse($data['pickup_at']),
            Carbon::parse($data['return_at'])
        );

        return response()->json(['available' => $available]);
    }

    private function transformVehicle(Vehicle $vehicle, ?int $days = null): array
    {
        $cover = $vehicle->media->firstWhere('is_cover', true) ?? $vehicle->media->first();
        $total = $days ? $days * $vehicle->price_per_day : null;

        return [
            'id' => $vehicle->id,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'display_name' => $vehicle->display_name,
            'year' => $vehicle->year,
            'category' => $vehicle->category,
            'category_label' => $vehicle->categoryInfo?->label ?? $vehicle->category,
            'seats' => $vehicle->seats,
            'doors' => $vehicle->doors,
            'luggage' => $vehicle->luggage,
            'transmission' => $vehicle->transmission,
            'fuel' => $vehicle->fuel,
            'air_conditioning' => $vehicle->air_conditioning,
            'included_km' => $vehicle->included_km,
            'deposit_amount' => $vehicle->deposit_amount,
            'min_driver_age' => $vehicle->min_driver_age,
            'booking_mode' => $vehicle->booking_mode,
            'cancellation_policy' => $vehicle->cancellation_policy,
            'with_driver_available' => $vehicle->with_driver_available,
            'airport_delivery' => $vehicle->airport_delivery,
            'free_cancellation' => $vehicle->free_cancellation,
            'fuel_policy' => $vehicle->fuel_policy,
            'features' => $vehicle->features,
            'description' => $vehicle->description,
            'price_per_day' => $vehicle->price_per_day,
            'total_price' => $total,
            'days' => $days,
            'currency' => 'XAF',
            'cover_url' => $cover?->url,
            'media' => $vehicle->media->map(fn ($m) => [
                'id' => $m->id,
                'url' => $m->url,
                'is_cover' => $m->is_cover,
            ]),
            'partner' => [
                'id' => $vehicle->partner->id,
                'name' => $vehicle->partner->company_name ?? $vehicle->partner->manager_name,
                'average_rating' => (float) $vehicle->partner->average_rating,
                'reviews_count' => $vehicle->partner->reviews_count,
                'trust_level' => $vehicle->partner->trust_level,
                'city' => $vehicle->partner->city,
            ],
            'agency' => $vehicle->agency ? [
                'id' => $vehicle->agency->id,
                'name' => $vehicle->agency->name,
                'city' => $vehicle->agency->city,
            ] : null,
        ];
    }
}
