<?php

namespace App\Http\Controllers\Weather;

use App\Http\Controllers\Controller;
use App\Models\GtaPostalCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostalCodeSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:120'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $query = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 10);

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $results = GtaPostalCode::search($query)
            ->limit($limit)
            ->get()
            ->map(fn (GtaPostalCode $pc) => [
                'fsa' => $pc->fsa,
                'municipality' => $pc->municipality,
                'neighbourhood' => $pc->neighbourhood,
                'lat' => $pc->lat,
                'lng' => $pc->lng,
            ]);

        return response()->json(['data' => $results]);
    }
}
