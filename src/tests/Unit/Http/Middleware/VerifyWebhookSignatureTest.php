<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VerifyWebhookSignatureTest extends TestCase
{
    private VerifyWebhookSignature $middleware;
    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        
        config(['services.medusa.medusa_webhook_secret' => $this->secret]);
        $this->middleware = new VerifyWebhookSignature();
    }

    /** @test */
    public function it_allows_request_with_valid_signature()
    {
        $payload = json_encode(['test' => 'data']);
        $validSignature = hash_hmac('sha256', $payload, $this->secret);

        $request = Request::create('/webhook', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Medusa-Signature', $validSignature);

        $response = $this->middleware->handle($request, fn() => response('OK', 200));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    /** @test */
    public function it_rejects_request_with_invalid_signature()
    {
        $payload = json_encode(['test' => 'data']);
        $invalidSignature = 'invalid-signature';

        $request = Request::create('/webhook', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Medusa-Signature', $invalidSignature);

        $response = $this->middleware->handle($request, fn() => response('OK'));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid signature', $response->getContent());
    }

    /** @test */
    public function it_rejects_request_without_signature_header()
    {
        $payload = json_encode(['test' => 'data']);

        $request = Request::create('/webhook', 'POST', [], [], [], [], $payload);
        // No signature header

        $response = $this->middleware->handle($request, fn() => response('OK'));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Unauthorized', $response->getContent());
    }

    /** @test */
    public function it_rejects_request_without_secret_configured()
    {
        config(['services.medusa.medusa_webhook_secret' => '']);
        
        $payload = json_encode(['test' => 'data']);
        $request = Request::create('/webhook', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Medusa-Signature', 'any-signature');

        $response = $this->middleware->handle($request, fn() => response('OK'));

        $this->assertEquals(401, $response->getStatusCode());
    }

    /** @test */
    public function it_does_not_log_sensitive_data_in_production()
    {
        app()->detectEnvironment(fn() => 'production');
        
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context = []) {
                // Verificar que NO contiene el secret ni el payload
                return $message === 'Webhook signature verification failed'
                    && !isset($context['secret'])
                    && !isset($context['payload']);
            });

        $payload = json_encode(['sensitive' => 'data']);
        $request = Request::create('/webhook', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Medusa-Signature', 'invalid');

        $this->middleware->handle($request, fn() => response('OK'));
    }

    /** @test */
    public function it_verifies_signature_with_special_characters_in_payload()
    {
        $payload = json_encode([
            'customer' => [
                'name' => 'José Martínez',
                'email' => 'test+special@example.com',
            ],
            'special_chars' => '!@#$%^&*()',
        ]);

        $validSignature = hash_hmac('sha256', $payload, $this->secret);

        $request = Request::create('/webhook', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-Medusa-Signature', $validSignature);

        $response = $this->middleware->handle($request, fn() => response('OK', 200));

        $this->assertEquals(200, $response->getStatusCode());
    }
}