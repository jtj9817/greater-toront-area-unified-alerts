<?php

namespace App\Services\Geocoding;

use App\Models\TorontoAddress;
use App\Models\TorontoPointOfInterest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LocalGeocodingService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $addressLimit = max(1, (int) ceil($limit * 0.6));
        $poiLimit = max(1, $limit - $addressLimit);

        $addresses = $this->searchAddresses($term, $addressLimit)->map(
            fn (TorontoAddress $address): array => [
                'id' => "address:{$address->id}",
                'type' => 'address',
                'name' => trim(implode(' ', array_filter([
                    $address->street_num,
                    $address->street_name,
                ]))),
                'secondary' => $address->zip,
                'zip' => $address->zip,
                'lat' => $address->lat,
                'long' => $address->long,
            ],
        );

        $pois = $this->searchPois($term, $poiLimit)->map(
            fn (TorontoPointOfInterest $poi): array => [
                'id' => "poi:{$poi->id}",
                'type' => 'poi',
                'name' => $poi->name,
                'secondary' => $poi->category,
                'zip' => null,
                'lat' => $poi->lat,
                'long' => $poi->long,
            ],
        );

        return $addresses
            ->concat($pois)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, TorontoAddress>
     */
    private function searchAddresses(string $term, int $limit): Collection
    {
        $prefix = $this->escapeLike(strtolower($term)).'%';

        return TorontoAddress::query()
            ->where(fn (Builder $query): Builder => $this->applyAddressTokenFilters($query, $term))
            ->orderByRaw("CASE WHEN street_name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix])
            ->orderBy('street_name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, TorontoPointOfInterest>
     */
    private function searchPois(string $term, int $limit): Collection
    {
        $escapedTerm = $this->escapeLike(strtolower($term));
        $like = '%'.$escapedTerm.'%';
        $prefix = $escapedTerm.'%';

        return TorontoPointOfInterest::query()
            ->where(function (Builder $query) use ($like): void {
                $query
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("COALESCE(category, '') LIKE ? ESCAPE '!'", [$like]);
            })
            ->orderByRaw("CASE WHEN name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    private function applyAddressTokenFilters(Builder $query, string $term): Builder
    {
        $tokens = preg_split('/\s+/', strtolower($term)) ?: [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $like = '%'.$this->escapeLike($token).'%';

            $query->where(function (Builder $nested) use ($like): void {
                $nested
                    ->whereRaw("street_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("COALESCE(street_num, '') LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("COALESCE(zip, '') LIKE ? ESCAPE '!'", [$like]);
            });
        }

        return $query;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
