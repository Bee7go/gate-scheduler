<?php

namespace App\Services\Flights;

class FlightFetchResult
{
    public const SOURCE_LIVE = 'live';

    public const SOURCE_FALLBACK = 'fallback';

    public const SOURCE_UNAVAILABLE = 'unavailable';

    /**
     * @param  array<int, array<string, mixed>>  $flights
     */
    public function __construct(
        public readonly array $flights,
        public readonly string $source,
        public readonly ?string $reason = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->source !== self::SOURCE_UNAVAILABLE;
    }
}
