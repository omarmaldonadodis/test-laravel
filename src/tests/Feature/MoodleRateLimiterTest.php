<?php

namespace Tests\Feature;

use App\Services\RateLimiting\MoodleRateLimiter;
use App\Exceptions\MoodleServiceException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MoodleRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // ✅ Habilitar rate limiting para estos tests
        config(['services.moodle.rate_limit' => [
            'enabled' => true,
            'max_attempts' => 10,
            'decay_seconds' => 60,
        ]]);
        
        Cache::flush();
    }

   
    public function test_it_allows_requests_until_limit_is_reached()
    {
        $limiter = new MoodleRateLimiter();

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($limiter->attempt('test'));
            $limiter->hit('test');
        }

        $this->assertEquals(0, $limiter->remaining('test'));
    }

    public function test_it_throws_exception_after_limit()
    {
        $limiter = new MoodleRateLimiter();

        // Hacer 10 llamadas
        for ($i = 0; $i < 10; $i++) {
            $limiter->attempt('test');
            $limiter->hit('test');
        }

        // La 11ª debe fallar
        $this->expectException(MoodleServiceException::class);
        $this->expectExceptionMessage('rate limit exceeded');

        $limiter->attempt('test');
    }
}