<?php

namespace Tests\Unit\Services;

use App\Services\MoodleService;
use App\Services\RateLimiting\MoodleRateLimiter;
use App\Exceptions\MoodleServiceException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Mockery;

class MoodleServiceTest extends TestCase
{
    private MoodleService $service;
    private MoodleRateLimiter $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();
        
        config(['services.moodle' => [
            'url' => env('MOODLE_URL'),
            'token' => env('MOODLE_TOKEN'),
            'timeout' => env('MOODLE_TIMEOUT', 30),
            'cache_ttl' => env('MOODLE_CACHE_TTL', 3600),
            'rate_limit' => [
                'enabled' => env('MOODLE_RATE_LIMIT_ENABLED', false),
            ],
        ]]);

        $this->rateLimiter = Mockery::mock(MoodleRateLimiter::class);
        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->rateLimiter->shouldReceive('hit')->andReturn(null);
        $this->rateLimiter->shouldReceive('remaining')->andReturn(60);

        $this->service = new MoodleService(
            Cache::store('array'),
            $this->rateLimiter
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_generates_unique_username_from_email()
    {
        $email = 'test@example.com';
        $username1 = $this->service->generateUsername($email);
        
        // Esperar un momento para asegurar diferencia en timestamp
        usleep(10000); // 10ms
        
        $username2 = $this->service->generateUsername($email);

        $this->assertNotEquals($username1, $username2);
        $this->assertStringStartsWith('test', $username1);
        $this->assertStringStartsWith('test', $username2);
    }

    public function test_it_generates_username_from_short_email()
    {
        $email = 'a@b.c';
        $username = $this->service->generateUsername($email);

        $this->assertGreaterThanOrEqual(10, strlen($username));
    }

    public function test_it_generates_secure_password()
    {
        $password = $this->service->generatePassword(12);

        $this->assertEquals(12, strlen($password));
        $this->assertMatchesRegularExpression('/[a-zA-Z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
    }

    public function test_it_creates_new_user_in_moodle()
    {
        Http::fake([
            '*/webservice/rest/server.php*' => Http::sequence()
                ->push([], 200) // getUserByEmail returns empty
                ->push([['id' => 123, 'username' => 'testuser']], 200), // createUser
        ]);

        $userData = [
            'username' => 'testuser',
            'password' => 'TestPass123!',
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'test@example.com',
        ];

        $result = $this->service->createUser($userData);

        $this->assertIsArray($result);
        $this->assertEquals(123, $result['id']);
        $this->assertEquals('testuser', $result['username']);
        $this->assertFalse($result['existing']);
    }

    public function test_it_returns_existing_user_if_already_exists()
    {
        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([
                [
                    'id' => 999,
                    'username' => 'existinguser',
                    'email' => 'existing@example.com',
                    'firstname' => 'Existing',
                    'lastname' => 'User',
                ]
            ], 200),
        ]);

        $userData = [
            'username' => 'existinguser',
            'password' => 'pass',
            'firstname' => 'Existing',
            'lastname' => 'User',
            'email' => 'existing@example.com',
        ];

        $result = $this->service->createUser($userData);

        $this->assertEquals(999, $result['id']);
        $this->assertTrue($result['existing']);
    }

    public function test_it_throws_exception_on_moodle_error()
    {
        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([
                'exception' => 'moodle_exception',
                'errorcode' => 'invalidtoken',
                'message' => 'Invalid token',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid token');
        $this->service->checkTokenPermissions();
    }

    public function test_it_enrolls_user_in_course()
    {
        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([], 200), // ✅ Array vacío, no null
        ]);

        $result = $this->service->enrollUser(123, 2, 5);

        $this->assertTrue($result);
    }

    public function test_it_checks_if_user_is_enrolled()
    {
        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([
                ['id' => 2, 'fullname' => 'Test Course'],
                ['id' => 3, 'fullname' => 'Another Course'],
            ], 200),
        ]);

        $isEnrolled = $this->service->isUserEnrolled(123, 2);
        $isNotEnrolled = $this->service->isUserEnrolled(123, 999);

        $this->assertTrue($isEnrolled);
        $this->assertFalse($isNotEnrolled);
    }

    public function test_it_caches_user_lookup_by_email()
    {
        Http::fake([
            '*/webservice/rest/server.php*' => Http::response([
                [
                    'id' => 123,
                    'email' => 'cached@example.com',
                    'username' => 'cacheduser',
                ]
            ], 200),
        ]);

        // Primera llamada - debería hacer request HTTP
        $user1 = $this->service->getUserByEmail('cached@example.com');
        
        // Segunda llamada - debería venir de cache
        $user2 = $this->service->getUserByEmail('cached@example.com');

        $this->assertEquals($user1, $user2);
        Http::assertSentCount(1); // Solo 1 request HTTP
    }

    public function test_it_handles_connection_timeout()
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
        });

        $this->expectException(MoodleServiceException::class);
        $this->expectExceptionMessage('Failed to connect to Moodle');

        // ✅ Simplificar
        $this->service->checkTokenPermissions();
    }
}