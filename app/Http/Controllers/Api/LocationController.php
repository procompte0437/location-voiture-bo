<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function autocomplete(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['nullable', 'string', 'max:120']])['q'] ?? '';

        $locations = Location::query()
            ->where('is_active', true)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('city', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'slug', 'type', 'city', 'latitude', 'longitude', 'is_popular']);

        return response()->json(['data' => $locations]);
    }

    public function popular(): JsonResponse
    {
        $locations = Location::query()
            ->where('is_active', true)
            ->where('is_popular', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type', 'city']);

        return response()->json(['data' => $locations]);
    }
}
