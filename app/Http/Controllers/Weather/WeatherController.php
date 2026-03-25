<?php

namespace App\Http\Controllers\Weather;

use App\Http\Controllers\Controller;
use App\Http\Resources\WeatherResource;
use App\Models\GtaPostalCode;
use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\WeatherCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeatherController extends Controller
{
    public function __invoke(Request $request, WeatherCacheService $weatherCache): JsonResponse
    {
        $validated = $request->validate([
            'fsa' => ['required', 'string', 'max:10'],
        ]);

        $fsa = GtaPostalCode::normalize($validated['fsa']);

        if (! GtaPostalCode::where('fsa', $fsa)->exists()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['fsa' => ['The fsa must be a valid GTA postal code.']],
            ], 422);
        }

        try {
            $data = $weatherCache->get($fsa);

            return response()->json(['data' => (new WeatherResource($data))->resolve()]);
        } catch (WeatherFetchException) {
            return response()->json([
                'message' => 'Weather data is temporarily unavailable.',
            ], 503);
        }
    }
}
