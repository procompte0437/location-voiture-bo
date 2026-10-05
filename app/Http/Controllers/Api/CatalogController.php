<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\ReassuranceArgument;
use App\Models\ReferenceList;
use App\Models\SiteContent;
use App\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
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
