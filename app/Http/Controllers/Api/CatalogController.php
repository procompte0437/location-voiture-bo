<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\ReassuranceArgument;
use App\Models\ReferenceList;
use App\Models\SiteContent;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function home(): JsonResponse
    {
        $contents = SiteContent::query()
            ->where('group', 'home')
            ->pluck('value', 'key');

        return response()->json([
            'data' => [
                'contents' => $contents,
                'reassurance' => ReassuranceArgument::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'title', 'text', 'sort_order']),
                'categories' => VehicleCategory::query()
                    ->where('is_active', true)
                    ->where('show_on_home', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'slug', 'label', 'description']),
            ],
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = VehicleCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'label', 'description', 'show_on_home']);

        return response()->json(['data' => $categories]);
    }

    public function labels(): JsonResponse
    {
        $rows = ReferenceList::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['type', 'slug', 'label']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->type][$row->slug] = $row->label;
        }

        $categories = VehicleCategory::query()
            ->where('is_active', true)
            ->pluck('label', 'slug');

        return response()->json([
            'data' => [
                'categories' => $categories,
                'fuel' => $grouped['fuel'] ?? [],
                'transmission' => $grouped['transmission'] ?? [],
                'partner_type' => $grouped['partner_type'] ?? [],
                'driver_age' => $grouped['driver_age'] ?? [],
                'booking_mode' => $grouped['booking_mode'] ?? [],
                'cancellation_policy' => $grouped['cancellation_policy'] ?? [],
                'payment_method' => $grouped['payment_method'] ?? [],
                'location_type' => $grouped['location_type'] ?? [],
                'marque' => $grouped['marque'] ?? [],
                'modele' => $grouped['modele'] ?? [],
            ],
        ]);
    }

    public function references(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $parentSlug = $request->query('parent_slug');

        $items = ReferenceList::query()
            ->where('is_active', true)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($parentSlug, fn ($q) => $q->where('parent_slug', $parentSlug))
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get(['id', 'type', 'slug', 'label', 'parent_slug', 'sort_order']);

        return response()->json(['data' => $items]);
    }

    /**
     * Liste légère des véhicules publiés pour le sélecteur du formulaire de réservation.
     * Avec pickup_at + return_at : ne retourne que les véhicules libres sur la plage.
     */
    public function vehicles(Request $request): JsonResponse
    {
        // Pas de règle boolean stricte : les query params envoient souvent "true"/"false" (rejetés par Laravel).
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'pickup_at' => ['nullable', 'date'],
            'return_at' => ['nullable', 'date', 'after:pickup_at'],
            'available_only' => ['nullable'],
        ]);

        $q = $data['q'] ?? null;
        $pickup = ! empty($data['pickup_at']) ? Carbon::parse($data['pickup_at']) : null;
        $return = ! empty($data['return_at']) ? Carbon::parse($data['return_at']) : null;
        $availableOnlyRaw = $data['available_only'] ?? true;
        $filtrerDisponibles = $pickup && $return && filter_var($availableOnlyRaw, FILTER_VALIDATE_BOOLEAN);

        $collection = Vehicle::query()
            ->with([
                'media',
                'categoryInfo',
                'partner:id,company_name,city,average_rating',
                'agency.location',
            ])
            ->where('status', VehicleStatus::Published)
            ->whereHas('partner', fn ($pq) => $pq->where('status', PartnerStatus::Approved))
            ->when($q, function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('brand', 'like', "%{$q}%")
                        ->orWhere('model', 'like', "%{$q}%")
                        ->orWhere('category', 'like', "%{$q}%");
                });
            })
            ->orderBy('brand')
            ->orderBy('model')
            ->limit(80)
            ->get();

        $vehicles = $collection
            ->map(function (Vehicle $v) use ($pickup, $return) {
                $cover = $v->media->firstWhere('is_cover', true) ?? $v->media->first();
                $lieu = $v->agency?->location;

                $nomRetrait = $lieu?->name
                    ?? $v->agency?->name
                    ?? $v->agency?->city
                    ?? $v->partner?->city
                    ?? null;

                $disponible = true;
                if ($pickup && $return) {
                    $disponible = $this->bookings->isAvailable($v, $pickup, $return);
                }

                return [
                    'id' => $v->id,
                    'brand' => $v->brand,
                    'model' => $v->model,
                    'display_name' => $v->display_name ?: trim("{$v->brand} {$v->model}"),
                    'category' => $v->category,
                    'category_label' => $v->categoryInfo?->label,
                    'price_per_day' => $v->price_per_day,
                    'cover_url' => $cover?->url,
                    'partner_city' => $v->partner?->city,
                    'pickup_location_id' => $lieu?->id,
                    'pickup_location_name' => $nomRetrait,
                    'agency_name' => $v->agency?->name,
                    'available' => $disponible,
                ];
            })
            ->when($filtrerDisponibles, fn ($rows) => $rows->where('available', true)->values())
            ->take(40)
            ->values();

        return response()->json([
            'data' => $vehicles,
            'meta' => [
                'filtered_by_dates' => (bool) $filtrerDisponibles,
                'count' => $vehicles->count(),
            ],
        ]);
    }

    public function faq(): JsonResponse
    {
        $faqs = Faq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'question', 'answer', 'sort_order']);

        return response()->json(['data' => $faqs]);
    }
}
