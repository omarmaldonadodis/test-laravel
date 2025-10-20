<?php

namespace Tests\Feature;

use App\Exceptions\MoodleServiceException;
use App\Services\RateLimiting\MoodleRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MoodleRateLimiterTest extends TestCase
{
    public function test_it_allows_requests_until_limit_is_reached()
    {
        $limiter = new MoodleRateLimiter();
        $limiter->reset('test');

        for ($i = 1; $i <= 60; $i++) {
            $this->assertTrue($limiter->attempt('test'));
            $limiter->hit('test');
        }

        $this->assertEquals(0, $limiter->remaining('test'));
    }

    public function test_it_throws_exception_after_limit()
    {
        $this->expectException(MoodleServiceException::class);

        $limiter = new MoodleRateLimiter();
        $limiter->reset('test');

        for ($i = 1; $i <= 61; $i++) {
            $limiter->attempt('test');
            $limiter->hit('test');
        }
    }
}
