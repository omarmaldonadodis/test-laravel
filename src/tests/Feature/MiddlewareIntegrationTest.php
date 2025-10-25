<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWebhookSignature;
use App\Models\User;
use App\Models\EnrollmentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MiddlewareIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private VerifyWebhookSignature $webhookMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        // Configurar middleware webhook
        $this->webhookMiddleware = new VerifyWebhookSignature();
        config(['services.medusa.medusa_webhook_secret' => 'test-secret-key']);
    }

    // ===============================
    // 🔹 Webhook Middleware - Unit Tests
    // ===============================

    #[Test]
    public function webhook_rejects_invalid_signature(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test":"data"}');
        $request->headers->set('X-Medusa-Signature', 'invalid');

        $response = $this->webhookMiddleware->handle($request, fn() => response('OK'));

        $this->assertEquals(401, $response->getStatusCode());
    }

    #[Test]
    public function webhook_accepts_valid_signature(): void
    {
        $payload = '{"order_id":"123"}';
        $validSignature = hash_hmac('sha256', $payload, 'test-secret-key');

        $request = Request::create('/webhook', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Medusa-Signature', $validSignature);

        $response = $this->webhookMiddleware->handle($request, fn() => response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function webhook_does_not_log_secret_in_production(): void
    {
        app()->detectEnvironment(fn() => 'production');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context = []) {
                $hasSecret = str_contains(json_encode($context), 'test-secret-key');
                return $message === 'Webhook signature verification failed' && !$hasSecret;
            });

        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"test":"data"}');
        $request->headers->set('X-Medusa-Signature', 'invalid');

        $this->webhookMiddleware->handle($request, fn() => response('OK'));
    }

    // ===============================
    // 🔹 Sanctum Middleware - Feature Tests
    // ===============================

    #[Test]
    public function enrollment_logs_require_authentication(): void
    {
        $this->getJson('/api/enrollment-logs')->assertStatus(401);
    }

    #[Test]
    public function enrollment_logs_access_with_authenticated_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/enrollment-logs')->assertStatus(200);
    }

    #[Test]
    public function enrollment_logs_show_route_requires_authentication(): void
    {
        $this->getJson('/api/enrollment-logs/1')->assertStatus(401);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Crear un registro para probar la ruta
        $log = EnrollmentLog::factory()->create([
            'status' => 'success',
            'customer_email' => 'test@example.com',
        ]);

        $response = $this->getJson("/api/enrollment-logs/{$log->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.customer.email', 'test@example.com');
    }
}
