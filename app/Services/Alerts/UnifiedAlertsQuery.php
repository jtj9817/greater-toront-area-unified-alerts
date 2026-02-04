<?php

namespace App\Services\Alerts;

use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class UnifiedAlertsQuery
{
    public function __construct(
        private readonly FireAlertSelectProvider $fire,
        private readonly PoliceAlertSelectProvider $police,
        private readonly TransitAlertSelectProvider $transit,
        private readonly UnifiedAlertMapper $mapper,
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
            ->through(fn (object $row) => $this->mapper->fromRow($row));
    }
}
