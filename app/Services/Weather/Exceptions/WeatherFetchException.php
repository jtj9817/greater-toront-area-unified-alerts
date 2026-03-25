<?php

namespace App\Services\Weather\Exceptions;

use RuntimeException;

class WeatherFetchException extends RuntimeException
{
    public function __construct(
        public readonly string $fsa,
        public readonly string $provider,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Failed to fetch weather for FSA '{$fsa}' from provider '{$provider}': {$reason}",
            0,
            $previous,
        );
    }
}
