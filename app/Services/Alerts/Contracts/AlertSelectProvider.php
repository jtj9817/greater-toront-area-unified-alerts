<?php

namespace App\Services\Alerts\Contracts;

use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use Illuminate\Database\Query\Builder;

interface AlertSelectProvider
{
    /**
     * The alert source this provider emits (e.g. "fire", "police").
     */
    public function source(): string;

    public function select(UnifiedAlertsCriteria $criteria): Builder;
}
