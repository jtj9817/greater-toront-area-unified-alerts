<?php

namespace App\Services\Alerts\DTOs;

use App\Enums\AlertStatus;

readonly class UnifiedAlertsCriteria
{
    public const int DEFAULT_PER_PAGE = 50;
    public const int MIN_PER_PAGE = 1;
    public const int MAX_PER_PAGE = 200;

    public string $status;
    public int $perPage;
    public ?int $page;

    public function __construct(
        string $status = AlertStatus::All->value,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?int $page = null,
    ) {
        $this->status = AlertStatus::normalize($status);
        $this->perPage = self::normalizePerPage($perPage);
        $this->page = self::normalizePage($page);
    }

    private static function normalizePerPage(int $perPage): int
    {
        if ($perPage < self::MIN_PER_PAGE || $perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException(
                "perPage must be between ".self::MIN_PER_PAGE.' and '.self::MAX_PER_PAGE.'.'
            );
        }

        return $perPage;
    }

    private static function normalizePage(?int $page): ?int
    {
        if ($page === null) {
            return null;
        }

        if ($page < 1) {
            throw new \InvalidArgumentException('page must be at least 1.');
        }

        return $page;
    }
}
