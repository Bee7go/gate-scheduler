<?php

namespace App\Services\Flights;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages circuit-breaker state for OpenSky API requests.
 *
 * The breaker is tracked per airport and direction pair using cache-backed
 * state and failure counters. It opens after a configurable number of failures,
 * remains open for a cooldown window, and then transitions to a half-open
 * state where live calls are permitted again.
 */
class OpenSkyCircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private const CACHE_KEY_PREFIX = 'opensky.breaker';

    /**
     * Determine whether a live OpenSky API request is currently allowed.
     *
     * Requests are allowed while the breaker is closed or half-open. Requests
     * are blocked only while the breaker is open during the cooldown window.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return bool True when the caller may attempt a live API request.
     */
    public function allows(string $airport, string $direction): bool
    {
        $state = $this->getState($airport, $direction);

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            return false;
        }

        return true;
    }

    /**
     * Record a successful API call and close the breaker for the given scope.
     *
     * Closing the breaker removes the cached state flag so subsequent calls are
     * treated as healthy again.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return void
     */
    public function recordSuccess(string $airport, string $direction): void
    {
        $cacheKey = $this->getStateKey($airport, $direction);
        Cache::forget($cacheKey);

        Log::info('OpenSky circuit breaker closed', [
            'airport' => $airport,
            'direction' => $direction,
        ]);
    }

    /**
     * Record a failed API call and open the breaker if the threshold is reached.
     *
     * Failures are counted inside a configurable rolling window. Once the number
     * of failures reaches the configured threshold, the breaker is opened for the
     * current airport and direction scope.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return void
     */
    public function recordFailure(string $airport, string $direction): void
    {
        $failureThreshold = max(1, (int) config('services.opensky.breaker_failure_threshold', 3));
        $failureWindowSeconds = max(60, (int) config('services.opensky.breaker_failure_window_seconds', 120));

        $cacheKey = $this->getFailuresKey($airport, $direction);
        $failures = (int) Cache::get($cacheKey, 0);
        $failures++;

        Cache::put($cacheKey, $failures, now()->addSeconds($failureWindowSeconds));

        if ($failures >= $failureThreshold) {
            $this->openCircuit($airport, $direction);
        }
    }

    /**
     * Check whether the breaker is currently open for the given scope.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return bool True when live calls should currently be blocked.
     */
    public function isOpen(string $airport, string $direction): bool
    {
        return $this->getState($airport, $direction) === self::STATE_OPEN;
    }

    /**
     * Reset the breaker state and failure counters for the given scope.
     *
     * This forces the breaker back to a healthy baseline regardless of its
     * current state.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return void
     */
    public function reset(string $airport, string $direction): void
    {
        Cache::forget($this->getStateKey($airport, $direction));
        Cache::forget($this->getFailuresKey($airport, $direction));

        Log::info('OpenSky circuit breaker reset', [
            'airport' => $airport,
            'direction' => $direction,
        ]);
    }

    /**
     * Resolve the effective breaker state for the given airport and direction.
     *
     * When the cached state is open and the cooldown period has elapsed, this
     * method returns a half-open state to allow callers to probe the dependency
     * again.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return string One of the internal state constants.
     */
    private function getState(string $airport, string $direction): string
    {
        $cacheKey = $this->getStateKey($airport, $direction);
        $state = Cache::get($cacheKey, self::STATE_CLOSED);

        if ($state === self::STATE_OPEN) {
            $cooldownSeconds = max(60, (int) config('services.opensky.breaker_cooldown_seconds', 600));
            $openedAt = Cache::get($this->getOpenedAtKey($airport, $direction));

            if ($openedAt && (time() - $openedAt) > $cooldownSeconds) {
                return self::STATE_HALF_OPEN;
            }
        }

        return $state;
    }

    /**
     * Mark the breaker as open for the configured cooldown duration.
     *
     * The open state and its timestamp are stored in cache so later calls can
     * determine when the breaker should move to half-open.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return void
     */
    private function openCircuit(string $airport, string $direction): void
    {
        $coodownSeconds = max(60, (int) config('services.opensky.breaker_cooldown_seconds', 600));
        $stateKey = $this->getStateKey($airport, $direction);
        $openedAtKey = $this->getOpenedAtKey($airport, $direction);

        Cache::put($stateKey, self::STATE_OPEN, now()->addSeconds($coodownSeconds));
        Cache::put($openedAtKey, time(), now()->addSeconds($coodownSeconds));

        Log::warning('OpenSky circuit breaker opened', [
            'airport' => $airport,
            'direction' => $direction,
            'cooldown_seconds' => $coodownSeconds,
        ]);
    }

    /**
     * Build the cache key used to store the breaker state.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return string Cache key for the breaker state value.
     */
    private function getStateKey(string $airport, string $direction): string
    {
        return sprintf('%s.%s.%s.state', self::CACHE_KEY_PREFIX, $airport, $direction);
    }

    /**
     * Build the cache key used to store the rolling failure count.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return string Cache key for the failure counter.
     */
    private function getFailuresKey(string $airport, string $direction): string
    {
        return sprintf('%s.%s.%s.failures', self::CACHE_KEY_PREFIX, $airport, $direction);
    }

    /**
     * Build the cache key used to store the timestamp when the breaker opened.
     *
     * @param string $airport ICAO airport code used as part of the breaker scope.
     * @param string $direction Flight direction key, typically arrival or departure.
     * @return string Cache key for the opened-at timestamp.
     */
    private function getOpenedAtKey(string $airport, string $direction): string
    {
        return sprintf('%s.%s.%s.opened_at', self::CACHE_KEY_PREFIX, $airport, $direction);
    }
}
