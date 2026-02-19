<?php

namespace App\Services\Alerts\DTOs;

use App\Enums\AlertStatus;
use App\Enums\AlertSource;
use Carbon\CarbonImmutable;

readonly class UnifiedAlertsCriteria
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MIN_PER_PAGE = 1;

    public const int MAX_PER_PAGE = 200;

    public string $status;

    public int $perPage;

    public ?int $page;

    public ?string $source;

    public ?string $query;

    public ?string $since;

    public ?CarbonImmutable $sinceCutoff;

    public ?UnifiedAlertsCursor $cursor;

    /**
     * @var array<int, string>
     */
    public const array SINCE_OPTIONS = ['30m', '1h', '3h', '6h', '12h'];

    public function __construct(
        string $status = AlertStatus::All->value,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?int $page = null,
        ?string $source = null,
        ?string $query = null,
        ?string $since = null,
        ?string $cursor = null,
    ) {
        $this->status = AlertStatus::normalize($status);
        $this->perPage = self::normalizePerPage($perPage);
        $this->page = self::normalizePage($page);
        $this->source = self::normalizeSource($source);
        $this->query = self::normalizeQuery($query);
        $this->since = self::normalizeSince($since);
        $this->sinceCutoff = self::computeSinceCutoff($this->since);
        $this->cursor = self::normalizeCursor($cursor);
    }

    private static function normalizePerPage(int $perPage): int
    {
        if ($perPage < self::MIN_PER_PAGE || $perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException(
                'perPage must be between '.self::MIN_PER_PAGE.' and '.self::MAX_PER_PAGE.'.'
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

    private static function normalizeSource(?string $source): ?string
    {
        if ($source === null) {
            return null;
        }

        $normalized = trim($source);
        if ($normalized === '') {
            return null;
        }

        if (AlertSource::tryFrom($normalized) === null) {
            $expected = implode(', ', AlertSource::values());
            throw new \InvalidArgumentException("Invalid source '{$source}'. Expected one of: {$expected}.");
        }

        return $normalized;
    }

    private static function normalizeQuery(?string $query): ?string
    {
        if ($query === null) {
            return null;
        }

        $normalized = trim($query);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeSince(?string $since): ?string
    {
        if ($since === null) {
            return null;
        }

        $normalized = trim($since);
        if ($normalized === '') {
            return null;
        }

        if (! in_array($normalized, self::SINCE_OPTIONS, true)) {
            $expected = implode(', ', self::SINCE_OPTIONS);
            throw new \InvalidArgumentException("Invalid since '{$since}'. Expected one of: {$expected}.");
        }

        return $normalized;
    }

    private static function computeSinceCutoff(?string $since): ?CarbonImmutable
    {
        if ($since === null) {
            return null;
        }

        return match ($since) {
            '30m' => CarbonImmutable::now()->subMinutes(30),
            '1h' => CarbonImmutable::now()->subHour(),
            '3h' => CarbonImmutable::now()->subHours(3),
            '6h' => CarbonImmutable::now()->subHours(6),
            '12h' => CarbonImmutable::now()->subHours(12),
            default => null,
        };
    }

    private static function normalizeCursor(?string $cursor): ?UnifiedAlertsCursor
    {
        if ($cursor === null) {
            return null;
        }

        $normalized = trim($cursor);
        if ($normalized === '') {
            return null;
        }

        return UnifiedAlertsCursor::decode($normalized);
    }
}
