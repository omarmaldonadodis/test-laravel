<?php

namespace App\Services\RateLimiting;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Exceptions\MoodleServiceException;

/**
 * Rate Limiter para Moodle API
 * Principio: Single Responsibility - Solo maneja throttling
 */
class MoodleRateLimiter
{
    private bool $enabled;
    private int $maxAttempts;
    private int $decaySeconds;
    private string $keyPrefix = 'moodle_rate_limit';

    public function __construct()
    {
        $this->enabled = config('services.moodle.rate_limit.enabled', true);
        $this->maxAttempts = config('services.moodle.rate_limit.max_attempts', 60);
        $this->decaySeconds = config('services.moodle.rate_limit.decay_seconds', 60);
    }

    /**
     * Verifica si se puede hacer una llamada
     */
    public function attempt(string $identifier = 'global'): bool
    {
        if (!$this->enabled) {
            return true; // Rate limiting deshabilitado
        }

        $key = $this->getKey($identifier);
        $attempts = Cache::get($key, 0);

        if ($attempts >= $this->maxAttempts) {
            $ttl = Cache::get("{$key}:ttl", 0);
            
            Log::warning('🚫 Moodle API rate limit exceeded', [
                'identifier' => $identifier,
                'attempts' => $attempts,
                'max_attempts' => $this->maxAttempts,
                'retry_after' => $ttl,
            ]);

            throw MoodleServiceException::rateLimitExceeded(
                $this->maxAttempts,
                $ttl,
                ['identifier' => $identifier]
            );
        }

        return true;
    }

    /**
     * Registra un intento de llamada
     */
    public function hit(string $identifier = 'global'): void
    {
        if (!$this->enabled) {
            return;
        }

        $key = $this->getKey($identifier);
        $attempts = Cache::get($key, 0);

        if ($attempts === 0) {
            // Primera llamada, establecer TTL
            Cache::put($key, 1, $this->decaySeconds);
            Cache::put("{$key}:ttl", $this->decaySeconds, $this->decaySeconds);
        } else {
            // Incrementar contador
            Cache::increment($key);
        }

        Log::debug('📊 Moodle API call tracked', [
            'identifier' => $identifier,
            'attempts' => $attempts + 1,
            'max_attempts' => $this->maxAttempts,
        ]);
    }

    /**
     * Obtiene intentos restantes
     */
    public function remaining(string $identifier = 'global'): int
    {
        if (!$this->enabled) {
            return PHP_INT_MAX;
        }

        $key = $this->getKey($identifier);
        $attempts = Cache::get($key, 0);
        
        return max(0, $this->maxAttempts - $attempts);
    }

    /**
     * Resetea el contador
     */
    public function reset(string $identifier = 'global'): void
    {
        $key = $this->getKey($identifier);
        Cache::forget($key);
        Cache::forget("{$key}:ttl");
    }

    /**
     * Genera la clave de cache
     */
    private function getKey(string $identifier): string
    {
        return "{$this->keyPrefix}:{$identifier}";
    }
}