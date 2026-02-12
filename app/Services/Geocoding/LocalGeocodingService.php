<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LocalGeocodingService
{
    /**
     * Search addresses by query string.
     *
     * @param string $query
     * @return Collection
     */
    public function searchAddresses(string $query): Collection
    {
        // Optimized:
        // 1. Remove LOWER() function calls on indexed columns
        // 2. Remove leading wildcards from LIKE clauses where possible
        // Assumes 'address' column has an index and appropriate collation (e.g., utf8mb4_unicode_ci) for case-insensitive search

        return DB::table('addresses')
            ->select('id', 'address', 'latitude', 'longitude')
            ->where('address', 'like', $query . '%')
            ->limit(10)
            ->get();
    }

    /**
     * Search POIs by query string.
     *
     * @param string $query
     * @return Collection
     */
    public function searchPois(string $query): Collection
    {
        // Optimized:
        // 1. Remove LOWER() function calls on indexed columns
        // 2. Remove leading wildcards from LIKE clauses where possible
        // Assumes 'name' column has an index and appropriate collation

        return DB::table('pois')
            ->select('id', 'name', 'category', 'latitude', 'longitude')
            ->where('name', 'like', $query . '%')
            ->limit(10)
            ->get();
    }
}
