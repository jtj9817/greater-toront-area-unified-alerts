<?php

namespace App\Http\Controllers\Geocoding;

use App\Http\Controllers\Controller;
use App\Services\Geocoding\LocalGeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LocalGeocodingSearchController extends Controller
{
    public function __invoke(Request $request, LocalGeocodingService $service): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'q' => ['required', 'string', 'max:120'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid search parameters.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = trim((string) $request->query('q', ''));
        $limit = (int) $request->integer('limit', 10);

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $service->search($query, $limit),
        ]);
    }
}
