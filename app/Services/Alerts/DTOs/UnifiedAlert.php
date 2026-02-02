<?php

namespace App\Services\Alerts\DTOs;

use Carbon\CarbonImmutable;

readonly class UnifiedAlert
{
    public function __construct(
        public string $id,
        public string $source,
        public string $externalId,
        public bool $isActive,
        public CarbonImmutable $timestamp,
        public string $title,
        public ?AlertLocation $location,
        public array $meta = [],
    ) {}
}

