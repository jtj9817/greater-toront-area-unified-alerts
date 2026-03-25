<?php

namespace App\Http\Controllers\Weather;

use App\Http\Controllers\Controller;
use App\Models\GtaPostalCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostalCodeResolveCoordsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:43.0,44.5'],
            'lng' => ['required', 'numeric', 'between:-80.5,-78.5'],
        ]);

        $nearest = GtaPostalCode::nearestFsa(
            (float) $validated['lat'],
            (float) $validated['lng'],
        );

        if ($nearest === null) {
            return response()->json(['message' => 'No postal code found.'], 404);
        }

        return response()->json([
            'data' => [
                'fsa' => $nearest->fsa,
                'municipality' => $nearest->municipality,
                'neighbourhood' => $nearest->neighbourhood,
                'lat' => $nearest->lat,
                'lng' => $nearest->lng,
            ],
        ]);
    }
}
