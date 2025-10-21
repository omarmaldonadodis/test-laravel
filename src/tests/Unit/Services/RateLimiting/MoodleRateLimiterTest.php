<?php

namespace Tests\Unit\Services\RateLimiting;

use App\Services\RateLimiting\MoodleRateLimiter;
use App\Exceptions\MoodleServiceException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MoodleRateLimiterTest extends TestCase
{
    private MoodleRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        
        Cache::flush();
        
        config(['services.moodle.rate_limit' => [
            'enabled' => true,
            'max_attempts' => 5, // Reducido para tests
            'decay_seconds' => 60,
        ]]);

        $this->limiter = new MoodleRateLimiter();
    }

    /** @test */
    public function it_allows_requests_under_limit()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->limiter->attempt());
            $this->limiter->hit();
        }

        $this->assertEquals(0, $this->limiter->remaining());
    }

    /** @test */
    public function it_throws_exception_when_limit_exceeded()
    {
        // Hacer 5 llamadas (el máximo)
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt();
            $this->limiter->hit();
        }

        // La sexta debe fallar
        $this->expectException(MoodleServiceException::class);
        $this->expectExceptionMessage('rate limit exceeded');

        $this->limiter->attempt();
    }

    /** @test */
    public function it_tracks_remaining_attempts()
    {
        $this->assertEquals(5, $this->limiter->remaining('test1'));

        $this->limiter->attempt('test1');
        $this->limiter->hit('test1');
        
        $this->assertEquals(4, $this->limiter->remaining('test1'));

        $this->limiter->attempt('test1');
        $this->limiter->hit('test1');
        
        $this->assertEquals(3, $this->limiter->remaining('test1'));
    }

    /** @test */
    public function it_uses_different_counters_per_identifier()
    {
        $this->limiter->attempt('user1');
        $this->limiter->hit('user1');

        $this->limiter->attempt('user2');
        $this->limiter->hit('user2');

        $this->assertEquals(4, $this->limiter->remaining('user1'));
        $this->assertEquals(4, $this->limiter->remaining('user2'));
    }

    /** @test */
    public function it_resets_counter()
    {
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->attempt('reset_test');
            $this->limiter->hit('reset_test');
        }

        $this->assertEquals(2, $this->limiter->remaining('reset_test'));

        $this->limiter->reset('reset_test');

        $this->assertEquals(5, $this->limiter->remaining('reset_test'));
    }

    /** @test */
    public function it_allows_unlimited_requests_when_disabled()
    {
        config(['services.moodle.rate_limit.enabled' => false]);
        $limiter = new MoodleRateLimiter();

        // Hacer más llamadas que el límite
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($limiter->attempt());
            $limiter->hit();
        }

        $this->assertEquals(PHP_INT_MAX, $limiter->remaining());
    }

    /** @test */
    public function it_expires_counter_after_decay_seconds()
    {
        config(['services.moodle.rate_limit.decay_seconds' => 1]);
        $limiter = new MoodleRateLimiter();

        $limiter->attempt('expire_test');
        $limiter->hit('expire_test');

        $this->assertEquals(4, $limiter->remaining('expire_test'));

        // Esperar que expire
        sleep(2);

        // Debe permitir nuevas llamadas
        $limiter->attempt('expire_test');
        $limiter->hit('expire_test');
        
        $this->assertEquals(4, $limiter->remaining('expire_test'));
    }
}