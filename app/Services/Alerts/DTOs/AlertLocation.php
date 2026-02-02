<?php

namespace App\Services\Alerts\DTOs;

readonly class AlertLocation
{
    public function __construct(
        public ?string $name,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $postalCode = null,
    ) {}
}

