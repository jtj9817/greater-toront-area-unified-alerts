<?php

namespace App\Services\Alerts\Contracts;

use Illuminate\Database\Query\Builder;

interface AlertSelectProvider
{
    public function select(): Builder;
}
