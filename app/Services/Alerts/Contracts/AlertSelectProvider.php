<?php

namespace App\Services\Alerts\Contracts;

use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use Illuminate\Database\Query\Builder;

interface AlertSelectProvider
{
    public function select(UnifiedAlertsCriteria $criteria): Builder;
}
