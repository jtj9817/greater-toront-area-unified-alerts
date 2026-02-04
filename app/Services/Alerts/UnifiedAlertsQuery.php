<?php

namespace App\Services\Alerts;

use App\Services\Alerts\DTOs\AlertLocation;
use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UnifiedAlertsQuery
{
    public function __construct(
        private readonly FireAlertSelectProvider $fire,
        private readonly PoliceAlertSelectProvider $police,
        private readonly TransitAlertSelectProvider $transit,
    ) {}

    /**
     * @param  'all'|'active'|'cleared'  $status
     */
    public function paginate(
        int $perPage = 50,
        string $status = 'all',
    ): LengthAwarePaginator {
        if (! in_array($status, ['all', 'active', 'cleared'], true)) {
            throw new \InvalidArgumentException("Invalid status '{$status}'. Expected one of: all, active, cleared.");
        }

        $union = $this->fire->select()
            ->unionAll($this->police->select())
            ->unionAll($this->transit->select());

        $query = DB::query()->fromSub($union, 'unified_alerts');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'cleared') {
            $query->where('is_active', false);
        }

        return $query
            ->orderByDesc('timestamp')
            ->orderBy('source')
            ->orderByDesc('external_id')
            ->paginate($perPage)
            ->through(fn (object $row) => $this->rowToDto($row));
    }

    private function rowToDto(object $row): UnifiedAlert
    {
        $location = null;
        if ($row->location_name !== null || $row->lat !== null || $row->lng !== null) {
            $location = new AlertLocation(
                name: $row->location_name,
                lat: $row->lat !== null ? (float) $row->lat : null,
                lng: $row->lng !== null ? (float) $row->lng : null,
            );
        }

        $rawTimestamp = $row->timestamp ?? null;
        if ($rawTimestamp === null || $rawTimestamp === '') {
            throw new \InvalidArgumentException('Unified alert timestamp is required.');
        }

        try {
            $timestamp = CarbonImmutable::parse($rawTimestamp);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Unified alert timestamp is not parseable.', previous: $exception);
        }

        return new UnifiedAlert(
            id: (string) $row->id,
            source: (string) $row->source,
            externalId: (string) $row->external_id,
            isActive: (bool) $row->is_active,
            timestamp: $timestamp,
            title: (string) $row->title,
            location: $location,
            meta: $this->decodeMeta($row->meta ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
