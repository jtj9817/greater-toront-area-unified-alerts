<?php

namespace App\Services\Alerts\DTOs;

use App\Enums\AlertSource;

readonly class AlertId
{
    public function __construct(
        public string $source,
        public string $externalId,
    ) {
        $this->assertValid();
    }

    public static function fromParts(string $source, string $externalId): self
    {
        return new self($source, $externalId);
    }

    public static function fromString(string $value): self
    {
        [$source, $externalId] = array_pad(explode(':', $value, 2), 2, null);

        if ($source === null || $externalId === null) {
            throw new \InvalidArgumentException("AlertId must be in the format {source}:{externalId}.");
        }

        return new self($source, $externalId);
    }

    public function value(): string
    {
        return "{$this->source}:{$this->externalId}";
    }

    public function __toString(): string
    {
        return $this->value();
    }

    private function assertValid(): void
    {
        if ($this->source === '' || $this->externalId === '') {
            throw new \InvalidArgumentException('AlertId requires non-empty source and externalId.');
        }

        if (! AlertSource::isValid($this->source)) {
            $expected = implode(', ', AlertSource::values());
            throw new \InvalidArgumentException("AlertId source '{$this->source}' is invalid. Expected one of: {$expected}.");
        }
    }
}
